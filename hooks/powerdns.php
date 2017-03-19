<?php
	if (isset($config['powerdns']['enabled']) && parseBool($config['powerdns']['enabled'])) {

		$config['powerdns']['defaults']['masters'] = [];
		$config['powerdns']['defaults']['slaves'] = [];

		foreach ($config['powerdns']['defaults'] as $setting => $value) {
			if (!isset($config['powerdns'][$setting])) {
				$config['powerdns'][$setting] = $value;
			}
		}

		$pdnsConfig = $config['powerdns'];

		$updateMasterServer = function($domain) use ($pdnsConfig) {
			if (count($pdnsConfig['masters']) < 1) { return; }

			$records = array();
			$records[] = $domain->getSOARecord()->toArray();

			$hasNS = false;

			foreach ($domain->getRecords() as $record) {
				if ($record->getType() == "NS" && $record->getName() == $domain->getDomain()) {
					$hasNS = true;
				}

				$records[] = $record->toArray();
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
			}
		};

		HookManager::get()->addHook('add_domain', $updateMasterServer);
		HookManager::get()->addHook('update_domain', $updateMasterServer);
		HookManager::get()->addHook('records_changed', $updateMasterServer);

		HookManager::get()->addHook('delete_domain', function($domain) use ($pdnsConfig) {
			foreach (array_merge($pdnsConfig['masters'], $pdnsConfig['slaves']) as $server) {
				$pdns = new PowerDNS($server, $domain->getDomain());
				$pdns->removeDomain();
			}
		});
	}
