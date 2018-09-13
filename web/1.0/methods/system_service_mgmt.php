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

		public function getFromDocker($method) {
			$ch = curl_init();

			curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, '/var/run/docker.sock');
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

 			curl_setopt($ch, CURLOPT_URL, 'http:' . $method);

			$response = json_decode(curl_exec($ch), true);
			return $response;
		}
	}

	$router->get('/system/service/list', new class extends SystemServiceMgmt {
		function run() {
			$containers = $this->getFromDocker('/containers/json');
			$list = [];
			foreach ($containers as $container) {
				$list[] = $containers['Names'][0];
			}

			$this->getContextKey('response')->data($list);

			return TRUE;
		}
	});

	$router->get('/system/service/([^/]+)/logs', new class extends SystemServiceMgmt {
		function run($service) {
			$since = isset($_REQUEST['since']) ? 0 + $_REQUEST['since'] : time() - 3600;

			$log = $this->getFromDocker('/containers/'. $service .'/logs?stderr=1&stdout=1&since' . $since . '&timestamps=1');

			$this->getContextKey('response')->data($log);

			return TRUE;
		}
	});
