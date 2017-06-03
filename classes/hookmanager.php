<?php

	/**
	 * Hook Mananger.
	 */
	class HookManager {
		// Instance of hook manager.
		private static $instance = null;

		// Known Hooks
		protected $hooks = [];

		/**
		 * Get the hookmanager instance.
		 *
		 * @return Hookmanager instance.
		 */
		public static function get() {
			if (self::$instance == null) {
				self::$instance = new HookManager();
			}

			return self::$instance;
		}

		/**
		 * Add a new hook type.
		 * Hook types must be added before hooks can use them.
		 *
		 * @param $hooktype Hook type to add.
		 */
		public function addHookType($hooktype) {
			if (array_key_exists($hooktype, $this->hooks)) {
				throw new Exception('HookType already exists.');
			}

			$this->hooks[$hooktype] = array('now' => [], 'background' => [], 'later' => []);
		}

		/**
		 * Add a hook to a hooktype.
		 *
		 * The hook function will be run on the main thread when the hook is
		 * called.
		 *
		 * @param $hooktype Hook type to add.
		 */
		public function addHook($hook, $callable) {
			if (!array_key_exists($hook, $this->hooks)) {
				throw new Exception('No such hook');
			}

			$this->hooks[$hook]['now'][] = $callable;
		}

		/**
		 * Add a background hook to a hooktype.
		 *
		 * The hook function will be run in a separate process in the background
		 * when it is called.
		 *
		 * @param $hooktype Hook type to add.
		 */
		public function addHookBackground($hook, $callable) {
			if (!array_key_exists($hook, $this->hooks)) {
				throw new Exception('No such hook');
			}

			$this->hooks[$hook]['background'][] = $callable;
		}

		/**
		 * Add a "later" hook to a hooktype.
		 *
		 * The hook function will be run in a separate process in the background
		 * at a later time (by cron).
		 *
		 * @param $hooktype Hook type to add.
		 */
		public function addHookLater($hook, $callable) {
			if (!array_key_exists($hook, $this->hooks)) {
				throw new Exception('No such hook');
			}

			$this->hooks[$hook]['later'][] = $callable;
		}

		/**
		 * Handle a hooktype.
		 *
		 * This will run all normal hooks, then if required will start a
		 * background process to run all the background hooks, and if required
		 * also add the hook to the database to be run later.
		 *
		 * @param $hook Hooktype to handle.
		 * @param $args Array of arguments for the hook.
		 */
		public function handle($hook, $args = []) {
			if (!array_key_exists($hook, $this->hooks)) {
				throw new Exception('No such hook');
			}

			$this->runHook('now', $hook, $args);
			if (count($this->hooks[$hook]['background']) > 0) {
				$this->handleBackground($hook, $args);
			}
			if (count($this->hooks[$hook]['later']) > 0) {
				$h = new Hook(DB::get());
				$h->setHook($hook);
				$h->setArgs($args);
				$h->save();
			}
		}

		/**
		 * Actually run some hook functions.
		 *
		 * @param $type What type of hook functions should we call? ('now',
		 *              'later', 'background')
		 * @param $hook Hooktype to run.
		 * @param $args Array of arguments for the hook.
		 */
		public function runHook($type, $hook, $args = []) {
			if (array_key_exists($hook, $this->hooks)) {
				foreach ($this->hooks[$hook][$type] as $callable) {
					try {
						call_user_func_array($callable, $args);
					} catch (Exception $ex) { }
				}
			}
		}

		/**
		 * Function to run hook objects.
		 *
		 * This just calls runHook after deserialising the object.
		 *
		 * @param $type What type of hook functions should we call? ('now',
		 *              'later', 'background')
		 * @param $obj Object to run
		 */
		public function runHookObject($type, $obj) {
			$hook = $obj->getHook();

			if (array_key_exists($hook, $this->hooks)) {
				$args = $obj->getArgs();
				foreach ($args as $arg) {
					if ($arg instanceof DBObject) { $arg->setDB(DB::get()); }
				}
				$this->runHook($type, $hook, $args);
			}
		}

		/**
		 * Spawn a background process to run background hooks.
		 *
		 * Horrible code ahead.
		 *
		 * @param $hook Hooktype to run.
		 * @param $args Array of arguments for the hook.
		 */
		private function handleBackground($hook, $args = []) {
			$data = serialize(['hook' => $hook, 'args' => $args]);
			$code = <<<'CODE'
	require_once(__DIR__ . '/../functions.php');

	$data = unserialize(file_get_contents("php://stdin"));

	$h = new Hook(DB::get());
	$h->setHook($data['hook']);
	$h->setArgs($data['args']);

	HookManager::get()->runHookObject('background', $h);
CODE;

			$cmd = 'cd ' . escapeshellarg(__DIR__) . '; echo ' . escapeshellarg($data) .' | /usr/bin/env php -r ' . escapeshellarg($code);
			$process = new Cocur\BackgroundProcess\BackgroundProcess($cmd);
			$process->run();
		}
	}
