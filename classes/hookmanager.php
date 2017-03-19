<?php

	class HookManager {
		private static $instance = null;

		protected $hooks = [];


		public static function get() {
			if (self::$instance == null) {
				self::$instance = new HookManager();
			}

			return self::$instance;
		}

		public static function set($instance) {
			self::$instance = $instance;
		}

		public function addHookType($hooktype) {
			if (array_key_exists($hooktype, $this->hooks)) {
				throw new Exception('HookType already exists.');
			}

			$this->hooks[$hooktype] = array();
		}

		public function addHook($hook, $callable) {
			if (!array_key_exists($hook, $this->hooks)) {
				throw new Exception('No such hook');
			}

			$this->hooks[$hook][] = $callable;
		}

		public function handle($hook, $args = []) {
			if (!array_key_exists($hook, $this->hooks)) {
				throw new Exception('No such hook');
			}

			foreach ($this->hooks[$hook] as $callable) {
				try {
					call_user_func_array($callable, $args);
				} catch (Exception $ex) { }
			}
		}
	}

	/**
	 * Implementation of HookManager that stores hooks in the database to be
	 * executed at a later date.
	 *
	 * There is a script `admin/backgroundHookRunner.php` that should be run
	 * from a cronjob to run through all the hooks.
	 *
	 * It is important to realise that some hooks may behave differently when
	 * run from the background hook runner. (For example, if a domain has
	 * records changed twice, both instances of records_changed will do the same
	 * thing as they only get given the `Domain` object and fetch the actual
	 * records from the DB when the hook is run (not when it is called))
	 */
	class BackgroundHookManager extends HookManager {
		public static function install() {
			HookManager::set(new BackgroundHookManager());
		}

		public function handle($hook, $args = []) {
			if (!array_key_exists($hook, $this->hooks)) {
				throw new Exception('No such hook');
			}

			$h = new Hook(DB::get());
			$h->setHook($hook);
			$h->setArgs($args);
			if (!$h->save()) {
				echo 'Failed to save hook.', "\n";
				var_dump($h->getLastError());
			}
		}

		/**
		 * Runs hooks from the database.
		 */
		public function run() {
			$search = Hook::getSearch(DB::get());
			$dbhooks = [];
			$rows = $search->find();
			foreach ($rows as $hook) {
				$dbhooks[] = [$hook->getHook(), $hook];
			}

			foreach ($dbhooks as $dbhook) {
				list($hook, $obj) = $dbhook;
				$args = $obj->getArgs();
				foreach ($args as $arg) {
					if ($arg instanceof DBObject) { $arg->setDB(DB::get()); }
				}

				echo 'Hook ID: ', $obj->getID(), "\n";

				foreach ($this->hooks[$hook] as $callable) {
					try {
						call_user_func_array($callable, $args);
					} catch (Exception $ex) { }
				}

				$obj->delete();
			}
		}
	}
