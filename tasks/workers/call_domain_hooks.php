<?php
	use shanemcc\phpdb\DB;
	require_once(dirname(__FILE__) . '/../classes/TaskWorker.php');

	/**
	 * Task to call domain hooks.
	 *
	 * Payload should be a json string with fields: 'domain', 'data'
	 */
	class call_domain_hooks extends TaskWorker {
		public function run($job) {
			$payload = $job->getPayload();

			if (isset($payload['domain']) && isset($payload['data'])) {
				$domain = Domain::loadFromDomain(DB::get(), $payload['domain']);
				if ($domain !== FALSE) {
					$hooks = DomainHook::loadFromDomainID(DB::get(), $domain->getID());

					foreach ($hooks as $hook) {
						echo 'Calling Hook: ', $hook->getID(), ' => ', $hook->getUrl(), "\n";
						$hook->call($payload['data']);
					}
				} else {
					$job->setError('Unknown domain: ' . $payload['domain']);
				}
				$job->setResult('OK');
			} else {
				$job->setError('Missing fields in payload.');
			}
		}
	}
