<?php

	class SystemJobsMgmt extends RouterMethod {
		public function check() {
			$user = $this->getContextKey('user');
			if ($user == NULL) {
				throw new RouterMethod_NeedsAuthentication();
			}

			$this->checkPermissions(['system_job_mgmt']);
		}
	}

	$router->get('/system/jobs/list', new class extends SystemJobsMgmt {
		function run() {
			$jobSearch = Job::getSearch($this->getContextKey('db'));
			$jobSearch->order('id', 'desc');
			$jobSearch->limit(50);

			$rows = $jobSearch->getRows();

			$this->getContextKey('response')->data($rows);

			return TRUE;
		}
	});

	$router->get('/system/jobs/([0-9]+)', new class extends SystemJobsMgmt {
		function run($job) {
			$j = Job::load($this->getContextKey('db'), $job);

			if ($j !== false) {
				$this->getContextKey('response')->data($j->toArray());
			} else {
				$this->getContextKey('response')->sendError('Error loading job.');
			}
			return TRUE;
		}
	});

	$router->get('/system/jobs/([0-9]+)/logs', new class extends SystemJobsMgmt {
		function run($job) {
			$j = Job::load($this->getContextKey('db'), $job);

			if ($j !== false) {
				$this->getContextKey('response')->data($j->getLogs());
			} else {
				$this->getContextKey('response')->sendError('Error loading job.');
			}
			return TRUE;
		}
	});
