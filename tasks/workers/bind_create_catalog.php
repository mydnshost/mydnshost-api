<?php
	require_once(dirname(__FILE__) . '/bind_update_catalog.php');

	/**
	 * Task to
	 */
	class bind_create_catalog extends bind_update_catalog {
		public function run($job) {
			file_put_contents($this->bindConfig['catalogZoneFile'], '');
			$bind = new Bind($this->bindConfig['catalogZoneName'], '', $this->bindConfig['catalogZoneFile']);
			$bind->clearRecords();

			$bindSOA = array('Nameserver' => '.',
			                 'Email' => '.',
			                 'Serial' => '0',
			                 'Refresh' => '86400',
			                 'Retry' => '3600',
			                 'Expire' => '86400',
			                 'MinTTL' => '3600');
			$bind->setSOA($bindSOA);
			$bind->setRecord('@', 'NS', 'invalid.', '3600', '');
			$bind->setRecord('version', 'TXT', '1', '3600', '');

			$this->bind_sleepForCatalog();
			$bind->saveZoneFile($this->bindConfig['catalogZoneFile']);
			chmod($this->bindConfig['catalogZoneFile'], 0777);

			file_put_contents($this->bindConfig['catalogZoneFile'] . '.lock', '');
			chmod($this->bindConfig['catalogZoneFile'] . '.lock', 0777);

			$this->getTaskServer()->runJob(new JobInfo('', 'bind_rebuild_catalog'));
			$job->setResult('OK');
		}
	}
