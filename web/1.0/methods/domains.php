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

			return true;
		}

		private function getDomainInfo($domain) {
			$r = $domain->toArray();
			unset($r['owner']);

			$soa = $domain->getSOARecord();
			if ($soa !== FALSE) {
				$soa = $soa->toArray();
				unset($soa['domain_id']);
			}
			$r['SOA'] = $soa;

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
			return true;
		}

		private function updateRecords($domain) {
			return true;
		}

		private function deleteRecordID($domain, $record) {
			$this->getContextKey('response')->data('deleted', $record->delete() ? 'true' : 'false');
			return true;
		}

		private function deleteRecords($domain) {
			$records = $domain->getRecords();
			$count = 0;
			foreach ($records as $record) {
				if ($record->delete()) { $count++; }
			}
			$this->getContextKey('response')->set('deleted', $count);

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
