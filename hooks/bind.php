<?php
	if (isset($config['bind']['enabled']) && parseBool($config['bind']['enabled'])) {
		// Default config settings
		$config['bind']['defaults']['zonedir'] = '/etc/bind/zones';
		$config['bind']['defaults']['addZoneCommand'] = 'chmod a+rwx %2$s; /usr/bin/sudo -n /usr/sbin/rndc addzone %1$s \'{type master; file "%2$s";};\' >/dev/null 2>&1';
		$config['bind']['defaults']['reloadZoneCommand'] = 'chmod a+rwx %2$s; /usr/bin/sudo -n /usr/sbin/rndc reload %1$s >/dev/null 2>&1';
		$config['bind']['defaults']['delZoneCommand'] = '/usr/bin/sudo -n /usr/sbin/rndc delzone %1$s >/dev/null 2>&1';

		foreach ($config['bind']['defaults'] as $setting => $value) {
			if (!isset($config['bind'][$setting])) {
				$config['bind'][$setting] = $value;
			}
		}

		@mkdir($config['bind']['zonedir'], 0777, true);
		$bindConfig = $config['bind'];

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

			foreach ($domain->getRecords() as $record) {
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

			// Bind requires an NS record to load the zone, don't bother
			// attempting to add/change unless there is one.
			//
			// This means that the zone won't be added until it is actually
			// valid.
			if ($hasNS) {
				$bind->saveZoneFile();
				if ($new) {
					HookManager::get()->handle('bind_zone_added', [$domain, $bind]);
				} else {
					HookManager::get()->handle('bind_zone_changed', [$domain, $bind]);
				}
			}
		};

		HookManager::get()->addHookType('bind_zone_added');
		HookManager::get()->addHookType('bind_zone_changed');
		HookManager::get()->addHookType('bind_zone_removed');

		HookManager::get()->addHook('add_domain', $writeZoneFile);
		HookManager::get()->addHook('records_changed', $writeZoneFile);

		HookManager::get()->addHook('rename_domain', function($oldName, $domain) {
			$bind = new Bind($oldName, $bindConfig['zonedir']);
			list($filename, $filename2) = $bind->getFileNames();
			if (file_exists($filename)) {
				@unlink($filename);
			}
			$oldDomain = $domain->clone()->setDomain($oldName);
			HookManager::get()->handle('bind_zone_removed', [$oldDomain, $bind]);

			call_user_func_array($writeZoneFile, [$domain]);
		});


		HookManager::get()->addHook('delete_domain', function($domain) use ($bindConfig) {
			$bind = new Bind($domain->getDomain(), $bindConfig['zonedir']);
			list($filename, $filename2) = $bind->getFileNames();
			if (file_exists($filename)) {
				@unlink($filename);
			}
			HookManager::get()->handle('bind_zone_removed', [$domain, $bind]);
		});

		class BindCommandRunner {
			private $command;
			public function __construct($command) { $this->command = $command; }
			public function run($domain, $bind) {
				list($filename, $filename2) = $bind->getFileNames();

				$cmd = sprintf($this->command, escapeshellarg($domain->getDomain()), $filename);
				exec($cmd);
			}
		}

		HookManager::get()->addHook('bind_zone_added', [new BindCommandRunner($bindConfig['addZoneCommand']), 'run']);
		HookManager::get()->addHook('bind_zone_changed', [new BindCommandRunner($bindConfig['reloadZoneCommand']), 'run']);
		HookManager::get()->addHook('bind_zone_removed', [new BindCommandRunner($bindConfig['delZoneCommand']), 'run']);
	}
