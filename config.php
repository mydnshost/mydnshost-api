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
	// If a hook is enabled, it will be run every time the API changes something.
	// Alternatively, set 'enabled' to 'background' and it will only be run by
	// the background hook runner.
	//
	// This requires that `useBackgroundHooks` is enabled.
	//
	// Enabling `useBackgroundHooks` will stop hooks working inline.
	// -------------------------------------------------------------------------
	$config['useBackgroundHooks'] = 'false';

	// --------------------
	// Bind
	// --------------------
	// This could also be used by other servers that support bind zonefiles
	// --------------------
	// zonedir = Directory to put zone files
	// addZoneCommand = Command to run to load new zones
	// reloadZoneCommand = Command to run to refresh zones
	// delZoneCommand = Command to run to remove zones	//
	// --------------------
	// $config['hooks']['bind']['enabled'] = 'true';
	// $config['hooks']['bind']['zonedir'] = '/tmp/bindzones';
	// $config['hooks']['bind']['addZoneCommand'] = 'chmod a+rwx %2$s; /usr/bin/sudo -n /usr/sbin/rndc addzone %1$s \'{type master; file "%2$s";};\' >/dev/null 2>&1';
	// $config['hooks']['bind']['reloadZoneCommand'] = 'chmod a+rwx %2$s; /usr/bin/sudo -n /usr/sbin/rndc reload %1$s >/dev/null 2>&1';
	// $config['hooks']['bind']['delZoneCommand'] = '/usr/bin/sudo -n /usr/sbin/rndc delzone %1$s >/dev/null 2>&1';

	// --------------------
	// PowerDNS
	// --------------------
	// masters = Array of "master" servers to add/update/remove zones on
	// slaves = Array of additional slave servers that zones should be removed from
	// --------------------
	// $config['hooks']['powerdns']['enabled'] = 'true';
	// $config['hooks']['powerdns']['masters'] = [['host' => '127.0.0.1', 'port' => '1080', 'apikey' => 'myapikey', 'zonetype' => 'master']];
	// $config['hooks']['powerdns']['slaves'] = [['host' => '192.168.0.2', 'port' => '1080', 'apikey' => 'myapikey'],
	//                                  ['host' => '192.168.0.3', 'port' => '1080', 'apikey' => 'myapikey']];


	if (file_exists(dirname(__FILE__) . '/config.local.php')) {
		include(dirname(__FILE__) . '/config.local.php');
	}
