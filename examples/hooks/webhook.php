<?php
	HookManager::get()->addHook('call_domain_hooks', function($domain, $data) use ($gmc) {
		$hooks = DomainHook::loadFromDomainID(DB::get(), $domain->getID());
		foreach ($hooks as $hook) {
			$hook->call($data);
		}
	});
