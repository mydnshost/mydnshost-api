<?php
	// Database Details

	$database['server'] = getEnvOrDefault('DB_SERVER', 'localhost');
	$database['type'] = getEnvOrDefault('DB_SERVER_TYPE', 'mysql');
	$database['username'] = getEnvOrDefault('DB_SERVER_USERNAME', 'dnsapi');
	$database['password'] = getEnvOrDefault('DB_SERVER_PASSWORD', 'dnsapi');
	$database['database'] = getEnvOrDefault('DB_SERVER_DATABASE', 'dnsapi');
