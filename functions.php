<?php

	// Load Config
	if (!function_exists('getEnvOrDefault')) {
		function getEnvOrDefault($var, $default) {
			$result = getEnv($var);
			return $result === FALSE ? $default : $result;
		}
	}
	require_once(dirname(__FILE__) . '/config.php');

	// Load main classes
	require_once(dirname(__FILE__) . '/vendor/autoload.php');

	// Prep DB
	$pdo = new PDO(sprintf('%s:host=%s;dbname=%s', $database['type'], $database['server'], $database['database']), $database['username'], $database['password']);
	DB::get()->setPDO($pdo);

	// Template Engine
	TemplateEngine::get()->setConfig($config['templates'])->setVar('sitename', $config['sitename'])->setVar('siteurl', $config['siteurl']);

	// Mailer
	Mailer::get()->setConfig($config['email']);

	HookManager::get()->addHookType('add_domain');
	HookManager::get()->addHookType('rename_domain');
	HookManager::get()->addHookType('delete_domain');

	HookManager::get()->addHookType('add_record');
	HookManager::get()->addHookType('update_record');
	HookManager::get()->addHookType('delete_record');

	HookManager::get()->addHookType('records_changed');

	HookManager::get()->addHookType('send_mail');

	HookManager::get()->addHookBackground('send_mail', function($to, $subject, $message, $htmlmessage = NULL) {
		Mailer::get()->send($to, $subject, $message, $htmlmessage);
	});

	// Load the hooks
	foreach (recursiveFindFiles(__DIR__ . '/hooks') as $file) { include_once($file); }

	// Functions
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

	function startsWith($haystack, $needle) {
		$length = strlen($needle);
		return (substr($haystack, 0, $length) === $needle);
	}

	function endsWith($haystack, $needle) {
		$length = strlen($needle);
		if ($length == 0) {
			return true;
		}

		return (substr($haystack, -$length) === $needle);
	}

	function updatePublicSuffixes() {
		(new Pdp\PublicSuffixListManager())->refreshPublicSuffixList();
	}

	function isPublicSuffix($domain) {
		$list = (new Pdp\PublicSuffixListManager())->getList();
		$parser = new Pdp\Parser($list);

		return $domain == $parser->getPublicSuffix($domain) || array_key_exists($domain, $list);
	}

	class bcrypt {
		public static function hash($password, $work_factor = 0) {
			if ($work_factor > 0) { $options = ['cost' => $work_factor]; }
			return password_hash($password, PASSWORD_DEFAULT);
		}

		public static function check($password, $stored_hash, $legacy_handler = NULL) {
			return password_verify($password, $stored_hash);
		}
	}
