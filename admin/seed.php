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

	$admin = new User(DB::get());
	$admin->setEmail('admin@example.org');
	$admin->setRealName('Admin User');
	$admin->setPassword('password');
	$admin->setAdmin(true);
	$admin->save();

	$user = new User(DB::get());
	$user->setEmail('user@example.org');
	$user->setRealName('Normal User');
	$user->setPassword('password');
	$user->setAdmin(false);
	$user->save();


	$testcom = new Domain(DB::get());
	$testcom->setDomain('test.com');
	$testcom->setOwner($admin->getID());
	$testcom->save();

	$test2com = new Domain(DB::get());
	$test2com->setDomain('test2.com');
	$test2com->setOwner($admin->getID());
	$test2com->save();

	$exampleorg = new Domain(DB::get());
	$exampleorg->setDomain('example.org');
	$exampleorg->setOwner($user->getID());
	$exampleorg->save();

	$examplecom = new Domain(DB::get());
	$examplecom->setDomain('example.com');
	$examplecom->save();

	foreach ([$testcom, $test2com, $exampleorg, $examplecom] as $domain) {

		$record = new Record(DB::get());
		$record->setDomainID($domain->getID());
		$record->setName('');
		$record->setType('SOA');
		$record->setContent('ns1.mydnshost.co.uk. dnsadmin.dataforce.org.uk. 2017030500 86400 7200 2419200 60');
		$record->setTTL(86400);
		$record->setChangedAt(time());
		$record->save();

		$record = new Record(DB::get());
		$record->setDomainID($domain->getID());
		$record->setName('');
		$record->setType('A');
		$record->setContent('127.0.0.1');
		$record->setTTL(86400);
		$record->setChangedAt(time());
		$record->save();

		$record = new Record(DB::get());
		$record->setDomainID($domain->getID());
		$record->setName('www');
		$record->setType('A');
		$record->setContent('127.0.0.1');
		$record->setTTL(86400);
		$record->setChangedAt(time());
		$record->save();

		$record = new Record(DB::get());
		$record->setDomainID($domain->getID());
		$record->setName('');
		$record->setType('AAAA');
		$record->setContent('::1');
		$record->setTTL(86400);
		$record->setChangedAt(time());
		$record->save();

		$record = new Record(DB::get());
		$record->setDomainID($domain->getID());
		$record->setName('www');
		$record->setType('AAAA');
		$record->setContent('::1');
		$record->setTTL(86400);
		$record->setChangedAt(time());
		$record->save();

		$record = new Record(DB::get());
		$record->setDomainID($domain->getID());
		$record->setName('test');
		$record->setType('CNAME');
		$record->setContent('www');
		$record->setTTL(86400);
		$record->setChangedAt(time());
		$record->save();

		$record = new Record(DB::get());
		$record->setDomainID($domain->getID());
		$record->setName('txt');
		$record->setType('TXT');
		$record->setContent('Some Text Record');
		$record->setTTL(86400);
		$record->setChangedAt(time());
		$record->save();

		$record = new Record(DB::get());
		$record->setDomainID($domain->getID());
		$record->setName('');
		$record->setType('MX');
		$record->setPriority('10');
		$record->setContent('');
		$record->setTTL(86400);
		$record->setChangedAt(time());
		$record->save();

		$record = new Record(DB::get());
		$record->setDomainID($domain->getID());
		$record->setName('');
		$record->setType('MX');
		$record->setPriority('50');
		$record->setContent('test');
		$record->setTTL(86400);
		$record->setChangedAt(time());
		$record->save();
	}

	exit(0);
