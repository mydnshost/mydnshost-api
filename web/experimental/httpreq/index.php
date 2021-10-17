<?php
	require_once(dirname(__FILE__) . '/../../1.0/functions.php');
	require_once(dirname(__FILE__) . '/../../1.0/response.php');

	$router = new MethodRouter();

	require_once(dirname(__FILE__) . '/../../1.0/methods/httpreq.php');
	define('AUTOLOAD_METHODS', false);

	$_SERVER['REQUEST_URI'] = '/external/httpreq' . $_SERVER['REQUEST_URI'];

	require_once(dirname(__FILE__) . '/../../1.0/index.php');

 	die(0);
