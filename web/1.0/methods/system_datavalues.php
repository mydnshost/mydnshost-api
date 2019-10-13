<?php

	$router->get('/system/datavalue/validPermissions', new class extends RouterMethod {
		function check() {
			if ($this->getContextKey('user') == NULL) {
				throw new RouterMethod_NeedsAuthentication();
			}
		}

		function run() {
			$this->getContextKey('response')->set('validPermissions', User::getValidPermissions());

			return TRUE;
		}
	});

	$router->get('/system/datavalue/validRecordTypes', new class extends RouterMethod {
		function check() {
			if ($this->getContextKey('user') == NULL) {
				throw new RouterMethod_NeedsAuthentication();
			}
		}

		function run() {
			$this->getContextKey('response')->set('validRecordTypes', Record::getValidRecordTypes());

			return TRUE;
		}
	});

	$router->get('/system/datavalue/selfDelete', new class extends RouterMethod {
		function check() {
			if ($this->getContextKey('user') == NULL) {
				throw new RouterMethod_NeedsAuthentication();
			}
		}

		function run() {
			$this->getContextKey('response')->set('selfDelete', getSystemAllowSelfDelete());

			return TRUE;
		}
	});

	$router->get('/system/datavalue/registerRequireTerms', new class extends RouterMethod {
		function run() {
			$this->getContextKey('response')->set('registerRequireTerms', getSystemRegisterRequireTerms());

			return TRUE;
		}
	});

	$router->get('/system/datavalue/registerEnabled', new class extends RouterMethod {
		function run() {
			$this->getContextKey('response')->set('registerEnabled', getSystemRegisterEnabled());

			return TRUE;
		}
	});

	$router->get('/system/datavalue/2faKeyTypes', new class extends RouterMethod {
		function run() {
			$types = [];

			$user = $this->getContextKey('user');

			foreach (TwoFactorKey::getKeyTypes() as $type => $permissions) {
				$valid = false;
				if (empty($permissions)) {
					$valid = true;
				} else if ($user != NULL) {
					$valid = true;
					foreach ($permissions as $perm) {
						$valid &= $user->getPermission($perm);
					}
				}

				if ($valid) { $types[] = $type; }
			}

			$this->getContextKey('response')->set('2faKeyTypes', $types);

			return TRUE;
		}
	});

	$router->get('/system/datavalue/defaultRecords', new class extends RouterMethod {
		function run() {
			$this->getContextKey('response')->set('defaultRecords', getSystemDefaultRecords());

			return TRUE;
		}
	});

	$router->get('/system/datavalue/importTypes', new class extends RouterMethod {
		function run() {
			$this->getContextKey('response')->set('importTypes', ['bind', 'tinydns']);

			return TRUE;
		}
	});

	$router->get('/system/datavalue/exportTypes', new class extends RouterMethod {
		function run() {
			$this->getContextKey('response')->set('exportTypes', ['bind']);

			return TRUE;
		}
	});
