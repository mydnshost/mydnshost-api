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

			$this->requireAdminElevation();
		}
	}

	$router->get('/system/service/list', new class extends SystemServiceMgmt {
		function run() {
			$services = VictoriaLogs::get()->streamFieldValues('*', 'service');
			sort($services);

			$this->getContextKey('response')->data($services);

			return TRUE;
		}
	});

	$router->get('/system/service/([^/]+)/logs', new class extends SystemServiceMgmt {
		function run($service) {
			$limit = 100;
			$page = isset($_REQUEST['page']) ? max(1, intval($_REQUEST['page'])) : 1;

			$filter = '{service=' . VictoriaLogs::quote($service) . '}';

			// Filter by stream (stdout/stderr).
			if (isset($_REQUEST['stream']) && $_REQUEST['stream'] !== '') {
				$filter .= ' stream:=' . VictoriaLogs::quote($_REQUEST['stream']);
			}

			// Filter by message text (case-insensitive).
			if (isset($_REQUEST['search']) && $_REQUEST['search'] !== '') {
				$filter .= ' ~' . VictoriaLogs::quote('(?i)' . preg_quote($_REQUEST['search']));
			}

			// Get total count for pagination.
			$count = VictoriaLogs::get()->query($filter . ' | stats count() total');
			$total = intval($count[0]['total'] ?? 0);
			$totalPages = intval(max(1, ceil($total / $limit)));
			$page = min($page, $totalPages);
			$skip = ($page - 1) * $limit;

			$logs = [];
			foreach (VictoriaLogs::get()->query($filter . ' | sort by (_time) desc | offset ' . $skip . ' | limit ' . $limit) as $log) {
				$logs[] = ['timestamp' => VictoriaLogs::formatTime($log['_time']),
				           'stream' => $log['stream'] ?? '',
				           'message' => $log['_msg'],
				           'docker' => ['hostname' => $log['service'], 'name' => $log['container_name'] ?? '', 'id' => $log['container_id'] ?? '', 'image' => $log['image'] ?? ''],
				          ];
			}
			$logs = array_reverse($logs);

			$this->getContextKey('response')->data(['logs' => $logs, 'pagination' => ['page' => $page, 'totalPages' => $totalPages, 'total' => $total]]);

			return TRUE;
		}
	});
