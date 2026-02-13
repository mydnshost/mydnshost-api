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

			// Build WHERE clause for both count and search queries.
			$where = [];
			$params = [];
			if (isset($filter['name']) && $filter['name'] !== '') {
				$where[] = '`name` = :name';
				$params[':name'] = $filter['name'];
			}
			if (isset($filter['state']) && $filter['state'] !== '') {
				$where[] = '`state` = :state';
				$params[':state'] = $filter['state'];
			}

			// Get total count for pagination.
			$countSql = 'SELECT COUNT(*) as total FROM `jobs`';
			if (!empty($where)) {
				$countSql .= ' WHERE ' . implode(' AND ', $where);
			}
			$countStmt = $db->getPDO()->prepare($countSql);
			$countStmt->execute($params);
			$total = intval($countStmt->fetch(PDO::FETCH_ASSOC)['total']);
			$totalPages = intval(max(1, ceil($total / $limit)));
			$page = min($page, $totalPages);
			$offset = ($page - 1) * $limit;

			$jobSearch = Job::getSearch($db);
			$jobSearch->order('id', 'desc');

			// Server-side filtering by name and state.
			if (isset($filter['name']) && $filter['name'] !== '') {
				$jobSearch->where('name', $filter['name']);
			}
			if (isset($filter['state']) && $filter['state'] !== '') {
				$jobSearch->where('state', $filter['state']);
			}

			// Note: Limit class generates LIMIT $1,$2 — MySQL interprets as LIMIT offset,count
			$jobSearch->limit($offset, $limit);

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
