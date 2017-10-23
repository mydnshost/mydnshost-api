<?php
	require_once(dirname(__FILE__) . '/../classes/BindTaskWorker.php');

	/**
	 * Task to action an update to the catalog zone.
	 *
	 * Payload should be a json string with fields: 'domain', 'change'
	 */
	class bind_update_catalog extends BindTaskWorker {
		public function run($job) {
			$payload = $job->getPayload();

			if (isset($payload['domain']) && isset($payload['change'])) {

				$this->updateCatalogZone($payload['domain'], $payload['change']);

				$job->setResult('OK');
			} else {
				$job->setError('Missing fields in payload.');
			}
		}

		protected function addCatalogRecords($bind, $domainraw) {
			$hash = sha1("\7" . str_replace(".", "\3", $domainraw) . "\0");

			$bind->setRecord($hash . '.zones', 'PTR', $domainraw . '.');

			// Convert NS Records to IPs
			$ips = $this->getAllowedIPs($domainraw, true);

			// Save IPs
			if (!empty($ips)) {
				$bind->setRecord('allow-transfer.' . $hash . '.zones', 'APL', implode(' ', $ips));
			}
		}

		protected function bind_sleepForCatalog() {
			global $__BIND__CATALOG_TIME;

			if (isset($__BIND__CATALOG_TIME)) {
				// Make sure there is at least 1 second between subsequent
				// writes to the catalog.
				$now = time();
				if ($__BIND__CATALOG_TIME >= $now) { @time_sleep_until($now + 1); }
			}
			$__BIND__CATALOG_TIME = time();
		}

		protected function updateCatalogZone($domainraw, $mode = 'remove') {
			if (empty($this->bindConfig['catalogZoneName']) || empty($this->bindConfig['catalogZoneFile'])) {
				return;
			}

			// Create the catalog if needed.
			if (!file_exists($this->bindConfig['catalogZoneFile'])) {
				$this->getTaskServer()->runJob(new JobInfo('', 'bind_create_catalog'));
			}

			// Now update.
			$fp = fopen($this->bindConfig['catalogZoneFile'] . '.lock', 'r+');
			if (flock($fp, LOCK_EX)) {
				$bind = new Bind($this->bindConfig['catalogZoneName'], '', $this->bindConfig['catalogZoneFile']);

				$bind->parseZoneFile();
				$bindSOA = $bind->getSOA();
				$bindSOA['Serial']++;
				$bind->setSOA($bindSOA);

				$hash = sha1("\7" . str_replace(".", "\3", $domainraw) . "\0");

				$oldAllowTransfer = $bind->getRecords('allow-transfer.' . $hash . '.zones', 'APL');
				if (!empty($oldAllowTransfer)) { $oldAllowTransfer = $oldAllowTransfer[0]; }

				$bind->unsetRecord($hash . '.zones', 'PTR');
				$bind->unsetRecord('allow-transfer.' . $hash . '.zones', 'APL');
				if ($mode == 'add' || $mode == 'change') {
					$this->addCatalogRecords($bind, $domainraw);
				}

				if ($mode == 'change') {
					if (!empty($oldAllowTransfer)) {
						$newAllowTransfer = $bind->getRecords('allow-transfer.' . $hash . '.zones', 'APL');
						if (!empty($newAllowTransfer) && $newAllowTransfer[0] != $oldAllowTransfer) {
							// Allowed-Transfer list has changed, re-add domain to bind
							$newjob = new JobInfo('', 'bind_zone_changed', ['domain' => $domainraw, 'change' => 'readd', 'noCatalog' => true]);
							$this->getTaskServer()->runBackgroundJob($newjob);
						} else {
							// Transfer list has not changed, abort.
							flock($fp, LOCK_UN);
							fclose($fp);
							return;
						}
					}
				}

				$this->bind_sleepForCatalog();
				$bind->saveZoneFile($this->bindConfig['catalogZoneFile']);

				$jobArgs = ['domain' => $this->bindConfig['catalogZoneName'], 'change' => 'change', 'noCatalog' => true, 'filename' => $this->bindConfig['catalogZoneFile']];
				$this->getTaskServer()->runBackgroundJob(new JobInfo('', 'bind_zone_changed', $jobArgs));

				flock($fp, LOCK_UN);
				fclose($fp);
			}
		}
	}
