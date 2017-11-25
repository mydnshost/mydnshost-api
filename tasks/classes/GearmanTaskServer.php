<?php

	class GearmanTaskServer {
		private $gearman;
		private $server;

		private $terminate = false;

		public function __construct($server, $port) {
			if ($port == -1) { $port = 4730; }

			$this->gearman = new GearmanWorker();
			$this->gearman->addServer($server, $port);

			$this->server = new GearmanClient();
			$this->server->addServer($server, $port);
			$this->server->setTimeout(5000);
		}

		public function runBackgroundJob($jobinfo) {
			return $this->server->doBackground($jobinfo->getFunction(), json_encode($jobinfo->getPayload()));
		}

		public function runJob($jobinfo) {
			return $this->server->doNormal($jobinfo->getFunction(), json_encode($jobinfo->getPayload()));
		}

		public function addTaskWorker($function, $worker) {
			$this->gearman->addFunction($function, function($job) use ($function, $worker) {
				sendReply('JOB', $job->handle());
				sendReply('FUNCTION', $function);
				sendReply('PAYLOAD', $job->workload());

				$payload = trim($job->workload());
				if (empty($payload)) {
					$payload = [];
				} else {
					$payload = @json_decode($job->workload(), true);
					if (empty($payload)) {
						$payload = [];
					} else {
						if (!is_array($payload)) {
							sendReply('EXCEPTION', 'Invalid Payload.');
							throw new Exception('Invalid Payload.');
						}
					}
				}

				$jobinfo = new JobInfo($job->handle(), $function, $payload);

				try {
					checkDBAlive();
				} catch (Exception $ex) {
					sendReply('EXCEPTION', 'Unable to connect to database, requeued job: ' . $ex->getMessage());
					$this->runBackgroundJob($jobinfo);
					throw $ex;
				}

				try {
					$worker->run($jobinfo);
				} catch (Throwable $ex) {
					sendReply('EXCEPTION', 'Uncaught Exception: ' . $ex->getMessage());
					throw $ex;
				}

				if ($jobinfo->hasError()) {
					sendReply('EXCEPTION', 'There was an error: ' . $jobinfo->getError());
					throw new Exception('There was an error: ' . $jobinfo->getError());
				} else {
					if ($jobinfo->hasResult()) {
						sendReply('RESULT', $jobinfo->getResult());
						return $jobinfo->getResult();
					} else {
						sendReply('EXCEPTION', 'There was no result.');
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
