<?php

	use shanemcc\phpdb\ValidationFailed;

	class Statistics extends RouterMethod {
		public function check() {
			$user = $this->getContextKey('user');
			if ($user == NULL) {
				throw new RouterMethod_NeedsAuthentication();
			}
		}
	}

	$router->get('/system/stats/queries-per-server', new class extends Statistics {
		function run() {
			$this->checkPermissions(['system_stats']);

			$time = isset($_REQUEST['time']) && ctype_digit($_REQUEST['time']) ? $_REQUEST['time'] : 3600;
			$type = isset($_REQUEST['type'])? $_REQUEST['type'] : "raw";

			$result = getGlobalQueriesPerServer($type, $time);
			if ($result !== false) {
				$this->getContextKey('response')->data($result);
				return true;
			}
			return false;
		}
	});

	$router->get('/system/stats/queries-per-rrtype', new class extends Statistics {
		function run() {
			$this->checkPermissions(['system_stats']);

			$time = isset($_REQUEST['time']) && ctype_digit($_REQUEST['time']) ? $_REQUEST['time'] : 3600;
			$type = isset($_REQUEST['type'])? $_REQUEST['type'] : "raw";

			$result = getGlobalQueriesPerRRType($type, $time);
			if ($result !== false) {
				$this->getContextKey('response')->data($result);
				return true;
			}
			return false;
		}
	});

	$router->get('/system/stats/queries-per-zone', new class extends Statistics {
		function run() {
			$this->checkPermissions(['system_stats']);

			$time = isset($_REQUEST['time']) && ctype_digit($_REQUEST['time']) ? $_REQUEST['time'] : 3600;
			$type = isset($_REQUEST['type'])? $_REQUEST['type'] : "raw";
			$zones = isset($_REQUEST['zones'])? $_REQUEST['zones'] : [];

			$result = getGlobalQueriesPerZone($type, $time, $zones);
			if ($result !== false) {
				$this->getContextKey('response')->data($result);
				return true;
			}
			return false;
		}
	});
