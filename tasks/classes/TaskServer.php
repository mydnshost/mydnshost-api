<?php

	abstract class TaskServer {
		abstract function __construct();
		abstract function addTaskWorker($function, $worker);
		abstract function run();
		abstract function stop();
	}
