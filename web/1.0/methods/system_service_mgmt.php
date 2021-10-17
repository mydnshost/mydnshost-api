<?php

	class SystemServiceMgmt extends RouterMethod {
		public function check() {
			$user = $this->getContextKey('user');
			if ($user == NULL) {
				throw new RouterMethod_NeedsAuthentication();
			}

			$this->checkPermissions(['system_service_mgmt']);

			if ($this->hasContextKey('key') && !$this->hasContextKey('key')->getAdminFeatures()) {
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

			$logs = Mongo::get()->getCollection('dockerlogs')->find(['docker.hostname' => $service], ['projection' => ['_id' => 0], 'sort' => ['timestamp' => -1], 'limit' => 100])->toArray();
			$this->getContextKey('response')->data(array_reverse($logs));

			return TRUE;
		}
	});
