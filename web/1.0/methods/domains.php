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
		 * @return True
		 */
		protected function checkAccess($domain, $required) {
			if ($this->isAdminMethod()) {
				return true;
			}

			if ($domain !== FALSE && !in_array($domain->getAccess($this->getContextKey('user')), $required)) {
				$this->getContextKey('response')->sendError('You do not have the required access to the domain: ' . $domain->getDomain());
			}

			return true;
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
			foreach ($records as $record) {
				$r = $record->toArray();
				unset($r['domain_id']);
				$r['name'] = preg_replace('#\.?' . preg_quote($domain->getDomain(), '#') . '$#', '', idn_to_utf8($r['name']));
				$list[] = $r;
			}
			$this->getContextKey('response')->set('records', $list);

			// Only include SOA in unfiltered.
			if (count($filter) == 0) {
				$this->getContextKey('response')->set('soa', $domain->getSOARecord()->parseSOA());
			}

			return true;
		}

		/**
		 * Get information about this domain.
		 *
		 * @param $domain Domain object based on the 'domain' parameter.
		 * @return TRUE if we handled this method.
		 */
		protected function getDomainInfo($domain) {
			$r = $domain->toArray();
			$r['domain'] = idn_to_utf8($r['domain']);

			$soa = $domain->getSOARecord();
			$r['SOA'] = ($soa === FALSE) ? FALSE : $soa->parseSOA();

			$keys = $domain->getDSKeys();
			if ($keys !== FALSE) {
				$r['DNSSEC'] = [];
				$r['DNSSEC']['parsed'] = [];

				$dsCount = 0;

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

				foreach ($keys as $key) {
					if (is_string($key) && !empty($key)) {
						try {
							$rec = new Record($domain->getDB());
							$rec->setDomainID($domain->getID());
							$rec->setTTL($domain->getDefaultTTL());
							$rec->parseString($key, $domain->getDomainRaw());

							$key = $rec;
						} catch (Exception $e) {
							$key = '';
						}
					}

					if ($key instanceof Record) {
						$rr = $key->getType();
						$data = $key->getContent();
					} else {
						continue;
					}

					if (!isset($r['DNSSEC'][$rr])) { $r['DNSSEC'][$rr] = []; }
					$r['DNSSEC'][$rr][] = $key->__toString();

					if ($rr == 'DS') {
						$dsCount++;
						$bits = explode(' ', $data);

						$r['DNSSEC']['parsed']['Key ID'] = $bits[0];
						$r['DNSSEC']['parsed']['Digest ' . $dsCount] = $bits[3];
						$r['DNSSEC']['parsed']['Digest ' . $dsCount . ' Type'] = (isset($digestTypes[$bits[2]]) ? $digestTypes[$bits[2]] : 'Other') . ' (' . $bits[2] . ')';
					} else if ($rr == 'DNSKEY') {
						$bits = explode(' ', $data, 4);

						$r['DNSSEC']['parsed']['Algorithm'] = (isset($algorithmTypes[$bits[2]]) ? $algorithmTypes[$bits[2]] : 'Other') . ' (' . $bits[2] . ')';
						$r['DNSSEC']['parsed']['Public Key'] = preg_replace('#\s+#', "\n", $bits[3]);
						$r['DNSSEC']['parsed']['Flags'] = (isset($flagTypes[$bits[0]]) ? $flagTypes[$bits[0]] : 'Other') . ' (' . $bits[0] . ')';
						$r['DNSSEC']['parsed']['Protocol'] = $bits[1];
					}
				}
			}

			$this->getContextKey('response')->data($r);

			return true;
		}



		/**
		 * Force domain to re sync.
		 *
		 * @param $domain Domain object based on the 'domain' parameter.
		 * @return TRUE if we handled this method.
		 */
		protected function getDomainSync($domain) {
			HookManager::get()->handle('sync_domain', [$domain]);
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

			$soa = $domain->getSOARecord()->parseSOA();
			$bindSOA = array('Nameserver' => $soa['primaryNS'],
			                 'Email' => $soa['adminAddress'],
			                 'Serial' => $soa['serial'],
			                 'Refresh' => $soa['refresh'],
			                 'Retry' => $soa['retry'],
			                 'Expire' => $soa['expire'],
			                 'MinTTL' => $soa['minttl']);

			$bind->setSOA($bindSOA);

			foreach ($domain->getRecords() as $record) {
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
						} else if ($type == 'TXT' && preg_match('#^"(.*)"$#', $record['Address'], $m)) {
							$record['Address'] = $m[1];
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
				HookManager::get()->handle('delete_record', [$domain, $record]);
			}

			foreach ($newRecords as $r) {
				HookManager::get()->handle('add_record', [$domain, $r]);
			}

			HookManager::get()->handle('update_record', [$domain, $soa]);

			HookManager::get()->handle('records_changed', [$domain]);
			HookManager::get()->handle('call_domain_hooks', [$domain, ['domain' => $domain->getDomainRaw(), 'type' => 'records_changed', 'reason' => 'import', 'serial' => $parsedsoa['serial'], 'time' => time()]]);

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
			$r['access'] = $domain->getAccessUsers();
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
			$r['access'] = $domain->getAccessUsers();
			$r['domain'] = idn_to_utf8($r['domain']);

			$this->getContextKey('response')->data($r);
			return true;
		}

		/**
		 * Check if this is a valid access-change.
		 *  - Don't allow changing own access
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

			if ($targetUser->getID() == $self->getID()) {
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

			if (isset($_REQUEST['contains'])) {
				$domains = [];

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

			$list = [];
			foreach ($domains as $domain) {
				$list[$domain->getDomain()] = $domain->getAccess($this->getContextKey('user'));
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
			$s->select('domain_access', 'level', 'level');
			$s->order('domain');
			$rows = $s->getRows();

			$domains = [];
			foreach ($rows as $row) {
				$row['domain'] = idn_to_utf8($row['domain']);
				if (!array_key_exists($row['domain'], $domains)) {
					$domains[$row['domain']] = ['disabled' => $row['disabled'], 'users' => []];
				}

				$domains[$row['domain']]['users'][$row['user']] = $row['level'];
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
			$this->doUpdateDomain($domain, $data['data'], $this->checkPermissions(['rename_domains'], true));
			$newName = $domain->getDomain();
			$isRename = ($oldName != $newName);

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

			// Add default records.
			if ($isCreate) {
				$this->addDefaultRecords($domain);
			}

			$this->getContextKey('db')->commit();

			if ($isCreate) {
				HookManager::get()->handle('new_domain', [$domain]);
				HookManager::get()->handle('add_domain', [$domain]);
			} else if ($isRename) {
				HookManager::get()->handle('rename_domain', [$oldName, $domain]);
			}

			if ($isCreate) {
				HookManager::get()->handle('add_record', [$domain, $domain->getSOARecord()]);
			} else {
				HookManager::get()->handle('update_record', [$domain, $domain->getSOARecord()]);
			}

			HookManager::get()->handle('records_changed', [$domain]);

			$r = $domain->toArray();
			$r['domain'] = idn_to_utf8($r['domain']);

			$soa = $domain->getSOARecord();
			$r['SOA'] = ($soa === FALSE) ? FALSE : $soa->parseSOA();

			HookManager::get()->handle('call_domain_hooks', [$domain, ['domain' => $domain->getDomainRaw(), 'type' => 'domain_changed', 'reason' => 'update', 'serial' => $r['SOA']['serial'], 'time' => time()]]);


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
						HookManager::get()->handle('add_record', [$domain, $record]);
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

			if (isset($data['SOA'])) {
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
						HookManager::get()->handle('update_record', [$domain, $record]);

						$serial = $domain->updateSerial();
						HookManager::get()->handle('update_record', [$domain, $domain->getSOARecord()]);
						HookManager::get()->handle('records_changed', [$domain]);
					}
				}
			} catch (ValidationFailed $ex) {
				$this->getContextKey('response')->sendError('Error updating record.', $ex->getMessage());
			}

			$r = $record->toArray();
			unset($r['domain_id']);
			$r['name'] = idn_to_utf8($r['name']);

			if ($serial > 0) {
				HookManager::get()->handle('call_domain_hooks', [$domain, ['domain' => $domain->getDomainRaw(), 'type' => 'records_changed', 'reason' => 'update_record', 'serial' => $serial, 'time' => time()]]);
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
				HookManager::get()->handle('delete_record', [$domain, $record]);
			}
			foreach ($updatedRecords as $record) {
				HookManager::get()->handle('update_record', [$domain, $record]);
			}
			foreach ($addedRecords as $record) {
				HookManager::get()->handle('add_record', [$domain, $record]);
			}

			if ($changeCount > 0) {
				$serial = $domain->updateSerial();
				HookManager::get()->handle('update_record', [$domain, $domain->getSOARecord()]);
				HookManager::get()->handle('records_changed', [$domain]);
			} else {
				$serial = $domain->getSOARecord()->parseSOA()['serial'];
			}

			if ($changeCount > 0) {
				HookManager::get()->handle('call_domain_hooks', [$domain, ['domain' => $domain->getDomainRaw(), 'type' => 'records_changed', 'reason' => 'update_records', 'serial' => $serial, 'time' => time()]]);
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
				HookManager::get()->handle('delete_record', [$domain, $record]);
			}
			$serial = $domain->updateSerial();
			HookManager::get()->handle('update_record', [$domain, $domain->getSOARecord()]);
			HookManager::get()->handle('records_changed', [$domain]);

			HookManager::get()->handle('call_domain_hooks', [$domain, ['domain' => $domain->getDomainRaw(), 'type' => 'records_changed', 'reason' => 'delete_record', 'serial' => $serial, 'time' => time()]]);

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
					HookManager::get()->handle('delete_record', [$domain, $record]);
				}
			}
			$this->getContextKey('response')->set('deleted', $count);
			$serial = $domain->updateSerial();
			HookManager::get()->handle('update_record', [$domain, $domain->getSOARecord()]);
			HookManager::get()->handle('records_changed', [$domain]);

			HookManager::get()->handle('call_domain_hooks', [$domain, ['domain' => $domain->getDomainRaw(), 'type' => 'records_changed', 'reason' => 'delete_records', 'serial' => $serial, 'time' => time()]]);

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
			$deleted = $domain->delete();
			$this->getContextKey('response')->data(['deleted', $deleted ? 'true' : 'false']);
			if ($deleted) {
				HookManager::get()->handle('delete_domain', [$domain]);
				// HookManager::get()->handle('call_domain_hooks', [$domain, ['domain' => $domain->getDomainRaw(), 'type' => 'domain_deleted', 'time' => time()]]);
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
			}

			$this->getContextKey('response')->data($result);

			return TRUE;
		}

		protected function getDomainKey($domain, $key) {
			$k = $key->toArray();
			unset($k['id']);
			unset($k['domain_id']);
			unset($k['domainkey']);

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

			$this->checkAccess($domain, ['write', 'admin', 'owner']);
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
			return $this->getDomainExport($domain);
		}
	});

	$router->post('/domains/([^/]+)/import', new class extends Domains {
		function run($domain) {
			$this->checkPermissions(['domains_write']);
			$domain = $this->getDomainFromParam($domain);

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
			return $this->getRecords($domain);
		}

		function post($domain) {
			$this->checkPermissions(['domains_write']);
			$domain = $this->getDomainFromParam($domain);

			$this->checkAccess($domain, ['write', 'admin', 'owner']);
			return $this->updateRecords($domain);
		}

		function delete($domain) {
			$this->checkPermissions(['domains_write']);
			$domain = $this->getDomainFromParam($domain);

			$this->checkAccess($domain, ['write', 'admin', 'owner']);
			return $this->deleteRecords($domain);
		}
	});

	$router->addRoute('(GET|POST|DELETE)', '/domains/([^/]+)/records/([0-9]+)', new class extends Domains {
		function get($domain, $recordid) {
			$this->checkPermissions(['domains_read']);

			$domain = $this->getDomainFromParam($domain);
			$record = $this->getRecordFromParam($domain, $recordid);

			return $this->getRecordID($domain, $record);
		}

		function post($domain, $recordid) {
			$this->checkPermissions(['domains_write']);
			$domain = $this->getDomainFromParam($domain);

			$this->checkAccess($domain, ['write', 'admin', 'owner']);
			$record = $this->getRecordFromParam($domain, $recordid);

			return $this->updateRecordID($domain, $record);
		}

		function delete($domain, $recordid) {
			$this->checkPermissions(['domains_write']);
			$domain = $this->getDomainFromParam($domain);

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

			$filter = [];
			$filter['name'] = $rrname;
			return $this->getRecords($domain, $filter);
		}

		function delete($domain, $rrname) {
			$this->checkPermissions(['domains_write']);
			$domain = $this->getDomainFromParam($domain);

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

			$filter = [];
			$filter['name'] = $rrname;
			$filter['type'] = $rrtype;

			return $this->getRecords($domain, $filter);
		}

		function delete($domain, $rrname, $rrtype) {
			$this->checkPermissions(['domains_write']);
			$domain = $this->getDomainFromParam($domain);

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
			$this->checkAccess($domain, ['write', 'admin', 'owner']);

			return $this->getDomainKeys($domain);
		}

		function post($domain) {
			$this->checkPermissions(['domains_write']);
			$domain = $this->getDomainFromParam($domain);
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
			$this->checkAccess($domain, ['write', 'admin', 'owner']);
			$key = $this->getKeyFromParam($domain, $keyid);

			return $this->getDomainKey($domain, $key);
		}

		function post($domain, $keyid) {
			$this->checkPermissions(['domains_write']);
			$domain = $this->getDomainFromParam($domain);
			$this->checkAccess($domain, ['write', 'admin', 'owner']);
			$key = $this->getKeyFromParam($domain, $keyid);

			return $this->updateDomainKey($domain, $key);
		}

		function delete($domain, $keyid) {
			$this->checkPermissions(['domains_write']);
			$domain = $this->getDomainFromParam($domain);
			$this->checkAccess($domain, ['write', 'admin', 'owner']);
			$key = $this->getKeyFromParam($domain, $keyid);

			return $this->deleteDomainKey($domain, $key);
		}
	});

	$router->addRoute('(GET|POST)', '/domains/([^/]+)/hooks', new class extends Domains {
		function get($domain) {
			$this->checkPermissions(['domains_read']);
			$domain = $this->getDomainFromParam($domain);
			$this->checkAccess($domain, ['write', 'admin', 'owner']);

			return $this->getDomainHooks($domain);
		}

		function post($domain) {
			$this->checkPermissions(['domains_write']);
			$domain = $this->getDomainFromParam($domain);
			$this->checkAccess($domain, ['write', 'admin', 'owner']);

			return $this->createDomainHook($domain);
		}
	});

	$router->addRoute('(GET|POST|DELETE)', '/domains/([^/]+)/hooks/([0-9]+)', new class extends Domains {
		function get($domain, $hookid) {
			$this->checkPermissions(['domains_read']);
			$domain = $this->getDomainFromParam($domain);
			$this->checkAccess($domain, ['write', 'admin', 'owner']);
			$key = $this->getHookFromParam($domain, $hookid);

			return $this->getDomainHook($domain, $key);
		}

		function post($domain, $hookid) {
			$this->checkPermissions(['domains_write']);
			$domain = $this->getDomainFromParam($domain);
			$this->checkAccess($domain, ['write', 'admin', 'owner']);
			$key = $this->getHookFromParam($domain, $hookid);

			return $this->updateDomainHook($domain, $key);
		}

		function delete($domain, $hookid) {
			$this->checkPermissions(['domains_write']);
			$domain = $this->getDomainFromParam($domain);
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
