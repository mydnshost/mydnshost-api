<?php
	EventQueue::get()->subscribe('call_domain_hooks', function($domainid, $data) use ($gmc) {
		$domain = Domain::load(DB::get(), $domainid);

		$gmc->doBackground('call_domain_hooks', json_encode(['domain' => $domain->getDomainRaw(), 'data' => $data]));
	});
