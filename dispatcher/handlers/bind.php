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
		EventQueue::get()->subscribe('domain.add', function($domainid) {
			$domain = Domain::load(DB::get(), $domainid);

			dispatchJob('bind_add_domain', ['domain' => $domain->getDomainRaw()]);
		});

		EventQueue::get()->subscribe('domain.rename', function($oldName, $domainid) {
			$domain = Domain::load(DB::get(), $domainid);

			dispatchJob('bind_rename_domain', ['oldName' => $oldName, 'domain' => $domain->getDomainRaw()]);
		});

		EventQueue::get()->subscribe('domain.delete', function($domainid, $domainRaw) {
			dispatchJob('bind_delete_domain', ['domain' => $domainRaw]);
		});

		EventQueue::get()->subscribe('changed.records', function($domainid) {
			$domain = Domain::load(DB::get(), $domainid);

			$domains = [];
			$domains[] = $domain;

			$checkDomains = $domain->getAliases();

			while ($alias = array_shift($checkDomains)) {
				$domains[] = $alias;
				$checkDomains = array_merge($checkDomains, $alias->getAliases());
			}

			foreach ($domains as $d) {
				dispatchJob('bind_records_changed', ['domain' => $d->getDomainRaw()]);
			}
		});

		EventQueue::get()->subscribe('domain.sync', function($domainid) {
			$domain = Domain::load(DB::get(), $domainid);

			dispatchJob('job_sequence', ['jobs' => [['job' => 'bind_zone_changed', 'args' => ['domain' => $domain->getDomainRaw(), 'change' => 'remove']],
			                                        ['wait' => '1', 'job' => 'bind_records_changed', 'args' => ['domain' => $domain->getDomainRaw()]],
			                                        ['job' => 'bind_zone_changed', 'args' => ['domain' => $domain->getDomainRaw(), 'change' => 'add']],
			                                       ]
			                             ]);
		});
	}

/*
	require_once('/dnsapi/functions.php');
	print_r(JobQueue::get()->publishAndWait('', []));
*/
