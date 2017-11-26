<?php
	require_once(dirname(__FILE__) . '/../classes/BindTaskWorker.php');

	/**
	 * Task to remove a domain from bind.
	 *
	 * Payload should be a json string with 'domain' field.
	 */
	class bind_delete_domain extends BindTaskWorker {
		public function run($job) {
			$payload = $job->getPayload();

			if (isset($payload['domain'])) {
				$bind = new Bind($payload['domain'], $this->bindConfig['zonedir']);
				list($filename, $filename2) = $bind->getFileNames();
				foreach ([$filename, $filename . '.jbk', $filename . '.signed', $filename . '.signed.jnl'] as $f) {
					if (file_exists($f)) { @unlink($f); }
				}
				$this->getTaskServer()->runBackgroundJob(new JobInfo('', 'bind_zone_changed', ['domain' => $payload['domain'], 'change' => 'remove', 'filename' => $filename]));
				$job->setResult('OK');
			} else {
				$job->setError('Missing fields in payload.');
			}
		}
	}
