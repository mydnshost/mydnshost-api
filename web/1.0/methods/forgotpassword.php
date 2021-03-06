<?php
	$router->post('/forgotpassword', new class extends RouterMethod {
		function run() {
			$data = $this->getContextKey('data');
			if (!isset($data['data']) || !is_array($data['data'])) {
				$this->getContextKey('response')->sendError('No data provided.');
			}

			if (!array_key_exists('email', $data['data'])) {
				$this->getContextKey('response')->sendError('Missing data: email');
			}

			$successMessage = ['success' => 'Password reset was submitted. If the email address you entered matched a valid account then you will get an email with further instructions.'];

			$user = User::loadFromEmail($this->getContextKey('db'), $data['data']['email']);
			if ($user == FALSE || $user->isDisabled()) {
				$this->getContextKey('response')->data($successMessage);
				return TRUE;
				// $this->getContextKey('response')->sendError('Invalid user');
			}

			$time = time();
			$uuid = md5(genUUID());
			$code = trim(str_replace('/', '_', base64_encode(gzdeflate($time . '/' . $uuid . '/' . crc32($user->getRawPassword() . $uuid . $time)))), '=');

			$user->setVerifyCode($code);
			$user->save();

			$te = TemplateEngine::get();
			$te->setVar('user', $user);
			[$subject, $message, $htmlmessage] = templateToMail($te, 'forgotpassword.tpl');
			EventQueue::get()->publish('mail.send', [$user->getEmail(), $subject, $message, $htmlmessage]);


			$this->getContextKey('response')->data($successMessage);

			return TRUE;
		}
	});

	$router->post('/forgotpassword/confirm/([0-9]+)', new class extends RouterMethod {
		function run($userid) {
			$data = $this->getContextKey('data');
			if (!isset($data['data']) || !is_array($data['data'])) {
				$this->getContextKey('response')->sendError('No data provided for registration.');
			}

			if (!array_key_exists('code', $data['data'])) {
				$this->getContextKey('response')->sendError('Missing data: code');
			}

			if (!array_key_exists('password', $data['data'])) {
				$this->getContextKey('response')->sendError('Missing data: password');
			}

			$user = User::load($this->getContextKey('db'), $userid);
			if ($user == FALSE) {
				$this->getContextKey('response')->sendError('Invalid user');
			}

			if (empty($user->getVerifyCode()) || $user->isDisabled()) {
				$this->getContextKey('response')->sendError('User does not require password reset.');
			}

			if ($user->getVerifyCode() != $data['data']['code']) {
				$this->getContextKey('response')->sendError('Invalid verification code.');
			}

			// Check code validity.
			$bits = explode('/', @gzinflate(@base64_decode(str_replace('_', '/', $data['data']['code']))));
			if (!isset($bits[2])) {
				$this->getContextKey('response')->sendError('Malformed verification code.');
			}

			$time = $bits[0];
			$uuid = $bits[1];
			$crc32 = $bits[2];
			$wanted_crc32 = crc32($user->getRawPassword() . $uuid . $time);

			if ($crc32 != $wanted_crc32) {
				$this->getContextKey('response')->sendError('Invalid verification code.');
			}

			if (time() - 3600 > $time) {
				$this->getContextKey('response')->sendError('Verification code has expired.');
			}

			$user->setVerifyCode('');
			$user->setPassword($data['data']['password']);
			$result = $user->save();

			if ($result) {
				$te = TemplateEngine::get();
				$te->setVar('user', $user);
				[$subject, $message, $htmlmessage] = templateToMail($te, 'passwordchanged.tpl');
				EventQueue::get()->publish('mail.send', [$user->getEmail(), $subject, $message, $htmlmessage]);

				$this->getContextKey('response')->data(['success' => 'Password was changed, you can now login.']);
			} else {
				$this->getContextKey('response')->sendError('There was an unknown error resetting the password.');
			}

			return TRUE;
		}
	});
