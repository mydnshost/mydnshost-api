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
				             'avatar' => $user->getAvatar(),
				            ];

				if (getSystemRegisterRequireTerms()) {
					$userinfo['termstime'] = $user->getAcceptTerms();
					$userinfo['acceptterms'] = $user->getAcceptTerms() > getSystemMinimumTermsTime();
				}

				if (!($user instanceof DomainKeyUser)) {
					$userinfo['customdata'] = [];

					$ui = UserCustomData::loadFromUserKey($this->getContextKey('db'), $user->getID());
					if ($ui !== false) {
						foreach ($ui as $d) {
							$userinfo['customdata'][$d->getKey()] = $d->getValue();
						}
					}
				}

				$this->getContextKey('response')->set('user', $userinfo);
			}
			$this->getContextKey('response')->set('access', $this->getContextKey('access'));

			return TRUE;
		}
	});
