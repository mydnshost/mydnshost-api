#!/usr/bin/env php
<?php
	// This takes events from the event queue related to jobs and logs them.

	use shanemcc\phpdb\DB;

	require_once(dirname(__FILE__) . '/../functions.php');

	echo showTime(), ' ', 'Job Logger started.', "\n";

	EventQueue::get()->consumeEvents(function ($event) {
		echo showTime(), ' ', 'Event: ', $event['event'], '(', json_encode($event['args']), ')', "\n";
		checkDBAlive();
		EventQueue::get()->handleSubscribers($event);
	}, 'event.job.#');

	$activeJobs = [];

	EventQueue::get()->subscribe('job.started', function($jobid) use (&$activeJobs) {
		$activeJobs[$jobid] = Job::load(DB::get(), $jobid);
	});

	EventQueue::get()->subscribe('job.log', function($jobid, $message) use (&$activeJobs) {
		$job = isset($activeJobs[$jobid]) ? $activeJobs[$jobid] : Job::load(DB::get(), $jobid);

		$job->addLog($message);
	});

	EventQueue::get()->subscribe('job.finished', function($jobid) use (&$activeJobs) {
		unset($activeJobs[$jobid]);
	});

	RabbitMQ::get()->consume();
