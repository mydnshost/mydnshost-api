<?php
	use shanemcc\phpdb\DB;
	use shanemcc\phpdb\Search;
	require_once(dirname(__FILE__) . '/../classes/BindTaskWorker.php');

	/**
	 * Task to rebuild all zones.
	 */
	class bind_rebuild_zones extends BindTaskWorker {
		public function run($job) {
			$s = new Search(DB::get()->getPDO(), 'domains', ['domain', 'disabled']);
			$s->order('domain');
			$rows = $s->getRows();

			foreach ($rows as $row) {
				$newjob = new JobInfo('', 'bind_add_domain', ['domain' => $row['domain']]);
				$this->getTaskServer()->runBackgroundJob($newjob);
			}

			$job->setResult('OK');
		}
	}
