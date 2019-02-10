<?php

	use shanemcc\phpdb\ValidationFailed;

	class HTTPReq extends RouterMethod {
		public function check() {
			if ($this->getContextKey('user') == NULL) {
				throw new RouterMethod_NeedsAuthentication();
			}

			$this->checkPermissions(['domains_write']);
		}

		protected function checkAccess($domain, $required) {
			if ($domain !== FALSE && !in_array($domain->getAccess($this->getContextKey('user')), $required)) {
				$this->getContextKey('response')->sendError('You do not have the required access to the domain: ' . $domain->getDomain());
			}

			return true;
		}

		protected function findDomain($domain) {
			$domains = [];

			// Remove trailing .
			$domain = rtrim($domain, '.');

			// Convert the requested domain into an array (eg foo.bar.baz.example.com => [foo, bar, baz, example, com])
			$bits = explode('.', $domain);

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

			return $domains;
		}

		public function doRun($deleteOnly) {
			$user = $this->getContextKey('user');
			$data = $this->getContextKey('data');

			$data['fqdn'] = rtrim($data['fqdn'], '.');

			$wantedRecordFull = $wantedRecord = $data['fqdn'];

			$domains = $this->findDomain($wantedRecord);
			if (empty($domains)) {
				$this->getContextKey('response')->sendError('No matching domains found for: ' . $wantedRecord);
			} else if (count($domains) > 1) {
				$this->getContextKey('response')->sendError('Too many matching domains found for: ' . $wantedRecord);
			}

			$domain = $domains[0];

			$this->checkAccess($domain, ['write', 'admin', 'owner']);

			$wantedRecord = preg_replace('#\.?' . preg_quote($domain->getDomain(), '#') . '$#', '', $wantedRecord);

			$this->getContextKey('db')->beginTransaction();

			$error = FALSE;
			$result = [];
			$deleted = [];
			$added = [];

			$existing = $domain->getRecords($wantedRecord, 'TXT');
			foreach ($existing as $record) {
				$r = ['id' => $record->getID(), 'deleted' => $record->delete()];
				$result[] = $r;

				if ($r['deleted']) {
					$deleted[] = $record;
				} else {
					$error = TRUE;
				}
			}

			if (isset($data['value']) && !$deleteOnly) {
				$value = $data['value'];
				$record = (new Record($domain->getDB()))->setDomainID($domain->getID());
				$record->setName($wantedRecordFull);
				$record->setType('TXT');
				$record->setTTL('60');
				$record->setContent($value);
				$record->setChangedAt(time());
				$record->setChangedBy($this->getContextKey('user')->getID());

				$r = $record->toArray();
				unset($r['domain_id']);

				try {
					$record->validate();
				} catch (ValidationFailed $ex) {
					$error = TRUE;
				}

				if (!$error) {
					$r['updated'] = $record->save();
					$r['id'] = $record->getID();
					// $r['name'] = preg_replace('#\.?' . preg_quote($domain->getDomain(), '#') . '$#', '', idn_to_utf8($r['name']));
					$result[] = $r;
				}
			}


			if ($error) {
				$this->getContextKey('db')->rollback();
				$this->getContextKey('response')->sendError('There was an error.');
			}

			$this->getContextKey('db')->commit();

			// Call various hooks.
			foreach ($deleted as $record) {
				HookManager::get()->handle('delete_record', [$domain, $record]);
			}
			foreach ($added as $record) {
				HookManager::get()->handle('add_record', [$domain, $record]);
			}

			if (!empty($result)) {
				$serial = $domain->updateSerial();
				HookManager::get()->handle('update_record', [$domain, $domain->getSOARecord()]);
				HookManager::get()->handle('records_changed', [$domain]);
				HookManager::get()->handle('call_domain_hooks', [$domain, ['domain' => $domain->getDomainRaw(), 'type' => 'records_changed', 'reason' => 'update_records', 'serial' => $serial, 'time' => time()]]);
			} else {
				$serial = $domain->getSOARecord()->parseSOA()['serial'];
			}

			$this->getContextKey('response')->data(['serial' => $serial, 'changed' => $result]);
			return TRUE;
		}
	};

	$router->post('/present', new class extends HTTPReq { function run() { return $this->doRun(false); } });
	$router->post('/cleanup', new class extends HTTPReq { function run() { return $this->doRun(true); } });

