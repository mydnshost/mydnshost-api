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
		if ($config['jobserver']['type'] == 'gearman') {
			HookManager::get()->addHook('add_domain', function($domain) use ($gmc) {
				@$gmc->doNormal('bind_add_domain', json_encode(['domain' => $domain->getDomainRaw()]));
			});

			HookManager::get()->addHook('rename_domain', function($oldName, $domain) use ($gmc) {
				@$gmc->doNormal('bind_rename_domain', json_encode(['oldName' => $oldName, 'domain' => $domain->getDomainRaw()]));
			});

			HookManager::get()->addHook('delete_domain', function($domain) use ($gmc) {
				@$gmc->doNormal('bind_delete_domain', json_encode(['domain' => $domain->getDomainRaw()]));
			});

			HookManager::get()->addHook('records_changed', function($domain) use ($gmc) {
				@$gmc->doNormal('bind_records_changed', json_encode(['domain' => $domain->getDomainRaw()]));
			});
		}

	}
