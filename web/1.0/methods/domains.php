<?php

	class AdminDomains extends Domains {
		public function check($requestMethod, $params) {
			if ($this->getContextKey('user') == NULL) {
				throw new APIMethod_NeedsAuthentication();
			}
			if (!$this->getContextKey('user')->isAdmin()) {
				throw new APIMethod_AccessDenied();
			}
		}

		/**
		 * Admin getDomainFromParam looks for the domain without checking
		 * that the current user can see it.
		 */
		protected function getDomainFromParam($params) {
			if (isset($params['domain'])) {
				$domain = Domain::loadFromDomain($this->getContextKey('db'), $params['domain']);
				if ($domain === FALSE) {
					$this->getContextKey('response')->sendError('Unknown domain: ' . $params['domain']);
				}
			} else {
				$domain = FALSE;
			}

			return $domain;
		}

		public function post($params) {
			$parent = parent::post($params);

			if ($parent === FALSE && !isset($params['domain'])) {
				return $this->createDomain();
			}

			return $parent;
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
			$domain->setAccess($this->getContextKey('user')->getID(), 'Owner');

			if (isset($data['data']['domain'])) {
				$domain->setDomain($data['data']['domain']);
			} else {
				$this->getContextKey('response')->sendError('No domain name provided for create.');
			}

			return $this->updateDomain($domain);
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
			$domain = $this->getDomainFromParam($params);

			if (isset($params['recordid'])) {
				$record = $this->getRecordFromParam($domain, $params);

				return $this->getRecordID($domain, $record);
			} else if (isset($params['records'])) {
				return $this->getRecords($domain);
			} else if (isset($params['access'])) {
				return $this->getDomainAccess($domain);
			} else if (isset($params['domain'])) {
				return $this->getDomainInfo($domain);
			} else {
				return $this->getDomainList();
			}

			return FALSE;
		}

		public function post($params) {
			$domain = $this->getDomainFromParam($params);

			$this->checkAccess($domain, ['write', 'admin', 'owner']);

			if (isset($params['recordid'])) {
				$record = $this->getRecordFromParam($domain, $params);

				return $this->updateRecordID($domain, $record);
			} else if (isset($params['records'])) {
				return $this->updateRecords($domain);
			} else if (isset($params['access'])) {
				return $this->updateDomainAccess($domain);
			} else if ($domain !== FALSE) {
				return $this->updateDomain($domain);
			}

			return FALSE;
		}

		public function delete($params) {
			$domain = $this->getDomainFromParam($params);

			$this->checkAccess($domain, ['write', 'admin', 'owner']);

			if (isset($params['recordid'])) {
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
		 * @return TRUE if we handled this method.
		 */
		protected function getRecords($domain) {
			$records = $domain->getRecords();
			$list = [];
			foreach ($records as $record) {
				$r = $record->toArray();
				unset($r['domain_id']);
				$list[] = $r;
			}
			$this->getContextKey('response')->set('records', $list);
			$this->getContextKey('response')->set('soa', $domain->getSOARecord()->parseSOA());

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
					$this->getContextKey('response')->sendError('No such user: ' . $user);
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
		 * Update this domain.
		 *
		 * @param $domain Domain object based on the 'domain' parameter.
		 * @return TRUE if we handled this method.
		 */
		protected function updateDomain($domain) {
			$data = $this->getContextKey('data');
			if (!isset($data['data']) || !is_array($data['data'])) {
				$this->getContextKey('response')->sendError('No data provided for update.');
			}

			$this->doUpdateDomain($domain, $data['data'], (false && $this->getContextKey('user')->isAdmin()));

			try {
				$domain->validate();
				$domain->getSOARecord()->validate();

				if (!$domain->save()) {
					throw new ValidationFailed($domain->getLastError()[2]);
				}
				$domain->getSOARecord()->setDomainID($domain->getID());
				if (!$domain->getSOARecord()->save()) {
					throw new ValidationFailed($domain->getSOARecord()->getLastError()[2]);
				}
			} catch (ValidationFailed $ex) {
				$this->getContextKey('response')->sendError('Error updating domain.', $ex->getMessage());
			}

			$r = $domain->toArray();

			$soa = $domain->getSOARecord();
			$r['SOA'] = ($soa === FALSE) ? FALSE : $soa->parseSOA();

			$this->getContextKey('response')->data($r);
			return true;
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

			$record = $this->doUpdateRecord($record, $data['data']);

			try {
				$record->validate();
				$record->save();
				$serial = $domain->updateSerial();
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
						$this->doUpdateRecord($record, $r);
						try {
							$record->validate();
							$recordsToBeSaved[] = $record;
						} catch (ValidationFailed $ex) {
							$errors[$i] = 'Unable to validate record: ' . $ex->getMessage();
							continue;
						}
					}
				}
			}

			if (count($errors) > 0) {
				$this->getContextKey('response')->sendError('There was errors with the records provided.', $errors);
			}

			$result = array();
			foreach ($recordsToBeSaved as $record) {
				$r = $record->toArray();
				unset($r['domain_id']);
				$r['updated'] = $record->save();
				$r['id'] = $record->getID();
				$result[] = $r;
			}

			foreach ($recordsToBeDeleted as $record) {
				$result[] = ['id' => $record->getID(), 'deleted' => $record->delete()];
			}

			$serial = $domain->updateSerial();

			$this->getContextKey('response')->data(['serial' => $serial, 'changed' => $result]);
			return true;
		}

		/**
		 * Actually update a record.
		 *
		 * @param $record Record object to update.
		 * @param $data Data to use to update the record.
		 * @return The record after being updated.
		 */
		protected function doUpdateRecord($record, $data) {
			$keys = array('name' => 'setName',
			              'type' => 'setType',
			              'content' => 'setContent',
			              'ttl' => 'setTTL',
			              'priority' => 'setPriority',
			              'disabled' => 'setDisabled',
			             );

			foreach ($keys as $k => $f) {
				if (array_key_exists($k, $data)) {
					$record->$f($data[$k]);
				}
			}

			$record->setSynced(false);
			$record->setChangedAt(time());
			$record->setChangedBy($this->getContextKey('user')->getID());

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
			$this->getContextKey('response')->set('deleted', $record->delete() ? 'true' : 'false');
			$serial = $domain->updateSerial();
			$this->getContextKey('response')->set('serial', $serial);
			return true;
		}

		/**
		 * Delete all records for a domain.
		 *
		 * @param $domain Domain object based on the 'domain' parameter.
		 * @return TRUE if we handled this method.
		 */
		protected function deleteRecords($domain) {
			$records = $domain->getRecords();
			$count = 0;
			foreach ($records as $record) {
				if ($record->delete()) { $count++; }
			}
			$this->getContextKey('response')->set('deleted', $count);
			$serial = $domain->updateSerial();
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
			$this->getContextKey('response')->data(['deleted', $domain->delete() ? 'true' : 'false']);
			return true;
		}

	}

	$domainsHandler = new Domains();
	$router->addRoute('(GET) /domains', $domainsHandler);
	$router->addRoute('(GET|POST|DELETE) /domains/(?P<domain>[^/]+)', $domainsHandler);
	$router->addRoute('(GET|POST) /domains/(?P<domain>[^/]+)/(?P<access>access)', $domainsHandler);
	$router->addRoute('(GET|POST|DELETE) /domains/(?P<domain>[^/]+)/(?P<records>records)', $domainsHandler);
	$router->addRoute('(GET|POST|DELETE) /domains/(?P<domain>[^/]+)/(?P<records>records)/(?P<recordid>[0-9]+)', $domainsHandler);


	$adminDomainsHandler = new AdminDomains();
	$router->addRoute('(GET|POST) /admin/domains', $adminDomainsHandler);
	$router->addRoute('(GET|POST|DELETE) /admin/domains/(?P<domain>[^/]+)', $adminDomainsHandler);
	$router->addRoute('(GET|POST) /admin/domains/(?P<domain>[^/]+)/(?P<access>access)', $adminDomainsHandler);
	$router->addRoute('(GET|POST|DELETE) /admin/domains/(?P<domain>[^/]+)/(?P<records>records)', $adminDomainsHandler);
	$router->addRoute('(GET|POST|DELETE) /admin/domains/(?P<domain>[^/]+)/(?P<records>records)/(?P<recordid>[0-9]+)', $adminDomainsHandler);
