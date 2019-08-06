<?php

	abstract class TaskWorker {
		private $taskServer;

		public function __construct($taskServer) {
			$this->taskServer = $taskServer;
		}

		public function getTaskServer() {
			return $this->taskServer;
		}

		abstract public function run($job);
	}
