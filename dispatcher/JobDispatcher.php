#!/usr/bin/env php
<?php
	// This takes events from the event queue, and dispatches some of them as
	// jobs onto gearman instead

	require_once(dirname(__FILE__) . '/../functions.php');

	$gmc = new GearmanClient();

	try {
		$gmc->addServer($config['jobserver']['host'], $config['jobserver']['port']);
		$gmc->setTimeout(5000);
	} catch (Exception $e) {
		die('Unable to connect to gearman.');
	}

	foreach (recursiveFindFiles(__DIR__ . '/handlers') as $file) { include_once($file); }
	foreach (recursiveFindFiles(__DIR__ . '/handlers/user') as $file) { include_once($file); }

	EventQueue::get()->consumeEvents();
