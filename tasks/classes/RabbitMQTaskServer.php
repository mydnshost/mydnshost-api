<?php
	use shanemcc\phpdb\DB;

	class RabbitMQTaskServer {
		public function __construct() { }

		public function runBackgroundJob($jobinfo) {
			echo 'Scheduling background job: ', $jobinfo->getFunction(), ' => ', json_encode($jobinfo->getPayload()), "\n";
			$jobID = JobQueue::get()->publish($jobinfo->getFunction(), $jobinfo->getPayload());
			echo 'Scheduled as: ', $jobID, "\n";
		}

		public function runJob($jobinfo) {
			echo 'Running foreground job: ', $jobinfo->getFunction(), ' => ', json_encode($jobinfo->getPayload()), "\n";

			[$jobID, $result] = JobQueue::get()->publishAndWait($jobinfo->getFunction(), $jobinfo->getPayload());
			echo 'Scheduled and finished as: ', $jobID, "\n";
			return $result;
		}

		public function addTaskWorker($function, $worker) {
			JobQueue::get()->consumeJobs($function, function ($msg) use ($function, $worker) {
				$msgInfo = json_decode($msg->body, true);

				EventQueue::get()->publish('job.started', [$msgInfo['jobid']]);

				sendReply('JOB', $msgInfo['jobid']);
				sendReply('FUNCTION', $msgInfo['job']);
				sendReply('PAYLOAD', json_encode($msgInfo['args']));

				try {
					checkDBAlive();
				} catch (Exception $ex) {
					sendReply('EXCEPTION', 'Unable to connect to database, requeued job: ' . $ex->getMessage());

					$msg->delivery_info['channel']->basic_reject($msg->delivery_info['delivery_tag']);
					throw $ex;
				}

				$job = Job::load(DB::get(), $msgInfo['jobid']);

				$payload = $msgInfo['args'];
				if (empty($payload)) {
					$payload = [];
				} else if (!is_array($payload)) {
					sendReply('EXCEPTION', 'Invalid Payload.');

					$resultMsg = 'EXCEPTION';
					$job->setState('finished')->setFinished(time())->setResult($resultMsg)->save();
					EventQueue::get()->publish('job.finished', [$msgInfo['jobid'], $resultMsg]);
					JobQueue::get()->replyToJob($msg, $resultMsg);

					throw new Exception('Invalid Payload.');
				}

				$jobinfo = new JobInfo($msgInfo['jobid'], $msgInfo['job'], $payload);

				$job->setState('started')->setStarted(time())->save();

				try {
					$worker->run($jobinfo);
				} catch (Throwable $ex) {
					sendReply('EXCEPTION', 'Uncaught Exception: ' . $ex->getMessage());

					// $msg->delivery_info['channel']->basic_reject($msg->delivery_info['delivery_tag'], true);

					$resultMsg = 'EXCEPTION';
					$job->setState('finished')->setFinished(time())->setResult($resultMsg)->save();
					EventQueue::get()->publish('job.finished', [$msgInfo['jobid'], $resultMsg]);
					JobQueue::get()->replyToJob($msg, $resultMsg);

					sendReply('ERR', 'Exception: ' . $ex->getMessage() . "\n" . $ex->getTraceAsString());
					return FALSE;
				}

				$resultMsg = null;

				if ($jobinfo->hasError()) {
					$resultMsg = 'ERRROR';
					sendReply('EXCEPTION', 'There was an error: ' . $jobinfo->getError());
				} else {
					if ($jobinfo->hasResult()) {
						$resultMsg = $jobinfo->getResult();
						sendReply('RESULT', $resultMsg);
					} else {
						$resultMsg = 'NO RESULT';
						sendReply('EXCEPTION', 'There was no result.');
					}
				}

				if ($resultMsg !== null) {
					$job->setState('finished')->setFinished(time())->setResult($resultMsg)->save();
					EventQueue::get()->publish('job.finished', [$msgInfo['jobid'], $resultMsg]);
					JobQueue::get()->replyToJob($msg, $resultMsg);

					return true;
				}
			});
		}

		public function stop() {
			RabbitMQ::get()->stopConsume();
		}

		public function run() {
			RabbitMQ::get()->consume();
		}
	}
