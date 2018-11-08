<?php
	use shanemcc\phpdb\DB;

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
	function checkDBAlive() {
		global $database;

		$errmode = NULL;
		if (DB::get()->getPDO() !== NULL) {
			$errmode = DB::get()->getPDO()->getAttribute(PDO::ATTR_ERRMODE);
			try {
				DB::get()->getPDO()->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
				DB::get()->getPDO()->query("SELECT 1");
				return TRUE;
			} catch (Exception $e) { }
		}

		$pdo = new PDO(sprintf('%s:host=%s;dbname=%s', $database['type'], $database['server'], $database['database']), $database['username'], $database['password']);
		DB::get()->setPDO($pdo);

		if ($errmode !== NULL) { DB::get()->getPDO()->setAttribute(PDO::ATTR_ERRMODE, $errmode); }
	}
	checkDBAlive();

	// Template Engine
	TemplateEngine::get()->setConfig($config['templates'])->setVar('sitename', $config['sitename'])->setVar('siteurl', $config['siteurl']);

	// Mailer
	Mailer::get()->setConfig($config['email']);

	HookManager::get()->addHookType('new_user');
	HookManager::get()->addHookType('new_user_pending');
	HookManager::get()->addHookType('new_user_confirmed');
	HookManager::get()->addHookType('new_domain');
	HookManager::get()->addHookType('worker_error');

	HookManager::get()->addHookType('add_domain');
	HookManager::get()->addHookType('rename_domain');
	HookManager::get()->addHookType('delete_domain');
	HookManager::get()->addHookType('sync_domain');

	HookManager::get()->addHookType('add_record');
	HookManager::get()->addHookType('update_record');
	HookManager::get()->addHookType('delete_record');

	HookManager::get()->addHookType('records_changed');

	HookManager::get()->addHookType('send_mail');

	HookManager::get()->addHookType('call_domain_hooks');

	if ($config['jobserver']['type'] == 'gearman') {
		$gmc = new GearmanClient();
		$gmc->addServer($config['jobserver']['host'], $config['jobserver']['port']);
		$gmc->setTimeout(5000);

		HookManager::get()->addHook('send_mail', function($to, $subject, $message, $htmlmessage = NULL) use ($gmc) {
			@$gmc->doBackground('sendmail', json_encode(['to' => $to, 'subject' => $subject, 'message' => $message, 'htmlmessage' => $htmlmessage]));
		});
	} else {
		HookManager::get()->addHookBackground('send_mail', function($to, $subject, $message, $htmlmessage = NULL) {
			Mailer::get()->send($to, $subject, $message, $htmlmessage);
		});
	}

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

	// From:
	// https://stackoverflow.com/questions/1707801/making-a-temporary-dir-for-unpacking-a-zipfile-into/30010928#30010928
	function tempdir($dir = null, $prefix = 'tmp_', $mode = 0700, $maxAttempts = 1000) {
		/* Use the system temp dir by default. */
		if (is_null($dir)) {
			$dir = sys_get_temp_dir();
		}

		/* Trim trailing slashes from $dir. */
		$dir = rtrim($dir, '/');

		/* If we don't have permission to create a directory, fail, otherwise we will
		 * be stuck in an endless loop.
		 */
		if (!is_dir($dir) || !is_writable($dir)) {
			return false;
		}

		/* Make sure characters in prefix are safe. */
		if (strpbrk($prefix, '\\/:*?"<>|') !== false) {
			return false;
		}

		/* Attempt to create a random directory until it works. Abort if we reach
		 * $maxAttempts. Something screwy could be happening with the filesystem
		 * and our loop could otherwise become endless.
		 */
		$attempts = 0;
		do {
			$path = sprintf('%s/%s%s', $dir, $prefix, mt_rand(100000, mt_getrandmax()));
		} while (!mkdir($path, $mode) && $attempts++ < $maxAttempts);

		return $path;
	}

	function deleteDir($dir) {
		if (empty($dir)) { return FALSE; }

		$files = array_diff(scandir($dir), ['.', '..']);

		foreach ($files as $file) {
			is_dir($dir . '/' . $file) ? delTree($dir . '/' . $file) : unlink($dir . '/' . $file);
		}

		return rmdir($dir);
	}

	function templateToMail($templateEngine, $template) {
		$subject = trim($templateEngine->renderBlock($template, 'subject'));
		$message = $templateEngine->renderBlock($template, 'body');
		$htmlmessage = $templateEngine->renderBlock($template, 'htmlbody');

		return [$subject, $message, $htmlmessage];
	}

	function updatePublicSuffixes() {
		(new Pdp\PublicSuffixListManager())->refreshPublicSuffixList();
	}

	function isPublicSuffix($domain) {
		$domain = idn_to_ascii($domain);
		$list = (new Pdp\PublicSuffixListManager())->getList();
		$parser = new Pdp\Parser($list);

		return $domain == $parser->getPublicSuffix($domain) || array_key_exists($domain, $list);
	}

	function hasValidPublicSuffix($domain) {
		$domain = idn_to_ascii($domain);
		$list = (new Pdp\PublicSuffixListManager())->getList();
		$parser = new Pdp\Parser($list);

		return $parser->isSuffixValid($domain);
	}

	function checkSessionHandler() {
		global $config;

		if (isset($config['memcached']) && !empty($config['memcached'])) {
			ini_set('session.save_handler', 'memcached');
			ini_set('session.save_path', $config['memcached']);
		}
	}

	function getSystemRegisterRequireTerms() {
		global $config;
		return $config['register_require_terms'];
	}

	function getSystemMinimumTermsTime() {
		global $config;
		return $config['minimum_terms_time'];
	}

	function getSystemAPIMinimumTermsTime() {
		global $config;
		return $config['api_minimum_terms_time'];
	}

	function getSystemAllowSelfDelete() {
		global $config;
		return $config['self_delete'];
	}

	function getSystemRegisterPermissions() {
		global $config;
		return $config['register_permissions'];
	}

	function getSystemRegisterEnabled() {
		global $config;
		return $config['register_enabled'];
	}

	function getSystemRegisterManualVerify() {
		global $config;
		return $config['register_manual_verify'];
	}

	function getSystemDefaultSOA() {
		global $config;
		return $config['defaultSOA'];
	}

	function getSystemDefaultRecords() {
		global $config;
		return $config['defaultRecords'];
	}

	function getFromDocker($method, $json = true) {
		$info = doGetFromDocker('/info', true);
		if (isset($info['message']) && $info['message'] == 'page not found') {
			$method = '/docker' . $method;
		}

		return doGetFromDocker($method, $json);
	}

	function doGetFromDocker($method, $json = true) {
		$ch = curl_init();

		curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, '/var/run/docker.sock');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

			curl_setopt($ch, CURLOPT_URL, 'http:' . $method);

		$response = curl_exec($ch);
		if ($json) {
			$response = json_decode($response, true);
		}

		return $response;
	}

	function getLogsFromDocker($container, $since = -1) {
		if ($since == -1) { $since = time() - 3600; }
		$log = getFromDocker('/containers/'. $container .'/logs?stderr=1&stdout=1&since=' . $since . '&timestamps=1', false);

		$logs = [];
		$pos = 0;
		while ($pos < strlen($log)) {
			$type = unpack('C*', substr($log, $pos, 1))[1];
			$len = unpack('N', substr($log, $pos + 4, 4))[1];

			$str = substr($log, $pos + 8, $len);
			switch ($type) {
				case 0:
					$type = "STDIN";
					break;
				case 1:
					$type = "STDOUT";
					break;
				case 2:
					$type = "STDERR";
					break;
				default:
					$type = "UNKNOWN";
			}

			$str = explode(' ', $str, 2);
			$timestamp = $str[0];
			$str = isset($str[1]) ? $str[1] : '';

			$logs[] = [$type, $timestamp, trim($str)];

			$pos += 8 + $len;
		}

		return $logs;
	}

	function getDomainLogs($domain) {
		global $config;
		$source = explode(':', $config['domainlogs']['source'], 2);

		if ($source[0] == 'docker' && isset($source[1])) {
			$since = time() - 3600;

			$logs = [];
			foreach (getLogsFromDocker($source[1]) as $log) {
				// TODO: Better filtering of zone-specific log entries.
				if (preg_match('#(\'| |\()' . preg_quote($domain->getDomain(), '#') . '#', $log[2])) {
					$logs[] = $log[2];
				}
			}

			return $logs;

		} else {
			return FALSE;
		}
	}

	// TODO: This shouldn't rely on text files on disk if possible.
	function getDSKeys($domain) {
		global $config;

		$keyName = $config['dnssec']['dskeys'] . '/' . $domain . '.dskey';
		if (file_exists($keyName)) {
			return explode("\n", trim(file_get_contents($keyName)));
		}

		return FALSE;
	}

	function getInfluxClient() {
		global $config;
		$client = new InfluxDB\Client($config['influx']['host'], $config['influx']['port']);
		return $client;
	}

	function getInfluxDB() {
		global $config;
		$database = getInfluxClient()->selectDB($config['influx']['db']);
		if (!$database->exists()) { $database->create(); }
		return $database;
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

	function getGlobalQueriesPerServer($type = 'raw', $time = '3600') {
		try {
			$database = getInfluxDB();

			// executing a query will yield a resultset object
			$result = $database->getQueryBuilder();

			if ($type == 'derivative') {
				$result = $result->select('non_negative_derivative(max("value")) AS value');
			} else {
				$result = $result->select('max("value") AS value');
			}

			$result = $result->from('opcode_query')
			                 ->where(["time > now() - " . $time . "s"])
			                 ->groupby("time(60s)")->groupby("host")
			                 ->getResultSet();

			$stats = [];
			foreach ($result->getSeries() AS $series) {
				$host = $series['tags']['host'];
				$stats[$host] = [];

				foreach ($series['values'] as $val) {
					if ($val[1] === NULL) { continue; }
					$stat = ['time' => strtotime($val[0]), 'value' => (int)$val[1]];

					$stats[$host][] = $stat;
				}
			}

			return ['stats' => $stats];
		} catch (Exception $ex) { }

		return false;
	}

	function getGlobalQueriesPerRRType($type = 'raw', $time = '3600') {
		try {
			$database = getInfluxDB();

			// executing a query will yield a resultset object
			$result = $database->getQueryBuilder();

			if ($type == 'derivative') {
				$result = $result->select('non_negative_derivative(max("value")) AS value');
			} else {
				$result = $result->select('max("value") AS value');
			}

			$result = $result->from('qtype')
			                 ->where(["time > now() - " . $time . "s"])
			                 ->groupby("time(60s)")->groupby("qtype")
			                 ->getResultSet();

			$stats = [];
			foreach ($result->getSeries() AS $series) {
				$qtype = $series['tags']['qtype'];
				$stats[$qtype] = [];

				$total = 0;
				foreach ($series['values'] as $val) {
					if ($val[1] === NULL) { continue; }
					$stat = ['time' => strtotime($val[0]), 'value' => (int)$val[1]];
					$total += $stat['value'];

					$stats[$qtype][] = $stat;
				}
				if ($total == 0) { unset($stats[$qtype]); }
			}

			return ['stats' => $stats];
		} catch (Exception $ex) { }

		return false;
	}

	function getGlobalQueriesPerZone($type = 'raw', $time = '3600', $zones = []) {
		try {
			$database = getInfluxDB();

			// executing a query will yield a resultset object
			$result = $database->getQueryBuilder();

			if ($type == 'derivative') {
				$result = $result->select('non_negative_derivative(max("value")) AS value');
			} else {
				$result = $result->select('max("value") AS value');
			}

			$where = ["time > now() - " . $time . "s"];
			if (!empty($zones)) {
				$zoneq = [];
				foreach ($zones as $z) {
					if (Domain::validDomainName($z)) {
						$zoneq[] = "\"zone\" = '" . $z ."'";
					}
				}
				$where[] = "(" . implode(" OR ", $zoneq) . ")";
			}

			$result = $result->from('zone_qtype')
			                 ->where($where)
			                 ->groupby("time(60s)")->groupby("zone")
			                 ->getResultSet();

			$stats = [];
			foreach ($result->getSeries() AS $series) {
				$zone = $series['tags']['zone'];
				$stats[$zone] = [];

				$total = 0;
				foreach ($series['values'] as $val) {
					if ($val[1] === NULL) { continue; }
					$stat = ['time' => strtotime($val[0]), 'value' => (int)$val[1]];
					$total += $stat['value'];

					$stats[$zone][] = $stat;
				}
				if ($total == 0) { unset($stats[$zone]); }
			}

			return ['stats' => $stats];
		} catch (Exception $ex) { }

		return false;
	}

	/**
	 * Get zone statistics.
	 *
	 * @param $domain Domain object.
	 * @return TRUE if we handled this method.
	 */
	function getDomainStats($domain, $type = 'raw', $time = '3600') {
		try {
			$database = getInfluxDB();

			// executing a query will yield a resultset object
//				SELECT max("value") FROM "zone_qtype" WHERE time > now() - 1h and "zone" = 'mydnshost.co.uk' GROUP BY time(60s),"zone","qtype";
//				SELECT max("value") FROM "zone_qtype" WHERE time > now() - 1h AND zone = 'mydnshost.co.uk' GROUP BY time(60s),zone,qtype"
			$result = $database->getQueryBuilder();

			if ($type == 'derivative') {
				$result = $result->select('non_negative_derivative(max("value")) AS value');
			} else {
				$result = $result->select('max("value") AS value');
			}

			$result = $result->from('zone_qtype')
			                 ->where(["time > now() - " . $time . "s", "\"zone\" = '" . $domain->getDomain() . "'"])
			                 ->groupby("time(60s)")->groupby("zone")->groupby("qtype")
			                 ->getResultSet();

			// $results = json_decode($result->getRaw(), true);

			$stats = [];
			foreach ($result->getSeries() AS $series) {
				$type = $series['tags']['qtype'];
				$stats[$type] = [];

				foreach ($series['values'] as $val) {
					if ($val[1] === NULL) { continue; }
					$stat = ['time' => strtotime($val[0]), 'value' => (int)$val[1]];

					$stats[$type][] = $stat;
				}
			}

			return ['stats' => $stats];
		} catch (Exception $ex) { }

		return false;
	}
