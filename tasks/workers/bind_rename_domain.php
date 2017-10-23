<?php
	use shanemcc\phpdb\DB;
	require_once(dirname(__FILE__) . '/../classes/BindTaskWorker.php');

	/**
	 * Task to rename a domain.
	 *
	 * Payload should be a json string with 'domain' and 'oldName' fields.
	 */
	class bind_rename_domain extends BindTaskWorker {
		public function run($job) {
			$payload = $job->getPayload();

			if (isset($payload['domain']) && isset($payload['oldName'])) {
				$bind = new Bind($payload['oldName'], $this->bindConfig['zonedir']);
				list($filename, $filename2) = $bind->getFileNames();
				if (file_exists($filename)) {
					@unlink($filename);
				}

				$newjob = new JobInfo('', 'bind_zone_changed', ['domain' => $payload['oldName'], 'filename' => $filename, 'change' => 'remove']);
				$this->getTaskServer()->runBackgroundJob($newjob);

				$domain = Domain::loadFromDomain(DB::get(), $payload['domain']);
				if ($domain !== FALSE) {
					$this->writeZoneFile($domain);
					$job->setResult('OK');
				} else {
					$job->setError('Unable to find renamed domain: ' . $payload['domain']);
				}
			} else {
				$job->setError('Missing fields in payload.');
			}
		}
	}
