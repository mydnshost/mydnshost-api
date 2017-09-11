<?php

	use shanemcc\phpdb\ValidationFailed;

	class Statistics extends RouterMethod {
		public function check() {
			$user = $this->getContextKey('user');
			if ($user == NULL) {
				throw new RouterMethod_NeedsAuthentication();
			}
		}

		protected function getQueriesPerServer($type = 'raw', $time = '3600') {
			try {
				$database = getInfluxDB();

				// executing a query will yield a resultset object
				$result = $database->getQueryBuilder();

				if ($type == 'derivative') {
					$result = $result->select('non_negative_derivative(sum("value")) AS value');
				} else {
					$result = $result->select('sum("value") AS value');
				}

				$result = $result->from('opcode_query')
				                 ->where(["time > now() - " . $time . "s"])
				                 ->groupby("time(60s)")->groupby("host")
				                 ->getResultSet();

				$stats = [];
				foreach ($result->getSeries() AS $series) {
					$host = $series['tags']['host'];
					$stats[$host] = [];

					foreach ($series['values'] as $val) {
						if ($val[1] === NULL) { continue; }
						$stat = ['time' => strtotime($val[0]), 'value' => (int)$val[1]];

						$stats[$host][] = $stat;
					}
				}

				$this->getContextKey('response')->data(['stats' => $stats]);

				return true;
			} catch (Exception $ex) { }

			return false;
		}

		protected function getQueriesPerRRType($type = 'raw', $time = '3600') {
			try {
				$database = getInfluxDB();

				// executing a query will yield a resultset object
				$result = $database->getQueryBuilder();

				if ($type == 'derivative') {
					$result = $result->select('non_negative_derivative(sum("value")) AS value');
				} else {
					$result = $result->select('sum("value") AS value');
				}

				$result = $result->from('qtype')
				                 ->where(["time > now() - " . $time . "s"])
				                 ->groupby("time(60s)")->groupby("qtype")
				                 ->getResultSet();

				$stats = [];
				foreach ($result->getSeries() AS $series) {
					$qtype = $series['tags']['qtype'];
					$stats[$qtype] = [];

					$total = 0;
					foreach ($series['values'] as $val) {
						if ($val[1] === NULL) { continue; }
						$stat = ['time' => strtotime($val[0]), 'value' => (int)$val[1]];
						$total += $stat['value'];

						$stats[$qtype][] = $stat;
					}
					if ($total == 0) { unset($stats[$qtype]); }
				}

				$this->getContextKey('response')->data(['stats' => $stats]);

				return true;
			} catch (Exception $ex) { }

			return false;
		}
	}

	$router->get('/system/stats/queries-per-server', new class extends Statistics {
		function run() {
			$this->checkPermissions(['system_stats']);

			$time = isset($_REQUEST['time']) && ctype_digit($_REQUEST['time']) ? $_REQUEST['time'] : 3600;
			$type = isset($_REQUEST['type'])? $_REQUEST['type'] : "raw";

			return $this->getQueriesPerServer($type, $time);
		}
	});

	$router->get('/system/stats/queries-per-rrtype', new class extends Statistics {
		function run() {
			$this->checkPermissions(['system_stats']);

			$time = isset($_REQUEST['time']) && ctype_digit($_REQUEST['time']) ? $_REQUEST['time'] : 3600;
			$type = isset($_REQUEST['type'])? $_REQUEST['type'] : "raw";

			return $this->getQueriesPerRRType($type, $time);
		}
	});
