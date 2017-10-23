<?php

	abstract class TaskServer {
		abstract function __construct();
		abstract function runBackgroundJob($jobinfo);
		abstract function runJob($jobinfo);
		abstract function addTaskWorker($function, $worker);
		abstract function run();
		abstract function stop();
	}
