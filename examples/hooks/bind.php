<?php
	use shanemcc\phpdb\DB;
	use shanemcc\phpdb\Search;

	// --------------------
	// Bind
	// --------------------
	// This could also be used by other servers that support bind zonefiles
	// --------------------
	// zonedir = Directory to put zone files
	// catalogZone = If non-empty, this file (full path) will be used as a
	//               catalog zone.
	// addZoneCommand = Command to run to load new zones
	// reloadZoneCommand = Command to run to refresh zones
	// delZoneCommand = Command to run to remove zones	//
	// --------------------
	// $config['hooks']['bind']['enabled'] = 'true';
	// $config['hooks']['bind']['catalogZoneFile'] = '/tmp/bindzones/catalog.db';
	// $config['hooks']['bind']['catalogZoneName'] = 'catalog.invalid';
	// $config['hooks']['bind']['zonedir'] = '/tmp/bindzones';
	// $config['hooks']['bind']['addZoneCommand'] = 'chmod a+rwx %2$s; /usr/bin/sudo -n /usr/sbin/rndc addzone %1$s \'{type master; file "%2$s";};\' >/dev/null 2>&1';
	// $config['hooks']['bind']['reloadZoneCommand'] = 'chmod a+rwx %2$s; /usr/bin/sudo -n /usr/sbin/rndc reload %1$s >/dev/null 2>&1';
	// $config['hooks']['bind']['delZoneCommand'] = '/usr/bin/sudo -n /usr/sbin/rndc delzone %1$s >/dev/null 2>&1';
	// $config['hooks']['bind']['slaveServers'] = ['1.1.1.1', '2.2.2.2', '3.3.3.3'];

	if (isset($config['hooks']['bind']['enabled']) && parseBool($config['hooks']['bind']['enabled'])) {
		// Default config settings
		$config['hooks']['bind']['defaults']['zonedir'] = '/etc/bind/zones';
		$config['hooks']['bind']['defaults']['addZoneCommand'] = 'chmod a+rwx %2$s; /usr/bin/sudo -n /usr/sbin/rndc addzone %1$s \'{type master; file "%2$s"; allow-transfer { %3$s };};\' >/dev/null 2>&1';
		$config['hooks']['bind']['defaults']['reloadZoneCommand'] = 'chmod a+rwx %2$s; /usr/bin/sudo -n /usr/sbin/rndc reload %1$s >/dev/null 2>&1';
		$config['hooks']['bind']['defaults']['delZoneCommand'] = '/usr/bin/sudo -n /usr/sbin/rndc delzone %1$s >/dev/null 2>&1';
		$config['hooks']['bind']['defaults']['catalogZoneFile'] = '/etc/bind/zones/catalog.db';
		$config['hooks']['bind']['defaults']['catalogZoneName'] = 'catalog.invalid';
		$config['hooks']['bind']['defaults']['slaveServers'] = [];

		foreach ($config['hooks']['bind']['defaults'] as $setting => $value) {
			if (!isset($config['hooks']['bind'][$setting])) {
				$config['hooks']['bind'][$setting] = $value;
			}
		}

		@mkdir($config['hooks']['bind']['zonedir'], 0777, true);
		$bindConfig = $config['hooks']['bind'];

		$writeZoneFile = function($domain) use ($bindConfig) {
			$bind = new Bind($domain->getDomain(), $bindConfig['zonedir']);
			list($filename, $filename2) = $bind->getFileNames();
			$new = !file_exists($filename);
			$bind->clearRecords();

			$soa = $domain->getSOARecord()->parseSOA();
			$bindSOA = array('Nameserver' => $soa['primaryNS'],
			                 'Email' => $soa['adminAddress'],
			                 'Serial' => $soa['serial'],
			                 'Refresh' => $soa['refresh'],
			                 'Retry' => $soa['retry'],
			                 'Expire' => $soa['expire'],
			                 'MinTTL' => $soa['minttl']);

			$bind->setSOA($bindSOA);

			$hasNS = false;

			if (!$domain->isDisabled()) {
				foreach ($domain->getRecords() as $record) {
					if ($record->isDisabled()) { continue; }

					$name = $record->getName() . '.';
					$content = $record->getContent();
					if ($record->getType() == "TXT") {
						$content = '"' . $record->getContent() . '"';
					} else if (in_array($record->getType(), ['CNAME', 'NS', 'MX', 'SRV', 'PTR'])) {
						$content = $record->getContent() . '.';
					}

					if ($record->getType() == "NS" && $record->getName() == $domain->getDomain()) {
						$hasNS = true;
					}

					$bind->setRecord($name, $record->getType(), $content, $record->getTTL(), $record->getPriority());
				}
			}

			// Bind requires an NS record to load the zone, don't bother
			// attempting to add/change unless there is one.
			//
			// This means that the zone won't be added until it is actually
			// valid.
			if ($hasNS) {
				$bind->saveZoneFile();
				if ($new) {
					HookManager::get()->handle('bind_zone_added', [$domain, $bind, $bindConfig]);
				} else {
					HookManager::get()->handle('bind_zone_changed', [$domain, $bind, $bindConfig]);
				}
			} else if (file_exists($filename)) {
				unlink($filename);
				HookManager::get()->handle('bind_zone_removed', [$domain, $bind, $bindConfig]);
			}
		};

		HookManager::get()->addHookType('bind_rebuild_catalog');
		HookManager::get()->addHookType('bind_readd_zones');
		HookManager::get()->addHookType('bind_rebuild_zones');

		HookManager::get()->addHookType('bind_zone_added');
		HookManager::get()->addHookType('bind_zone_changed');
		HookManager::get()->addHookType('bind_zone_removed');

		HookManager::get()->addHook('add_domain', $writeZoneFile);
		HookManager::get()->addHook('records_changed', $writeZoneFile);

		HookManager::get()->addHook('rename_domain', function($oldName, $domain) use ($bindConfig, $writeZoneFile) {
			$bind = new Bind($oldName, $bindConfig['zonedir']);
			list($filename, $filename2) = $bind->getFileNames();
			if (file_exists($filename)) {
				@unlink($filename);
			}
			$oldDomain = $domain->clone()->setDomain($oldName);
			HookManager::get()->handle('bind_zone_removed', [$oldDomain, $bind, $bindConfig]);

			call_user_func_array($writeZoneFile, [$domain]);
		});


		HookManager::get()->addHook('delete_domain', function($domain) use ($bindConfig) {
			$bind = new Bind($domain->getDomain(), $bindConfig['zonedir']);
			list($filename, $filename2) = $bind->getFileNames();
			if (file_exists($filename)) {
				@unlink($filename);
			}
			HookManager::get()->handle('bind_zone_removed', [$domain, $bind, $bindConfig]);
		});

		class BindCommandRunner {
			private $command;
			public function __construct($command) { $this->command = $command; }
			public function run($domain, $bind, $bindConfig) {
				list($filename, $filename2) = $bind->getFileNames();

				$ips = getAllowedIPs($bindConfig, $domain, false);
				if (empty($ips)) {
					$ips[] = '"none"';
				}
				$ips[] = '';

				$cmd = sprintf($this->command, escapeshellarg($domain->getDomainRaw()), escapeshellarg($filename), implode('; ', $ips));
				exec($cmd);
			}
		}

		HookManager::get()->addHook('bind_zone_added', [new BindCommandRunner($bindConfig['addZoneCommand']), 'run']);
		HookManager::get()->addHook('bind_zone_changed', [new BindCommandRunner($bindConfig['reloadZoneCommand']), 'run']);
		HookManager::get()->addHook('bind_zone_removed', [new BindCommandRunner($bindConfig['delZoneCommand']), 'run']);

		HookManager::get()->addHook('bind_zone_added', function ($domain, $bind) use ($bindConfig) { updateCatalogZone($bindConfig, $domain, 'added'); });
		HookManager::get()->addHook('bind_zone_changed', function ($domain, $bind) use ($bindConfig) { updateCatalogZone($bindConfig, $domain, 'changed'); });
		HookManager::get()->addHook('bind_zone_removed', function ($domain, $bind) use ($bindConfig) { updateCatalogZone($bindConfig, $domain, 'removed'); });

		function getAllowedIPs($bindConfig, $domain, $APL) {
			global $__BIND__DNSCACHE;

			// Get NS Records
			$NS = [];
			foreach ($domain->getRecords() as $record) {
				if ($record->isDisabled()) { continue; }
				if ($record->getType() == "NS" && $record->getName() == $domain->getDomain()) {
					$NS[] = $record->getContent();
				}
			}

			$ips = [];
			foreach ($NS as $host) {
				if (!isset($__BIND__DNSCACHE[$host])) {
					$__BIND__DNSCACHE[$host] = dns_get_record($host, DNS_A | DNS_AAAA);
				}
				$records = $__BIND__DNSCACHE[$host];

				foreach ($records as $rr) {
					if ($rr['type'] == 'A') {
						$ips[] = ($APL) ? '1:' . $rr['ip'] . '/32' : $rr['ip'];
					} else if ($rr['type'] == 'AAAA') {
						$ips[] = ($APL) ? '2:' . $rr['ipv6'] . '/128' : $rr['ipv6'];
					}
				}
			}

			// Add slave IPs
			$slaveServers = is_array($bindConfig['slaveServers']) ? $bindConfig['slaveServers'] : explode(',', $bindConfig['slaveServers']);
			foreach ($slaveServers as $s) {
				$s = trim($s);

				if (filter_var($s, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
					$ips[] = ($APL) ? '1:' . $s . '/32' : $s;
				} else if (filter_var($s, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
					$ips[] = ($APL) ? '2:' . $s . '/128' : $s;
				}
			}

			return array_unique($ips);
		}

		function addCatalogRecords($bindConfig, $bind, $domain) {
			$hash = sha1("\7" . str_replace(".", "\3", $domain->getDomainRaw()) . "\0");

			$bind->setRecord($hash . '.zones', 'PTR', $domain->getDomainRaw() . '.');

			// Convert NS Records to IPs
			$ips = getAllowedIPs($bindConfig, $domain, true);

			// Save IPs
			if (!empty($ips)) {
				$bind->setRecord('allow-transfer.' . $hash . '.zones', 'APL', implode(' ', $ips));
			}
		}

		function updateCatalogZone($bindConfig, $domain, $mode = 'removed') {
			// Update the catalog
			if (!empty($bindConfig['catalogZoneName']) && !empty($bindConfig['catalogZoneFile']) && file_exists($bindConfig['catalogZoneFile'])) {
				$fp = fopen($bindConfig['catalogZoneFile'] . '.lock', 'r+');
				if (flock($fp, LOCK_EX)) {
					$bind = new Bind($bindConfig['catalogZoneName'], '', $bindConfig['catalogZoneFile']);

					$bind->parseZoneFile();
					$bindSOA = $bind->getSOA();
					$bindSOA['Serial']++;
					$bind->setSOA($bindSOA);

					$hash = sha1("\7" . str_replace(".", "\3", $domain->getDomainRaw()) . "\0");

					$oldAllowTransfer = $bind->getRecords('allow-transfer.' . $hash . '.zones', 'APL');
					if (!empty($oldAllowTransfer)) { $oldAllowTransfer = $oldAllowTransfer[0]; }

					$bind->unsetRecord($hash . '.zones', 'PTR');
					$bind->unsetRecord('allow-transfer.' . $hash . '.zones', 'APL');
					if ($mode == 'added' || $mode == 'changed') {
						addCatalogRecords($bindConfig, $bind, $domain);
					}

					if ($mode == 'changed') {
						if (!empty($oldAllowTransfer)) {
							$newAllowTransfer = $bind->getRecords('allow-transfer.' . $hash . '.zones', 'APL');
							if (!empty($newAllowTransfer) && $newAllowTransfer != $oldAllowTransfer) {
								// Allowed-Transfer list has changed, re-add domain to bind
								$zoneBind = new Bind($domain->getDomain(), $bindConfig['zonedir']);
								call_user_func_array([new BindCommandRunner($bindConfig['delZoneCommand']), 'run'], [$domain, $zoneBind, $bindConfig]);
								call_user_func_array([new BindCommandRunner($bindConfig['addZoneCommand']), 'run'], [$domain, $zoneBind, $bindConfig]);
							} else {
								// Transfer list has not changed, abort.
								flock($fp, LOCK_UN);
								fclose($fp);
								return;
							}
						}
					}
					$bind->saveZoneFile($bindConfig['catalogZoneFile']);

					$cmd = sprintf($bindConfig['reloadZoneCommand'], escapeshellarg($bindConfig['catalogZoneName']), $bindConfig['catalogZoneFile']);
					exec($cmd);

					flock($fp, LOCK_UN);
					fclose($fp);
				}
			}
		}

		// Hook to rebuild all zone files.
		HookManager::get()->addHook('bind_rebuild_zones', function () use ($bindConfig, $writeZoneFile) {
			$s = new Search(DB::get()->getPDO(), 'domains', ['domain', 'disabled']);
			$s->order('domain');
			$rows = $s->getRows();

			foreach ($rows as $row) {
				$domain = Domain::loadFromDomain(DB::get(), $row['domain']);
				call_user_func_array($writeZoneFile, [$domain]);
			}
		});

		// Hook to remove and re-add all zones to bind.
		HookManager::get()->addHook('bind_readd_zones', function () use ($bindConfig) {
			$s = new Search(DB::get()->getPDO(), 'domains', ['domain', 'disabled']);
			$s->order('domain');
			$rows = $s->getRows();

			$add = [new BindCommandRunner($bindConfig['addZoneCommand']), 'run'];
			$del = [new BindCommandRunner($bindConfig['delZoneCommand']), 'run'];

			foreach ($rows as $row) {
				$domain = Domain::loadFromDomain(DB::get(), $row['domain']);
				$bind = new Bind($domain->getDomain(), $bindConfig['zonedir']);

				call_user_func_array($del, [$domain, $bind, $bindConfig]);

				if (strtolower($row['disabled']) == 'true') { continue; }
				call_user_func_array($add, [$domain, $bind, $bindConfig]);
			}
		});

		// Hook to rebuild the whole catalog.
		HookManager::get()->addHook('bind_rebuild_catalog', function ($zoneName, $zoneFile) use ($bindConfig) {
			$fp = fopen($zoneFile . '.lock', 'r+');
			if (flock($fp, LOCK_EX)) {
				$bind = new Bind($zoneName, '', $zoneFile);
				$bind->parseZoneFile();
				$bindSOA = $bind->getSOA();
				$bindSOA['Serial']++;
				$bind->clearRecords();
				$bind->setSOA($bindSOA);

				$bind->setRecord('@', 'NS', 'invalid.', '3600', '');
				$bind->setRecord('version', 'TXT', '1', '3600', '');

				$s = new Search(DB::get()->getPDO(), 'domains', ['domain', 'disabled']);
				$s->order('domain');
				$rows = $s->getRows();

				foreach ($rows as $row) {
					if (strtolower($row['disabled']) == 'true') { continue; }

					$domain = Domain::loadFromDomain(DB::get(), $row['domain']);

					if ($domain != FALSE) {
						foreach ($domain->getRecords() as $record) {
							if ($record->isDisabled()) { continue; }
							if ($record->getType() == "NS" && $record->getName() == $domain->getDomain()) {
								addCatalogRecords($bindConfig, $bind, $domain);
								break;
							}
						}
					}
				}

				$bind->saveZoneFile($zoneFile);
				chmod($zoneFile, 0777);

				$cmd = sprintf($bindConfig['reloadZoneCommand'], $zoneName, $zoneFile);
				exec($cmd);

				flock($fp, LOCK_UN);
				fclose($fp);
			}
		});

		if (!empty($bindConfig['catalogZoneName']) && !empty($bindConfig['catalogZoneFile']) && !file_exists($bindConfig['catalogZoneFile'])) {
			file_put_contents($bindConfig['catalogZoneFile'], '');
			$bind = new Bind($bindConfig['catalogZoneName'], '', $bindConfig['catalogZoneFile']);
			$bind->clearRecords();

			$bindSOA = array('Nameserver' => '.',
			                 'Email' => '.',
			                 'Serial' => '0',
			                 'Refresh' => '86400',
			                 'Retry' => '3600',
			                 'Expire' => '86400',
			                 'MinTTL' => '3600');
			$bind->setSOA($bindSOA);
			$bind->setRecord('@', 'NS', 'invalid.', '3600', '');
			$bind->setRecord('version', 'TXT', '1', '3600', '');
			$bind->saveZoneFile($bindConfig['catalogZoneFile']);
			chmod($bindConfig['catalogZoneFile'], 0777);

			file_put_contents($bindConfig['catalogZoneFile'] . '.lock', '');
			chmod($bindConfig['catalogZoneFile'] . '.lock', 0777);

			HookManager::get()->handle('bind_rebuild_catalog', [$bindConfig['catalogZoneName'], $bindConfig['catalogZoneFile']]);
		}
	}

/*
	require_once('/dnsapi/functions.php');
	$bindConfig = $config['hooks']['bind'];
	HookManager::get()->handle('bind_rebuild_zones');


	require_once('/dnsapi/functions.php');
	$bindConfig = $config['hooks']['bind'];
	HookManager::get()->handle('bind_rebuild_catalog', [$bindConfig['catalogZoneName'], $bindConfig['catalogZoneFile']]);
	$cmd = sprintf($bindConfig['reloadZoneCommand'], $bindConfig['catalogZoneName'], $bindConfig['catalogZoneFile']);
	exec($cmd);


	require_once('/dnsapi/functions.php');
	$bindConfig = $config['hooks']['bind'];
	HookManager::get()->handle('bind_readd_zones');
*/
