<?php

	$router->addRoute('GET /userdata', new class extends APIMethod {
		function check($requestMethod, $params) {
			if ($this->getContextKey('user') == NULL) {
				throw new APIMethod_NeedsAuthentication();
			}
		}

		function call($requestMethod, $params) {
			$user = $this->getContextKey('user');

			$userinfo = ['id' => $user->getId(),
			             'email' => $user->getEmail(),
			             'realname' => $user->getRealName(),
			            ];

			$this->getContextKey('response')->set('user', $userinfo);
			$this->getContextKey('response')->set('access', $this->getContextKey('access'));

			return TRUE;
		}
	});
