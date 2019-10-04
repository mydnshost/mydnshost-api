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

			$filter = isset($_REQUEST['filter']) ? $_REQUEST['filter'] : [];

			$rows = [];
			foreach ($jobSearch->search([]) as $j) {
				if (isset($filter['data'])) {
					foreach ($filter['data'] as $key => $value) {
						$json = $j->getJobData();

						if (!isset($json[$key]) || strtolower($json[$key]) != strtolower($value)) {
							continue 2;
						}
					}
				}

				$arr = $j->toArray();
				$arr['dependsOn'] = array_keys($j->getDependsOn());
				$arr['dependants'] = array_keys($j->getDependants());
				$rows[] = $arr;
			}

			$this->getContextKey('response')->data($rows);

			return TRUE;
		}
	});

	$router->get('/system/jobs/([0-9]+)', new class extends SystemJobsMgmt {
		function run($job) {
			$j = Job::load($this->getContextKey('db'), $job);

			if ($j !== false) {
				$arr = $j->toArray();
				$arr['dependsOn'] = array_keys($j->getDependsOn());
				$arr['dependants'] = array_keys($j->getDependants());
				$this->getContextKey('response')->data($arr);
			} else {
				$this->getContextKey('response')->sendError('Error loading job.');
			}
			return TRUE;
		}
	});

	$router->get('/system/jobs/([0-9]+)/repeat', new class extends SystemJobsMgmt {
		function run($job) {
			$j = Job::load($this->getContextKey('db'), $job);

			if ($j !== false) {
				$job = JobQueue::get()->create($j->getName(), $j->getJobData());
				JobQueue::get()->publish($job);

				$this->getContextKey('response')->data(['jobid' => $job->getID(), 'status' => 'Repeat job scheduled.']);
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
