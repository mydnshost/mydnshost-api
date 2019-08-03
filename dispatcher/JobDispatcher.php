#!/usr/bin/env php
<?php
	require_once(dirname(__FILE__) . '/../functions.php');

	$gmc = new GearmanClient();

	try {
		$gmc->addServer($config['jobserver']['host'], $config['jobserver']['port']);
		$gmc->setTimeout(5000);
	} catch (Exception $e) {
		die('Unable to connect to gearman.');
	}

	foreach (recursiveFindFiles(__DIR__ . '/handlers') as $file) { include_once($file); }

	EventQueue::get()->consumeEvents();
