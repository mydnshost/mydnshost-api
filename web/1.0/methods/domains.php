<?php

	use shanemcc\phpdb\ValidationFailed;
	use shanemcc\phpdb\Search;

	class Domains extends RouterMethod {
		public function isAdminMethod() {
			return $this->getContextKey('Admin Method') === true;
		}

		public function check() {
			if ($this->getContextKey('user') == NULL) {
				throw new RouterMethod_NeedsAuthentication();
			}

			if ($this->isAdminMethod() && !$this->checkPermissions(['manage_domains'], true)) {
				throw new RouterMethod_AccessDenied();
			}
		}

		/**
		 * Helper function for get()/post()/delete() to get the domain object or
		 * return an error.
		 *
		 * @param $domain Name to look form
		 * @return Domain object or FALSE if no domain provided or found.
		 */
		protected function getDomainFromParam($domain) {
			if (!Domain::validDomainName($domain)) {
				$this->getContextKey('response')->sendError('Invalid domain: ' . $domain);
			}

			$result = FALSE;
			if ($this->isAdminMethod()) {
				$result = Domain::loadFromDomain($this->getContextKey('db'), $domain);
			} else {
				$result = $this->getContextKey('user')->getDomainByName($domain);
			}

			if ($result === FALSE) {
				$this->getContextKey('response')->sendError('Unknown domain: ' . $domain);
			}

			return $result;
		}

		protected function getKeyFromParam($domain, $keyid) {
			$key = DomainKey::loadFromDomainKey($this->getContextKey('db'), $domain->getID(), $keyid);
			if ($key === FALSE) {
				$this->getContextKey('response')->sendError('Unknown domainkey: ' . $keyid);
			}

			return $key;
		}

		protected function getHookFromParam($domain, $hookid) {
			$key = DomainHook::loadFromDomainHookID($this->getContextKey('db'), $domain->getID(), $hookid);
			if ($key === FALSE) {
				$this->getContextKey('response')->sendError('Unknown domain hook: ' . $hookid);
			}

			return $key;
		}

		/**
		 * Helper function for get()/post()/delete() to get the record object or
		 * return an error.
		 *
		 * @param $domain Domain object to search under.
		 * @param $recordid Record ID to look for
		 * @return Domain object or FALSE if no domain provided or found.
		 */
		protected function getRecordFromParam($domain, $recordid) {
			$record = $domain !== FALSE ? $domain->getRecord($recordid) : FALSE;
			if ($record === FALSE) {
				$this->getContextKey('response')->sendError('Unknown record id: ' . $recordid);
			}

			return $record;
		}

		/**
		 * Check if the current user has sufficient access to this domain.
		 * This will throw an API error if the user does not have access, else
		 * return true.
		 *
		 * @param $domain Domain object to check. (if FALSE, return true)
		 * @param $required Array of valid access levels.
		 * @param $silent (Default: false) If true, don't throw an error just return false.
		 * @return True
		 */
		protected function checkAccess($domain, $required, $silent = false) {
			if ($this->isAdminMethod()) {
				return true;
			}

			if ($domain !== FALSE && !in_array($domain->getAccess($this->getContextKey('user')), $required)) {
				if ($silent) { return false; }
				$this->getContextKey('response')->sendError('You do not have the required access to the domain: ' . $domain->getDomain());
			}

			return true;
		}

		/**
		 * Check if the given domain is an alias of another, and throw an error
		 * if it is.
		 *
		 * This is used to restrict access from certain endpoints on aliases.
		 *
		 * @param $domain to check
		 * @return true if we are allowed, or returns an error to the caller.
		 */
		protected function checkAliasOf($domain) {
			if ($domain->getAliasOf() != null) {
				$this->getContextKey('response')->sendError('This endpoint is unavailable for aliased domains.');
			}
		}

		/**
		 * Get information about a record.
		 *
		 * @param $domain Domain object based on the 'domain' parameter.
		 * @param $record Record object based on the 'domain' parameter.
		 * @return TRUE if we handled this method.
		 */
		protected function getRecordID($domain, $record) {
			$r = $record->toArray();
			unset($r['domain_id']);
			$r['name'] = idn_to_utf8($r['name']);

			$this->getContextKey('response')->data($r);

			return true;
		}

		/**
		 * Get all records for this domain.
		 *
		 * @param $domain Domain object based on the 'domain' parameter.
		 * @param $filter Optional array of filters.
		 * @return TRUE if we handled this method.
		 */
		protected function getRecords($domain, $filter = null) {
			if (!is_array($filter)) { $filter = []; }
			$nameFilter = array_key_exists('name', $filter) ? $filter['name'] : NULL;
			$typeFilter = array_key_exists('type', $filter) ? $filter['type'] : NULL;

			if (endsWith($nameFilter, $domain->getDomain() . '.')) {
				$nameFilter = preg_replace('#\.?' . preg_quote($domain->getDomain(), '#') . '\.$#', '', $nameFilter);
			}

			$records = $domain->getRecords($nameFilter, $typeFilter);
			$list = [];
			$hasNS = false;
			foreach ($records as $record) {
				$r = $record->toArray();
				unset($r['domain_id']);
				$r['name'] = preg_replace('#\.?' . preg_quote($domain->getDomain(), '#') . '$#', '', idn_to_utf8($r['name']));
				$list[] = $r;

				$hasNS |= ($r['type'] == 'NS' && !parseBool($r['disabled']) && $r['name'] === '');
			}
			$this->getContextKey('response')->set('records', $list);
			$this->getContextKey('response')->set('hasNS', $hasNS);

			// Only include SOA in unfiltered.
			if (count($filter) == 0) {
				$this->getContextKey('response')->set('soa', $domain->getSOARecord()->parseSOA());
			}

			return true;
		}

		protected function getDomainInfoArray($domain) {
			$r = $domain->toArray();
			$r['domain'] = idn_to_utf8($r['domain']);

			$r['aliases'] = [];
			$r['aliases']['direct'] = [];
			$r['aliases']['indirect'] = [];

			$checkDomains = [];
			foreach ($domain->getAliases() as $alias) {
				$r['aliases']['direct'][] = $alias->getDomain();
				$checkDomains = array_merge($checkDomains, $alias->getAliases());
			}
			if (empty($r['aliases']['direct'])) { unset($r['aliases']['direct']); } else { sort($r['aliases']['direct']); }

			while ($alias = array_shift($checkDomains)) {
				$r['aliases']['indirect'][] = $alias->getDomain();
				$checkDomains = array_merge($checkDomains, $alias->getAliases());
			}
			if (empty($r['aliases']['indirect'])) { unset($r['aliases']['indirect']); } else { sort($r['aliases']['indirect']); }

			if ($domain->getAliasOf() != null) {
				$ad = $domain->getAliasDomain();
				$r['aliasof'] = $ad->getDomain();
				if ($ad->getAliasOf() != null) {
					$r['superalias'] = $ad->getAliasDomain(true)->getDomain();
				}
			} else {
				$soa = $domain->getSOARecord();
				$r['SOA'] = ($soa === FALSE) ? FALSE : $soa->parseSOA();
			}

			$keys = $domain->getDSKeys();
			if (!empty($keys)) {
				$r['DNSSEC'] = [];
				$r['DNSSEC']['parsed'] = [];

				$digestTypes = [];
				$digestTypes[1] = 'SHA-1';
				$digestTypes[2] = 'SHA-256';
				$digestTypes[3] = 'GOST R 34.11-94';
				$digestTypes[4] = 'SHA-384';

				$algorithmTypes = [];
				$algorithmTypes[3] = 'DSA/SHA1';
				$algorithmTypes[5] = 'RSA/SHA-1';
				$algorithmTypes[6] = 'DSA-NSEC3-SHA1';
				$algorithmTypes[7] = 'RSASHA1-NSEC3-SHA1';
				$algorithmTypes[8] = 'RSA/SHA-256';
				$algorithmTypes[10] = 'RSA/SHA-512';
				$algorithmTypes[12] = 'GOST R 34.10-2001';
				$algorithmTypes[13] = 'ECDSA Curve P-256 with SHA-256';
				$algorithmTypes[14] = 'ECDSA Curve P-384 with SHA-384';
				$algorithmTypes[15] = 'Ed25519';
				$algorithmTypes[16] = 'Ed448';

				$flagTypes = [];
				$flagTypes[256] = 'ZSK';
				$flagTypes[257] = 'KSK';

				$dsCount = [];

				foreach ($keys as $keyrec) {
					$rr = $keyrec->getType();
					$data = $keyrec->getContent();

					if (!isset($r['DNSSEC'][$rr])) { $r['DNSSEC'][$rr] = []; }
					$r['DNSSEC'][$rr][] = $keyrec->__toString();

					if ($rr == 'DS') {
						$bits = explode(' ', $data);

						$keyID = $bits[0];
						$algorithm = $bits[1];
						$type = $bits[2];
						$digest = $bits[3];

						if (!isset($dsCount[$keyID])) { $dsCount[$keyID] = 0; }
						$dsCount[$keyID]++;

						$r['DNSSEC']['parsed'][$keyID]['Key ID'] = $keyID;
						$r['DNSSEC']['parsed'][$keyID]['Digest ' . $dsCount[$keyID]] = $digest;
						$r['DNSSEC']['parsed'][$keyID]['Digest ' . $dsCount[$keyID] . ' Type'] = (isset($digestTypes[$type]) ? $digestTypes[$type] : 'Other') . ' (' . $type . ')';
					} else if ($rr == 'DNSKEY') {
						$bits = explode(' ', $data, 4);

						$flags = $bits[0];
						$protocol = $bits[1];
						$algorithm = $bits[2];
						$key = preg_replace('#\s+#', "\n", $bits[3]);

						$keyID = $this->generate_keytag($flags, $protocol, $algorithm, $key);

						$r['DNSSEC']['parsed'][$keyID]['Algorithm'] = (isset($algorithmTypes[$algorithm]) ? $algorithmTypes[$algorithm] : 'Other') . ' (' . $algorithm . ')';
						$r['DNSSEC']['parsed'][$keyID]['Public Key'] = $key;
						$r['DNSSEC']['parsed'][$keyID]['Flags'] = (isset($flagTypes[$flags]) ? $flagTypes[$flags] : 'Other') . ' (' . $flags . ')';
						$r['DNSSEC']['parsed'][$keyID]['Protocol'] = $protocol;
					}
				}

				if (empty($r['DNSSEC']['parsed'])) { unset($r['DNSSEC']['parsed']); }
			}

			if (!$this->isAdminMethod() && !($this->getContextKey('user') instanceof DomainKeyUser)) {
				$r['userdata'] = [];

				$ui = UserDomainCustomData::loadFromUserDomainKey($this->getContextKey('db'), $this->getContextKey('user')->getID(), $domain->getID());
				if ($ui !== false) {
					foreach ($ui as $d) {
						$r['userdata'][$d->getKey()] = $d->getValue();
					}
				}
			}

			$r['access'] = $domain->getAccess($this->getContextKey('user'));

			return $r;
		}

		/**
		 * Get information about this domain.
		 *
		 * @param $domain Domain object based on the 'domain' parameter.
		 * @return TRUE if we handled this method.
		 */
		protected function getDomainInfo($domain) {
			$this->getContextKey('response')->data($this->getDomainInfoArray($domain));

			return true;
		}

		// From https://robin.waarts.eu/2012/07/14/get-the-keytag-from-dnskey-data-in-php/
		private function generate_keytag($flags, $prot, $algo, $key){
			$rdata = base64_decode($key);
			$sum = 0;
			$wire = pack("ncc", $flags, $prot, $algo) . $rdata;
			if ($algo == 1) {
				$keytag = 0xffff & unpack("n", substr($wire, -3, 2));
			} else {
				$sum = 0;
				for ($i = 0; $i < strlen($wire); $i++) {
					$a = unpack("C", substr($wire,$i,1));
					$sum += ($i & 1) ? $a[1] : $a[1] << 8;
				}
				$keytag = 0xffff & ($sum + ($sum >> 16));
			}

			return $keytag;
		}

		/**
		 * Force domain to re sync.
		 *
		 * @param $domain Domain object based on the 'domain' parameter.
		 * @return TRUE if we handled this method.
		 */
		protected function getDomainSync($domain) {
			EventQueue::get()->publish('sync_domain', [$domain->getID()]);
			return true;
		}

		/**
		 * Get zone data in BIND format.
		 *
		 * @param $domain Domain object based on the 'domain' parameter.
		 * @return TRUE if we handled this method.
		 */
		protected function getDomainExport($domain) {
			$bind = new Bind($domain->getDomain(), '');
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

			foreach ($recordDomain->getRecords() as $record) {
				if ($record->isDisabled()) { continue; }

				$name = $record->getName() . '.';
				if ($recordDomain != $domain) { $name = preg_replace('#' . preg_quote($recordDomain->getDomainRaw()) . '.$#', $domain->getDomainRaw() . '.', $name); }

				$content = $record->getContent();
				if (in_array($record->getType(), ['CNAME', 'NS', 'MX', 'PTR'])) {
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

			$this->getContextKey('response')->data(['zone' => implode("\n", $bind->getParsedZoneFile())]);

			return true;
		}



		/**
		 * Import zone data as BIND format.
		 *
		 * @param $domain Domain object based on the 'domain' parameter.
		 * @return TRUE if we handled this method.
		 */
		protected function doDomainImport($domain) {
			$data = $this->getContextKey('data');
			if (!isset($data['data']['zone'])) {
				$this->getContextKey('response')->sendError('No data provided for update.');
			}

			// Delete old records.
			$this->getContextKey('db')->beginTransaction();
			$deletedRecords = [];
			$records = $domain->getRecords();
			$count = 0;
			foreach ($records as $record) {
				if ($record->delete()) {
					$deletedRecords[] = $record;
				}
			}

			$tmpname = tempnam('/tmp', 'ZONEIMPORT');
			if ($tmpname === FALSE) {
				$this->getContextKey('response')->sendError('Unable to import zone.');
			}

			file_put_contents($tmpname, $data['data']['zone']);

			$bind = new Bind($domain->getDomain(), '', $tmpname);
			try {
				$bind->parseZoneFile();
				$bind->getSOA();
				unlink($tmpname);
			} catch (Exception $ex) {
				unlink($tmpname);
				$this->getContextKey('response')->sendError('Import Error: ' . $ex->getMessage());
			}

			$domainInfo = $bind->getDomainInfo();

			$bindsoa = $bind->getSOA();
			$soa = $domain->getSOARecord();
			$parsedsoa = $soa->parseSOA();

			$parsedsoa['primaryNS'] = $bindsoa['Nameserver'];
			$parsedsoa['adminAddress'] = $bindsoa['Email'];
			$parsedsoa['serial'] = $domain->getNextSerial($parsedsoa['serial']);
			$parsedsoa['refresh'] = $bindsoa['Refresh'];
			$parsedsoa['retry'] = $bindsoa['Retry'];
			$parsedsoa['expire'] = $bindsoa['Expire'];
			$parsedsoa['minttl'] = $bindsoa['MinTTL'];

			try {
				$soa->updateSOAContent($parsedsoa);
				$soa->validate();
			} catch (Exception $ex) {
				$this->getContextKey('response')->sendError('Import Error: ' . $ex->getMessage());
			}

			$newRecords = [];

			foreach ($domainInfo as $type => $bits) {
				if ($type == 'SOA' || $type == ' META ') { continue; }

				foreach ($bits as $rname => $records) {
					foreach ($records as $record) {
						$r = (new Record($domain->getDB()))->setDomainID($domain->getID());

						$name = $rname;

						if (!endsWith($name, '.')) {
							if (!empty($name) || $name == "0") { $name .= '.'; }
							$name .= $domain->getDomain();
						}

						if (in_array($type, ['CNAME', 'NS', 'MX', 'PTR'])) {
							if (endsWith($record['Address'], '.')) {
								$record['Address'] = rtrim($record['Address'], '.');
							} else {
								if (!empty($record['Address'])) { $record['Address'] .= '.'; }
								$record['Address'] .= $domain->getDomain();
							}
						} else if ($type == 'SRV' && preg_match('#^([0-9]+ [0-9]+) ([^\s]+)$#', $record['Address'], $m)) {
							if ($m[2] != '.') {
								if (endsWith($record['Address'], '.')) {
									$record['Address'] = rtrim($record['Address'], '.');
								} else {
									if (!empty($record['Address'])) { $record['Address'] .= '.'; }
									$record['Address'] .= $domain->getDomain();
								}
							}
						}

						$record['TTL'] = $bind->ttlToInt($record['TTL']);

						// Test for cloudflare imports.
						if ($type == 'NS' && $record['Address'] == 'REPLACE&ME$WITH^YOUR@NAMESERVER') {
							foreach ($this->getDefaultRecords($domain) as $r) {
								if ($r->getType() == 'NS') {
									$r->setName($name);
									$r->setType($type);
									$r->setTTL($record['TTL']);
									$r->setChangedAt(time());
									$r->setChangedBy($this->getContextKey('user')->getID());

									try {
										$r->validate();
										if (!$r->save()) { throw new ValidationFailed('Error saving record: ' . $ex->getMessage()); }
									} catch (Exception $ex) {
										$this->getContextKey('db')->rollback();
										$this->getContextKey('response')->sendError('Import Error: ' . $ex->getMessage() . ' => ' . print_r($record, true));
									}

									$newRecords[] = $r;
								}
							}
						} else {
							$r->setName($name);
							$r->setType($type);
							$r->setTTL($record['TTL']);
							$r->setContent($record['Address']);
							if ($type == 'MX' || $type == 'SRV') {
								$r->setPriority($record['Priority']);
							}
							$r->setChangedAt(time());
							$r->setChangedBy($this->getContextKey('user')->getID());

							try {
								$r->validate();
								if (!$r->save()) { throw new ValidationFailed('Error saving record: ' . $ex->getMessage()); }
							} catch (Exception $ex) {
								$this->getContextKey('db')->rollback();
								$this->getContextKey('response')->sendError('Import Error: ' . $ex->getMessage() . ' => ' . print_r($record, true));
							}

							$newRecords[] = $r;
						}
					}
				}
			}

			if ($soa->save()) {
				$this->getContextKey('db')->commit();
			} else {
				$this->getContextKey('db')->rollback();
				$this->getContextKey('response')->sendError('Import Error: ' . $ex->getMessage() . ' => ' . print_r($record, true));
			}

			foreach ($deletedRecords as $r) {
				EventQueue::get()->publish('delete_record', [$domain->getID(), $record->getID()]);
			}

			foreach ($newRecords as $r) {
				EventQueue::get()->publish('add_record', [$domain->getID(), $r->getID()]);
			}

			EventQueue::get()->publish('update_record', [$domain->getID(), $soa->getID()]);

			EventQueue::get()->publish('records_changed', [$domain->getID()]);
			EventQueue::get()->publish('call_domain_hooks', [$domain->getID(), ['domain' => $domain->getDomainRaw(), 'type' => 'records_changed', 'reason' => 'import', 'serial' => $parsedsoa['serial'], 'time' => time()]]);

			$this->getContextKey('response')->set('serial', $parsedsoa['serial']);
			return true;
		}


		/**
		 * Get access information about this domain.
		 *
		 * @param $domain Domain object based on the 'domain' parameter.
		 * @return TRUE if we handled this method.
		 */
		protected function getDomainAccess($domain) {
			$r = $domain->toArray();
			$access = $domain->getAccessUsers();
			$r['access'] = [];
			$r['userinfo'] = [];
			foreach ($access as $e => $ui) {
				$r['access'][$e] = $ui['access'];
				$r['userinfo'][$e] = $ui;
				unset($r['userinfo'][$e]['access']);
			}

			$r['domain'] = idn_to_utf8($r['domain']);

			$this->getContextKey('response')->data($r);
			return true;
		}

		/**
		 * Update access information about this domain.
		 *
		 * @param $domain Domain object based on the 'domain' parameter.
		 * @return TRUE if we handled this method.
		 */
		protected function updateDomainAccess($domain) {
			$this->checkAccess($domain, ['admin', 'owner']);

			$data = $this->getContextKey('data');
			if (!isset($data['data']) || !is_array($data['data'])) {
				$this->getContextKey('response')->sendError('No data provided for update.');
			}

			$users = User::findByAddress($this->getContextKey('db'), array_keys($data['data']['access']));
			$self = $this->getContextKey('user');

			foreach ($data['data']['access'] as $email => $access) {
				if (!array_key_exists($email, $users)) {
					$this->getContextKey('response')->sendError('No such user: ' . $email);
				}
				$this->validAccessChange($domain, $users[$email], $access, $self);

				$domain->setAccess($users[$email]->getID(), $access);
			}
			$domain->save();

			$r = $domain->toArray();
			$access = $domain->getAccessUsers();
			$r['access'] = [];
			$r['userinfo'] = [];
			foreach ($access as $e => $ui) {
				$r['access'][$e] = $ui['access'];
				$r['userinfo'][$e] = $ui;
				unset($r['userinfo'][$e]['access']);
			}
			$r['domain'] = idn_to_utf8($r['domain']);

			$this->getContextKey('response')->data($r);
			return true;
		}

		/**
		 * Check if this is a valid access-change.
		 *  - Don't allow changing own access except to none.
		 *  - Don't allow setting access higher or equal than own access level.
		 *  - Don't allow changing the access of someone higher or equal than self
		 *
		 * @param $domain Domain object we are changing.
		 * @param $targetUser Target User
		 * @param $access Wanted access
		 * @param $self Self object.
		 * @return True if valid, or send an api error.
		 */
		protected function validAccessChange($domain, $targetUser, $access, $self) {
			if ($this->isAdminMethod()) {
				return true;
			}

			$selfAccess = $domain->getAccess($self);
			$targetAccess = $domain->getAccess($targetUser);
			$levels = ['none', 'read', 'write', 'admin', 'owner'];

			if ($targetUser->getID() == $self->getID() && $access != 'none') {
				$this->getContextKey('response')->sendError('You can\'t change your own access level');
			}
			if (array_search($access, $levels) >= array_search($selfAccess, $levels)) {
				$this->getContextKey('response')->sendError('You can\'t set an access level greater or equal to your own.');
			}
			if (array_search($targetAccess, $levels) >= array_search($selfAccess, $levels)) {
				$this->getContextKey('response')->sendError('You can\'t change the access level of someone who has greater or equal access to your own.');
			}
		}

		/**
		 * Get our list of domains.
		 *
		 * @return TRUE if we handled this method.
		 */
		protected function getDomainList() {
			if ($this->isAdminMethod()) {
				return $this->getDomainListAdmin();
			}

			$domains = [];

			if (isset($_REQUEST['contains'])) {
				// Convert the requested domain into an array (eg foo.bar.baz.example.com => [foo, bar, baz, example, com])
				$bits = explode('.', $_REQUEST['contains']);

				// Domains can have at most 255 characters,
				// subdomains require a . between them leaving a maximum sub-domain count of ~128 levels deep.
				// If someone tries to look for more than this then just ignore them.
				$limit = 128;
				do {
					// Get the domain to look for ([foo, bar, baz, example, com] => foo.bar.baz.example.com)
					$dom = implode('.', $bits);

					// If we have an exact match for this domain, then only return it in the output.
					// There may be a nicer way to do this than asking the DB every time.
					$domain = $this->getContextKey('user')->getDomainByName($dom);
					if ($domain !== FALSE) {
						$domains[] = $domain;
						break;
					}

					// Remove the first entry from the array so that the next time we check the parent domain.
					// eg [foo, bar, baz, example, com] => [bar, baz, example, com] and the next check is bar.baz.example.com
					array_shift($bits);
				} while (!empty($bits) && $limit-- > 0);

				$this->getContextKey('response')->setHeader('contains', $_REQUEST['contains']);
			} else {
				// Just get them all.
				$domains = $this->getContextKey('user')->getDomains();
			}

			$wantedType = isset($_REQUEST['type']) ? $_REQUEST['type'] : '';

			$valData = [];
			$useValData = false;
			if ($wantedType == 'userdata' && isset($_REQUEST['key'])) {
				$useValData = true;
				$udcd = UserDomainCustomData::loadFromUserDomainKey($this->getContextKey('db'), $this->getContextKey('user')->getID(), null, $_REQUEST['key']);
				foreach ($udcd as $d) {
					$valData[$d->getDomainID()] = $d->getValue();
				}
			}

			$list = [];
			foreach ($domains as $domain) {
				if ($useValData) {
					$value = isset($valData[$domain->getID()]) ? $valData[$domain->getID()] : '';
				} else {
					$value = $domain->getAccess($this->getContextKey('user'));
				}

				$list[$domain->getDomain()] = $value;
			}

			$this->getContextKey('response')->data($list);

			return true;
		}

		/**
		 * Get list of all domains for admins.
		 *
		 * @return TRUE if we handled this method.
		 */
		protected function getDomainListAdmin() {
			$s = new Search($this->getContextKey('db')->getPDO(), 'domains', ['domain', 'disabled']);
			$s->join('domain_access', '`domains`.`id` = `domain_access`.`domain_id`', 'LEFT');
			$s->join('users', '`users`.`id` = `domain_access`.`user_id`', 'LEFT');
			$s->select('users', 'email', 'user');
			$s->select('users', 'avatar', 'avatar');
			$s->select('domain_access', 'level', 'level');
			$s->order('domain');
			$rows = $s->getRows();

			$domains = [];
			foreach ($rows as $row) {
				$row['domain'] = idn_to_utf8($row['domain']);
				if (!array_key_exists($row['domain'], $domains)) {
					$domains[$row['domain']] = ['disabled' => $row['disabled'], 'users' => [], 'userinfo' => []];
				}

				$domains[$row['domain']]['users'][$row['user']] = $row['level'];
				$domains[$row['domain']]['userinfo'][$row['user']] = ['avatar' => $row['avatar']];
			}

			$this->getContextKey('response')->data($domains);

			return true;
		}

		/**
		 * Can we set the domain owner?
		 */
		protected function canSetOwner() {
			if ($this->isAdminMethod()) {
				return $this->checkPermissions(['manage_domains'], true);
			} else {
				return false;
			}
		}

		/**
		 * Create a new domain.
		 *
		 * @return TRUE if we handled this method.
		 */
		protected function createDomain() {
			$data = $this->getContextKey('data');
			if (!isset($data['data']) || !is_array($data['data'])) {
				$this->getContextKey('response')->sendError('No data provided for create.');
			}

			$domain = new Domain($this->getContextKey('user')->getDB());
			if (isset($data['data']['owner']) && $this->canSetOwner()) {
				if (!empty($data['data']['owner'])) {
					$newOwner = User::loadFromEmail($this->getContextKey('db'), $data['data']['owner']);

					if ($newOwner === FALSE) {
						$this->getContextKey('response')->sendError('Invalid owner specified: ' . $data['data']['owner']);
					} else {
						$domain->setAccess($newOwner->getID(), 'Owner');
					}
				}
			} else {
				$domain->setAccess($this->getContextKey('user')->getID(), 'Owner');
			}

			if (isset($data['data']['domain'])) {
				if (!Domain::validDomainName($data['data']['domain']) || isPublicSuffix($data['data']['domain']) || !hasValidPublicSuffix($data['data']['domain'])) {
					$this->getContextKey('response')->sendError('Invalid domain: ' . $data['data']['domain']);
				}

				$domain->setDomain($data['data']['domain']);
			} else {
				$this->getContextKey('response')->sendError('No domain name provided for create.');
			}

			$parent = $domain->findParent();
			if ($parent !== FALSE) {
				$this->checkAccess($parent, ['owner']);
			}

			return $this->updateDomain($domain, true);
		}

		/**
		 * Update this domain.
		 *
		 * @param $domain Domain object based on the 'domain' parameter.
		 * @return TRUE if we handled this method.
		 */
		protected function updateDomain($domain, $isCreate = false) {
			$data = $this->getContextKey('data');
			if (!isset($data['data']) || !is_array($data['data'])) {
				$this->getContextKey('response')->sendError('No data provided for update.');
			}

			$oldName = $domain->getDomain();
			$oldSuperAlias = $domain->getAliasDomain(true);
			$this->doUpdateDomain($domain, $data['data'], $this->checkPermissions(['rename_domains'], true));
			$newName = $domain->getDomain();
			$newSuperAlias = $domain->getAliasDomain(true);
			$isRename = ($oldName != $newName);
			$aliasChanged = ($oldSuperAlias != $newSuperAlias);

			$this->getContextKey('db')->beginTransaction();

			try {
				$domain->validate();

				if ($isCreate) {
					$soa = $domain->getSOARecord();
					$soa->updateSOAContent(array_merge($soa->parseSOA(), getSystemDefaultSOA()));
				} else {
					$domain->getSOARecord()->validate();
				}

				if ($domain->save()) {
					$domain->getSOARecord()->setDomainID($domain->getID());
					$domain->getSOARecord()->validate();
				} else {
					$error = $domain->getLastError()[2];
					if (preg_match('#.*Duplicate entry.*domains_domain_unique.*#', $error)) {
						throw new ValidationFailed('Domain already exists');
					} else {
						throw new ValidationFailed('Unknown Error');
					}
				}

				if (!$domain->getSOARecord()->save()) {
					$error = $domain->getSOARecord()->getLastError()[2];
					throw new ValidationFailed('Unknown Error with SOA');
				}
			} catch (ValidationFailed $ex) {
				$this->getContextKey('db')->rollback();
				$this->getContextKey('response')->sendError('Error updating domain.', $ex->getMessage());
			}

			$triggerSuperAlias = false;

			if ($aliasChanged && $newSuperAlias !== FALSE) {
				// We have been aliased against a domain.
				// We need to find out which SOA is larger and bump up.

				// Get SOAs
				$mySOA = $domain->getSOARecord()->parseSOA();
				$parentSOA = $newSuperAlias->getSOARecord()->parseSOA();

				// If our serial is higher, we need to bump the superalias one
				// above us to ensure that when we write out a zone file based
				// on it, our SOA is higher so that we get updated by slave servers
				//
				// If it's already above us then we don't need to worry as
				// things will behave anyway.
				if ($mySOA['serial'] >= $parentSOA['serial']) {
					$newSerial = $newSuperAlias->updateSerial($mySOA['serial']);
					$mySOA['serial'] = $newSerial;

					$triggerSuperAlias = true;
				}
			} else if ($aliasChanged && $newSuperAlias === FALSE) {
				// If we are removing an alias, we need to bump our SOA above
				// our old parent to force an update of slaves.
				$mySOA = $domain->getSOARecord()->parseSOA();
				$parentSOA = $oldSuperAlias->getSOARecord()->parseSOA();

				$mySOA['serial'] = $domain->getNextSerial($parentSOA['serial']);

				$domain->getSOARecord()->updateSOAContent($mySOA);
				$domain->getSOARecord()->save();
			}

			// Add default records.
			if ($isCreate) {
				$this->addDefaultRecords($domain);
			}

			$this->getContextKey('db')->commit();

			if ($isCreate) {
				EventQueue::get()->publish('new_domain', [$domain->getID()]);
				EventQueue::get()->publish('add_domain', [$domain->getID()]);
			} else if ($isRename) {
				EventQueue::get()->publish('rename_domain', [$oldName, $domain->getID()]);
			}

			if ($isCreate) {
				EventQueue::get()->publish('add_record', [$domain->getID(), $domain->getSOARecord()->getID()]);
			} else {
				EventQueue::get()->publish('update_record', [$domain->getID(), $domain->getSOARecord()->getID()]);
			}

			// If we are triggering the superalias, no need to trigger us, we will
			// get indirectly triggered.
			if (!$triggerSuperAlias) {
				EventQueue::get()->publish('records_changed', [$domain->getID()]);
			}

			$r = $this->getDomainInfoArray($domain);

			$serial = isset($r['SOA']) ? $r['SOA']['serial'] : $newSuperAlias->getSOARecord()->parseSOA()['serial'];

			EventQueue::get()->publish('call_domain_hooks', [$domain->getID(), ['domain' => $domain->getDomainRaw(), 'type' => 'domain_changed', 'reason' => 'update', 'serial' => $serial, 'time' => time()]]);

			if ($triggerSuperAlias) {
				// If we are here, $serial will be the serial of the super alias which is what we want...
				EventQueue::get()->publish('records_changed', [$newSuperAlias->getID()]);
				EventQueue::get()->publish('call_domain_hooks', [$newSuperAlias->getID(), ['domain' => $newSuperAlias->getDomainRaw(), 'type' => 'domain_changed', 'reason' => 'update', 'serial' => $serial, 'time' => time()]]);
			}

			$this->getContextKey('response')->data($r);
			return true;
		}

		/**
		 * Add default records to a domain
		 *
		 * @param $domain Domain object to add records to.
		 */
		protected function addDefaultRecords($domain) {
			$defaultRecords = $this->getDefaultRecords($domain);

			foreach ($defaultRecords as $record) {
				try {
					$record->validate();
					if ($record->save()) {
						EventQueue::get()->publish('add_record', [$domain->getID(), $record->getID()]);
					}
				} catch (ValidationFailed $ex) { }
			}
		}

		/**
		 * Get default records for this domain
		 *
		 * @param $domain Domain object to add records to.
		 */
		protected function getDefaultRecords($domain) {
			// TODO: Allow some kind of per-user default, and only fall back to
			//       these if not specified.
			$defaultRecords = getSystemDefaultRecords();

			$records = [];
			foreach ($defaultRecords as $data) {
				$record = (new Record($domain->getDB()))->setDomainID($domain->getID());
				$record = $this->doUpdateRecord($domain, $record, $data);
				$records[] = $record;
			}
			return $records;
		}

		/**
		 * Actually handle doing to update for the domain object.
		 *
		 * @param $domain Domain object to update
		 * @param $data Array of data to use to modify this object.
		 * @param $allowSetName (Default: false) Allow us to change the name of the domain?
		 * @return $domain object after modification
		 */
		protected function doUpdateDomain($domain, $data, $allowSetName = false) {
			$keys = array('disabled' => 'setDisabled',
			              'defaultttl' => 'setDefaultTTL',
			             );

			if ($allowSetName) {
				$keys['domain'] = 'setDomain';
			}

			foreach ($keys as $k => $f) {
				if (array_key_exists($k, $data)) {
					$domain->$f($data[$k]);
				}
			}

			// Handle userdata if appropriate.
			if (array_key_exists('userdata', $data) && !$this->isAdminMethod() && !($this->getContextKey('user') instanceof DomainKeyUser)) {
				$uid = $this->getContextKey('user')->getID();
				$did = $domain->getID();
				foreach ($data['userdata'] as $key => $value) {
					$udcd = UserDomainCustomData::loadFromUserDomainKey($this->getContextKey('db'), $uid, $did, $key);
					if ($udcd == false) {
						if (empty($value)) { continue; } // No point continuing

						$udcd = (new UserDomainCustomData($this->getContextKey('db')))->setUserID($uid)->setDomainID($did)->setKey($key);
					}
					if (empty($value)) {
						$udcd->delete();
					} else {
						$udcd->setValue($value)->save();
					}
				}
			}


			$currentAlias = ($domain->getAliasOf() != null) ? strtolower($domain->getAliasDomain()->getDomainRaw()) : '';
			$wantedAlias = array_key_exists('aliasof', $data) ? strtolower(idn_to_ascii($data['aliasof'])) : '';

			// Handle AliasOf specially.
			if (array_key_exists('aliasof', $data) && $this->checkAccess($domain, ['owner'], true) && $wantedAlias != $currentAlias) {
				// Trying to change aliasof.

				if (empty($data['aliasof'])) {
					// Unalias Domain.
					$domain->setAliasOf(null);
				} else {
					// Find target domain.
					$target = Domain::loadFromDomain($this->getContextKey('db'), $data['aliasof']);
					if ($target != FALSE) {
						if ($target->getID() == $domain->getID()) {
							$this->getContextKey('response')->sendError('Domain can\'t be alias of self.');
						}

						$chainLength = 0;
						$testTarget = $target;
						while ($testTarget->getAliasOf() != null) {
							$testTarget = Domain::load($testTarget->getDB(), $testTarget->getAliasOf());
							if ($testTarget != FALSE && $testTarget->getID() == $domain->getID()) {
								$this->getContextKey('response')->sendError('Domain can\'t have an alias chain back to self.');
							}
							if ($chainLength++ > 5) {
								$this->getContextKey('response')->sendError('Alias chain too long.');
							}
						}

						if ($this->checkAccess($target, ['owner'], true)) {
							$domain->setAliasOf($target->getID());
						}
					}
				}
			}


			if ($domain->getAliasOf() == null && isset($data['SOA'])) {
				$soa = $domain->getSOARecord();
				$soa->updateSOAContent(array_merge($soa->parseSOA(), $data['SOA']));
			}

			return $domain;
		}

		/**
		 * Update an individual record.
		 *
		 * @param $domain Domain object based on the 'domain' parameter.
		 * @param $record Record object based on the 'domain' parameter.
		 * @return TRUE if we handled this method.
		 */
		protected function updateRecordID($domain, $record) {
			$data = $this->getContextKey('data');
			if (!isset($data['data']) || !is_array($data['data'])) {
				$this->getContextKey('response')->sendError('No data provided for update.');
			}

			if (isset($data['delete']) && parseBool($data['delete'])) {
				return $this->deleteRecordID($domain, $record);
			}

			$record = $this->doUpdateRecord($domain, $record, $data['data']);
			$serial = -1;
			try {
				if ($record->hasChanged()) {
					$record->validate();
					if ($record->save()) {
						EventQueue::get()->publish('update_record', [$domain->getID(), $record->getID()]);

						$serial = $domain->updateSerial();
						EventQueue::get()->publish('update_record', [$domain->getID(), $domain->getSOARecord()->getID()]);
						EventQueue::get()->publish('records_changed', [$domain->getID()]);
					}
				}
			} catch (ValidationFailed $ex) {
				$this->getContextKey('response')->sendError('Error updating record.', $ex->getMessage());
			}

			$r = $record->toArray();
			unset($r['domain_id']);
			$r['name'] = idn_to_utf8($r['name']);

			if ($serial > 0) {
				EventQueue::get()->publish('call_domain_hooks', [$domain->getID(), ['domain' => $domain->getDomainRaw(), 'type' => 'records_changed', 'reason' => 'update_record', 'serial' => $serial, 'time' => time()]]);
			}

			$this->getContextKey('response')->data($r);

			return true;
		}

		/**
		 * Update multiple records on a domain.
		 *
		 * @param $domain Domain object based on the 'domain' parameter.
		 * @return TRUE if we handled this method.
		 */
		protected function updateRecords($domain) {
			$data = $this->getContextKey('data');
			if (!isset($data['data']) || !is_array($data['data'])) {
				$this->getContextKey('response')->sendError('No data provided for update.');
			}

			$errors = array();
			$recordsToBeSaved = array();
			$recordsToBeDeleted = array();

			if (isset($data['data']['records'])) {
				for ($i = 0; $i < count($data['data']['records']); $i++) {
					$r = $data['data']['records'][$i];

					$record = isset($r['id']) ? $domain->getRecord($r['id']) : (new Record($domain->getDB()))->setDomainID($domain->getID());
					if ($record === FALSE) {
						$errors[$i] = 'No such record ID: ' . $r['id'];
						continue;
					}

					if (isset($r['delete']) && parseBool($r['delete'])) {
						if ($record->isKnown()) {
							$recordsToBeDeleted[] = $record;
						}
					} else {
						$this->doUpdateRecord($domain, $record, $r);
						if ($record->hasChanged()) {
							$recordsToBeSaved[$i] = $record;
						}
					}
				}
			}

			$this->getContextKey('db')->beginTransaction();

			$changeCount = 0;

			$result = array();
			$deletedRecords = [];
			foreach ($recordsToBeDeleted as $record) {
				$r = ['id' => $record->getID(), 'deleted' => $record->delete()];
				$result[] = $r;
				if ($r['deleted']) {
					$deletedRecords[] = $r;
					$changeCount++;
				}
			}

			$updatedRecords = [];
			$addedRecords = [];
			foreach ($recordsToBeSaved as $i => $record) {
				$r = $record->toArray();
				unset($r['domain_id']);

				try {
					$record->validate();
				} catch (ValidationFailed $ex) {
					$errors[$i] = 'Unable to validate record: ' . $ex->getMessage();
					continue;
				}

				$new = $record->getID() === NULL;
				$r['updated'] = $record->save();
				$r['id'] = $record->getID();
				$r['name'] = preg_replace('#\.?' . preg_quote($domain->getDomain(), '#') . '$#', '', idn_to_utf8($r['name']));
				$result[] = $r;
				if ($r['updated']) {
					if ($new) {
						$addedRecords[] = $record;
					} else {
						$updatedRecords[] = $record;
					}
					$changeCount++;
				}
			}

			if (count($errors) > 0) {
				$this->getContextKey('db')->rollback();
				$this->getContextKey('response')->sendError('There was errors with the records provided.', $errors);
			}

			$this->getContextKey('db')->commit();

			// Only call hooks if we are not rolling back.
			foreach ($deletedRecords as $record) {
				EventQueue::get()->publish('delete_record', [$domain->getID(), $record->getID()]);
			}
			foreach ($updatedRecords as $record) {
				EventQueue::get()->publish('update_record', [$domain->getID(), $record->getID()]);
			}
			foreach ($addedRecords as $record) {
				EventQueue::get()->publish('add_record', [$domain->getID(), $record->getID()]);
			}

			if ($changeCount > 0) {
				$serial = $domain->updateSerial();
				EventQueue::get()->publish('update_record', [$domain->getID(), $domain->getSOARecord()->getID()]);
				EventQueue::get()->publish('records_changed', [$domain->getID()]);
			} else {
				$serial = $domain->getSOARecord()->parseSOA()['serial'];
			}

			if ($changeCount > 0) {
				EventQueue::get()->publish('call_domain_hooks', [$domain->getID(), ['domain' => $domain->getDomainRaw(), 'type' => 'records_changed', 'reason' => 'update_records', 'serial' => $serial, 'time' => time()]]);
			}

			$this->getContextKey('response')->data(['serial' => $serial, 'changed' => $result]);
			return true;
		}

		/**
		 * Actually update a record.
		 *
		 * @param $domain Domain the record belongs to
		 * @param $record Record object to update.
		 * @param $data Data to use to update the record.
		 * @return The record after being updated.
		 */
		protected function doUpdateRecord($domain, $record, $data) {
			$keys = array('name' => 'setName',
			              'type' => 'setType',
			              'content' => 'setContent',
			              'ttl' => 'setTTL',
			              'priority' => 'setPriority',
			              'disabled' => 'setDisabled',
			             );

			if (array_key_exists('name', $data)) {
				if ($data['name'] == '@') { $data['name'] = ''; }

				if (!empty($data['name']) && endsWith($data['name'], $domain->getDomain() . '.')) {
					$data['name'] = rtrim($data['name'], '.');
				} else {
					if (!empty($data['name']) && !endsWith($data['name'], '.')) { $data['name'] .= '.'; }
					$data['name'] .= $domain->getDomain();
				}
			}

			if (array_key_exists('ttl', $data)) {
				if (empty($data['ttl'])) {
					$data['ttl'] = $domain->getDefaultTTL();
				}
			}

			foreach ($keys as $k => $f) {
				if (array_key_exists($k, $data)) {
					$record->$f($data[$k]);
				}
			}

			if ($record->hasChanged()) {
				$record->setChangedAt(time());
				$record->setChangedBy($this->getContextKey('user')->getID());
			}

			return $record;
		}

		/**
		 * Delete an individual record.
		 *
		 * @param $domain Domain object based on the 'domain' parameter.
		 * @param $record Record object based on the 'domain' parameter.
		 * @return TRUE if we handled this method.
		 */
		protected function deleteRecordID($domain, $record) {
			$deleted = $record->delete();
			$this->getContextKey('response')->set('deleted', $deleted ? 'true' : 'false');
			if ($deleted) {
				EventQueue::get()->publish('delete_record', [$domain->getID(), $record->getID()]);
			}
			$serial = $domain->updateSerial();
			EventQueue::get()->publish('update_record', [$domain->getID(), $domain->getSOARecord()->getID()]);
			EventQueue::get()->publish('records_changed', [$domain->getID()]);

			EventQueue::get()->publish('call_domain_hooks', [$domain->getID(), ['domain' => $domain->getDomainRaw(), 'type' => 'records_changed', 'reason' => 'delete_record', 'serial' => $serial, 'time' => time()]]);

			$this->getContextKey('response')->set('serial', $serial);
			return true;
		}

		/**
		 * Delete all records for a domain.
		 *
		 * @param $domain Domain object based on the 'domain' parameter.
		 * @param $filter Optional array of filters.
		 * @return TRUE if we handled this method.
		 */
		protected function deleteRecords($domain, $filter = null) {
			if (!is_array($filter)) { $filter = []; }
			$nameFilter = array_key_exists('name', $filter) ? $filter['name'] : NULL;
			$typeFilter = array_key_exists('type', $filter) ? $filter['type'] : NULL;

			if (endsWith($nameFilter, $domain->getDomain() . '.')) {
				$nameFilter = preg_replace('#\.?' . preg_quote($domain->getDomain(), '#') . '\.$#', '', $nameFilter);
			}

			$records = $domain->getRecords($nameFilter, $typeFilter);
			$count = 0;
			foreach ($records as $record) {
				if ($record->delete()) {
					$count++;
					EventQueue::get()->publish('delete_record', [$domain->getID(), $record->getID()]);
				}
			}
			$this->getContextKey('response')->set('deleted', $count);
			$serial = $domain->updateSerial();
			EventQueue::get()->publish('update_record', [$domain->getID(), $domain->getSOARecord()->getID()]);
			EventQueue::get()->publish('records_changed', [$domain->getID()]);

			EventQueue::get()->publish('call_domain_hooks', [$domain->getID(), ['domain' => $domain->getDomainRaw(), 'type' => 'records_changed', 'reason' => 'delete_records', 'serial' => $serial, 'time' => time()]]);

			$this->getContextKey('response')->set('serial', $serial);

			return true;
		}

		/**
		 * Delete a domain.
		 *
		 * @param $domain Domain object based on the 'domain' parameter.
		 * @return TRUE if we handled this method.
		 */
		protected function deleteDomain($domain) {
			$aliases = $domain->getAliases();
			$oldSOA = $domain->getSOARecord()->parseSOA();

			$deleted = $domain->delete();
			$this->getContextKey('response')->data(['deleted', $deleted ? 'true' : 'false']);
			if ($deleted) {
				EventQueue::get()->publish('delete_domain', [$domain->getID()]);
				// EventQueue::get()->publish('call_domain_hooks', [$domain->getID(), ['domain' => $domain->getDomainRaw(), 'type' => 'domain_deleted', 'time' => time()]]);

				// We need to serial bump and rebuild all the direct children...
				foreach ($aliases as $alias) {
					$serial = $alias->updateSerial($oldSOA['serial']);

					EventQueue::get()->publish('records_changed', [$alias->getID()]);
					EventQueue::get()->publish('call_domain_hooks', [$alias->getID(), ['domain' => $alias->getDomainRaw(), 'type' => 'records_changed', 'reason' => 'parent_deleted', 'serial' => $serial, 'time' => time()]]);
				}

			}
			return true;
		}

		protected function getDomainKeys($domain) {
			$keys = DomainKey::getSearch($this->getContextKey('db'))->where('domain_id', $domain->getID())->find('domainkey');

			$result = [];
			foreach ($keys as $k => $v) {
				$result[$k] = $v->toArray();
				unset($result[$k]['id']);
				unset($result[$k]['domain_id']);
				unset($result[$k]['domainkey']);
				$result[$k]['maskedkey'] = $v->getKey(true);
			}

			$this->getContextKey('response')->data($result);

			return TRUE;
		}

		protected function getDomainKey($domain, $key) {
			$k = $key->toArray();
			unset($k['id']);
			unset($k['domain_id']);
			unset($k['domainkey']);
			$k['maskedkey'] = $key->getKey(true);

			$this->getContextKey('response')->data($k);

			return TRUE;
		}

		protected function createDomainKey($domain) {
			$key = (new DomainKey($this->getContextKey('db')))->setKey(TRUE)->setDomainID($domain->getID())->setCreated(time());
			return $this->updateDomainKey($domain, $key, true);
		}

		protected function updateDomainKey($domain, $key, $isCreate = false) {
			$data = $this->getContextKey('data');
			if (!isset($data['data']) || !is_array($data['data'])) {
				$this->getContextKey('response')->sendError('No data provided for update.');
			}

			if ($key !== FALSE) {
				$this->doUpdateKey($key, $data['data']);

				try {
					$key->validate();
				} catch (ValidationFailed $ex) {
					if ($isCreate) {
						$this->getContextKey('response')->sendError('Error creating key.', $ex->getMessage());
					} else {
						$this->getContextKey('response')->sendError('Error updating key: ' . $key->getKey(), $ex->getMessage());
					}
				}

				$k = $key->toArray();
				unset($k['id']);
				unset($k['domain_id']);
				unset($k['domainkey']);
				$k['maskedkey'] = $key->getKey(true);
				$k['updated'] = $key->save();
				if (!$k['updated']) {
					if ($isCreate) {
						$this->getContextKey('response')->sendError('Error creating key.', $ex->getMessage());
					} else {
						$this->getContextKey('response')->sendError('Error updating key: ' . $key->getKey(), $ex->getMessage());
					}
				} else if ($isCreate) {
					$this->getContextKey('response')->data([$key->getKey() => $k]);
				} else {
					$this->getContextKey('response')->data($k);
				}

				return TRUE;

			}
		}

		private function doUpdateKey($key, $data) {
			$keys = array('description' => 'setDescription',
			              'domains_read' => 'setDomainRead',
			              'domains_write' => 'setDomainWrite',
			             );

			foreach ($keys as $k => $f) {
				if (array_key_exists($k, $data)) {
					$key->$f($data[$k]);
				}
			}

			return $key;
		}

		protected function deleteDomainKey($domain, $key) {
			$this->getContextKey('response')->data(['deleted' => $key->delete() ? 'true' : 'false']);
			return TRUE;
		}

		protected function getDomainHooks($domain) {
			$hooks = DomainHook::getSearch($this->getContextKey('db'))->where('domain_id', $domain->getID())->find('id');

			$result = [];
			foreach ($hooks as $k => $v) {
				$result[$k] = $v->toArray();
				unset($result[$k]['id']);
				unset($result[$k]['domain_id']);
			}

			$this->getContextKey('response')->data($result);

			return TRUE;
		}

		protected function getDomainHook($domain, $hook) {
			$k = $hook->toArray();
			unset($k['id']);
			unset($k['domain_id']);
			$this->getContextKey('response')->data($k);

			return TRUE;
		}

		protected function createDomainHook($domain) {
			$hook = (new DomainHook($this->getContextKey('db')))->setDomainID($domain->getID())->setCreated(time());
			return $this->updateDomainHook($domain, $hook, true);
		}

		protected function updateDomainHook($domain, $hook, $isCreate = false) {
			$data = $this->getContextKey('data');
			if (!isset($data['data']) || !is_array($data['data'])) {
				$this->getContextKey('response')->sendError('No data provided for update.');
			}

			if ($hook !== FALSE) {
				$this->doUpdateHook($hook, $data['data']);

				try {
					$hook->validate();
				} catch (ValidationFailed $ex) {
					if ($isCreate) {
						$this->getContextKey('response')->sendError('Error creating Hook.', $ex->getMessage());
					} else {
						$this->getContextKey('response')->sendError('Error updating Hook: ' . $hook->getID(), $ex->getMessage());
					}
				}

				$k = $hook->toArray();
				unset($k['id']);
				unset($k['domain_id']);
				$k['updated'] = $hook->save();
				if (!$k['updated']) {
					if ($isCreate) {
						$this->getContextKey('response')->sendError('Error creating hook.');
					} else {
						$this->getContextKey('response')->sendError('Error updating hook: ' . $hook->getID());
					}
				} else if ($isCreate) {
					$this->getContextKey('response')->data([$hook->getID() => $k]);
				} else {
					$this->getContextKey('response')->data($k);
				}

				return TRUE;

			}
		}

		private function doUpdateHook($hook, $data) {
			$keys = array('url' => 'setUrl',
			              'password' => 'setPassword',
			              'disabled' => 'setDisabled',
			             );

			foreach ($keys as $k => $f) {
				if (array_key_exists($k, $data)) {
					$hook->$f($data[$k]);
				}
			}

			return $hook;
		}

		protected function deleteDomainHook($domain, $hook) {
			$this->getContextKey('response')->data(['deleted' => $hook->delete() ? 'true' : 'false']);
			return TRUE;
		}
	}

	$router->addRoute('(GET|POST)', '/domains', new class extends Domains {
		function get() {
			$this->checkPermissions(['domains_read']);
			return $this->getDomainList();
		}

		function post() {
			$this->checkPermissions(['domains_write', 'domains_create']);
			return $this->createDomain();
		}
	});

	$router->addRoute('(GET|POST|DELETE)', '/domains/([^/]+)', new class extends Domains {
		function get($domain) {
			$this->checkPermissions(['domains_read']);
			$domain = $this->getDomainFromParam($domain);

			return $this->getDomainInfo($domain);
		}

		function post($domain) {
			$this->checkPermissions(['domains_write']);
			$domain = $this->getDomainFromParam($domain);

			$hasWriteAccess = $this->checkAccess($domain, ['write', 'admin', 'owner'], true);

			$context = $this->getContext();

			if (!$hasWriteAccess) {
				// Check for read access if we don't have write access.
				$this->checkAccess($domain, ['read']);

				// Allow the user to change only their own user data.
				if (isset($context['data']['data']['userdata'])) {
					$context['data'] = ['data' => ['userdata' => $context['data']['data']['userdata']]];
					$this->setContext($context);
				} else {
					// Otherwise error out.
					$this->checkAccess($domain, ['write', 'admin', 'owner']);
				}
			}
			return $this->updateDomain($domain);
		}

		function delete($domain) {
			$this->checkPermissions(['domains_write']);
			$domain = $this->getDomainFromParam($domain);

			$this->checkAccess($domain, ['owner']);
			return $this->deleteDomain($domain);
		}
	});

	$router->addRoute('(GET|POST)', '/domains/([^/]+)/access', new class extends Domains {
		function get($domain) {
			$this->checkPermissions(['domains_read']);

			$domain = $this->getDomainFromParam($domain);

			return $this->getDomainAccess($domain);
		}

		function post($domain) {
			$this->checkPermissions(['domains_write']);
			$domain = $this->getDomainFromParam($domain);

			$this->checkAccess($domain, ['write', 'admin', 'owner']);
			return $this->updateDomainAccess($domain);
		}
	});

	$router->get('/domains/([^/]+)/sync', new class extends Domains {
		function run($domain) {
			$this->checkPermissions(['domains_read']);

			$domain = $this->getDomainFromParam($domain);
			return $this->getDomainSync($domain);
		}
	});

	$router->get('/domains/([^/]+)/export', new class extends Domains {
		function run($domain) {
			$this->checkPermissions(['domains_read']);

			$domain = $this->getDomainFromParam($domain);
			// $this->checkAliasOf($domain);
			return $this->getDomainExport($domain);
		}
	});

	$router->post('/domains/([^/]+)/import', new class extends Domains {
		function run($domain) {
			$this->checkPermissions(['domains_write']);
			$domain = $this->getDomainFromParam($domain);
			$this->checkAliasOf($domain);

			$this->checkAccess($domain, ['write', 'admin', 'owner']);
			return $this->doDomainImport($domain);
		}
	});

	$router->get('/domains/([^/]+)/stats', new class extends Domains {
		function run($domain) {
			$this->checkPermissions(['domains_stats']);

			$domain = $this->getDomainFromParam($domain);

			$time = isset($_REQUEST['time']) && ctype_digit($_REQUEST['time']) ? $_REQUEST['time'] : 3600;
			$type = isset($_REQUEST['type'])? $_REQUEST['type'] : "raw";

			$result = getDomainStats($domain, $type, $time);
			if ($result !== false) {
				$this->getContextKey('response')->data($result);
				return true;
			}
			return false;
		}
	});

	$router->get('/domains/([^/]+)/logs', new class extends Domains {
		function run($domain) {
			$this->checkPermissions(['domains_logs']);

			$domain = $this->getDomainFromParam($domain);

			$result = getDomainLogs($domain);
			if ($result !== false) {
				$this->getContextKey('response')->data($result);
				return true;
			}
			return false;
		}
	});

	$router->addRoute('(GET|POST|DELETE)', '/domains/([^/]+)/records', new class extends Domains {
		function get($domain) {
			$this->checkPermissions(['domains_read']);

			$domain = $this->getDomainFromParam($domain);
			$this->checkAliasOf($domain);

			return $this->getRecords($domain);
		}

		function post($domain) {
			$this->checkPermissions(['domains_write']);
			$domain = $this->getDomainFromParam($domain);

			$this->checkAliasOf($domain);

			$this->checkAccess($domain, ['write', 'admin', 'owner']);
			return $this->updateRecords($domain);
		}

		function delete($domain) {
			$this->checkPermissions(['domains_write']);
			$domain = $this->getDomainFromParam($domain);

			$this->checkAliasOf($domain);

			$this->checkAccess($domain, ['write', 'admin', 'owner']);
			return $this->deleteRecords($domain);
		}
	});

	$router->addRoute('(GET|POST|DELETE)', '/domains/([^/]+)/records/([0-9]+)', new class extends Domains {
		function get($domain, $recordid) {
			$this->checkPermissions(['domains_read']);
			$domain = $this->getDomainFromParam($domain);

			$this->checkAliasOf($domain);

			$record = $this->getRecordFromParam($domain, $recordid);

			return $this->getRecordID($domain, $record);
		}

		function post($domain, $recordid) {
			$this->checkPermissions(['domains_write']);
			$domain = $this->getDomainFromParam($domain);

			$this->checkAliasOf($domain);

			$this->checkAccess($domain, ['write', 'admin', 'owner']);
			$record = $this->getRecordFromParam($domain, $recordid);

			return $this->updateRecordID($domain, $record);
		}

		function delete($domain, $recordid) {
			$this->checkPermissions(['domains_write']);
			$domain = $this->getDomainFromParam($domain);

			$this->checkAliasOf($domain);

			$this->checkAccess($domain, ['write', 'admin', 'owner']);
			$record = $domain !== FALSE ? $domain->getRecord($recordid) : FALSE;
			if ($record === FALSE) {
				$this->getContextKey('response')->sendError('Unknown record id for domain ' . $domain . ' : ' . $recordid);
			}

			return $this->deleteRecordID($domain, $record);
		}
	});

	$router->addRoute('(GET|DELETE)', '/domains/([^/]+)/record/([^/]*)', new class extends Domains {
		function get($domain, $rrname) {
			$this->checkPermissions(['domains_read']);

			$domain = $this->getDomainFromParam($domain);

			$this->checkAliasOf($domain);

			$filter = [];
			$filter['name'] = $rrname;
			return $this->getRecords($domain, $filter);
		}

		function delete($domain, $rrname) {
			$this->checkPermissions(['domains_write']);
			$domain = $this->getDomainFromParam($domain);

			$this->checkAliasOf($domain);

			$this->checkAccess($domain, ['write', 'admin', 'owner']);
			$filter = [];
			$filter['name'] = $rrname;

			return $this->deleteRecords($domain, $filter);
		}
	});

	$router->addRoute('(GET|DELETE)', '/domains/([^/]+)/record/([^/]*)/([^/]+)', new class extends Domains {
		function get($domain, $rrname, $rrtype) {
			$this->checkPermissions(['domains_read']);

			$domain = $this->getDomainFromParam($domain);

			$this->checkAliasOf($domain);

			$filter = [];
			$filter['name'] = $rrname;
			$filter['type'] = $rrtype;

			return $this->getRecords($domain, $filter);
		}

		function delete($domain, $rrname, $rrtype) {
			$this->checkPermissions(['domains_write']);
			$domain = $this->getDomainFromParam($domain);

			$this->checkAliasOf($domain);

			$this->checkAccess($domain, ['write', 'admin', 'owner']);
			$filter = [];
			$filter['name'] = $rrname;
			$filter['type'] = $rrtype;

			return $this->deleteRecords($domain, $filter);
		}
	});

	$router->addRoute('(GET|POST)', '/domains/([^/]+)/keys', new class extends Domains {
		function check() {
			parent::check();

			if ($this->getContextKey('user') instanceof DomainKeyUser) {
				throw new RouterMethod_AccessDenied('You must authenticate as a user to view domain keys.');
			}
		}

		function get($domain) {
			$this->checkPermissions(['domains_read']);
			$domain = $this->getDomainFromParam($domain);
			$this->checkAliasOf($domain);
			$this->checkAccess($domain, ['write', 'admin', 'owner']);

			return $this->getDomainKeys($domain);
		}

		function post($domain) {
			$this->checkPermissions(['domains_write']);
			$domain = $this->getDomainFromParam($domain);
			$this->checkAliasOf($domain);
			$this->checkAccess($domain, ['write', 'admin', 'owner']);

			return $this->createDomainKey($domain);
		}
	});

	$router->addRoute('(GET|POST|DELETE)', '/domains/([^/]+)/keys/([^/]+)', new class extends Domains {
		function check() {
			parent::check();

			if ($this->getContextKey('user') instanceof DomainKeyUser) {
				throw new RouterMethod_AccessDenied('You must authenticate as a user to view domain keys.');
			}
		}

		function get($domain, $keyid) {
			$this->checkPermissions(['domains_read']);
			$domain = $this->getDomainFromParam($domain);
			$this->checkAliasOf($domain);
			$this->checkAccess($domain, ['write', 'admin', 'owner']);
			$key = $this->getKeyFromParam($domain, $keyid);

			return $this->getDomainKey($domain, $key);
		}

		function post($domain, $keyid) {
			$this->checkPermissions(['domains_write']);
			$domain = $this->getDomainFromParam($domain);
			$this->checkAliasOf($domain);
			$this->checkAccess($domain, ['write', 'admin', 'owner']);
			$key = $this->getKeyFromParam($domain, $keyid);

			return $this->updateDomainKey($domain, $key);
		}

		function delete($domain, $keyid) {
			$this->checkPermissions(['domains_write']);
			$domain = $this->getDomainFromParam($domain);
			$this->checkAliasOf($domain);
			$this->checkAccess($domain, ['write', 'admin', 'owner']);
			$key = $this->getKeyFromParam($domain, $keyid);

			return $this->deleteDomainKey($domain, $key);
		}
	});

	$router->addRoute('(GET|POST)', '/domains/([^/]+)/hooks', new class extends Domains {
		function get($domain) {
			$this->checkPermissions(['domains_read']);
			$domain = $this->getDomainFromParam($domain);
			$this->checkAliasOf($domain);
			$this->checkAccess($domain, ['write', 'admin', 'owner']);

			return $this->getDomainHooks($domain);
		}

		function post($domain) {
			$this->checkPermissions(['domains_write']);
			$domain = $this->getDomainFromParam($domain);
			$this->checkAliasOf($domain);
			$this->checkAccess($domain, ['write', 'admin', 'owner']);

			return $this->createDomainHook($domain);
		}
	});

	$router->addRoute('(GET|POST|DELETE)', '/domains/([^/]+)/hooks/([0-9]+)', new class extends Domains {
		function get($domain, $hookid) {
			$this->checkPermissions(['domains_read']);
			$domain = $this->getDomainFromParam($domain);
			$this->checkAliasOf($domain);
			$this->checkAccess($domain, ['write', 'admin', 'owner']);
			$key = $this->getHookFromParam($domain, $hookid);

			return $this->getDomainHook($domain, $key);
		}

		function post($domain, $hookid) {
			$this->checkPermissions(['domains_write']);
			$domain = $this->getDomainFromParam($domain);
			$this->checkAliasOf($domain);
			$this->checkAccess($domain, ['write', 'admin', 'owner']);
			$key = $this->getHookFromParam($domain, $hookid);

			return $this->updateDomainHook($domain, $key);
		}

		function delete($domain, $hookid) {
			$this->checkPermissions(['domains_write']);
			$domain = $this->getDomainFromParam($domain);
			$this->checkAliasOf($domain);
			$this->checkAccess($domain, ['write', 'admin', 'owner']);
			$key = $this->getHookFromParam($domain, $hookid);

			return $this->deleteDomainHook($domain, $key);
		}
	});

	$router->addRoute('GET|POST|DELETE', '/admin/(domains.*)', new class extends RouterMethod {
		function call($params) {
			$context = $this->getContext();
			$context['Admin Method'] = true;
			return $context['MethodRouter']->run($this->getRequestMethod(), $params[0], $context);
		}
	});
