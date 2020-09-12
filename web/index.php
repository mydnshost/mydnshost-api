<?php
	// We only output json.
	header('Content-Type: application/json');

	if (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'text/html') !== false && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') === false) {
		// Probably a browser, redirect somewhere useful.
		header('HTTP/1.1 303 See Other');
		header('Location: ./docs');
	} else {
		header('HTTP/1.1 404 Not Found');
	}
	die(json_encode(array('error' => 'Invalid API Version. (Latest: 1.0)')));
