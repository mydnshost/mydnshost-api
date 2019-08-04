<?php
	use shanemcc\phpdb\DB;

	EventQueue::get()->subscribe('domain.hooks.call', function($domainid, $data) {
		$domain = Domain::load(DB::get(), $domainid);

		dispatchJob('call_domain_hooks', json_encode(['domain' => $domain->getDomainRaw(), 'data' => $data]));
	});
