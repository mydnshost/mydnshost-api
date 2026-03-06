<?php

	$router->addRoute('(GET|DELETE)', '/session', new class extends RouterMethod {
		function check() {
			$user = $this->getContextKey('user');
			if ($user == NULL) {
				throw new RouterMethod_NeedsAuthentication();
			}
		}

		function get() {
			session_start(['use_cookies' => '0', 'cache_limiter' => '']);
			$_SESSION['access'] = $this->getContextKey('access');
			if ($this->hasContextKey('domainkey')) {
				$_SESSION['domainkey'] = $this->getContextKey('domainkey')->getID();
			} else {
				$_SESSION['userid'] = $this->getContextKey('user')->getID();
				if ($this->hasContextKey('key')) {
					$_SESSION['keyid'] = $this->getContextKey('key')->getID();
				}
			}
			session_commit();

			$this->getContextKey('response')->data(['session' => session_id()]);
			return true;
		}

		function delete() {
			if ($this->hasContextKey('sessionid')) {
				session_id($this->getContextKey('sessionid'));
				session_start(['use_cookies' => '0', 'cache_limiter' => '']);
				unset($_SESSION['userid']);
				unset($_SESSION['domainkey']);
				unset($_SESSION['access']);
				unset($_SESSION['keyid']);
				session_destroy();
				session_commit();

				$this->getContextKey('response')->data(['session' => '']);
			} else {
				$this->getContextKey('response')->sendError('No current session.');
			}
			return true;
		}
	});

	$router->post('/session/admintoken', new class extends RouterMethod {
		function check() {
			$user = $this->getContextKey('user');
			if ($user == NULL) {
				throw new RouterMethod_NeedsAuthentication();
			}
		}

		function run() {
			if (!getAdminElevationEnabled()) {
				$this->getContextKey('response')->sendError('Admin elevation is not enabled.');
			}

			$data = $this->getContextKey('data');
			$user = $this->getContextKey('user');
			$db = $this->getContextKey('db');
			$elevationType = getAdminElevationType();

			if ($elevationType === '2fa') {
				if (!isset($data['data']['code']) || empty($data['data']['code'])) {
					$this->getContextKey('response')->sendError('2FA code is required.');
				}

				$testCode = $data['data']['code'];

				// Load active 2FA keys for this user.
				$possibleKeys = TwoFactorKey::getSearch($db)
					->where('user_id', $user->getID())
					->where('active', 'true')
					->find('key');

				$keys = [];
				foreach ($possibleKeys as $key) {
					if ($key->isUsableKey($user)) { $keys[] = $key; }
				}

				if (count($keys) === 0) {
					$this->getContextKey('response')->sendError('2FA is required for admin elevation. Please set up 2FA first.');
				}

				$valid = false;
				foreach ($keys as $key) {
					if ($key->isCode() && $key->verify($testCode, 1)) {
						$valid = true;
						$key->setLastUsed(time())->save();
						break;
					}
				}

				if (!$valid) {
					$this->getContextKey('response')->sendError('Invalid 2FA code.');
				}
			} else if ($elevationType === 'password') {
				if (!isset($data['data']['password']) || empty($data['data']['password'])) {
					$this->getContextKey('response')->sendError('Password is required.');
				}

				if (!$user->checkPassword($data['data']['password'])) {
					$this->getContextKey('response')->sendError('Invalid password.');
				}
			} else {
				$this->getContextKey('response')->sendError('Unknown admin elevation type configured.');
			}

			// Build the admin token JWT.
			$ttl = getAdminElevationTTL();
			$payload = [
				'iat' => time(),
				'exp' => time() + $ttl,
				'iss' => getSiteName(),
				'type' => 'admin_elevation',
				'userid' => $user->getID(),
			];

			$token = ReallySimpleJWT\Token::customPayload($payload, getAdminJWTSecret());

			$this->getContextKey('response')->data([
				'admintoken' => $token,
				'expires' => time() + $ttl,
			]);
			return true;
		}
	});

	$router->addRoute('GET', '/session/jwt', new class extends RouterMethod {
		function check() {
			$user = $this->getContextKey('user');
			if ($user == NULL) {
				throw new RouterMethod_NeedsAuthentication();
			}
		}

		function get() {
			$payload = [
				'iat' => time(),
				'exp' => time() + 3600,
				'iss' => getSiteName(),
				'access' => [], // $this->getContextKey('access');
			];

			if ($this->hasContextKey('domainkey')) {
				$payload['domainkey'] = $this->getContextKey('domainkey')->getID();
			} else {
				$payload['userid'] = $this->getContextKey('user')->getID();
				if ($this->hasContextKey('key')) {
					$payload['keyid'] = $this->getContextKey('key')->getID();
				} else {
					// User logged in using a username/password not a key
					// we want to invalidate sessions if this changes.
					$payload['nonce'] = $this->getContextKey('user')->getPasswordNonce();
				}
			}

			$token = ReallySimpleJWT\Token::customPayload($payload, getJWTSecret());

			$this->getContextKey('response')->data(['token' => $token]);
			return true;
		}
	});
