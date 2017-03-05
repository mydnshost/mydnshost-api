<?php
	require_once(dirname(__FILE__) . '/apimethod.php');

	class MethodRouter {
		private $knownRoutes = [];

		public function addRoute($match, $object) {
			if (array_key_exists($match, $this->knownRoutes)) {
				throw new Exception('Route already exists.');
			}

			$this->knownRoutes[$match] = $object;
		}

		public function findRoute($requestMethod, $path) {
			$testString = $requestMethod . ' ' . $path;

			foreach ($this->knownRoutes as $k => $v) {
				if (preg_match('#^' . $k . '$#', $testString, $matches)) {
					return [$v, $matches];
				}
			}

			return [FALSE, ''];
		}
	}

	$router = new MethodRouter();

	// A bit horrible.
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/methods', RecursiveDirectoryIterator::SKIP_DOTS));
	foreach($it as $file) {
	    if (pathinfo($file, PATHINFO_EXTENSION) == "php") {
	        include_once($file);
	    }
	}
