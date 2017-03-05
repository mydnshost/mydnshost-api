<?php

	class Ping extends APIMethod {
		public function get($params) {
			if (isset($params['time'])) {
				$this->getContext()['response']->set('time', $params['time']);
			}

			return TRUE;
		}
	}

	$router->addRoute('GET /ping(?:/(?P<time>.+))?', new Ping());
