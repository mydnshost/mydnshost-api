<?php

	use PhpAmqpLib\Connection\AMQPStreamConnection;
	use PhpAmqpLib\Message\AMQPMessage;
  	use shanemcc\phpdb\DB;

	class JobQueue {
		// Instance of JobQueue.
		private static $instance = null;

		private $callbackQueue = null;
		private $callbackQueueResponses = [];

		/**
		 * Get the JobQueue instance.
		 *
		 * @return JobQueue instance.
		 */
		public static function get() {
			if (self::$instance == null) {
				self::$instance = new JobQueue();
			}

			return self::$instance;
		}

		/**
		 * Create a job, this will not publish it.
		 *
		 * @param $job Job name
		 * @param $args Job Arguments
		 */
		public function create($jobname, $args) {
			$jobname = strtolower($jobname);

			$job = new Job(DB::get());
			$job->setName($jobname)->setJobData($args)->setCreated(time())->setState('created')->save();

			return $job;
		}

		/**
		 * Publish a job.
		 *
		 * @param $job Job to publish
		 */
		public function publish($job) {
			$jobname = strtolower($job->getName());

			RabbitMQ::get()->getChannel()->exchange_declare('jobs', 'direct', false, true, false);

			$msg = new AMQPMessage(json_encode(['job' => $jobname, 'args' => $job->getJobData(), 'jobid' => $job->getID()]), ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]);
			RabbitMQ::get()->getChannel()->basic_publish($msg, 'jobs', 'job.' . $jobname);


			return $job->getID();
		}


		/**
		 * Publish a job and wait for the result.
		 *
		 * @param $job Job to publish
		 * @return Output from job.
		 */
		public function publishAndWait($job) {
			$jobname = strtolower($job->getName());

			RabbitMQ::get()->getChannel()->exchange_declare('jobs', 'direct', false, true, false);

			if ($this->callbackQueue == null) {
				list($this->callbackQueue, ,) = RabbitMQ::get()->getChannel()->queue_declare("", false, false, true, false);

	        	RabbitMQ::get()->getChannel()->basic_consume($this->callbackQueue, '', false, true, false, false, function ($msg) {
	        		if ($msg->has('correlation_id') && array_key_exists($msg->get('correlation_id'), $this->callbackQueueResponses)) {
						$this->callbackQueueResponses[$msg->get('correlation_id')] = $msg->body === NULL ? '' : $msg->body;
					}
	        	});
			}

			$correlation_id = genUUID();
			$msg = new AMQPMessage(json_encode(['job' => $jobname, 'args' => $job->getJobData(), 'jobid' => $job->getID()]), ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT, 'reply_to' => $this->callbackQueue, 'correlation_id' => $correlation_id]);
			RabbitMQ::get()->getChannel()->basic_publish($msg, 'jobs', 'job.' . $jobname);

			// Wait for response.
			$this->callbackQueueResponses[$correlation_id] = NULL;
			while ($this->callbackQueueResponses[$correlation_id] === null) { RabbitMQ::get()->getChannel()->wait(); }
			$response = $this->callbackQueueResponses[$correlation_id];
			unset($this->callbackQueueResponses[$correlation_id]);

			return [$job->getID(), $response];
		}

		/**
		 * Publish a job and wait for the result.
		 *
		 * @param $job Job name
		 * @param $args Job Arguments
		 */
		public function replytoJob($req, $response) {
			if ($req->has('correlation_id')) {
				$msg = new AMQPMessage($response, array('correlation_id' => $req->get('correlation_id')));
				$req->delivery_info['channel']->basic_publish($msg, '', $req->get('reply_to'));
			}

			$req->delivery_info['channel']->basic_ack($req->delivery_info['delivery_tag']);
		}

		/**
		 * Allow consuming jobs from the bus.
		 *
		 * @param $jobname Job name key
		 * @param $function Function call when we get a job
		 */
		public function consumeJobs($jobname, $function) {
			$jobname = strtolower($jobname);

			RabbitMQ::get()->getChannel()->exchange_declare('jobs', 'direct', false, true, false);
			RabbitMQ::get()->getChannel()->queue_declare('job.' . $jobname, false, false, false, false);
			RabbitMQ::get()->getChannel()->queue_bind('job.' . $jobname, 'jobs', 'job.' . $jobname);
			RabbitMQ::get()->getChannel()->basic_qos(null, 1, null);

			RabbitMQ::get()->getChannel()->basic_consume('job.' . $jobname, '', false, false, false, false, $function);
		}
	}
