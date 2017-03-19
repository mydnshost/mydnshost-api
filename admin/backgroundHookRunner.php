#!/usr/bin/env php
<?php
	// Load Config.
	if (!function_exists('getEnvOrDefault')) {
		function getEnvOrDefault($var, $default) {
			$result = getEnv($var);
			return $result === FALSE ? $default : $result;
		}
	}
	require_once(dirname(__FILE__) . '/../config.php');

	// Now, poke at hooks and change enabled-ness.
	//
	// Background hooks get enabled, everything else is disabled.
	foreach (array_keys($config['hooks']) as $hook) {
		if ($config['hooks'][$hook]['enabled'] == 'background') {
			$config['hooks'][$hook]['enabled'] = true;
		} else {
			$config['hooks'][$hook]['enabled'] = false;
		}
	}

	// Load and install the background hook manager
	require_once(dirname(__FILE__) . '/../classes/hookmanager.php');
	BackgroundHookManager::install();

	// Now load the rest of the functions which will also load all the hooks.
	// Files are included with require_once require_once, so won't be re-loaded.
	require_once(dirname(__FILE__) . '/../functions.php');

	// Now we can run the hooks.
	BackgroundHookManager::get()->run();

	exit(0);
