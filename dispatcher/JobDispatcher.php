#!/usr/bin/env php
<?php
	// This takes events from the event queue, and dispatches some of them as
	// jobs.

	require_once(dirname(__FILE__) . '/../functions.php');

	echo showTime(), ' ', 'Job Dispatcher started.', "\n";

	function dispatchJob($job, $args) {
		global $gmc;

		echo showTime(), ' ', 'Dispatching: ', $job, '(', json_encode($args), ')', "\n";
		JobQueue::get()->publish($job, $args);
	}

	foreach (recursiveFindFiles(__DIR__ . '/handlers') as $file) {
		echo showTime(), ' ', 'Loading from: ', $file, "\n";
		include_once($file);
	}

	EventQueue::get()->consumeEvents(function ($event) {
		echo showTime(), ' ', 'Event: ', $event['event'], '(', json_encode($event['args']), ')', "\n";

		checkDBAlive();

		EventQueue::get()->handleSubscribers($event);
	});

	RabbitMQ::get()->consume();
