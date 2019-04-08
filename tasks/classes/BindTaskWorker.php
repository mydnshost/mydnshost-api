<?php
	use shanemcc\phpdb\DB;

	abstract class BindTaskWorker extends TaskWorker {
		protected $bindConfig;

		public function __construct($taskServer) {
			parent::__construct($taskServer);

			global $config;
			$this->bindConfig = $config['hooks']['bind'];

			// Default config settings
			$defaults['zonedir'] = '/etc/bind/zones';
			$defaults['keydir'] = '/etc/bind/keys';
			$defaults['catalogZoneFile'] = '/etc/bind/zones/catalog.db';
			$defaults['catalogZoneName'] = 'catalog.invalid';
			$defaults['slaveServers'] = [];

			foreach ($defaults as $setting => $value) {
				if (!isset($this->bindConfig[$setting])) {
					$this->bindConfig[$setting] = $value;
				}
			}

			@mkdir($this->bindConfig['zonedir'], 0777, true);
			@mkdir($this->bindConfig['keydir'], 0777, true);
		}

		public function writeZoneFile($domain) {
			echo 'Writing zone file for: ', $domain->getDomainRaw(), "\n";
			$bind = new Bind($domain->getDomainRaw(), $this->bindConfig['zonedir']);
			list($filename, $filename2) = $bind->getFileNames();
			$new = !file_exists($filename);
			$bind->clearRecords();

			$recordDomain = ($domain->getAliasOf() != null) ? $domain->getAliasDomain(true) : $domain;

			$soa = $recordDomain->getSOARecord()->parseSOA();
			$bindSOA = array('Nameserver' => $soa['primaryNS'],
			                 'Email' => $soa['adminAddress'],
			                 'Serial' => $soa['serial'],
			                 'Refresh' => $soa['refresh'],
			                 'Retry' => $soa['retry'],
			                 'Expire' => $soa['expire'],
			                 'MinTTL' => $soa['minttl']);

			$bind->setSOA($bindSOA);

			$hasNS = false;

			if (!$domain->isDisabled()) {
				foreach ($recordDomain->getRecords() as $record) {
					if ($record->isDisabled()) { continue; }

					$name = $record->getName() . '.';
					if ($recordDomain != $domain) { $name = preg_replace('#' . preg_quote($recordDomain->getDomainRaw()) . '.$#', $domain->getDomainRaw() . '.', $name); }

					$content = $record->getContent();
					if ($record->getType() == "TXT") {
						$content = '"' . $record->getContent() . '"';
					} else if (in_array($record->getType(), ['CNAME', 'NS', 'MX', 'PTR'])) {
						$content = $record->getContent() . '.';
						if ($recordDomain != $domain) { $content = preg_replace('#' . preg_quote($recordDomain->getDomainRaw()) . '.$#', $domain->getDomainRaw() . '.', $content); }

					} else if ($record->getType() == 'SRV') {
						if (preg_match('#^[0-9]+ [0-9]+ ([^\s]+)$#', $content, $m)) {
							if ($m[1] != ".") {
								$content = $record->getContent() . '.';
								if ($recordDomain != $domain) { $content = preg_replace('#' . preg_quote($recordDomain->getDomainRaw()) . '.$#', $domain->getDomainRaw() . '.', $content); }

							}
						}
					}

					if ($record->getType() == "NS" && $record->getName() == $recordDomain->getDomain()) {
						$hasNS = true;
					}

					$bind->setRecord($name, $record->getType(), $content, $record->getTTL(), $record->getPriority());
				}
			}

			// Bind requires an NS record to load the zone, don't bother
			// attempting to add/change unless there is one.
			//
			// This means that the zone won't be added until it is actually
			// valid.
			$jobArgs = ['domain' => $domain->getDomainRaw(), 'filename' => $filename];

			// Ensure the file exists to let us lock it.
			if (!file_exists($filename)) { file_put_contents($filename, ''); }

			// Try and lock the file to ensure that we are the only ones
			// writing to it.
			$fp = fopen($filename, 'r+');
			if (flock($fp, LOCK_EX)) {
				if ($hasNS) {
					// if filemtime is the same as now, we need to wait to ensure
					// bind does the right thing.
					$filetime = filemtime($filename);
					if ($filetime >= time()) {
						echo 'Sleeping for zone: ', $filename, "\n";
						@time_sleep_until($filetime + 2);
					}

					$bind->saveZoneFile();
					if ($new) {
						$jobArgs['change'] = 'add';
					} else {
						$jobArgs['change'] = 'change';
					}
				} else if (file_exists($filename)) {
					foreach ([$filename, $filename . '.jbk', $filename . '.signed', $filename . '.signed.jnl'] as $f) {
						if (file_exists($f)) { @unlink($f); }
					}
					$jobArgs['change'] = 'remove';
				}

				flock($fp, LOCK_UN);
				fclose($fp);
			}

			$this->writeZoneKeys($domain);

			if (isset($jobArgs['change'])) {
				$this->getTaskServer()->runBackgroundJob(new JobInfo('', 'bind_zone_changed', $jobArgs));
			}
		}

		public function writeZoneKeys($domain) {
			// Lock the zone file while we are making changes.
			echo 'Writing zone keys for: ', $domain->getDomainRaw(), "\n";
			$bind = new Bind($domain->getDomainRaw(), $this->bindConfig['zonedir']);
			list($filename, $filename2) = $bind->getFileNames();

			$fp = fopen($filename, 'r+');
			if (flock($fp, LOCK_EX)) {
				// Output any missing keys.
				$keys = $domain->getZoneKeys();

				if (empty($keys)) {
					echo 'No keys found, generating new keys.', "\n";
					$this->getTaskServer()->runBackgroundJob(new JobInfo('', 'bind_create_keys', ['domain' => $domain->getDomainRaw()]));
				} else {
					$validFiles = [];
					foreach ($keys as $key) {
						$private = $this->bindConfig['keydir'] . '/' . $key->getKeyFileName('private');
						$public = $this->bindConfig['keydir'] . '/' . $key->getKeyFileName('key');

						if (!file_exists($private) || !file_exists($public)) {
							echo 'Writing missing keys: ', $key->getKeyFileName(), "\n";
							file_put_contents($private, $key->getKeyPrivateFileContent());
							file_put_contents($public, $key->getKeyPublicFileContent());
						}

						$validFiles[] = $private;
						$validFiles[] = $public;
					}

					// Remove no-longer required keys.
					$keys = glob($this->bindConfig['keydir'] . '/K' . $domain->getDomainRaw() . '.+*');
					foreach ($keys as $key) {
						if (!in_array($key, $validFiles)) {
							echo 'Removing invalid keyfile: ', $key, "\n";
							unlink($key);
						}
					}
				}

				flock($fp, LOCK_UN);
				fclose($fp);
			}
		}

		public function runCommand($command, $domain, $filename) {
			$ips = [];
			$domainRaw = ($domain instanceof Domain) ? $domain->getDomainRaw() : $domain;

			if ($domain !== FALSE) {
				$ips = $this->getAllowedIPs($domain, false);
				if (empty($ips)) {
					$ips[] = '"none"';
				}
				$ips[] = '';
			}

			$cmd = sprintf($command, escapeshellarg($domainRaw), escapeshellarg($filename), implode('; ', $ips));
			exec($cmd);
		}

		private function dns_get_record($host, $type) {
			global $__BIND__DNSCACHE;
			// Remove records older than 5 minutes to ensure we don't cache
			// things for too long.
			if (isset($__BIND__DNSCACHE[$host]) && ($__BIND__DNSCACHE[$host]['time'] + (60*5)) < time()) {
				unset($__BIND__DNSCACHE[$host]);
			}

			if (!isset($__BIND__DNSCACHE[$host])) {
				$records = dns_get_record($host, DNS_A | DNS_AAAA);
				$time = time();

				$__BIND__DNSCACHE[$host] = ['records' => $records, 'time' => time()];
			}

			return $__BIND__DNSCACHE[$host]['records'];
		}

		public function getAllowedIPs($domain, $APL) {
			if (!($domain instanceof Domain)) {
				$domain = Domain::loadFromDomain(DB::get(), $domain);
				if ($domain === FALSE) {
					return [];
				}
			}

			// Get NS Records
			$NS = [];
			foreach ($domain->getRecords() as $record) {
				if ($record->isDisabled()) { continue; }
				if ($record->getType() == "NS" && $record->getName() == $domain->getDomain()) {
					$NS[] = $record->getContent();
				}
			}

			$ips = [];
			foreach ($NS as $host) {
				$records = $this->dns_get_record($host, DNS_A | DNS_AAAA);

				foreach ($records as $rr) {
					if ($rr['type'] == 'A') {
						$ips[] = ($APL) ? '1:' . $rr['ip'] . '/32' : $rr['ip'];
					} else if ($rr['type'] == 'AAAA') {
						$ips[] = ($APL) ? '2:' . $rr['ipv6'] . '/128' : $rr['ipv6'];
					}
				}
			}

			// Add slave IPs
			$slaveServers = is_array($this->bindConfig['slaveServers']) ? $this->bindConfig['slaveServers'] : explode(',', $this->bindConfig['slaveServers']);
			foreach ($slaveServers as $s) {
				$s = trim($s);

				if (filter_var($s, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
					$ips[] = ($APL) ? '1:' . $s . '/32' : $s;
				} else if (filter_var($s, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
					$ips[] = ($APL) ? '2:' . $s . '/128' : $s;
				}
			}

			return array_unique($ips);
		}
	}
