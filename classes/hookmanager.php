<?php

	class HookManager {
		private static $instance = null;

		private $hooks = [];


		public static function get() {
			if (self::$instance == null) {
				self::$instance = new HookManager();
			}

			return self::$instance;
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
