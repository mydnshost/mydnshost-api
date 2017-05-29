<?php

	$router->get('/ping(?:/(.+))?', new class extends RouterMethod {
		function run($time = null) {
			if ($time != null) {
				$this->getContext()['response']->set('time', $time);
			}

			return TRUE;
		}
	});
