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
