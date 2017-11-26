<?php
	if ($config['jobserver']['type'] == 'gearman') {
		HookManager::get()->addHook('call_domain_hooks', function($domain, $data) use ($gmc) {
			@$gmc->doBackground('call_domain_hooks', json_encode(['domain' => $domain->getDomainRaw(), 'data' => $data]));
		});
	}
