<?php

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
		private function getDomainFromParam($params) {
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
		private function getRecordFromParam($domain, $params) {
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

		public function get($params) {
			$domain = $this->getDomainFromParam($params);

			if (isset($params['recordid'])) {
				$record = $this->getRecordFromParam($domain, $params);

				return $this->getRecordID($domain, $record);
			} else if (isset($params['records'])) {
				return $this->getRecords($domain);
			} else if (isset($params['domain'])) {
				return $this->getDomainInfo($domain);
			} else {
				return $this->getDomainList();
			}

			return FALSE;
		}

		public function post($params) {
			$domain = $this->getDomainFromParam($params);

			if (isset($params['recordid'])) {
				$record = $this->getRecordFromParam($domain, $params);

				return $this->updateRecordID($domain, $record);
			} else if (isset($params['records'])) {
				return $this->updateRecords($domain);
			}

			return FALSE;
		}

		public function delete($params) {
			$domain = $this->getDomainFromParam($params);

			if (isset($params['recordid'])) {
				$record = $domain !== FALSE ? $domain->getRecord($params['recordid']) : FALSE;
				if ($record === FALSE) {
					$this->getContextKey('response')->sendError('Unknown record id for domain ' . $params['domain'] . ' : ' . $params['recordid']);
				}

				return $this->deleteRecordID($domain, $record);
			} else if (isset($params['records'])) {
				return $this->deleteRecords($domain);
			} else if (isset($params['domain'])) {
				return $this->deleteDomain($domain);
			}

			return FALSE;
		}

		private function getRecordID($domain, $record) {
			$r = $record->toArray();
			unset($r['domain_id']);

			$this->getContextKey('response')->data($r);

			return true;
		}

		private function getRecords($domain) {
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

		private function getDomainInfo($domain) {
			$r = $domain->toArray();
			unset($r['owner']);

			$soa = $domain->getSOARecord();
			$r['SOA'] = ($soa === FALSE) ? FALSE : $soa->parseSOA();

			$this->getContextKey('response')->data($r);

			return true;
		}

		private function getDomainList() {
			$domains = $this->getContextKey('user')->getDomains();
			$list = [];
			foreach ($domains as $domain) {
				$list[] = $domain->getDomain();
			}
			$this->getContextKey('response')->data($list);

			return true;
		}

		private function updateRecordID($domain, $record) {
			$data = $this->getContextKey('data');
			if (!isset($data['data']) || !is_array($data['data'])) {
				$this->getContextKey('response')->sendError('No data provided for update.');
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

		private function updateRecords($domain) {
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

		private function doUpdateRecord($record, $data) {
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

		private function deleteRecordID($domain, $record) {
			$this->getContextKey('response')->data('deleted', $record->delete() ? 'true' : 'false');
			$serial = $domain->updateSerial();
			$this->getContextKey('response')->set('serial', $serial);
			return true;
		}

		private function deleteRecords($domain) {
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

		private function deleteDomain($domain) {
			$this->getContextKey('response')->data('deleted', $domain->delete() ? 'true' : 'false');
			return true;
		}

	}

	$router->addRoute('GET /domains', new Domains());
	$router->addRoute('(GET|DELETE) /domains/(?P<domain>[^/]+)', new Domains());
	$router->addRoute('(GET|POST|DELETE) /domains/(?P<domain>[^/]+)/(?P<records>records)', new Domains());
	$router->addRoute('(GET|POST|DELETE) /domains/(?P<domain>[^/]+)/(?P<records>records)/(?P<recordid>[0-9]+)', new Domains());
