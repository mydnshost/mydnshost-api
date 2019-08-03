<?php
	use shanemcc\phpdb\DB;

	EventQueue::get()->subscribe('call_domain_hooks', function($domainid, $data) {
		$domain = Domain::load(DB::get(), $domainid);

		dispatchJob('call_domain_hooks', json_encode(['domain' => $domain->getDomainRaw(), 'data' => $data]));
	});
