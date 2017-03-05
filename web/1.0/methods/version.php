<?php

	class Version extends APIMethod {
		public function check($requestMethod, $matches) {
			if ($this->getContextKey('user') == NULL) {
				throw new APIMethod_NeedsAuthentication();
			}
		}

		public function get($matches) {
			$user = $this->getContextKey('user');

			$apiData = ['version' => '1.0'];

			if ($user->isAdmin()) {
				$apiData['gitversion'] = `git describe --tags 2>&1`;
			}

			$this->getContextKey('response')->data($apiData);

			return TRUE;
		}
	}

	$router->addRoute('GET /version', new Version());
