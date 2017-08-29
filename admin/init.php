#!/usr/bin/env php
<?php
	use shanemcc\phpdb\DB;

	require_once(dirname(__FILE__) . '/init_functions.php');

	echo 'Updating public suffixes...';
	updatePublicSuffixes();
	echo ' Done.', "\n";

	initDataServer(DB::get());

	public function getUserCount() {
		$query = "SELECT count(id) AS `count` FROM `users`";
		$statement = DB::get()->getPDO()->prepare($query);
		$statement->execute();
		$result = $statement->fetch(PDO::FETCH_ASSOC);

		return !isset($result['count']) ? 0 : $result['count'];
	}

	// Check for users, if there isn't one - add a default admin user.
	if (getUserCount() == 0) {
		echo 'Inserting default admin user.', "\n";

		$password = getEnvOrDefault('ADMIN_PASS', 'password');

		$user = new User(DB::get());
		$user->setEmail(getEnvOrDefault('ADMIN_EMAIL', 'admin@example.org'));
		$user->setRealName(getEnvOrDefault('ADMIN_NAME', 'Admin User'));
		$user->setPassword($password);
		$user->setPermission('all', true);

		if ($user->save()) {
			echo 'Added admin user: ', $user->getEmail(), ' with password: ', $password, "\n";
			echo 'ID: ', $user->getID(), "\n";
		} else {
			echo 'Failed to add admin user: ', $user->getLastError()[2], "\n";
			exit(1);
		}
	} else {
		echo 'Found users, not adding anything.', "\n";
	}

	exit(0);
