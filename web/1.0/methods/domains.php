<?php

	class AdminDomains extends Domains {
		public function check($requestMethod, $params) {
			parent::check($requestMethod, $params);

			if (!$this->checkPermissions(['manage_domains'], true)) {
				throw new APIMethod_AccessDenied();
			}
		}

		/**
		 * Admin getDomainFromParam looks for the domain without checking
		 * that the current user can see it.
		 */
		protected function getDomainFromParam($params) {
			if (isset($params['domain'])) {
				if (!Domain::validDomainName($params['domain'])) {
					$this->getContextKey('response')->sendError('Invalid domain: ' . $params['domain']);
				}

				$domain = Domain::loadFromDomain($this->getContextKey('db'), $params['domain']);
				if ($domain === FALSE) {
					$this->getContextKey('response')->sendError('Unknown domain: ' . $params['domain']);
				}
			} else {
				$domain = FALSE;
			}

			return $domain;
		}

		/**
		 * Admin end point allows setting domain owner.
		 */
		protected function canSetOwner() {
			return $this->checkPermissions(['manage_domains'], true);
		}

		/**
		 * Admin's always have access.
		 */
		protected function checkAccess($domain, $required) {
			return true;
		}

		/**
		 * Admin's can change anything.
		 */
		protected function validAccessChange($domain, $email, $access, $self) {
			return true;
		}

		/**
		 * Get list of all domains.
		 *
		 * @return TRUE if we handled this method.
		 */
		protected function getDomainList() {
			$s = new Search($this->getContextKey('db')->getPDO(), 'domains', ['domain', 'disabled']);
			$s->join('domain_access', '`domains`.`id` = `domain_access`.`domain_id`', 'LEFT');
			$s->join('users', '`users`.`id` = `domain_access`.`user_id`', 'LEFT');
			$s->select('users', 'email', 'user');
			$s->select('domain_access', 'level', 'level');
			$s->order('domain');
			$rows = $s->getRows();

			$domains = [];
			foreach ($rows as $row) {
				if (!array_key_exists($row['domain'], $domains)) {
					$domains[$row['domain']] = ['disabled' => $row['disabled'], 'users' => []];
				}

				$domains[$row['domain']]['users'][$row['user']] = $row['level'];
			}

			$this->getContextKey('response')->data($domains);

			return true;
		}
	}

	class Domains extends APIMethod {
		public function check($requestMethod, $params) {
			if ($this->getContextKey('user') == NULL) {
				throw new APIMethod_NeedsAuthentication();
			}
		}

		/**
		 * Helper function for get()/post()/delete() to get the domain object or
		 * return an error.
		 * If a domain param is not provided, we will return false. If a domain
		 * param is provided and the domain is not found, we will error out.
		 *
		 * @param $params Params array.
		 * @return Domain object or FALSE if no domain provided or found.
		 */
		protected function getDomainFromParam($params) {
			if (isset($params['domain'])) {
				if (!Domain::validDomainName($params['domain'])) {
					$this->getContextKey('response')->sendError('Invalid domain: ' . $params['domain']);
				}

				$domain = $this->getContextKey('user')->getDomainByName($params['domain']);
				if ($domain === FALSE) {
					$this->getContextKey('response')->sendError('Unknown domain: ' . $params['domain']);
				}
			} else {
				$domain = FALSE;
			}

			return $domain;
		}

		/**
		 * Helper function for get()/post()/delete() to get the record object or
		 * return an error.
		 * If a record param is not provided, we will return false. If a record
		 * param is provided and the record is not found, we will error out.
		 *
		 * @param $domain Domain object to search under.
		 * @param $params Params array.
		 * @return Domain object or FALSE if no domain provided or found.
		 */
		protected function getRecordFromParam($domain, $params) {
			if (isset($params['recordid'])) {
				$record = $domain !== FALSE ? $domain->getRecord($params['recordid']) : FALSE;
				if ($record === FALSE) {
					$this->getContextKey('response')->sendError('Unknown record id: ' . $params['recordid']);
				}
			} else {
				$record = FALSE;
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
			if ($domain !== FALSE && !in_array($domain->getAccess($this->getContextKey('user')->getID()), $required)) {
				$this->getContextKey('response')->sendError('You do not have the required access to the domain: ' . $domain->getDomain());
			}

			return true;
		}

		public function get($params) {
			$this->checkPermissions(['domains_read']);

			$domain = $this->getDomainFromParam($params);

			if (isset($params['rrname']) || isset($params['rrtype'])) {
				$filter = [];
				if (isset($params['rrname'])) {
					$filter['name'] = $params['rrname'];
				}
				if (isset($params['rrtype'])) {
					$filter['type'] = $params['rrtype'];
				}

				return $this->getRecords($domain, $filter);
			} else if (isset($params['recordid'])) {
				$record = $this->getRecordFromParam($domain, $params);

				return $this->getRecordID($domain, $record);
			} else if (isset($params['records'])) {
				return $this->getRecords($domain);
			} else if (isset($params['access'])) {
				return $this->getDomainAccess($domain);
			} else if (isset($params['sync'])) {
				return $this->getDomainSync($domain);
			} else if (isset($params['export'])) {
				return $this->getDomainExport($domain);
			} else if (isset($params['domain'])) {
				return $this->getDomainInfo($domain);
			} else {
				return $this->getDomainList();
			}

			return FALSE;
		}

		public function post($params) {
			$this->checkPermissions(['domains_write']);
			$domain = $this->getDomainFromParam($params);

			$this->checkAccess($domain, ['write', 'admin', 'owner']);

			if (isset($params['recordid'])) {
				$record = $this->getRecordFromParam($domain, $params);

				return $this->updateRecordID($domain, $record);
			} else if (isset($params['records'])) {
				return $this->updateRecords($domain);
			} else if (isset($params['access'])) {
				return $this->updateDomainAccess($domain);
			} else if (isset($params['import'])) {
				return $this->doDomainImport($domain);
			} else if ($domain !== FALSE) {
				return $this->updateDomain($domain);
			} else if ($domain === FALSE) {
				$this->checkPermissions(['domains_create']);
				return $this->createDomain();
			}

			return FALSE;
		}

		public function delete($params) {
			$this->checkPermissions(['domains_write']);
			$domain = $this->getDomainFromParam($params);

			$this->checkAccess($domain, ['write', 'admin', 'owner']);

			if (isset($params['rrname']) || isset($params['rrtype'])) {
				$filter = [];
				if (isset($params['rrname'])) {
					$filter['name'] = $params['rrname'];
				}
				if (isset($params['rrtype'])) {
					$filter['type'] = $params['rrtype'];
				}

				return $this->deleteRecords($domain, $filter);
			} else if (isset($params['recordid'])) {
				$record = $domain !== FALSE ? $domain->getRecord($params['recordid']) : FALSE;
				if ($record === FALSE) {
					$this->getContextKey('response')->sendError('Unknown record id for domain ' . $params['domain'] . ' : ' . $params['recordid']);
				}

				return $this->deleteRecordID($domain, $record);
			} else if (isset($params['records'])) {
				return $this->deleteRecords($domain);
			} else if (isset($params['domain'])) {
				$this->checkAccess($domain, ['owner']);
				return $this->deleteDomain($domain);
			}

			return FALSE;
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

			$records = $domain->getRecords($nameFilter, $typeFilter);
			$list = [];
			foreach ($records as $record) {
				$r = $record->toArray();
				unset($r['domain_id']);
				$r['name'] = preg_replace('#.?' . preg_quote($domain->getDomain(), '#') . '$#', '', $r['name']);
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
			$selfAccess = $domain->getAccess($self->getID());
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
			$domains = $this->getContextKey('user')->getDomains();
			$list = [];
			foreach ($domains as $domain) {
				$list[$domain->getDomain()] = $domain->getAccess($this->getContextKey('user')->getID());
			}
			$this->getContextKey('response')->data($list);

			return true;
		}

		/**
		 * Can we set the domain owner?
		 */
		protected function canSetOwner() {
			return false;
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
					global $config;
					$soa = $domain->getSOARecord();
					$soa->updateSOAContent(array_merge($soa->parseSOA(), $config['defaultSOA']));
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
			global $config;
			// TODO: Allow some kind of per-user default, and only fall back to
			//       these if not specified.
			$defaultRecords = $config['defaultRecords'];

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
				$r['name'] = preg_replace('#.?' . preg_quote($domain->getDomain(), '#') . '$#', '', $r['name']);
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
				if (!empty($data['name'])) { $data['name'] .= '.'; }
				$data['name'] .= $domain->getDomain();
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

	}

	$domainsHandler = new Domains();
	$router->addRoute('(GET|POST) /domains', $domainsHandler);
	$router->addRoute('(GET|POST|DELETE) /domains/(?P<domain>[^/]+)', $domainsHandler);

	$router->addRoute('(GET|POST) /domains/(?P<domain>[^/]+)/(?P<access>access)', $domainsHandler);
	$router->addRoute('(GET) /domains/(?P<domain>[^/]+)/(?P<sync>sync)', $domainsHandler);
	$router->addRoute('(GET) /domains/(?P<domain>[^/]+)/(?P<export>export)', $domainsHandler);
	$router->addRoute('(POST) /domains/(?P<domain>[^/]+)/(?P<import>import)', $domainsHandler);

	$router->addRoute('(GET|POST|DELETE) /domains/(?P<domain>[^/]+)/(?P<records>records)', $domainsHandler);
	$router->addRoute('(GET|POST|DELETE) /domains/(?P<domain>[^/]+)/(?P<records>records)/(?P<recordid>[0-9]+)', $domainsHandler);
	$router->addRoute('(GET|DELETE) /domains/(?P<domain>[^/]+)/(?P<record>record)/(?P<rrname>[0-9]+)', $domainsHandler);

	$router->addRoute('(GET|DELETE) /domains/(?P<domain>[^/]+)/(?P<record>record)/(?P<rrname>[^/]*)', $domainsHandler);
	$router->addRoute('(GET|DELETE) /domains/(?P<domain>[^/]+)/(?P<record>record)/(?P<rrname>[^/]*)/(?P<rrtype>[^/]+)', $domainsHandler);



	$adminDomainsHandler = new AdminDomains();
	$router->addRoute('(GET|POST) /admin/domains', $adminDomainsHandler);
	$router->addRoute('(GET|POST|DELETE) /admin/domains/(?P<domain>[^/]+)', $adminDomainsHandler);

	$router->addRoute('(GET|POST) /admin/domains/(?P<domain>[^/]+)/(?P<access>access)', $adminDomainsHandler);
	$router->addRoute('(GET) /admin/domains/(?P<domain>[^/]+)/(?P<sync>sync)', $adminDomainsHandler);
	$router->addRoute('(GET) /admin/domains/(?P<domain>[^/]+)/(?P<export>export)', $adminDomainsHandler);
	$router->addRoute('(POST) /admin/domains/(?P<domain>[^/]+)/(?P<import>import)', $adminDomainsHandler);

	$router->addRoute('(GET|POST|DELETE) /admin/domains/(?P<domain>[^/]+)/(?P<records>records)', $adminDomainsHandler);
	$router->addRoute('(GET|POST|DELETE) /admin/domains/(?P<domain>[^/]+)/(?P<records>records)/(?P<recordid>[0-9]+)', $adminDomainsHandler);

	$router->addRoute('(GET|DELETE) /admin/domains/(?P<domain>[^/]+)/(?P<record>record)/(?P<rrname>[^/]*)', $adminDomainsHandler);
	$router->addRoute('(GET|DELETE) /admin/domains/(?P<domain>[^/]+)/(?P<record>record)/(?P<rrname>[^/]*)/(?P<rrtype>[^/]+)', $adminDomainsHandler);
