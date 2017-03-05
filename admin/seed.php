#!/usr/bin/env php
<?php
	require_once(dirname(__FILE__) . '/init_functions.php');

	initDataServer(DB::get());

	// This will delete the database and seed it with test data.
	$pdo = DB::get()->getPDO();
	$pdo->exec('SET FOREIGN_KEY_CHECKS = 0;');
	$pdo->exec('TRUNCATE users;');
	$pdo->exec('TRUNCATE domains;');
	$pdo->exec('TRUNCATE records;');
	$pdo->exec('SET FOREIGN_KEY_CHECKS = 1;');

	$admin = new User();
	$admin->setEmail('admin@example.org');
	$admin->setRealName('Admin User');
	$admin->setPassword('password');
	$admin->setAdmin(true);
	DB::get()->save($admin);

	$user = new User();
	$user->setEmail('user@example.org');
	$user->setRealName('Normal User');
	$user->setPassword('password');
	$user->setAdmin(false);
	DB::get()->save($user);


	$testcom = new Domain();
	$testcom->setDomain('test.com');
	$testcom->setOwner($admin->getID());
	DB::get()->save($testcom);

	$test2com = new Domain();
	$test2com->setDomain('test2.com');
	$test2com->setOwner($admin->getID());
	DB::get()->save($test2com);

	$exampleorg = new Domain();
	$exampleorg->setDomain('example.org');
	$exampleorg->setOwner($user->getID());
	DB::get()->save($exampleorg);

	$examplecom = new Domain();
	$examplecom->setDomain('example.com');
	DB::get()->save($examplecom);

	foreach ([$testcom, $test2com, $exampleorg, $examplecom] as $domain) {

		$record = new Record();
		$record->setDomainID($domain->getID());
		$record->setName('');
		$record->setType('SOA');
		$record->setContent('ns1.mydnshost.co.uk. dnsadmin.dataforce.org.uk. 2017030500 86400 7200 2419200 60');
		$record->setTTL(86400);
		$record->setChangedAt(time());
		DB::get()->save($record);

		$record = new Record();
		$record->setDomainID($domain->getID());
		$record->setName('');
		$record->setType('A');
		$record->setContent('127.0.0.1');
		$record->setTTL(86400);
		$record->setChangedAt(time());
		DB::get()->save($record);

		$record = new Record();
		$record->setDomainID($domain->getID());
		$record->setName('www');
		$record->setType('A');
		$record->setContent('127.0.0.1');
		$record->setTTL(86400);
		$record->setChangedAt(time());
		DB::get()->save($record);

		$record = new Record();
		$record->setDomainID($domain->getID());
		$record->setName('');
		$record->setType('AAAA');
		$record->setContent('::1');
		$record->setTTL(86400);
		$record->setChangedAt(time());
		DB::get()->save($record);

		$record = new Record();
		$record->setDomainID($domain->getID());
		$record->setName('www');
		$record->setType('AAAA');
		$record->setContent('::1');
		$record->setTTL(86400);
		$record->setChangedAt(time());
		DB::get()->save($record);

		$record = new Record();
		$record->setDomainID($domain->getID());
		$record->setName('test');
		$record->setType('CNAME');
		$record->setContent('www');
		$record->setTTL(86400);
		$record->setChangedAt(time());
		DB::get()->save($record);

		$record = new Record();
		$record->setDomainID($domain->getID());
		$record->setName('txt');
		$record->setType('TXT');
		$record->setContent('Some Text Record');
		$record->setTTL(86400);
		$record->setChangedAt(time());
		DB::get()->save($record);

		$record = new Record();
		$record->setDomainID($domain->getID());
		$record->setName('');
		$record->setType('MX');
		$record->setPriority('10');
		$record->setContent('');
		$record->setTTL(86400);
		$record->setChangedAt(time());
		DB::get()->save($record);

		$record = new Record();
		$record->setDomainID($domain->getID());
		$record->setName('');
		$record->setType('MX');
		$record->setPriority('50');
		$record->setContent('test');
		$record->setTTL(86400);
		$record->setChangedAt(time());
		DB::get()->save($record);
	}

	exit(0);
