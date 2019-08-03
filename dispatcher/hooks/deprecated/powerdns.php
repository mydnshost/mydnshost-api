<?php

	//
	// THIS WILL NOT WORK, THIS HAS NOT BEEN UPDATED SINCE MOVING TO WORKERS AND RABBITMQ.
	//

	// --------------------
	// PowerDNS
	// --------------------
	// masters = Array of "master" servers to add/update/remove zones on
	// slaves = Array of additional slave servers that zones should be removed from
	// --------------------
	// $config['hooks']['powerdns']['enabled'] = 'true';
	// $config['hooks']['powerdns']['masters'] = [['host' => '127.0.0.1', 'port' => '1080', 'apikey' => 'myapikey', 'zonetype' => 'master']];
	// $config['hooks']['powerdns']['slaves'] = [['host' => '192.168.0.2', 'port' => '1080', 'apikey' => 'myapikey'],
	//                                  ['host' => '192.168.0.3', 'port' => '1080', 'apikey' => 'myapikey']];

	if (isset($config['hooks']['powerdns']['enabled']) && parseBool($config['hooks']['powerdns']['enabled'])) {

		$config['hooks']['powerdns']['defaults']['masters'] = [];
		$config['hooks']['powerdns']['defaults']['slaves'] = [];

		foreach ($config['hooks']['powerdns']['defaults'] as $setting => $value) {
			if (!isset($config['hooks']['powerdns'][$setting])) {
				$config['hooks']['powerdns'][$setting] = $value;
			}
		}

		$pdnsConfig = $config['hooks']['powerdns'];

		$updateMasterServer = function($domain) use ($pdnsConfig) {
			if (count($pdnsConfig['masters']) < 1) { return; }

			$recordDomain = ($domain->getAliasOf() != null) ? $domain->getAliasDomain(true) : $domain;

			$records = array();
			$soa = $recordDomain->getSOARecord()->toArray();
			if ($recordDomain != $domain) { $soa['name'] = preg_replace('#' . preg_quote($recordDomain->getDomainRaw()) . '.$#', $domain->getDomainRaw() . '.', $soa['name']); }

			$records[] = $soa;

			$hasNS = false;

			if (!$domain->isDisabled()) {
				foreach ($recordDomain->getRecords() as $record) {
					if ($record->isDisabled()) { continue; }
					if ($record->getType() == "NS" && $record->getName() == $recordDomain->getDomain()) {
						$hasNS = true;
					}

					$r = $record->toArray();
					if ($recordDomain != $domain) {
						$r['name'] = preg_replace('#' . preg_quote($recordDomain->getDomainRaw()) . '.$#', $domain->getDomainRaw() . '.', $r['name']);

						if (in_array($record->getType(), ['CNAME', 'NS', 'MX', 'PTR', 'SRV'])) {
							$r['content'] = preg_replace('#' . preg_quote($recordDomain->getDomainRaw()) . '.$#', $domain->getDomainRaw() . '.', $r['name']);
						}
					}
					$records[] = $r;
				}
			}

			if ($hasNS) {
				foreach ($pdnsConfig['masters'] as $server) {
					$pdns = new PowerDNS($server, $domain->getDomain());
					if (!$pdns->domainExists()) {
						$zonetype = array_key_exists('zonetype', $server) ? $server['zonetype'] : 'native';
						$pdns->createDomain($zonetype);
					}
					$pdns->setRecords($records);
					$pdns->notify();
				}
			} else if ($pdns->domainExists()) {
				$pdns->removeDomain();
			}
		};

		HookManager::get()->addHook('add_domain', $updateMasterServer);
		HookManager::get()->addHook('records_changed', function($domain) {
			$domains = [];
			$domains[] = $domain;

			$checkDomains = $domain->getAliases();

			while ($alias = array_shift($checkDomains)) {
				$domains[] = $alias;
				$checkDomains = array_merge($checkDomains, $alias->getAliases());
			}

			foreach ($domains as $d) {
				call_user_func_array($updateMasterServer, [$d]);
			}
		);

		HookManager::get()->addHook('rename_domain', function($oldName, $domain) {
			foreach (array_merge($pdnsConfig['masters'], $pdnsConfig['slaves']) as $server) {
				$pdns = new PowerDNS($server, $oldName);
				$pdns->removeDomain();
			}

			call_user_func_array($updateMasterServer, [$domain]);
		});

		HookManager::get()->addHook('delete_domain', function($domain) use ($pdnsConfig) {
			foreach (array_merge($pdnsConfig['masters'], $pdnsConfig['slaves']) as $server) {
				$pdns = new PowerDNS($server, $domain->getDomain());
				$pdns->removeDomain();
			}
		});
	}
