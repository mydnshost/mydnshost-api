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
			HookManager::get()->handle('delete_domain', [$domain]);
			HookManager::get()->handle('add_domain', [$domain]);
			HookManager::get()->handle('records_changed', [$domain]);
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
				} else if (in_array($record->getType(), ['CNAME', 'NS', 'MX', 'SRV', 'PTR'])) {
					$content = $record->getContent() . '.';
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
		 * Get zone statistics.
		 *
		 * @param $domain Domain object based on the 'domain' parameter.
		 * @return TRUE if we handled this method.
		 */
		protected function getDomainStats($domain, $type = 'raw', $time = '3600') {
			try {
				$database = getInfluxDB();

				// executing a query will yield a resultset object
//				SELECT sum("value") FROM "zone_qtype" WHERE time > now() - 1h and "zone" = 'mydnshost.co.uk' GROUP BY time(60s),"zone","qtype";
//				SELECT sum("value") FROM "zone_qtype" WHERE time > now() - 1h AND zone = 'mydnshost.co.uk' GROUP BY time(60s),zone,qtype"
				$result = $database->getQueryBuilder();

				if ($type == 'derivative') {
					$result = $result->select('non_negative_derivative(sum("value")) AS value');
				} else {
					$result = $result->select('sum("value") AS value');
				}

				$result = $result->from('zone_qtype')
				                 ->where(["time > now() - " . $time . "s", "\"zone\" = '" . $domain->getDomain() . "'"])
				                 ->groupby("time(60s)")->groupby("zone")->groupby("qtype")
				                 ->getResultSet();

				// $results = json_decode($result->getRaw(), true);

				$stats = [];
				foreach ($result->getSeries() AS $series) {
					$type = $series['tags']['qtype'];
					$stats[$type] = [];

					foreach ($series['values'] as $val) {
						if ($val[1] === NULL) { continue; }
						$stat = ['time' => strtotime($val[0]), 'value' => (int)$val[1]];

						$stats[$type][] = $stat;
					}
				}

				$this->getContextKey('response')->data(['stats' => $stats]);

				return true;
			} catch (Exception $ex) { }

			return false;
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
			$parsedsoa['serial'] = $bindsoa['Serial'];
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

						if (in_array($type, ['CNAME', 'NS', 'MX', 'SRV', 'PTR'])) {
							if (endsWith($record['Address'], '.')) {
								$record['Address'] = rtrim($record['Address'], '.');
							} else {
								if (!empty($record['Address'])) { $record['Address'] .= '.'; }
								$record['Address'] .= $domain->getDomain();
							}
						} else if ($type == 'TXT' && preg_match('#^"(.*)"$#', $record['Address'], $m)) {
							$record['Address'] = $m[1];
						}

						$record['TTL'] = $bind->ttlToInt($record['TTL']);

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
						} catch (Exception $ex) {
							$this->getContextKey('response')->sendError('Import Error: ' . $ex->getMessage() . ' => ' . print_r($record, true));
						}

						$newRecords[] = $r;
					}
				}
			}

			// Delete old records.
			$records = $domain->getRecords();
			$count = 0;
			foreach ($records as $record) {
				if ($record->delete()) {
					HookManager::get()->handle('delete_record', [$domain, $record]);
				}
			}

			foreach ($newRecords as $r) {
				if ($r->save()) {
					HookManager::get()->handle('add_record', [$domain, $r]);
				}
			}

			if ($soa->save()) {
				HookManager::get()->handle('update_record', [$domain, $soa]);
			}

			HookManager::get()->handle('records_changed', [$domain]);

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
				$this->validAccessChange($domain, $email, $access, $self);

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
		 *  - Don't allow setting access higher than own access level.
		 *
		 * @param $domain Domain object we are changing.
		 * @param $email Wanted email
		 * @param $access Wanted access
		 * @param $self Self object.
		 * @return True if valid, or send an api error.
		 */
		protected function validAccessChange($domain, $email, $access, $self) {
			if ($this->isAdminMethod()) {
				return true;
			}

			$selfAccess = $domain->getAccess($self);
			$levels = ['none', 'read', 'write', 'admin', 'owner'];

			if ($email == $self->getEmail()) {
				$this->getContextKey('response')->sendError('You can\'t change your own access level');
			}
			if (array_search($access, $levels) >= array_search($selfAccess, $levels)) {
				$this->getContextKey('response')->sendError('You can\'t set an access level greater or equal to your own.');
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

			$domains = $this->getContextKey('user')->getDomains();
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
				if (!Domain::validDomainName($data['data']['domain']) || isPublicSuffix($data['data']['domain'])) {
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

			try {
				$domain->validate();

				if ($isCreate) {
					$soa = $domain->getSOARecord();
					$soa->updateSOAContent(array_merge($soa->parseSOA(), getSystemDefaultSOA()));
				}

				$domain->getSOARecord()->validate();

				if ($domain->save()) {
					if ($isCreate) {
						HookManager::get()->handle('add_domain', [$domain]);
					} else if ($isRename) {
						HookManager::get()->handle('rename_domain', [$oldName, $domain]);
					}
				} else {
					$error = $domain->getLastError()[2];
					if (preg_match('#.*Duplicate entry.*domains_domain_unique.*#', $error)) {
						throw new ValidationFailed('Domain already exists');
					} else {
						throw new ValidationFailed('Unknown Error');
					}
				}
				$domain->getSOARecord()->setDomainID($domain->getID());

				if ($domain->getSOARecord()->save()) {
					if ($isCreate) {
						HookManager::get()->handle('add_record', [$domain, $domain->getSOARecord()]);
					} else {
						HookManager::get()->handle('update_record', [$domain, $domain->getSOARecord()]);
					}
				} else {
					$error = $domain->getSOARecord()->getLastError()[2];
					throw new ValidationFailed('Unknown Error with SOA');
				}
			} catch (ValidationFailed $ex) {
				$this->getContextKey('response')->sendError('Error updating domain.', $ex->getMessage());
			}

			// Add default records.
			if ($isCreate) {
				$this->addDefaultRecords($domain);
			}

			HookManager::get()->handle('records_changed', [$domain]);

			$r = $domain->toArray();
			$r['domain'] = idn_to_utf8($r['domain']);

			$soa = $domain->getSOARecord();
			$r['SOA'] = ($soa === FALSE) ? FALSE : $soa->parseSOA();

			$this->getContextKey('response')->data($r);
			return true;
		}

		/**
		 * Add default records to a domain
		 *
		 * @param $domain Domain object to add records to.
		 */
		protected function addDefaultRecords($domain) {
			// TODO: Allow some kind of per-user default, and only fall back to
			//       these if not specified.
			$defaultRecords = getSystemDefaultRecords();

			foreach ($defaultRecords as $data) {
				$record = (new Record($domain->getDB()))->setDomainID($domain->getID());
				$record = $this->doUpdateRecord($domain, $record, $data);

				try {
					$record->validate();
					if ($record->save()) {
						HookManager::get()->handle('add_record', [$domain, $record]);
					}
				} catch (ValidationFailed $ex) { }
			}
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
						try {
							if ($record->hasChanged()) {
								$record->validate();
								$recordsToBeSaved[] = $record;
							}
						} catch (ValidationFailed $ex) {
							$errors[$i] = 'Unable to validate record: ' . $ex->getMessage();
							continue;
						}
					}
				}
			}

			// TODO: Validate CNAMEs are not duplicated.

			if (count($errors) > 0) {
				$this->getContextKey('response')->sendError('There was errors with the records provided.', $errors);
			}

			$changeCount = 0;

			$result = array();
			foreach ($recordsToBeSaved as $record) {
				$r = $record->toArray();
				unset($r['domain_id']);
				$r['updated'] = $record->save();
				$r['id'] = $record->getID();
				$r['name'] = preg_replace('#\.?' . preg_quote($domain->getDomain(), '#') . '$#', '', idn_to_utf8($r['name']));
				$result[] = $r;
				if ($r['updated']) {
					HookManager::get()->handle('update_record', [$domain, $record]);
					$changeCount++;
				}
			}

			foreach ($recordsToBeDeleted as $record) {
				$r = ['id' => $record->getID(), 'deleted' => $record->delete()];
				$result[] = $r;
				if ($r['deleted']) {
					HookManager::get()->handle('delete_record', [$domain, $record]);
					$changeCount++;
				}
			}

			if ($changeCount > 0) {
				$serial = $domain->updateSerial();
				HookManager::get()->handle('update_record', [$domain, $domain->getSOARecord()]);
				HookManager::get()->handle('records_changed', [$domain]);
			} else {
				$serial = $domain->getSOARecord()->parseSOA()['serial'];
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
			$this->getContextKey('response')->data('deleted', $key->delete() ? 'true' : 'false');
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

			return $this->getDomainStats($domain, $type, $time);
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

	$router->addRoute('GET|POST|DELETE', '/admin/(domains.*)', new class extends RouterMethod {
		function call($params) {
			$context = $this->getContext();
			$context['Admin Method'] = true;
			return $context['MethodRouter']->run($this->getRequestMethod(), $params[0], $context);
		}
	});
