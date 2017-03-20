<?php

	class SessionAdmin extends APIMethod {
		public function check($requestMethod, $params) {
			$user = $this->getContextKey('user');
			if ($user == NULL) {
				throw new APIMethod_NeedsAuthentication();
			}
		}

		public function get($params) {
			session_start(['use_cookies' => '0', 'cache_limiter' => '']);
			$_SESSION['userid'] = $this->getContextKey('user')->getID();
			$_SESSION['access'] = $this->getContextKey('access');
			if ($this->hasContextKey('key')) {
				$_SESSION['keyid'] = $this->getContextKey('key')->getID();
			}
			session_commit();

			$this->getContextKey('response')->data(['session' => session_id()]);
			return true;
		}

		public function delete($params) {
			if ($this->hasContextKey('sessionid')) {
				session_id($this->getContextKey('sessionid'));
				session_start(['use_cookies' => '0', 'cache_limiter' => '']);
				unset($_SESSION['userid']);
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
	}

	$router->addRoute('(GET|DELETE) /session', new SessionAdmin());
