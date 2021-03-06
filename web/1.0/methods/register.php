<?php

	use shanemcc\phpdb\ValidationFailed;

	$router->post('/register', new class extends RouterMethod {
		function run() {
			if (!getSystemRegisterEnabled()) {
				$this->getContextKey('response')->sendError('Registration is currently closed.');
			}

			$user = new User($this->getContextKey('db'));

			$data = $this->getContextKey('data');
			if (!isset($data['data']) || !is_array($data['data'])) {
				$this->getContextKey('response')->sendError('No data provided for registration.');
			}

			if (!array_key_exists('email', $data['data'])) {
				$this->getContextKey('response')->sendError('Missing data: email');
			}

			if (!array_key_exists('realname', $data['data'])) {
				$this->getContextKey('response')->sendError('Missing data: realname');
			}

			if (getSystemRegisterRequireTerms()) {
				if (!array_key_exists('acceptterms', $data['data'])) {
					$this->getContextKey('response')->sendError('You must accept the terms of service.');
				} else if (!parseBool($data['data']['acceptterms'])) {
					$this->getContextKey('response')->sendError('You must accept the terms of service.');
				} else {
					$user->setAcceptTerms(time());
				}
			}

			$user->setVerifyCode(genUUID());
			$user->setEmail($data['data']['email']);
			$user->setRealName($data['data']['realname']);
			$user->setRawPassword('-');
			$user->setDisabled(true);
			$user->setDisabledReason("Email address has not yet been verified.");

			foreach (BlockRegex::find($this->getContextKey('db'), ['signup_name' => 'true']) as $br) {
				if ($br->matches($user->getRealName())) {
					$this->getContextKey('response')->sendError('This name has been blocked from being used.');
				}
			}

			foreach (BlockRegex::find($this->getContextKey('db'), ['signup_email' => 'true']) as $br) {
				if ($br->matches($user->getEmail())) {
					$this->getContextKey('response')->sendError('This email address has been blocked from being used.');
				}
			}

			// Set default permissions.
			foreach (getSystemRegisterPermissions() as $permission) {
				$permission = trim($permission);
				if (!empty($permission)) {
					$user->setPermission($permission, true);
				}
			}

			try {
				$user->validate();
			} catch (ValidationFailed $ex) {
				$this->getContextKey('response')->sendError('Error creating user.');
			}

			$result = $user->save();

			if (!$result) {
				$reason = $user->getLastError()[2];

				if (preg_match('#.*Duplicate entry.*users_email_unique.*#', $reason)) {
					$this->getContextKey('response')->sendError('Error creating user, email address already exists.');
				} else {
					$this->getContextKey('response')->sendError('Unknown error creating user.');
				}
			} else {
				$u = ['id' => $user->getID(), 'email' => $user->getEmail(), 'realname' => $user->getRealName()];
				$this->getContextKey('response')->data($u);

				$te = TemplateEngine::get();
				$te->setVar('user', $user);
				[$subject, $message, $htmlmessage] = templateToMail($te, 'register.tpl');
				EventQueue::get()->publish('mail.send', [$user->getEmail(), $subject, $message, $htmlmessage]);
				EventQueue::get()->publish('user.new', [$user->getID()]);

				return TRUE;
			}
		}
	});

	$router->post('/register/confirm/([0-9]+)', new class extends RouterMethod {
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

			if (!$user->isUnVerified()) {
				$this->getContextKey('response')->sendError('User does not require verification.');
			}

			if ($user->getVerifyCode() != $data['data']['code']) {
				$this->getContextKey('response')->sendError('Invalid verification code.');
			}

			$user->setVerifyCode('');
			$user->setPassword($data['data']['password']);

			$data = [];
			if (getSystemRegisterManualVerify()) {
				$data = ['pending' => 'Registration was successful, however the account has been held pending manual approval.'];
				$user->setDisabled(true);
				$user->setDisabledReason('Account is pending manual approval.');

				EventQueue::get()->publish('user.new.pending', [$user->getID()]);
			} else {
				$data = ['success' => 'Registration was successful, you can now log in.'];
				$user->setDisabled(false);

				EventQueue::get()->publish('user.new.confirmed', [$user->getID()]);
			}

			$result = $user->save();

			if ($result) {
				$this->getContextKey('response')->data($data);
			} else {
				$this->getContextKey('response')->sendError('There was an unknown error confirming the registration.');
			}

			return TRUE;
		}
	});
