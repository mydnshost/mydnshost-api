<?php

	$router->addRoute('GET /ping(?:/(?P<time>.+))?', new class extends APIMethod {
		function call($requestMethod, $params) {
			if (isset($params['time'])) {
				$this->getContext()['response']->set('time', $params['time']);
			}

			return TRUE;
		}
	});
