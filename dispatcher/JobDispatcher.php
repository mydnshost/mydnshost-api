#!/usr/bin/env php
<?php
	// This takes events from the event queue, and dispatches some of them as
	// jobs onto gearman instead

	require_once(dirname(__FILE__) . '/../functions.php');

	echo showTime(), ' ', 'Job Dispatcher started.', "\n";

	$gmc = new GearmanClient();

	try {
		$gmc->addServer($config['jobserver']['host'], $config['jobserver']['port']);
		$gmc->setTimeout(5000);

		echo showTime(), ' ', "\t", 'Gearman Server: ', $config['jobserver']['host'], ':', $config['jobserver']['port'], "\n";
	} catch (Exception $e) {
		die('Unable to connect to gearman.');
	}

	foreach (recursiveFindFiles(__DIR__ . '/handlers') as $file) {
		echo showTime(), ' ', 'Loading from: ', $file, "\n";
		include_once($file);
	}

	EventQueue::get()->consumeEvents(function ($event) {
		echo showTime(), ' ', 'Event: ', $event['event'], '(', json_encode($event['args']), ')', "\n";
	});
