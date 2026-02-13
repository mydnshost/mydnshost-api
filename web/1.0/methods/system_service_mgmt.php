<?php

	class SystemServiceMgmt extends RouterMethod {
		public function check() {
			$user = $this->getContextKey('user');
			if ($user == NULL) {
				throw new RouterMethod_NeedsAuthentication();
			}

			$this->checkPermissions(['system_service_mgmt']);

			if ($this->hasContextKey('key') && !$this->getContextKey('key')->getAdminFeatures()) {
				throw new RouterMethod_AccessDenied();
			}
		}
	}

	$router->get('/system/service/list', new class extends SystemServiceMgmt {
		function run() {
			Mongo::get()->connect();
			$containers = Mongo::get()->getCollection('dockerlogs')->distinct('docker.hostname');

			$this->getContextKey('response')->data($containers);

			return TRUE;
		}
	});

	$router->get('/system/service/([^/]+)/logs', new class extends SystemServiceMgmt {
		function run($service) {
			Mongo::get()->connect();

			$limit = 100;
			$page = isset($_REQUEST['page']) ? max(1, intval($_REQUEST['page'])) : 1;

			$filter = ['docker.hostname' => $service];

			// Filter by stream (stdout/stderr).
			if (isset($_REQUEST['stream']) && $_REQUEST['stream'] !== '') {
				$filter['stream'] = $_REQUEST['stream'];
			}

			// Filter by message text (case-insensitive regex).
			if (isset($_REQUEST['search']) && $_REQUEST['search'] !== '') {
				$filter['message'] = ['$regex' => preg_quote($_REQUEST['search']), '$options' => 'i'];
			}

			$collection = Mongo::get()->getCollection('dockerlogs');

			// Get total count for pagination.
			$total = intval($collection->countDocuments($filter));
			$totalPages = intval(max(1, ceil($total / $limit)));
			$page = min($page, $totalPages);
			$skip = ($page - 1) * $limit;

			$logs = $collection->find($filter, ['projection' => ['_id' => 0], 'sort' => ['timestamp' => -1], 'skip' => $skip, 'limit' => $limit])->toArray();
			$logs = array_reverse($logs);
			foreach ($logs as &$log) {
				if ($log['timestamp'] instanceof \MongoDB\BSON\UTCDateTime) {
					$log['timestamp'] = $log['timestamp']->toDateTime()->format('r');
				}
			}

			$this->getContextKey('response')->data(['logs' => $logs, 'pagination' => ['page' => $page, 'totalPages' => $totalPages, 'total' => $total]]);

			return TRUE;
		}
	});
