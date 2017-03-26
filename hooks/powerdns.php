<?php
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

			$records = array();
			$records[] = $domain->getSOARecord()->toArray();

			$hasNS = false;

			if (!$domain->isDisabled()) {
				foreach ($domain->getRecords() as $record) {
					if ($record->isDisabled()) { continue; }
					if ($record->getType() == "NS" && $record->getName() == $domain->getDomain()) {
						$hasNS = true;
					}

					$records[] = $record->toArray();
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
		HookManager::get()->addHook('records_changed', $updateMasterServer);

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
