<?php
	// Database Details
	$database['server'] = getEnvOrDefault('DB_SERVER', 'localhost');
	$database['type'] = getEnvOrDefault('DB_SERVER_TYPE', 'mysql');
	$database['username'] = getEnvOrDefault('DB_SERVER_USERNAME', 'dnsapi');
	$database['password'] = getEnvOrDefault('DB_SERVER_PASSWORD', 'dnsapi');
	$database['database'] = getEnvOrDefault('DB_SERVER_DATABASE', 'dnsapi');

	// Config for session data
	$config['memcached'] = getEnvOrDefault('MEMCACHED', '');

	// Config for Site registration
	$config['register_enabled'] = parseBool(getEnvOrDefault('ALLOW_REGISTER', 'true'));
	$config['register_manual_verify'] = parseBool(getEnvOrDefault('REGISTER_MANUAL_VERIFY', 'false'));
	$config['register_permissions'] = explode(',', getEnvOrDefault('REGISTER_PERMISSIONS', 'domains_create'));

	// Allow users to delete their own account.
	$config['self_delete'] = parseBool(getEnvOrDefault('ALLOW_SELF_DELETE', 'true'));

	// Location of <zone>.dskey files
	$config['dnssec']['dskeys'] = getEnvOrDefault('DNSSEC_DSKEY_FILES', '/etc/bind/keys/');

	// General details (used by emails)
	$config['sitename'] = getEnvOrDefault('SITE_NAME', 'MyDNSHost');
	$config['siteurl'] = getEnvOrDefault('SITE_URL', 'https://mydnshost.co.uk/');

	// Template details (used by emails)
	$config['templates']['dir'] = getEnvOrDefault('TEMPLATE_DIR', __DIR__ . '/templates');
	$config['templates']['theme'] = getEnvOrDefault('TEMPLATE_THEME', 'default');
	$config['templates']['cache'] = getEnvOrDefault('TEMPLATE_CACHE', __DIR__ . '/templates_c');

	// Config for email sending
	$config['email']['enabled'] = parseBool(getEnvOrDefault('EMAIL_ENABLED', 'false'));
	$config['email']['server'] = getEnvOrDefault('EMAIL_SERVER', '');
	$config['email']['username'] = getEnvOrDefault('EMAIL_USERNAME', '');
	$config['email']['password'] = getEnvOrDefault('EMAIL_PASSWORD', '');
	$config['email']['from'] = getEnvOrDefault('EMAIL_FROM', 'dns@example.org');
	$config['email']['from_name'] = getEnvOrDefault('EMAIL_FROM_NAME', $config['sitename']);

	// Config for jobserver.
	$config['jobserver']['type'] = getEnvOrDefault('JOBSERVER_TYPE', 'none');
	$config['jobserver']['host'] = getEnvOrDefault('JOBSERVER_HOST', '127.0.0.1');
	$config['jobserver']['port'] = getEnvOrDefault('JOBSERVER_PORT', 4730);

	$config['jobworkers'] = [];
	foreach (explode(',', getEnvOrDefault('WORKER_WORKERS', '*')) AS $w) {
		$config['jobworkers'][$w]['processes'] = getEnvOrDefault('WORKER_' . $w . '_PROCESSES', 1);
		$config['jobworkers'][$w]['maxJobs'] = getEnvOrDefault('WORKER_' . $w . '_MAXJOBS', 250);
	}

	// Default DNS Records
	$config['defaultRecords'] = [];
	$config['defaultRecords'][] = ['name' => '', 'type' => 'NS', 'content' => 'ns1.example.com'];
	$config['defaultRecords'][] = ['name' => '', 'type' => 'NS', 'content' => 'ns2.example.com'];
	$config['defaultRecords'][] = ['name' => '', 'type' => 'NS', 'content' => 'ns3.example.com'];

	// Default SOA
	$config['defaultSOA'] = ['primaryNS' => 'ns1.example.com.'];

	// Influx DB
	$config['influx']['host'] = getEnvOrDefault('INFLUX_HOST', 'localhost');
	$config['influx']['port'] = getEnvOrDefault('INFLUX_PORT', '8086');
	$config['influx']['user'] = getEnvOrDefault('INFLUX_USER', '');
	$config['influx']['pass'] = getEnvOrDefault('INFLUX_PASS', '');
	$config['influx']['db'] = getEnvOrDefault('INFLUX_DB', 'MyDNSHost');

	// Local configuration.
	if (file_exists(dirname(__FILE__) . '/config.local.php')) {
		include(dirname(__FILE__) . '/config.local.php');
	}
