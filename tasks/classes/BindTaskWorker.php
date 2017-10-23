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
			$defaults['addZoneCommand'] = 'chmod a+rwx %2$s; /usr/bin/sudo -n /usr/sbin/rndc addzone %1$s \'{type master; file "%2$s"; allow-transfer { %3$s }; auto-dnssec maintain; inline-signing yes; };\' >/dev/null 2>&1';
			$defaults['reloadZoneCommand'] = 'chmod a+rwx %2$s; /usr/bin/sudo -n /usr/sbin/rndc reload %1$s >/dev/null 2>&1';
			$defaults['delZoneCommand'] = '/usr/bin/sudo -n /usr/sbin/rndc delzone %1$s >/dev/null 2>&1';
			$defaults['catalogZoneFile'] = '/etc/bind/zones/catalog.db';
			$defaults['catalogZoneName'] = 'catalog.invalid';
			$defaults['slaveServers'] = [];

			foreach ($defaults as $setting => $value) {
				if (!isset($this->bindConfig[$setting])) {
					$this->bindConfig[$setting] = $value;
				}
			}

			@mkdir($this->bindConfig['zonedir'], 0777, true);
		}

		public function writeZoneFile($domain) {
			$bind = new Bind($domain->getDomainRaw(), $this->bindConfig['zonedir']);
			list($filename, $filename2) = $bind->getFileNames();
			$new = !file_exists($filename);
			$bind->clearRecords();

			$soa = $domain->getSOARecord()->parseSOA();
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
				foreach ($domain->getRecords() as $record) {
					if ($record->isDisabled()) { continue; }

					$name = $record->getName() . '.';
					$content = $record->getContent();
					if ($record->getType() == "TXT") {
						$content = '"' . $record->getContent() . '"';
					} else if (in_array($record->getType(), ['CNAME', 'NS', 'MX', 'PTR'])) {
						$content = $record->getContent() . '.';
					} else if ($record->getType() == 'SRV') {
						if (preg_match('#^[0-9]+ [0-9]+ ([^\s]+)$#', $content, $m)) {
							if ($m[1] != ".") {
								$content = $record->getContent() . '.';
							}
						}
					}

					if ($record->getType() == "NS" && $record->getName() == $domain->getDomain()) {
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
			if ($hasNS) {
				$bind->saveZoneFile();
				if ($new) {
					$jobArgs['change'] = 'add';
				} else {
					$jobArgs['change'] = 'change';
				}
			} else if (file_exists($filename)) {
				unlink($filename);
				$jobArgs['change'] = 'remove';
			}

			if (isset($jobArgs['change'])) {
				$this->getTaskServer()->runBackgroundJob(new JobInfo('', 'bind_zone_changed', $jobArgs));
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
