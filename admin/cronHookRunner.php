#!/usr/bin/env php
<?php
	require_once(dirname(__FILE__) . '/../functions.php');

	use shanemcc\phpdb\DB;

	HookManager::get()->addHook('hook_error', function($ex) {
		echo '--', "\n";
		echo 'Hook Error: ', $ex->getMessage(), "\n";
		echo $ex->getTraceAsString();
		echo '--', "\n\n";
	});

	$search = Hook::getSearch(DB::get());
	$dbhooks = [];
	$rows = $search->find();
	foreach ($rows as $hook) {
		$dbhooks[] = $hook;
	}

	foreach ($dbhooks as $obj) {
		echo 'Hook ID: ', $obj->getID(), "\n";
		HookManager::get()->runHookObject('later', $obj);
		echo 'Done.', "\n";
		$obj->delete();
	}

	exit(0);
