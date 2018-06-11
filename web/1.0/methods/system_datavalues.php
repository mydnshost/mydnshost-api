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
