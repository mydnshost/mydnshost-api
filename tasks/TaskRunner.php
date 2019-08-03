#!/usr/bin/env php
<?php
	require_once(dirname(__FILE__) . '/../functions.php');

	class ProcessManager {
		/** Job server information. */
		private $server;

		/** Redis Host */
		private $redisHost;

		/** React-PHP Event Loop. */
		private $loop;

		/** Job worker information. */
		private $jobs;

		/** Worker check Timer. */
		private $checkTimer;

		/** Are we stopping? */
		private $stopping = true;

		/**
		 * Create a new ProcessManager
		 *
		 * @param $server Job Server information
		 */
		public function __construct($server, $redis) {
			$this->server = $server;
			$loop = React\EventLoop\Factory::create();
			$this->loop = $loop;

			$this->redisHost = $redis['host'];
			$this->redisPort = isset($redis['port']) ? $redis['port'] : '';

			echo $this->showTime(), ' ', 'Creating ProcessManager for server type: ', $server['type'], "\n";
			echo $this->showTime(), ' ', "\t", 'Server: ', $server['host'], ':', $server['port'], "\n";
			echo $this->showTime(), ' ', "\t", 'Redis Host: ', $this->redisHost, (!empty($this->redisPort) ? ':' . $this->redisPort : ''), "\n";
		}

		private function showTime() {
			return date('[Y-m-d H:i:s O]');
		}

		/**
		 * Run the process manager.
		 */
		public function run() {
			$this->stopping = false;

			// Timer to ensure that we have all of our workers running.
			$this->checkTimer = $this->loop->addPeriodicTimer(10, function() {
				$this->checkWorkers();
			});

			// Force start all the workers
			$this->checkWorkers();

			// Begin the event loop.
			echo $this->showTime(), ' ', 'Running.', "\n";
			$this->loop->run();
		}

		/**
		 * Stop the process manager.
		 */
		public function stop() {
			if ($this->stopping) {
				echo $this->showTime(), ' ', 'Force Stopping!', "\n";
				$this->loop->stop();
				return;
			}

			echo $this->showTime(), ' ', 'Stopping...', "\n";
			$this->stopping = true;

			// Stop the check timer
			$this->loop->cancelTimer($this->checkTimer);

			// Kill all the worker processes.
			foreach ($this->jobs as $function => $functionInfo) {
				$this->jobs[$function]['maxWorkers'] = 0;
				$this->jobs[$function]['maxJobs'] = 0;
				foreach ($functionInfo['workers'] as $pid => $proc) {
					$proc['process']->terminate(SIGTERM);
				}
			}
		}

		/**
		 * Add a new Job Type.
		 *
		 * @param $function Function Name
		 * @param $workerConfig Worker config.
		 */
		public function addJob($function, $workerConfig) {
			if (isset($workerConfig['include']) && !$workerConfig['include']) {
				echo $this->showTime(), ' ', 'Excluding worker type: ', $function, "\n";
				return;
			}

			if (isset($this->jobs[$function])) { return; }

			$this->jobs[$function] = ['maxWorkers' => isset($workerConfig['processes']) ? $workerConfig['processes'] : 1,
			                          'maxJobs' => isset($workerConfig['maxJobs']) ? $workerConfig['maxJobs'] : 1,
			                          'workers' => []
			                         ];

			echo $this->showTime(), ' ', 'Adding worker type: ', $function, "\n";
			echo $this->showTime(), ' ', "\t", 'Processes: ', $this->jobs[$function]['maxWorkers'], "\n";
			echo $this->showTime(), ' ', "\t", 'Max Jobs: ', $this->jobs[$function]['maxJobs'], "\n";
		}

		/**
		 * Check that we have all of our required workers.
		 */
		private function checkWorkers() {
			if ($this->stopping) { return; }

			foreach (array_keys($this->jobs) as $function) {
				$this->startWorkers($function);
			}
		}

		/**
		 * Start all the workers for a given process type.
		 *
		 * @param $function Function to start workers for.
		 */
		private function startWorkers($function) {
			if ($this->stopping) { return; }

			while (count($this->jobs[$function]['workers']) < $this->jobs[$function]['maxWorkers']) {
				$this->startWorker($function);
			}
		}

		/**
		 * Start a new worker for a given function.
		 *
		 * @param $function Function to start worker for.
		 */
		private function startWorker($function) {
			if ($this->stopping) { return; }

			// Create a new runWorker process.
			$process = new React\ChildProcess\Process('exec /usr/bin/env php ' . escapeshellarg(__DIR__ . '/runWorker.php') . ' ' . escapeshellarg($function));
			$process->start($this->loop);

			// Store the process.
			$pid = $process->getPid();
			$this->jobs[$function]['workers'][$pid] = ['jobcount' => 0, 'process' => $process, 'buffers' => ['stdout' => '', 'stderr' => '']];

			// Register handlers for output from the worker.
			// STDOUT data from the worker.
			$process->stdout->on('data', function ($data) use ($function, $pid) {
				$this->jobs[$function]['workers'][$pid]['buffers']['stdout'] .= $data;
				$lines = explode("\n", $this->jobs[$function]['workers'][$pid]['buffers']['stdout']);
				$this->jobs[$function]['workers'][$pid]['buffers']['stdout'] = array_pop($lines);
				foreach ($lines as $line) {
					if (!empty($line)) {
						$this->processWorkerData($function, $pid, $line);
					}
				}
			});

			// STDERR data from the worker.
			$process->stderr->on('data', function ($data) use ($function, $pid) {
				$this->jobs[$function]['workers'][$pid]['buffers']['stderr'] .= $data;
				$lines = explode("\n", $this->jobs[$function]['workers'][$pid]['buffers']['stderr']);
				$this->jobs[$function]['workers'][$pid]['buffers']['stderr'] = array_pop($lines);
				foreach ($lines as $line) {
					if (!empty($line)) {
						$this->processWorkerData($function, $pid, '# STDERR: ' . $line);
					}
				}
			});

			// Process terminated.
			$process->on('exit', function($exitCode, $termSignal) use ($function, $pid) {
				$this->processWorkerExit($function, $pid, $exitCode, $termSignal);
			});

			// Start the worker.
			$this->processWorkerStart($function, $pid);
		}

		/**
		 * Called when a worker starts.
		 *
		 * @param $function Function that this worker is for
		 * @param $pid Process ID for this worker
		 */
		private function processWorkerStart($function, $pid) {
			echo $this->showTime(), ' ', '[', $function, '::', $pid, ']  Process started.', "\n";

			// Configure the worker.
			$process = $this->jobs[$function]['workers'][$pid]['process'];
			$process->stdin->write('setRedisHost ' . $this->redisHost . ' ' . $this->redisPort . "\n");
			$process->stdin->write('addFunction ' . $function . "\n");
			$process->stdin->write('setTaskServer ' . $this->server['type'] . ' ' . $this->server['host'] . ' ' . $this->server['port'] . "\n");
			$process->stdin->write('run' . "\n");
		}

		private function showIdent($function, $pid) {
			$jobfunc = isset($this->jobs[$function]) ? $this->jobs[$function] : NULL;
			$proc = isset($jobfunc['workers'][$pid]) ? $jobfunc['workers'][$pid] : NULL;

			$result = '[' . $function . '::' . $pid;

			if ($jobfunc != null && $proc != null) {
				$result .= ' (';
				$result .= $proc['jobcount'];
				$result .= '/';
				$result .= $jobfunc['maxJobs'];
				$result .= ')';
			} else {
				$result .= ' (END)';
			}

			$result .= ']';

			return $result;
		}

		/**
		 * Called when a worker starts sends data.
		 *
		 * @param $function Function that this worker is for
		 * @param $pid Process ID for this worker
		 * @param $data Data from the worker
		 */
		private function processWorkerData($function, $pid, $data) {
			echo $this->showTime(), ' ', $this->showIdent($function, $pid), '> ', trim($data), "\n";

			$bits = explode(" ", $data, 2);
			$cmd = $bits[0];
			$args = isset($bits[1]) ? $bits[1] : '';

			// Count the jobs from the worker, restarting it as needed.
			if ($cmd == 'JOB') {
				$this->jobs[$function]['workers'][$pid]['jobcount']++;
				$process = $this->jobs[$function]['workers'][$pid];
				if ($process['jobcount'] >= $this->jobs[$function]['maxJobs']) {
					echo $this->showTime(), ' ', $this->showIdent($function, $pid), '  Terminating process after ' . $process['jobcount'] . ' jobs.', "\n";

					$process['process']->terminate(SIGTERM);

					// Replace the worker immediately if we stop it.
					$this->startWorker($function);
				}
			} else if ($cmd == 'EXCEPTION') {
				EventQueue::get()->publish('worker_error', [$function, $args]);
			}
		}

		/**
		 * Called when a worker exits.
		 *
		 * @param $function Function that this worker is for
		 * @param $pid Process ID for this worker
		 */
		private function processWorkerExit($function, $pid, $exitCode, $termSignal) {
			echo $this->showTime(), ' ', $this->showIdent($function, $pid), '  Process exited. (', $exitCode, '/', $termSignal, ')', "\n";
			unset($this->jobs[$function]['workers'][$pid]);
		}
	}

	if (empty($config['redis']) || !class_exists('Redis')) {
		die('Redis is required for TaskRunner.');
	}

	// Create the process manager
	$pm = new ProcessManager($config['jobserver'], ['host' => $config['redis'], 'port' => $config['redisPort']]);

	// Add the workers.
	foreach ($config['jobworkers'] as $worker => $conf) {
		$pm->addJob($worker, $conf);
	}

	// Deal with shutdown requests
	$shutdownFunc = function() use ($pm) { $pm->stop();	};
	pcntl_signal(SIGINT, $shutdownFunc);
	pcntl_signal(SIGTERM, $shutdownFunc);
	pcntl_async_signals(true);

	// Run the ProcessManager
	$pm->run();
