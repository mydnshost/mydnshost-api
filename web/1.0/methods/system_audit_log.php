<?php

	class SystemAuditLog extends RouterMethod {
		public function check() {
			$user = $this->getContextKey('user');
			if ($user == NULL) {
				throw new RouterMethod_NeedsAuthentication();
			}

			$this->checkPermissions(['system_audit_log']);

			if ($this->hasContextKey('key') && !$this->getContextKey('key')->getAdminFeatures()) {
				throw new RouterMethod_AccessDenied();
			}
		}
	}

	$router->get('/system/audit/list', new class extends SystemAuditLog {
		function run() {
			$limit = 50;
			$page = isset($_REQUEST['page']) ? max(1, intval($_REQUEST['page'])) : 1;

			$db = $this->getContextKey('db');
			$filter = isset($_REQUEST['filter']) ? $_REQUEST['filter'] : [];

			$search = AuditEntry::getSearch($db);
			$search->order('id', 'desc');

			if (isset($filter['type']) && $filter['type'] !== '') {
				$search->where('type', $filter['type']);
			}
			if (isset($filter['actor']) && $filter['actor'] !== '') {
				$search->where('actor', '%' . $filter['actor'] . '%', 'LIKE');
			}
			if (isset($filter['search']) && $filter['search'] !== '') {
				$search->whereOr([
					['summary', '%' . $filter['search'] . '%', 'LIKE'],
					['extendedsummary', '%' . $filter['search'] . '%', 'LIKE'],
				]);
			}

			$total = $search->count();
			$totalPages = intval(max(1, ceil($total / $limit)));
			$page = min($page, $totalPages);
			$offset = ($page - 1) * $limit;

			$search->limit($offset, $limit);

			$rows = [];
			foreach ($search->search([]) ?: [] as $entry) {
				$rows[] = $entry->toArray();
			}

			$this->getContextKey('response')->data(['entries' => $rows, 'pagination' => ['page' => $page, 'totalPages' => $totalPages, 'total' => $total]]);

			return TRUE;
		}
	});

	$router->get('/system/audit/([0-9]+)', new class extends SystemAuditLog {
		function run($id) {
			$entry = AuditEntry::load($this->getContextKey('db'), $id);

			if ($entry !== false) {
				$this->getContextKey('response')->data($entry->toArray());
			} else {
				$this->getContextKey('response')->sendError('Error loading audit entry.');
			}
			return TRUE;
		}
	});
