<?php
	use shanemcc\phpdb\DB;
	use shanemcc\phpdb\Search;
	require_once(dirname(__FILE__) . '/../classes/BindTaskWorker.php');

	/**
	 * Task to import any unknown on-disk dnssec keys.
	 */
	class bind_import_unknown_keys extends BindTaskWorker {
		public function run($job) {
			// Get generated key data
			$keys = glob($this->bindConfig['keydir'] . '/*.private');

			foreach ($keys as $key) {
				$keyBits = pathinfo($key);
				$bits = explode('+', $keyBits['filename']);

				if (!isset($bits[2])) { continue; }

				$domainName = substr($bits[0], 1, strlen($bits[0]) - 2);
				$keyID = ltrim($bits[2], '0');

				$private = $keyBits['dirname'] . '/' . $keyBits['filename'] . '.private';
				$public = $keyBits['dirname'] . '/' . $keyBits['filename'] . '.key';

				if (file_exists($private) && file_exists($public)) {
					// Check if domain is known.
					$domain = Domain::loadFromDomain(DB::get(), $domainName);

					if ($domain !== FALSE) {
						echo 'Found keyid: ', $keyID, ' for ', $domainName, "\n";

						$key = $domain->getZoneKey($keyID);
						if ($key === FALSE) {
							echo '    Importing.', "\n";
							$zonekey = new ZoneKey(DB::get());
							$zonekey->importKeyData(file_get_contents($private), file_get_contents($public));
							$zonekey->setDomainID($domain->getID());
							$zonekey->validate();
							$zonekey->save();

							// Check if domain has NSEC3PARAMS, if not, create some.
							$nsec3 = $domain->getNSEC3Params();
							if (empty($nsec3)) {
								$nsec3hash = substr(sha1(openssl_random_pseudo_bytes('512')), 0, 16);
								$domain->setNSEC3Params('1 0 10 ' . $nsec3hash);
								$domain->save();
							}
						} else {
							echo '    Key already known.', "\n";
						}
					}
				}
			}

			$job->setResult('OK');
		}
	}
