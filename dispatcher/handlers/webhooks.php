<?php
	use shanemcc\phpdb\DB;

	EventQueue::get()->subscribe('domain.hooks.call', function($domainid, $data) {
		$domain = Domain::load(DB::get(), $domainid);

		dispatchJob(createJob('call_domain_hooks', ['domain' => $domain->getDomainRaw(), 'data' => $data]));
	});
