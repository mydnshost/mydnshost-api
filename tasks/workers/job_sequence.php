<?php
	/**
	 * Task to run multiple jobs in sequence, one after another.
	 *
	 * Payload is a json string with a 'jobs' array where each item has "job"
	 * and "args" arrays.
	 *
	 * "job" will be used as a job to run, and "args" will be passed in as the
	 * payload.
	 */
	class job_sequence extends TaskWorker {
		public function run($job) {
			$payload = $job->getPayload();

			if (isset($payload['jobs'])) {
				foreach ($payload['jobs'] as $newJob) {
					if (isset($newJob['wait'])) { @time_sleep_until(time() + $newJob['wait']); }
					if (!isset($newJob['job']) || !isset($newJob['args'])) { continue; }

					$newjob = new JobInfo('', $newJob['job'], $newJob['args']);
					$this->getTaskServer()->runJob($newjob);
				}

				$job->setResult('OK');
			} else {
				$job->setError('Missing fields in payload.');
			}
		}
	}
