<?php

	class SystemServiceMgmt extends RouterMethod {
		public function check() {
			$user = $this->getContextKey('user');
			if ($user == NULL) {
				throw new RouterMethod_NeedsAuthentication();
			}

			$this->checkPermissions(['system_service_mgmt']);

			if (!file_exists('/var/run/docker.sock')) {
				$this->getContextKey('response')->sendError('Unable to obtain data.');
			}
		}
	}

	$router->get('/system/service/list', new class extends SystemServiceMgmt {
		function run() {
			$containers = getFromDocker('/containers/json');

			$list = [];
			foreach ($containers as $container) {
				$list[] = substr($container['Names'][0], 1);
			}

			$this->getContextKey('response')->data($list);

			return TRUE;
		}
	});

	$router->get('/system/service/([^/]+)/logs', new class extends SystemServiceMgmt {
		function run($service) {
			$since = isset($_REQUEST['since']) ? 0 + $_REQUEST['since'] : time() - 3600;
			$logs = getLogsFromDocker($service, $since);
			$this->getContextKey('response')->data($logs);

			return TRUE;
		}
	});
