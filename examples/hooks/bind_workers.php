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

	// --------------------
	// $config['hooks']['bind']['enabled'] = 'true';
	// $config['hooks']['bind']['catalogZoneFile'] = '/tmp/bindzones/catalog.db';
	// $config['hooks']['bind']['catalogZoneName'] = 'catalog.invalid';
	// $config['hooks']['bind']['zonedir'] = '/tmp/bindzones';
	// $config['hooks']['bind']['slaveServers'] = ['1.1.1.1', '2.2.2.2', '3.3.3.3'];

	if (isset($config['hooks']['bind']['enabled']) && parseBool($config['hooks']['bind']['enabled'])) {
		if ($config['jobserver']['type'] == 'gearman') {
			HookManager::get()->addHook('add_domain', function($domain) use ($gmc) {
				@$gmc->doBackground('bind_add_domain', json_encode(['domain' => $domain->getDomainRaw()]));
			});

			HookManager::get()->addHook('rename_domain', function($oldName, $domain) use ($gmc) {
				@$gmc->doBackground('bind_rename_domain', json_encode(['oldName' => $oldName, 'domain' => $domain->getDomainRaw()]));
			});

			HookManager::get()->addHook('delete_domain', function($domain) use ($gmc) {
				@$gmc->doBackground('bind_delete_domain', json_encode(['domain' => $domain->getDomainRaw()]));
			});

			HookManager::get()->addHook('records_changed', function($domain) use ($gmc) {
				$domains = [];
				$domains[] = $domain;

				$checkDomains = $domain->getAliases();

				while ($alias = array_shift($checkDomains)) {
					$domains[] = $alias;
					$checkDomains = array_merge($checkDomains, $alias->getAliases());
				}

				foreach ($domains as $d) {
					@$gmc->doBackground('bind_records_changed', json_encode(['domain' => $d->getDomainRaw()]));
				}
			});

			HookManager::get()->addHook('sync_domain', function($domain) use ($gmc) {
				@$gmc->doBackground('job_sequence', json_encode(['jobs' => [['job' => 'bind_records_changed', 'args' => ['domain' => $domain->getDomainRaw()]],
				                                                            ['job' => 'bind_zone_changed', 'args' => ['domain' => $domain->getDomainRaw(), 'change' => 'readd']],
				                                                           ]
				                                                 ]));
			});
		}

	}

/*
	require_once('/dnsapi/functions.php');
	echo $gmc->doNormal('', json_encode([]));
*/
