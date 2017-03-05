<?php

	class Ping extends APIMethod {
		public function get($matches) {
			if (isset($matches['time'])) {
				$this->getContext()['response']->set('time', $matches['time']);
			}

			return TRUE;
		}
	}

	$router->addRoute('GET /ping(?:/(?P<time>.+))?', 'Ping');
