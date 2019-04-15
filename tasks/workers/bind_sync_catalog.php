<?php
	use shanemcc\phpdb\DB;

	require_once(dirname(__FILE__) . '/bind_update_catalog.php');

	/**
	 * Task to sync the catalog.
	 *
	 * This is similar to bind_rebuild_catalog but goes about it a different
	 * way.
	 */
	class bind_sync_catalog extends bind_update_catalog {
		public function run($job) {

			foreach (Domain::getSearch(DB::get())->order('domain')->getRows() as $d) {
				$change = (parseBool($d['disabled']) ? 'remove' : 'add');
				echo $json, ' => ', $change, "\n";
				$this->updateCatalogZone($d["domain"], $change, false);
			}

			$fp = fopen($this->bindConfig['catalogZoneFile'] . '.lock', 'r+');
			if (flock($fp, LOCK_EX)) {
				// Touch the catalog file to bump the time if needed.
				$this->bind_sleepForCatalog();
				touch($this->bindConfig['catalogZoneFile']);

				// Schedule the refresh.
				$jobArgs = ['domain' => $this->bindConfig['catalogZoneName'], 'change' => 'change', 'noCatalog' => true, 'filename' => $this->bindConfig['catalogZoneFile']];
				$this->getTaskServer()->runBackgroundJob(new JobInfo('', 'bind_zone_changed', $jobArgs));

				flock($fp, LOCK_UN);
				fclose($fp);
			}

			$job->setResult('OK');
		}
	}
