#!/usr/bin/env php
<?php
	require_once(dirname(__FILE__) . '/../functions.php');
	require_once(dirname(__FILE__) . '/classes/TaskWorker.php');
	require_once(dirname(__FILE__) . '/classes/JobInfo.php');
	require_once(dirname(__FILE__) . '/classes/TaskServer.php');
	require_once(dirname(__FILE__) . '/classes/GearmanTaskServer.php');

	$functions = [];
	$type = '';
	$server = '127.0.0.1';
	$port = -1;

	function sendReply($type, ...$message) {
		echo $type, ' ', implode('', $message), "\n";

		if ($type == 'ERR') { exit(1); }
	}

	// Wait for commands.
	while (true) {
		$line = trim(fgets(STDIN));

		$bits = explode(" ", $line, 2);

		$cmd = $bits[0];
		$args = isset($bits[1]) ? $bits[1] : '';

		if ($cmd == 'addFunction') {
			$args = explode(' ', $args);
			$func = preg_replace('#[^a-z0-9_-]#i', '', $args[0]);
			$funcFileName = isset($args[1]) ? preg_replace('#[^a-z0-9_-]#i', '', $args[1]) : $func;
			$functions[] = ['name' => $func, 'file' => $funcFileName];

			sendReply('OK', 'Worker for function: ', $func, ' (', $funcFileName, ')');
		} else if ($cmd == 'setRedisHost') {
			$args = explode(" ", $args);

			$host = $args[0];

			sendReply('OK', 'Set redis host: ', $host);
			TaskWorker::setRedisHost($host);
		} else if ($cmd == 'setTaskServer') {
			$args = explode(" ", $args);

			$type = $args[0];
			if (isset($args[1]) && !empty($args[1])) { $server = $args[1]; }
			if (isset($args[2]) && !empty($args[2])) { $port = $args[2]; }

			sendReply('OK', 'Set task server type: ', $type, ' (', $server, ':', $port, ')');
		} else if ($cmd == 'run') {
			sendReply('OK', 'Running');
			break;
		} else {
			sendReply('ERR', 'Unknown Command: ', $cmd);
		}
	}

	// Load TaskServer
	if ($type == 'gearman') {
		$taskServer = new GearmanTaskServer($server, $port);
	} else {
		sendReply('ERR', 'Invalid TaskServer type: ', $type);
		exit(2);
	}

	// Load TaskWorkers
	$workers = [];
	foreach ($functions as $function) {
		$workerFile = __DIR__ . '/workers/' . $function['file'] . '.php';

		if (!file_exists($workerFile)) {
			sendReply('ERR', 'Invalid Worker Function: ', $function['name']);
			exit(2);
		}

		require_once($workerFile);
		$workers[$function['name']] = new $function['file']($taskServer);
	}

	// Add all the workers into the task server.
	foreach ($workers as $function => $worker) {
		$taskServer->addTaskWorker($function, $worker);
	}

	// Setup the signal handlers etc.
	$signalFunc = function() use ($taskServer) { $taskServer->stop(); };
	$shutdownFunc = function() use ($signalFunc) {
		call_user_func($signalFunc);
		$lasterr =  error_get_last();
		if ($lasterr !== null && in_array($lasterr['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR])) {
			fwrite(STDERR, 'Last Error: ' . $lasterr['message'] . "\n");
			fwrite(STDERR, "\t" . 'In: ' . $lasterr['file'] . ' (Line: ' . $lasterr['line'] . ')' . "\n");
		}

		sendReply('SHUTDOWN', 'Shutting down.');
	};
	register_shutdown_function($shutdownFunc);
	pcntl_signal(SIGINT, $signalFunc);
	pcntl_signal(SIGTERM, $signalFunc);
	pcntl_async_signals(true);

	// Ensure errors are displayed
	ini_set('display_errors', 1);
	ini_set('display_startup_errors', 1);
	error_reporting(E_ALL);

	// Run the task server!
	$taskServer->run();
	exit(0);
