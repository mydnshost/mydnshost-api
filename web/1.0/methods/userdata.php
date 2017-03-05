<?php

	class Userdata extends APIMethod {
		public function check($requestMethod, $matches) {
			if ($this->getContextKey('user') == NULL) {
				throw new APIMethod_NeedsAuthentication();
			}
		}

		public function get($matches) {
			$user = $this->getContextKey('user');

			$userinfo = ['id' => $user->getId(),
			             'email' => $user->getEmail(),
			             'realname' => $user->getRealName(),
			            ];

			if ($user->isAdmin()) {
				$userinfo['admin'] = true;
			}

			$this->getContextKey('response')->set('user', $userinfo);

			return TRUE;
		}
	}

	$router->addRoute('GET /userdata', new Userdata());
