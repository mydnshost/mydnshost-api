<?php
	if (isset($config['powerdns']['enabled']) && parseBool($config['powerdns']['enabled'])) {

		// Default config settings
		$config['powerdns']['defaults']['apikey'] = 'SOMEAPIKEY';

		foreach ($config['powerdns']['defaults'] as $setting => $value) {
			if (!isset($config['powerdns'][$setting])) {
				$config['powerdns'][$setting] = $value;
			}
		}
		$pdnsConfig = $config['powerdns'];

		$updateMasterServer = function($domain) use ($pdnsConfig) {
			$pdns = new PowerDNS($pdnsConfig['master'], $domain->getDomain());

			$records = array();
			$records[] = $domain->getSOARecord();

			$hasNS = false;

			foreach ($domain->getRecords() as $record) {
				if ($record->getType() == "NS" && $record->getName() == $domain->getDomain()) {
					$hasNS = true;
				}

				$records[] = $record;
			}

			if ($hasNS) {
				$pdns->clearRecords();
				$pdns->setRecords($records);
			}
		};

		HookManager::get()->addHook('add_domain', $updateMasterServer);
		HookManager::get()->addHook('update_domain', $updateMasterServer);
		HookManager::get()->addHook('records_changed', $updateMasterServer);

		HookManager::get()->addHook('delete_domain', function($domain) use ($pdnsConfig) {
			$pdns = new PowerDNS($pdnsConfig['master'], $domain->getDomain());
			$pdns->removeDomain();
		});
	}


	class PowerDNS {
		/** Our server configuration. */
		private $server;
		/** Our domain name */
		private $domain;

		/**
		 * Create a new PowerDNS
		 *
		 * @param $server Server configuration
		 * @param $domain Domain Name
		 */
		public function __construct($server, $domain) {

		}

		public function clearRecords() {

		}

		public function setRecords($records) {

		}

		public function removeDomain() {

		}
	}
