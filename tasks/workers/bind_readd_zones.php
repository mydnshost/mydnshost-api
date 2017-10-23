<?php
	use shanemcc\phpdb\DB;
	use shanemcc\phpdb\Search;
	require_once(dirname(__FILE__) . '/../classes/BindTaskWorker.php');

	/**
	 * Task to
	 */
	class bind_readd_zones extends BindTaskWorker {
		public function run($job) {
			$s = new Search(DB::get()->getPDO(), 'domains', ['domain', 'disabled']);
			$s->order('domain');
			$rows = $s->getRows();

			foreach ($rows as $row) {
				$change = strtolower($row['disabled']) == 'true' ? 'del' : 'readd';
				$newjob = new JobInfo('', 'bind_zone_changed', ['domain' => $row['domain'], 'change' => $change, 'noCatalog' => true]);
				$this->getTaskServer()->runBackgroundJob($newjob);
			}

			$job->setResult('OK');
		}
	}
