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

			if (isset($_REQUEST['dynamicvalue'])) {
				if (in_array($_REQUEST['dynamicvalue'], ['myip', 'myip4', 'myip6'])) {
					if (isset($_SERVER['HTTP_X_REAL_IP'])) { $remoteHost = $_SERVER['HTTP_X_REAL_IP']; }
					else if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) { $remoteHost = $_SERVER['HTTP_X_FORWARDED_FOR']; }
					else if (isset($_SERVER['HTTP_CLIENT_IP'])) { $remoteHost = $_SERVER['HTTP_CLIENT_IP']; }
					else { $remoteHost = $_SERVER['REMOTE_ADDR']; }

					if ($_REQUEST['dynamicvalue'] == 'myip4' && !filter_var($remoteHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
						$remoteHost = '';
					} else if ($_REQUEST['dynamicvalue'] == 'myip6' && !filter_var($remoteHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
						$remoteHost = '';
					}

					if (!empty($remoteHost)) {
						$_REQUEST['value'] = $remoteHost;
					}
				} else if (in_array($_REQUEST['dynamicvalue'], ['time'])) {
					$_REQUEST['value'] = time();
				}
			}

			if (!isset($_REQUEST['value'])) {
				$this->getContextKey('response')->sendError('You must specify a value.');
			}
			$value = $_REQUEST['value'];
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
				$ttl = min($record->getTTL(), $ttl);
				$disabled |= $record->isDisabled();

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
	});
