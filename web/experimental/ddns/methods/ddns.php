<?php

	use shanemcc\phpdb\ValidationFailed;

	$router->addRoute('(GET|POST)', '/setrecord/([^/]+)/([^/]+)/([^/]*)/([^/]+)', new class extends RouterMethod {
		public function check($domainName, $domainKey, $rrname, $rrtype) {
			$valid = false;

			$context = $this->getContext();

			$domain = Domain::loadFromDomain($context['db'], $domainName);

			if ($domain != FALSE) {
				$key = DomainKey::loadFromDomainKey($context['db'], $domain->getID(), $domainKey);

				if ($key != FALSE) {
					$user = $key->getDomainKeyUser();

					$context['user'] = $user;
					$context['access'] = ['domains_read' => true, 'domains_write' => (true && $key->getDomainWrite())];
					$context['domainkey'] = $key;
					$this->setContext($context);

					$key->setLastUsed(time())->save();
				}
			}

			if ($this->getContextKey('user') == NULL) {
				throw new RouterMethod_NeedsAuthentication();
			}

			$this->checkPermissions(['domains_write']);
		}

		public function run($domainName, $domainKey, $rrname, $rrtype) {
			$domain = Domain::loadFromDomain($this->getContextKey('db'), $domainName);

			// TODO: Check for domain key recordregex when we add it.

			if (isset($_REQUEST['dynamiccontent'])) {
				if (in_array($_REQUEST['dynamiccontent'], ['myip', 'myip4', 'myip6'])) {
					if (isset($_SERVER['HTTP_X_REAL_IP'])) { $remoteHost = $_SERVER['HTTP_X_REAL_IP']; }
					else if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) { $remoteHost = $_SERVER['HTTP_X_FORWARDED_FOR']; }
					else if (isset($_SERVER['HTTP_CLIENT_IP'])) { $remoteHost = $_SERVER['HTTP_CLIENT_IP']; }
					else { $remoteHost = $_SERVER['REMOTE_ADDR']; }

					if ($_REQUEST['dynamiccontent'] == 'myip4' && !filter_var($remoteHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
						$remoteHost = '';
					} else if ($_REQUEST['dynamiccontent'] == 'myip6' && !filter_var($remoteHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
						$remoteHost = '';
					}

					if (!empty($remoteHost)) {
						$_REQUEST['content'] = $remoteHost;
					}
				} else if (in_array($_REQUEST['dynamiccontent'], ['time'])) {
					$_REQUEST['content'] = time();
				}
			}

			if (!isset($_REQUEST['content'])) {
				$this->getContextKey('response')->sendError('You must specify a content.');
			}
			$value = $_REQUEST['content'];
			$this->getContextKey('db')->beginTransaction();

			$ttl = 86400;
			$disabled = FALSE;

			$error = FALSE;
			$errorData = [];
			$result = [];
			$deleted = [];
			$added = [];

			$existing = $domain->getRecords($rrname, $rrtype);
			foreach ($existing as $record) {
				// If value is the same as what we are setting then we don't
				// delete this record, and then don't create a new one.
				if ($record->getContent() == $value) {
					$value = '';
					continue;
				}

				$ttl = min($record->getTTL(), $ttl);
				$disabled = $disabled || $record->isDisabled();

				$r = ['id' => $record->getID(), 'deleted' => $record->delete()];
				$result[] = $r;

				if ($r['deleted']) {
					$deleted[] = $record;
				} else {
					$error = TRUE;
				}
			}

			if (!empty($value)) {
				$record = (new Record($domain->getDB()))->setDomainID($domain->getID());

				$setName = $rrname;
				if ($setName == '@') { $setName = ''; }

				if (!empty($setName) && endsWith($setName, $domain->getDomain() . '.')) {
					$setName = rtrim($setName, '.');
				} else {
					if (!empty($setName) && !endsWith($setName, '.')) { $setName .= '.'; }
					$setName .= $domain->getDomain();
				}

				$record->setName($setName);
				$record->setType($rrtype);
				$record->setTTL($ttl);
				$record->setContent($value);
				$record->setDisabled($disabled);
				$record->setChangedAt(time());
				$record->setChangedBy($this->getContextKey('user')->getID());

				$r = $record->toArray();
				unset($r['domain_id']);

				try {
					$record->validate();
				} catch (ValidationFailed $ex) {
					$error = TRUE;
					$errorData[] = $ex->getMessage();
				}

				if (!$error) {
					$r['updated'] = $record->save();
					$r['id'] = $record->getID();
					$result[] = $r;
				}
			}


			if ($error) {
				$this->getContextKey('db')->rollback();
				$this->getContextKey('response')->sendError('There was an error.', $errorData);
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
	});
