<?php

	function getEnvOrDefault($var, $default) {
		$result = getEnv($var);
		return $result === FALSE ? $default : $result;
	}
	require_once(dirname(__FILE__) . '/config.php');
	require_once(dirname(__FILE__) . '/classes/hookmanager.php');
	require_once(dirname(__FILE__) . '/classes/db.php');
	require_once(dirname(__FILE__) . '/classes/search.php');
	require_once(dirname(__FILE__) . '/classes/searchtoobject.php');
	require_once(dirname(__FILE__) . '/classes/dbobject.php');
	require_once(dirname(__FILE__) . '/classes/domain.php');
	require_once(dirname(__FILE__) . '/classes/record.php');
	require_once(dirname(__FILE__) . '/classes/user.php');
	require_once(dirname(__FILE__) . '/classes/apikey.php');

	$pdo = new PDO(sprintf('%s:host=%s;dbname=%s', $database['type'], $database['server'], $database['database']), $database['username'], $database['password']);
	DB::get()->setPDO($pdo);

	// Prepare the hook manager.
	HookManager::get()->addHookType('add_domain');
	HookManager::get()->addHookType('update_domain');
	HookManager::get()->addHookType('delete_domain');
	HookManager::get()->addHookType('records_changed');
	HookManager::get()->addHookType('add_record');
	HookManager::get()->addHookType('update_record');
	HookManager::get()->addHookType('delete_record');

	foreach (recursiveFindFiles(__DIR__ . '/hooks') as $file) { include_once($file); }
	foreach (recursiveFindFiles(__DIR__ . '/hooks.local') as $file) { include_once($file); }

	function recursiveFindFiles($dir) {
		if (!file_exists($dir)) { return; }

		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
		foreach($it as $file) {
			if (pathinfo($file, PATHINFO_EXTENSION) == "php") {
				yield $file;
			}
		}
	}

	function parseBool($input) {
		$in = strtolower($input);
		return ($in === true || $in == 'true' || $in == '1' || $in == 'on' || $in == 'yes');
	}

	function genUUID() {
		return sprintf('%04X%04X-%04X-%04X-%04X-%04X%04X%04X', mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(16384, 20479), mt_rand(32768, 49151), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535));
	}

	class bcrypt {
		const defaultWorkFactor = 8;

		private static function pseudoRandomKey($size) {
			if (function_exists('openssl_random_pseudo_bytes')) {
				$rnd = openssl_random_pseudo_bytes($size, $strong);
				if ($strong === TRUE) {
					return $rnd;
				}
			}

			$sha='';
			$rnd='';
			for ($i = 0; $i < $size; $i++) {
				$sha = hash('sha256', $sha . mt_rand());
				$char = mt_rand(0, 62);
				$rnd .= chr(hexdec($sha[$char] . $sha[$char + 1]));
			}

			return $rnd;
		}

		public static function hash($password, $work_factor = 0) {
			if (version_compare(PHP_VERSION, '5.3') < 0) throw new Exception('Bcrypt requires PHP 5.3 or above');

			if ($work_factor < 4 || $work_factor > 31) {
				$work_factor = self::defaultWorkFactor;
			}

			$salt = '$2a$' . str_pad($work_factor, 2, '0', STR_PAD_LEFT) . '$' . substr(strtr(base64_encode(self::pseudoRandomKey(16)), '+', '.'),  0, 22);
			return crypt($password, $salt);
		}

		public static function check($password, $stored_hash, $legacy_handler = NULL) {
			if (version_compare(PHP_VERSION, '5.3') < 0) throw new Exception('Bcrypt requires PHP 5.3 or above');

			return crypt($password, $stored_hash) == $stored_hash;
		}
	}
