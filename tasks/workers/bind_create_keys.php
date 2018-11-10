<?php
	use shanemcc\phpdb\DB;
	use shanemcc\phpdb\Search;
	require_once(dirname(__FILE__) . '/../classes/BindTaskWorker.php');

	/**
	 * Task to create keys for a domain
	 */
	class bind_create_keys extends BindTaskWorker {
		public function run($job) {
			$payload = $job->getPayload();

			if (isset($payload['domain'])) {
				$domain = Domain::loadFromDomain(DB::get(), $payload['domain']);

				if ($domain !== FALSE) {
					$ksk = ZoneKey::generateKey(DB::get(), $domain, 257, 'RSASHA256', 2048);
					$ksk->validate();
					$ksk->save();

					$zsk = ZoneKey::generateKey(DB::get(), $domain, 256, 'RSASHA256', 1024);
					$zsk->validate();
					$zsk->save();

					$this->writeZoneKeys($domain);

					$this->getTaskServer()->runBackgroundJob(new JobInfo('', 'bind_zone_changed', ['domain' => $domain->getDomainRaw(), 'change' => 'change']));
				} else {
					$job->setError('Unknown domain: ' . $payload['domain']);
				}
			} else {
				$job->setError('Missing fields in payload.');
			}

			$job->setResult('OK');
		}
	}
