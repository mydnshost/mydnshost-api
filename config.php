<?php
	// Database Details

	$database['server'] = getEnvOrDefault('DB_SERVER', 'localhost');
	$database['type'] = getEnvOrDefault('DB_SERVER_TYPE', 'mysql');
	$database['username'] = getEnvOrDefault('DB_SERVER_USERNAME', 'dnsapi');
	$database['password'] = getEnvOrDefault('DB_SERVER_PASSWORD', 'dnsapi');
	$database['database'] = getEnvOrDefault('DB_SERVER_DATABASE', 'dnsapi');

	// -------------------------------------------------------------------------
	// Configuration for hooks
	// -------------------------------------------------------------------------
	// Should hooks only be run by the background hook runner rather than
	// inline?
	// -------------------------------------------------------------------------
	$config['useBackgroundHooks'] = 'false';

	// Local configuration.
	if (file_exists(dirname(__FILE__) . '/config.local.php')) {
		include(dirname(__FILE__) . '/config.local.php');
	}
