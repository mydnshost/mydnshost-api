<?php

	class Domains extends APIMethod {
		public function check($requestMethod, $matches) {
			if ($this->getContextKey('user') == NULL) {
				throw new APIMethod_NeedsAuthentication();
			}
		}

		public function get($matches) {
			if (isset($matches['recordid'])) {
				return $this->getRecordID($matches['domain'], $matches['recordid']);
			} else if (isset($matches['records'])) {
				return $this->getRecords($matches['domain']);
			} else if (isset($matches['domain'])) {
				return $this->getDomainInfo($matches['domain']);
			} else {
				return $this->getDomainList();
			}

			return FALSE;
		}

		public function post($matches) {
			if (isset($matches['recordid'])) {
				return $this->updateRecordID($matches['domain'], $matches['recordid']);
			} else if (isset($matches['records'])) {
				return $this->updateRecords($matches['domain']);
			}

			return FALSE;
		}

		public function delete($matches) {
			if (isset($matches['recordid'])) {
				return $this->deleteRecordID($matches['domain'], $matches['recordid']);
			} else if (isset($matches['records'])) {
				return $this->deleteRecords($matches['domain']);
			} else if (isset($matches['domain'])) {
				return $this->deleteDomain($matches['domain']);
			}

			return FALSE;
		}

		private function getRecordID($domain, $record) {
			$dom = $this->getContextKey('user')->getDomainByName($this->getContextKey('db'), $domain);
			if ($dom === FALSE) { $this->getContextKey('response')->sendError('Unknown domain: ' . $domain); }

			$rec = $dom->getRecord($this->getContextKey('db'), $record);
			if ($rec === FALSE) { $this->getContextKey('response')->sendError('Unknown record id for domain ' . $domain . ' : ' . $record); }

			$r = $rec->toArray();
			unset($r['domain_id']);

			$this->getContextKey('response')->data($r);

			return true;
		}

		private function getRecords($domain) {
			$dom = $this->getContextKey('user')->getDomainByName($this->getContextKey('db'), $domain);
			if ($dom === FALSE) { $this->getContextKey('response')->sendError('Unknown domain: ' . $domain); }

			$records = $dom->getRecords($this->getContextKey('db'));
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
			$dom = $this->getContextKey('user')->getDomainByName($this->getContextKey('db'), $domain);
			if ($dom === FALSE) { $this->getContextKey('response')->sendError('Unknown domain: ' . $domain); }

			$r = $dom->toArray();
			unset($r['owner']);

			$this->getContextKey('response')->data($r);

			return true;
		}

		private function getDomainList() {
			$domains = $this->getContextKey('user')->getDomains($this->getContextKey('db'));
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
