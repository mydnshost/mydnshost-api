<?php
	use shanemcc\phpdb\DB;
	use shanemcc\phpdb\Search;

	require_once(dirname(__FILE__) . '/bind_update_catalog.php');

	/**
	 * Task to rebuild the entire catalog
	 */
	class bind_rebuild_catalog extends bind_update_catalog {
		public function run($job) {
			$zoneName = $this->bindConfig['catalogZoneName'];
			$zoneFile = $this->bindConfig['catalogZoneFile'];

			if (RedisLock::acquireLock('zone_' . $zoneName)) {
				$bind = new Bind($zoneName, '', $zoneFile);
				$bind->parseZoneFile();
				$bindSOA = $bind->getSOA();
				$bindSOA['Serial']++;
				$bind->clearRecords();
				$bind->setSOA($bindSOA);

				$bind->setRecord('@', 'NS', 'invalid.', '3600', '');
				$bind->setRecord('version', 'TXT', '1', '3600', '');

				$s = new Search(DB::get()->getPDO(), 'domains', ['domain', 'disabled']);
				$s->order('domain');
				$rows = $s->getRows();

				foreach ($rows as $row) {
					if (strtolower($row['disabled']) == 'true') { continue; }

					$domain = Domain::loadFromDomain(DB::get(), $row['domain']);

					if ($domain != FALSE) {
						foreach ($domain->getRecords() as $record) {
							if ($record->isDisabled()) { continue; }
							if ($record->getType() == "NS" && $record->getName() == $domain->getDomain()) {
								$this->addCatalogRecords($bind, $domain->getDomainRaw());
								break;
							}
						}
					}
				}

				$this->bind_sleepForCatalog();
				$bind->saveZoneFile($zoneFile);
				chmod($zoneFile, 0777);

				$jobArgs = ['domain' => $zoneName, 'change' => 'change', 'noCatalog' => true, 'filename' => $zoneFile];
				$this->getTaskServer()->runBackgroundJob(new JobInfo('', 'bind_zone_changed', $jobArgs));

				RedisLock::releaseLock('zone_' . $zoneName);
			}

			$job->setResult('OK');
		}
	}
