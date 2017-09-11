<?php

	use shanemcc\phpdb\ValidationFailed;

	class Statistics extends RouterMethod {
		public function check() {
			$user = $this->getContextKey('user');
			if ($user == NULL) {
				throw new RouterMethod_NeedsAuthentication();
			}
		}

		protected function getSystemStats() {
			return true;
		}
	}

	$router->get('/system/stats', new class extends Statistics {
		function run() {
			$this->checkPermissions(['system_stats']);

			return $this->getSystemStats();
		}
	});
