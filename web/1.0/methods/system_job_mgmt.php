<?php

	class SystemJobsMgmt extends RouterMethod {
		public function check() {
			$user = $this->getContextKey('user');
			if ($user == NULL) {
				throw new RouterMethod_NeedsAuthentication();
			}

			$this->checkPermissions(['system_job_mgmt']);

			if ($this->hasContextKey('key') && !$this->getContextKey('key')->getAdminFeatures()) {
				throw new RouterMethod_AccessDenied();
			}
		}
	}

	$router->get('/system/jobs/list', new class extends SystemJobsMgmt {
		function run() {
			$limit = 50;
			$page = isset($_REQUEST['page']) ? max(1, intval($_REQUEST['page'])) : 1;

			$db = $this->getContextKey('db');
			$filter = isset($_REQUEST['filter']) ? $_REQUEST['filter'] : [];

			$jobSearch = Job::getSearch($db);
			$jobSearch->order('id', 'desc');

			// Server-side filtering by name, state, and JSON payload.
			if (isset($filter['name']) && $filter['name'] !== '') {
				$jobSearch->where('name', $filter['name']);
			}
			if (isset($filter['state']) && $filter['state'] !== '') {
				$jobSearch->where('state', $filter['state']);
			}
			if (isset($filter['data']) && is_array($filter['data'])) {
				foreach ($filter['data'] as $key => $value) {
					$safePath = preg_replace('/[^a-zA-Z0-9_-]/', '', $key);
					if ($safePath === '' || $value === '') { continue; }
					$jobSearch->whereJson('data', $safePath, $value);
				}
			}

			// Get total count for pagination (before applying limit).
			$total = $jobSearch->count();
			$totalPages = intval(max(1, ceil($total / $limit)));
			$page = min($page, $totalPages);
			$offset = ($page - 1) * $limit;

			// Note: Limit class generates LIMIT $1,$2 — MySQL interprets as LIMIT offset,count
			$jobSearch->limit($offset, $limit);

			$rows = [];
			foreach ($jobSearch->search([]) as $j) {
				$arr = $j->toArray();
				$arr['dependsOn'] = array_keys($j->getDependsOn());
				$arr['dependants'] = array_keys($j->getDependants());
				$rows[] = $arr;
			}

			$this->getContextKey('response')->data(['jobs' => $rows, 'pagination' => ['page' => $page, 'totalPages' => $totalPages, 'total' => $total]]);

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
				$arr['logs'] = $j->getLogs();
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

	$router->post('/system/jobs/create', new class extends SystemJobsMgmt {
		function run() {
			$data = $this->getContextKey('data');

			if (!isset($data['data']['name']) || empty(trim($data['data']['name']))) {
				$this->getContextKey('response')->sendError('Job name is required.');
				return TRUE;
			}

			if (!isset($data['data']['data'])) {
				$this->getContextKey('response')->sendError('Job payload is required.');
				return TRUE;
			}

			$name = trim($data['data']['name']);
			$jobData = $data['data']['data'];

			if (is_string($jobData)) {
				$decoded = json_decode($jobData, true);
				if ($decoded === null && $jobData !== 'null') {
					$this->getContextKey('response')->sendError('Job payload must be valid JSON.');
					return TRUE;
				}
				$jobData = $decoded;
			}

			if (!is_array($jobData)) {
				$jobData = [];
			}

			$job = JobQueue::get()->create($name, $jobData);

			// If a dependency is specified, set the job as blocked instead of publishing it.
			$dependsOn = isset($data['data']['dependsOn']) ? intval($data['data']['dependsOn']) : 0;
			if ($dependsOn > 0) {
				$parent = Job::load($this->getContextKey('db'), $dependsOn);
				if ($parent === false) {
					$this->getContextKey('response')->sendError('Depends-on job ID ' . $dependsOn . ' not found.');
					return TRUE;
				}
				$job->addDependency($dependsOn)->setState('blocked')->save();
			} else {
				JobQueue::get()->publish($job);
			}

			$this->getContextKey('response')->data(['jobid' => $job->getID(), 'status' => $dependsOn > 0 ? 'Job created (blocked, waiting on job ' . $dependsOn . ').' : 'Job scheduled.']);
			return TRUE;
		}
	});

	$router->get('/system/jobs/([0-9]+)/cancel', new class extends SystemJobsMgmt {
		function run($job) {
			$j = Job::load($this->getContextKey('db'), $job);

			if ($j === false) {
				$this->getContextKey('response')->sendError('Error loading job.');
				return TRUE;
			}

			if (!in_array($j->getState(), ['created', 'blocked'])) {
				$this->getContextKey('response')->sendError('Only jobs in created or blocked state can be cancelled.');
				return TRUE;
			}

			$cancelled = $this->cancelRecursive($j);
			$this->getContextKey('response')->data(['status' => $cancelled . ' job' . ($cancelled != 1 ? 's' : '') . ' cancelled.']);
			return TRUE;
		}

		private function cancelRecursive($job) {
			$job->setState('cancelled')->save();
			$count = 1;

			foreach ($job->getDependants() as $child) {
				if (in_array($child->getState(), ['created', 'blocked'])) {
					$count += $this->cancelRecursive($child);
				}
			}

			return $count;
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
