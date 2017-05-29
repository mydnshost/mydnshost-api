<?php

	$router->get('/userdata', new class extends RouterMethod {
		function check() {
			if ($this->getContextKey('user') == NULL) {
				throw new RouterMethod_NeedsAuthentication();
			}
		}

		function run() {
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
