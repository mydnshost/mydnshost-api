<?php

	class Domains extends APIMethod {
		public function check($requestMethod, $params) {
			if ($this->getContextKey('user') == NULL) {
				throw new APIMethod_NeedsAuthentication();
			}
		}

		private function getDomainFromParam($params) {
			if (isset($params['domain'])) {
				$domain = $this->getContextKey('user')->getDomainByName($params['domain']);
				if ($domain === FALSE) {
					$this->getContextKey('response')->sendError('Unknown domain: ' . $domain);
				}
			} else {
				$domain = FALSE;
			}

			return $domain;
		}

		public function get($params) {
			$domain = $this->getDomainFromParam($params);

			if (isset($params['recordid'])) {
				$record = $domain !== FALSE ? $domain->getRecord($params['recordid']) : FALSE;
				if ($record === FALSE) {
					$this->getContextKey('response')->sendError('Unknown record id for domain ' . $params['domain'] . ' : ' . $params['recordid']);
				}

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
				$record = $domain !== FALSE ? $domain->getRecord($params['recordid']) : FALSE;
				if ($record === FALSE) {
					$this->getContextKey('response')->sendError('Unknown record id for domain ' . $params['domain'] . ' : ' . $params['recordid']);
				}

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
			return true;
		}

		private function deleteRecords($domain) {
			return true;
		}

		private function deleteDomain($domain) {
			return true;
		}

	}

	$router->addRoute('GET /domains', new Domains());
	$router->addRoute('(GET|DELETE) /domains/(?P<domain>[^/]+)', new Domains());
	$router->addRoute('(GET|POST|DELETE) /domains/(?P<domain>[^/]+)/(?P<records>records)', new Domains());
	$router->addRoute('(GET|POST|DELETE) /domains/(?P<domain>[^/]+)/(?P<records>records)/(?P<recordid>[0-9]+)', new Domains());
