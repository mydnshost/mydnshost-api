<?php
	use shanemcc\phpdb\DB;
	require_once(dirname(__FILE__) . '/../classes/BindTaskWorker.php');

	/**
	 * Task to action a change to a zone file.
	 *
	 * Payload should be a json string with fields: 'domain', 'change'
	 */
	class bind_zone_changed extends BindTaskWorker {
		public function run($job) {
			$payload = $job->getPayload();

			if (isset($payload['domain']) && isset($payload['change'])) {

				// Get filename unless it was specified for us.
				if (isset($payload['filename'])) {
					$filename = $payload['filename'];
				} else {
					$bind = new Bind($payload['domain'], $this->bindConfig['zonedir']);
					list($filename, $filename2) = $bind->getFileNames();
				}

				// Remove a domain (or delete as part of readd)
				if ($payload['change'] == 'remove' || $payload['change'] == 'readd') {
					$this->runCommand($this->bindConfig['delZoneCommand'], $payload['domain'], $filename);
				}

				// Add a domain (Standalone or as part of readd)
				if ($payload['change'] == 'add' || $payload['change'] == 'readd') {
					$domain = Domain::loadFromDomain(DB::get(), $payload['domain']);
					if ($domain !== FALSE) {
						$this->runCommand($this->bindConfig['addZoneCommand'], $domain, $filename);
					}
				}

				// Reload a domain.
				if ($payload['change'] == 'change') {
					$domain = Domain::loadFromDomain(DB::get(), $payload['domain']);
					if ($domain !== FALSE) {
						$this->runCommand($this->bindConfig['reloadZoneCommand'], $domain, $filename);
					}
				}

				// Update the catalog zone unless noCatalog is passed.
				// (This will be passed when we are being called because of the catalog zone being updated.)
				if (!isset($payload['noCatalog'])) {
					$newjob = new JobInfo('', 'bind_update_catalog', $job->getPayload());
					$this->getTaskServer()->runBackgroundJob($newjob);
				}

				$job->setResult('OK');
			} else {
				$job->setError('Missing fields in payload.');
			}
		}
	}
