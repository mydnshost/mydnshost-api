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
	HookManager::get()->addHookType('new_domain');

	HookManager::get()->addHookType('add_domain');
	HookManager::get()->addHookType('rename_domain');
	HookManager::get()->addHookType('delete_domain');
	HookManager::get()->addHookType('sync_domain');

	HookManager::get()->addHookType('add_record');
	HookManager::get()->addHookType('update_record');
	HookManager::get()->addHookType('delete_record');

	HookManager::get()->addHookType('records_changed');

	HookManager::get()->addHookType('send_mail');

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
				$result = $result->select('non_negative_derivative(sum("value")) AS value');
			} else {
				$result = $result->select('sum("value") AS value');
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
				$result = $result->select('non_negative_derivative(sum("value")) AS value');
			} else {
				$result = $result->select('sum("value") AS value');
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
				$result = $result->select('non_negative_derivative(sum("value")) AS value');
			} else {
				$result = $result->select('sum("value") AS value');
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
//				SELECT sum("value") FROM "zone_qtype" WHERE time > now() - 1h and "zone" = 'mydnshost.co.uk' GROUP BY time(60s),"zone","qtype";
//				SELECT sum("value") FROM "zone_qtype" WHERE time > now() - 1h AND zone = 'mydnshost.co.uk' GROUP BY time(60s),zone,qtype"
			$result = $database->getQueryBuilder();

			if ($type == 'derivative') {
				$result = $result->select('non_negative_derivative(sum("value")) AS value');
			} else {
				$result = $result->select('sum("value") AS value');
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
