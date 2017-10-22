<?php

	class GearmanTaskServer {
		private $gearman;
		private $terminate = false;

		public function __construct($server, $port) {
			if ($port == -1) { $port = 4730; }

			$this->gearman = new GearmanWorker();
			$this->gearman->addServer($server, $port);
		}

		public function addTaskWorker($function, $worker) {
			$this->gearman->addFunction($function, function($job) use ($worker) {
				sendReply('JOB', $job->handle());

				$jobinfo = new JobInfo($job->handle(), $job->workload());
				// try {
					$worker->run($jobinfo);
				// } catch (Exception $ex) {
				//	$jobinfo->setError('Uncaught Exception: ' . $ex->getMessage());
				// }

				if ($jobinfo->hasError()) {
					throw new Exception('There was an error: ' . $jobinfo->getError());
				} else {
					if ($jobinfo->hasResult()) {
						return $jobinfo->getResult();
					} else {
						throw new Exception('There was no result.');
					}
				}
			});
		}

		public function stop() {
			$this->terminate = true;
		}

		public function run() {
			$gm = $this->gearman;
			$gm->addOptions(GEARMAN_WORKER_NON_BLOCKING);
			$gm->setTimeout(1000);

			# Try to grab a job
			while (!$this->terminate && (@$gm->work() || $gm->returnCode() == GEARMAN_IO_WAIT || $gm->returnCode() == GEARMAN_NO_JOBS || $gm->returnCode() == GEARMAN_TIMEOUT)) {
				if ($gm->returnCode() == GEARMAN_SUCCESS) {
					continue;
				}

				if (!@$gm->wait()) {
					if ($gm->returnCode() == GEARMAN_NO_ACTIVE_FDS) {
						sleep(5);
						continue;
					} else if ($gm->returnCode() == GEARMAN_TIMEOUT) {
						continue;
					}
					break;
				}
			}
		}
	}
