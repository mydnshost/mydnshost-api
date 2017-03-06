<?php

	class Version extends APIMethod {
		public function get($matches) {
			$apiData = ['version' => '1.0'];

			if ($this->getContextKey('user') != NULL && $this->getContextKey('user')->isAdmin()) {
				$apiData['gitversion'] = trim(`git describe --tags 2>&1`);
			}

			$this->getContextKey('response')->data($apiData);

			return TRUE;
		}
	}

	$router->addRoute('GET /version', new Version());
