#!/usr/bin/env php
<?php
	// This takes events from the event queue, and dispatches some of them as
	// jobs.

  	use shanemcc\phpdb\DB;

	require_once(dirname(__FILE__) . '/../functions.php');

	echo showTime(), ' ', 'Job Dispatcher started.', "\n";

	function createJob($job, $args) {
		return JobQueue::get()->create($job, $args);
	}

	function dispatchJob($job) {
		echo showTime(), ' ', 'Dispatching: ', $job->getName(), '(', json_encode($job->getJobData()), ')', "\n";

		JobQueue::get()->publish($job);
	}

	foreach (recursiveFindFiles(__DIR__ . '/handlers') as $file) {
		echo showTime(), ' ', 'Loading from: ', $file, "\n";
		include_once($file);
	}

	EventQueue::get()->consumeEvents(function ($event) {
		if (is_array($event) && isset($event['event'])) {
			echo showTime(), ' ', 'Event: ', $event['event'], '(', json_encode($event['args']), ')', "\n";

			checkDBAlive();

			EventQueue::get()->handleSubscribers($event);
		} else {
			echo showTime(), ' ', 'Unknown Event: ', $event, "\n";
		}
	});

	EventQueue::get()->subscribe('job.finished', function($jobid) {
		$job = Job::load(DB::get(), $jobid);

		$dependants = $job->getDependants();

		echo showTime(), ' ', 'Job Finished: ', $jobid, "\n";
		foreach ($dependants as $j) {
			echo showTime(), ' ', "\t", 'Dependant: ', $j->getID(), "\n";

			$canRun = true;
			foreach ($j->getDependsOn() as $j2) {
				echo showTime(), ' ', "\t\t", 'Depends on: ', $j2->getID(), ' which has state: ', $j2->getState(), "\n";
				if ($j2->getState() == 'error') {
					echo showTime(), ' ', "\t\t", 'Job unable to run due to error, marking as failed.', "\n";

					$resultMsg = 'PARENT ERROR';
					$j->setState('error')->setResult($resultMsg)->save();
					EventQueue::get()->publish('job.finished', [$j->getID(), $resultMsg]);
					$canRun = false;
				}

				if ($j2->getState() != 'finished') {
					echo showTime(), ' ', "\t\t", 'Job unable to run due to incomplete parent.', "\n";

					$canRun = false;
				}
			}

			if ($canRun) {
				echo showTime(), ' ', "\t\t", 'Job able to be run, dispatching.', "\n";

				$j->setState('created')->save();
				dispatchJob($j);
			}
		}
	});

	RabbitMQ::get()->consume();
