#!/usr/bin/env php
<?php
	require_once(dirname(__FILE__) . '/../functions.php');

	$search = Hook::getSearch(DB::get());
	$dbhooks = [];
	$rows = $search->find();
	foreach ($rows as $hook) {
		$dbhooks[] = $hook;
	}

	foreach ($dbhooks as $obj) {
		echo 'Hook ID: ', $obj->getID(), "\n";
		HookManager::get()->runHookObject('later', $obj);
		$obj->delete();
	}

	exit(0);
