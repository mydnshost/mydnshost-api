<?php

	$router->get('/userdata', new class extends RouterMethod {
		function check() {
			if ($this->getContextKey('user') == NULL) {
				throw new RouterMethod_NeedsAuthentication();
			}
		}

		function run() {
			$user = $this->getContextKey('user');

			if ($user instanceof DomainKeyUser) {
				$key = $user->getDomainKey();
				$keyinfo = ['key' => $key->getKey(),
				            'description' => $key->getDescription(),
				            'domain' => $key->getDomain()->getDomain(),
				           ];

				$this->getContextKey('response')->set('domainkey', $keyinfo);
			} else {
				$userinfo = ['id' => $user->getId(),
				             'email' => $user->getEmail(),
				             'realname' => $user->getRealName(),
				            ];

				if (getSystemRegisterRequireTerms()) {
					$userinfo['acceptterms'] = $user->getAcceptTerms() > 0;
				}

				$this->getContextKey('response')->set('user', $userinfo);
			}
			$this->getContextKey('response')->set('access', $this->getContextKey('access'));

			return TRUE;
		}
	});
