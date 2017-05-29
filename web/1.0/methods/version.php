<?php

	$router->get('/version', new class extends RouterMethod {
		function run() {
			$apiData = ['version' => '1.0'];

			// This permission doesn't actually exist right now, but we need
			// to lock this off.
			if ($this->getContextKey('user') != NULL && $this->checkPermissions(['site_admin'], true)) {
				$apiData['gitversion'] = trim(`git describe --tags 2>&1`);
			}

			$this->getContextKey('response')->data($apiData);

			return TRUE;
		}
	});

