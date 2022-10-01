<?php

	use shanemcc\phpdb\ValidationFailed;

	class HTTPReq extends RouterMethod {
		public function check() {
			if ($this->getContextKey('user') == NULL) {
				throw new RouterMethod_NeedsAuthentication();
			}

			$this->checkPermissions(['domains_write']);
		}

		private function checkUserCanEditRecord($rrtype, $name) {
			$key = $this->hasContextKey('domainkey') ? $this->getContextKey('domainkey') : $this->getContextKey('key');
			if ($key instanceof APIKey || $key instanceof DomainKey) {
				if (!$key->canEditRecord($rrtype, $name)) {
					$this->getContextKey('response')->sendError('This API Key has a regex restriction and is not permitted to make changes to: "' . $rrtype . ' ' . $name . '".');
				}
			}
		}

		protected function checkAccess($domain, $required) {
			if ($domain !== FALSE && !in_array($domain->getAccess($this->getContextKey('user')), $required)) {
				$this->getContextKey('response')->sendError('You do not have the required access to the domain: ' . $domain->getDomain());
			}

			return true;
		}

		public function doRun($deleteOnly) {
			$user = $this->getContextKey('user');
			$data = $this->getContextKey('data');

			// Handle raw mode.
			if (isset($data['domain'])) {
				$info = $data;
				$data = ['fqdn' => '_acme-challenge.' . $info['domain'] . '.', 'value' => ''];

				if (isset($data['keyAuth']) && !empty($data['keyAuth'])) {
					if (!startsWith($info['keyAuth'], $info['token'] . '.')) {
						$info['keyAuth'] = $info['token'] . '.' . $info['keyAuth'];
					}

					$data['value'] = rtrim(base64_encode(hash("sha256", $info['keyAuth'], true)), '=');
				}
			}

			if (!isset($data['fqdn'])) {
				$this->getContextKey('response')->sendError('Invalid data provided.');
			}
			if (!isset($data['value'])) {
				$data['value'] = '';
			}

			$data['fqdn'] = rtrim($data['fqdn'], '.');

			$wantedRecordFull = $wantedRecord = $data['fqdn'];

			$this->checkUserCanEditRecord('TXT', $data['fqdn']);

			$domain = Record::findDomainForRecord($this->getContextKey('user'), $wantedRecord);
			if ($domain == FALSE) {
				$this->getContextKey('response')->sendError('No matching domains found for: ' . $wantedRecord);
			}

			$this->checkAccess($domain, ['write', 'admin', 'owner']);

			$wantedRecord = preg_replace('#\.?' . preg_quote($domain->getDomain(), '#') . '$#', '', $wantedRecord);

			$this->getContextKey('db')->beginTransaction();

			$error = FALSE;
			$result = [];
			$deleted = [];
			$added = [];

			$existing = $domain->getRecords($wantedRecord, 'TXT');
			foreach ($existing as $record) {
				if ($record->getContent() == $data['value'] || empty($data['value'])) {
					$r = ['id' => $record->getID(), 'content' => $record->getContent(), 'deleted' => $record->delete()];
					$result[] = $r;

					if ($r['deleted']) {
						$deleted[] = $record;
					} else {
						$error = TRUE;
					}
				}
			}

			if (isset($data['value']) && !empty($data['value']) && !$deleteOnly) {
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
					// $r['name'] = preg_replace('#\.?' . preg_quote($domain->getDomain(), '#') . '$#', '', do_idn_to_utf8($r['name']));
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
				EventQueue::get()->publish('record.delete', [$domain->getID(), $record->getID(), json_encode($record)]);
			}
			foreach ($added as $record) {
				EventQueue::get()->publish('record.add', [$domain->getID(), $record->getID()]);
			}

			if (!empty($result)) {
				$serial = $domain->updateSerial();
				EventQueue::get()->publish('record.update', [$domain->getID(), $domain->getSOARecord()->getID()]);
				EventQueue::get()->publish('domain.records.changed', [$domain->getID()]);
				EventQueue::get()->publish('domain.hooks.call', [$domain->getID(), ['domain' => $domain->getDomainRaw(), 'type' => 'records_changed', 'reason' => 'update_records', 'serial' => $serial, 'time' => time()]]);
			} else {
				$serial = $domain->getSOARecord()->parseSOA()['serial'];
			}

			$this->getContextKey('response')->data(['serial' => $serial, 'changed' => $result]);
			return TRUE;
		}
	};

	$router->post('/external/httpreq/present', new class extends HTTPReq {
		function run() {
			return $this->doRun(false);
		}
	});

	$router->post('/external/httpreq/cleanup', new class extends HTTPReq {
		function run() {
			return $this->doRun(true);
		}
	});

	$router->get('/external/httpreq/ping(?:/(.+))?', new class extends RouterMethod {
		function run($time = null) {
			if ($time != null) {
				$this->getContext()['response']->set('time', $time);
			}

			return TRUE;
		}
	});
