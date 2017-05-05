<?php
	// Database Details

	$database['server'] = getEnvOrDefault('DB_SERVER', 'localhost');
	$database['type'] = getEnvOrDefault('DB_SERVER_TYPE', 'mysql');
	$database['username'] = getEnvOrDefault('DB_SERVER_USERNAME', 'dnsapi');
	$database['password'] = getEnvOrDefault('DB_SERVER_PASSWORD', 'dnsapi');
	$database['database'] = getEnvOrDefault('DB_SERVER_DATABASE', 'dnsapi');

	// Config for session data
	$config['memcached'] = getEnvOrDefault('MEMCACHED', '');

	// -------------------------------------------------------------------------
	// Configuration for hooks
	// -------------------------------------------------------------------------
	// Should the background hook runner be used?
	//
	// Background hook runner will swallow all attempts to run hooks and store
	// them in the database to be run at a later date instead.
	//
	// For hooks that need to be run, the 'enabled' flag should be set to
	// 'background' so that they don't run their enabled functions during
	// normal use.
	//
	// admin/backgroundHookRunner.php can then be used to run the hooks based
	// on the entries in the database, this will enable any hooks with the
	// 'enabled' flag set to 'background'.
	//
	// (This only works if the hook config is defined in config.local.php and
	// not if it only gets config from the included script.)
	//
	// This is all a bit icky, and subject to change in the future.
	// -------------------------------------------------------------------------
	$config['useBackgroundHooks'] = 'false';

	// Local configuration.
	if (file_exists(dirname(__FILE__) . '/config.local.php')) {
		include(dirname(__FILE__) . '/config.local.php');
	}
