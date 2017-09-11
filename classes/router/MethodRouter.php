<?php
	class MethodRouter {
		private $knownRoutes = [];

		public function addRoute($requestMethod, $match, $object) {
			if (!preg_match('#^\(.*\)$#', $requestMethod)) { $requestMethod = '(' . $requestMethod . ')'; }

			$key = strtoupper($requestMethod) . ' ' . $match;
			if (array_key_exists($key, $this->knownRoutes)) {
				throw new Exception('Route already exists.');
			}

			$this->knownRoutes[$key] = $object;
		}

		public function get($match, $object) {
			return $this->addRoute('GET', $match, $object);
		}

		public function post($match, $object) {
			return $this->addRoute('POST', $match, $object);
		}

		public function delete($match, $object) {
			return $this->addRoute('DELETE', $match, $object);
		}

		public function findRoute($requestMethod, $path) {
			$testString = $requestMethod . ' ' . $path;

			foreach ($this->knownRoutes as $k => $v) {
				if (preg_match('#^' . $k . '$#i', $testString, $matches)) {

					array_shift($matches);
					array_shift($matches);

					$matches = array_map(function($a) { return urldecode($a); }, $matches);
					return [$v, $matches];
				}
			}

			throw new RouterMethod_NotFound();
		}

		public function run($requestMethod, $path, $context) {
			// Find the route
			list($method, $params) = $this->findRoute($requestMethod,  '/' . $path);

			$context['Request Method'] = $requestMethod;
			$context['MethodRouter'] = $this;
			$method->setContext($context);

			// And run it!
			if ($method->call($params)) {
				return true;
			} else {
				throw new RouterMethod_NotAllowed();
			}
		}
	}
