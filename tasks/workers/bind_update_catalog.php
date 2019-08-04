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
			$this->sleepForZoneFile($this->bindConfig['catalogZoneFile']);
		}

		protected function updateCatalogZone($domainraw, $mode = 'remove', $refresh = true) {
			if (empty($this->bindConfig['catalogZoneName']) || empty($this->bindConfig['catalogZoneFile'])) {
				return;
			}

			// Create the catalog if needed.
			if (!file_exists($this->bindConfig['catalogZoneFile'])) {
				$this->getTaskServer()->runJob(new JobInfo('', 'bind_create_catalog'));
			}

			// Now update.
			if (TaskWorker::acquireLock('zone_' . $this->bindConfig['catalogZoneName'])) {
				$bind = new Bind($this->bindConfig['catalogZoneName'], '', $this->bindConfig['catalogZoneFile']);

				$bind->parseZoneFile();
				$zoneHash = $bind->getZoneHash();
				$hash = sha1("\7" . str_replace(".", "\3", $domainraw) . "\0");

				$oldAllowTransfer = $bind->getRecords('allow-transfer.' . $hash . '.zones', 'APL');
				if (!empty($oldAllowTransfer)) { $oldAllowTransfer = $oldAllowTransfer[0]; }

				$bind->unsetRecord($hash . '.zones', 'PTR');
				$bind->unsetRecord('allow-transfer.' . $hash . '.zones', 'APL');
				if ($mode != 'remove') {
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
							TaskWorker::releaseLock('zone_' . $this->bindConfig['catalogZoneName']);
							return;
						}
					}
				}

				// If the zone changed, save it and schedule a refresh.
				if ($zoneHash != $bind->getZoneHash()) {
					$bindSOA = $bind->getSOA();
					$bindSOA['Serial']++;
					$bind->setSOA($bindSOA);

					if ($refresh) { $this->bind_sleepForCatalog(); }
					$bind->saveZoneFile($this->bindConfig['catalogZoneFile']);

					if ($refresh) {
						$jobArgs = ['domain' => $this->bindConfig['catalogZoneName'], 'change' => 'change', 'noCatalog' => true, 'filename' => $this->bindConfig['catalogZoneFile']];
						$this->getTaskServer()->runBackgroundJob(new JobInfo('', 'bind_zone_changed', $jobArgs));
					}
				}

				TaskWorker::releaseLock('zone_' . $this->bindConfig['catalogZoneName']);
			}
		}
	}
