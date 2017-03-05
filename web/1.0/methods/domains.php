<?php

	class Domains extends APIMethod {
		public function check($requestMethod, $params) {
			if ($this->getContextKey('user') == NULL) {
				throw new APIMethod_NeedsAuthentication();
			}
		}

		public function get($params) {
			if (isset($params['recordid'])) {
				return $this->getRecordID($params['domain'], $params['recordid']);
			} else if (isset($params['records'])) {
				return $this->getRecords($params['domain']);
			} else if (isset($params['domain'])) {
				return $this->getDomainInfo($params['domain']);
			} else {
				return $this->getDomainList();
			}

			return FALSE;
		}

		public function post($params) {
			if (isset($params['recordid'])) {
				return $this->updateRecordID($params['domain'], $params['recordid']);
			} else if (isset($params['records'])) {
				return $this->updateRecords($params['domain']);
			}

			return FALSE;
		}

		public function delete($params) {
			if (isset($params['recordid'])) {
				return $this->deleteRecordID($params['domain'], $params['recordid']);
			} else if (isset($params['records'])) {
				return $this->deleteRecords($params['domain']);
			} else if (isset($params['domain'])) {
				return $this->deleteDomain($params['domain']);
			}

			return FALSE;
		}

		private function getRecordID($domain, $record) {
			$dom = $this->getContextKey('user')->getDomainByName($domain);
			if ($dom === FALSE) { $this->getContextKey('response')->sendError('Unknown domain: ' . $domain); }

			$rec = $dom->getRecord($record);
			if ($rec === FALSE) { $this->getContextKey('response')->sendError('Unknown record id for domain ' . $domain . ' : ' . $record); }

			$r = $rec->toArray();
			unset($r['domain_id']);

			$this->getContextKey('response')->data($r);

			return true;
		}

		private function getRecords($domain) {
			$dom = $this->getContextKey('user')->getDomainByName($domain);
			if ($dom === FALSE) { $this->getContextKey('response')->sendError('Unknown domain: ' . $domain); }

			$records = $dom->getRecords();
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
			$dom = $this->getContextKey('user')->getDomainByName($domain);
			if ($dom === FALSE) { $this->getContextKey('response')->sendError('Unknown domain: ' . $domain); }

			$r = $dom->toArray();
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
