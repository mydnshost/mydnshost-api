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

		public function getFromDocker($method, $json = true) {
			$info = $this->doGetFromDocker('/info', true);
			if (isset($info['message']) && $info['message'] == 'page not found') {
				$method = '/docker' . $method;
			}

			return $this->doGetFromDocker($method, $json);
		}

		private function doGetFromDocker($method, $json = true) {
			$ch = curl_init();

			curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, '/var/run/docker.sock');
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

 			curl_setopt($ch, CURLOPT_URL, 'http:' . $method);

			$response = curl_exec($ch);
			if ($json) {
				$response = json_decode($response, true);
			}

			return $response;
		}
	}

	$router->get('/system/service/list', new class extends SystemServiceMgmt {
		function run() {
			$containers = $this->getFromDocker('/containers/json');

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

			$log = $this->getFromDocker('/containers/'. $service .'/logs?stderr=1&stdout=1&since' . $since . '&timestamps=1', false);

			$logs = [];
			$pos = 0;
			while ($pos < strlen($log)) {
				$type = unpack('C*', substr($log, $pos, 1))[1];
				$len = unpack('N', substr($log, $pos + 4, 4))[1];

				$str = substr($log, $pos + 8, $len);
				switch ($type) {
					case 0:
						$type = "STDIN";
						break;
					case 1:
						$type = "STDOUT";
						break;
					case 2:
						$type = "STDERR";
						break;
					default:
						$type = "UNKNOWN";
				}

				$str = explode(' ', $str, 2);
				$timestamp = $str[0];
				$str = isset($str[1]) ? $str[1] : '';

				$logs[] = [$type, $timestamp, $str];

				$pos += 8 + $len;
			}


			$this->getContextKey('response')->data($logs);

			return TRUE;
		}
	});
