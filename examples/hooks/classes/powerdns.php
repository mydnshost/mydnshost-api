<?php
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
			$this->server = $server;
			$this->domain = $domain;
		}

		private function recordsArrayToPDNS($records) {
			$data = [];

			foreach ($records as $record) {
				$name = $record['name'];
				$type = $record['type'];
				$content = $record['content'];
				$ttl = $record['ttl'];
				$priority = isset($record['priority']) ? $record['priority'] : null;
				$disabled = $record['disabled'];

 				if ($type == "TXT") {
					$content = '"' . $content . '"';
				} else if (in_array($type, ['CNAME', 'NS', 'MX', 'SRV', 'PTR'])) {
					$content .= '.';
				}

				if (!array_key_exists($name, $data)) { $data[$name] = []; }
				if (!array_key_exists($type, $data[$name])) { $data[$name][$type] = ['ttl' => $ttl, 'records' => []]; }

				$data[$name][$type]['ttl'] = min($data[$name][$type]['ttl'], $ttl);
				$rec = ['content' => $content, 'disabled' => $this->parseBool($disabled)];

				if ($priority != null) {
					$rec['content'] = $priority . ' ' . $rec['content'];
				}

				$data[$name][$type]['records'][] = $rec;
			}

			return $data;
		}

		private function pdnsToRecordsArray($rrsets) {
			$records = [];
			foreach ($rrsets as $rrset) {
				if (array_key_exists('records', $rrset)) {
					foreach ($rrset['records'] as $record) {
						$r = ['name' => rtrim($rrset['name'], '.'),
						      'type' => $rrset['type'],
						      'content' => $record['content'],
						      'ttl' => $rrset['ttl'],
						      'disabled' => $record['disabled'],
						     ];

						if (in_array($r['type'], ['MX', 'SRV'])) {
							$bits = explode(' ', $r['content'], 2);
							$r['priority'] = $bits[0];
							$r['content'] = isset($bits[1]) ? $bits[1] : '';
						}

						if ($r['type'] == "TXT") {
							$r['content'] = preg_replace('#^"(.*)"$#', '\1', $r['content']);
						} else if (in_array($r['type'], ['CNAME', 'NS', 'MX', 'SRV', 'PTR'])) {
							$r['content'] = rtrim($r['content'], '.');
						}

						$records[] = $r;
					}
				}
			}

			return $records;
		}

		private function parseBool($input) {
			$in = strtolower($input);
			return ($in === true || $in == 'true' || $in == '1' || $in == 'on' || $in == 'yes');
		}

		public function setRecords($records) {
			$newRecords = $this->recordsArrayToPDNS($records);
			$oldRecords = $this->recordsArrayToPDNS($this->getRecords());

			$postData = ['rrsets' => []];

			// Set New Records
			foreach ($newRecords as $name => $data_name) {
				foreach ($data_name as $type => $record) {

					$rrset = ['name' => $name . '.',
					          'type' => $type,
					          'changetype' => 'REPLACE',
					          'ttl' => $record['ttl'],
					          'records' => $record['records'],
					         ];
					$postData['rrsets'][] = $rrset;
				}
			}

			// Delete records that are no longer found.
			foreach ($oldRecords as $name => $data_name) {
				foreach ($data_name as $type => $record) {
					if (!array_key_exists($name, $newRecords) || !array_key_exists($type, $newRecords[$name])) {
						$rrset = ['name' => $name . '.',
						          'type' => $type,
						          'changetype' => 'DELETE',
						         ];

						// Don't clear the SOA.
						if ($name != $this->domain || $type != 'SOA') {
							$postData['rrsets'][] = $rrset;
						}
					}
				}
			}

			$res = $this->api('zones/' . $this->domain . '.', 'PATCH', json_encode($postData));
			return $res;
		}

		public function clearRecords() {
			return $this->setRecords([]);
		}

		public function getRecords() {
			$data = json_decode($this->api('zones/' . $this->domain . '.'), true);

			$records = [];
			if (array_key_exists('rrsets', $data)) {
				$records = $this->pdnsToRecordsArray($data['rrsets']);
			}

			return $records;
		}

		public function removeDomain() {
			$res = $this->api('zones/' . $this->domain . '.', 'DELETE');
			return $res;
		}

		public function domainExists() {
			$data = json_decode($this->api('zones/' . $this->domain . '.'), true);

			return isset($data['id']);
		}

		public function createDomain($kind = 'native') {
			$data = ['name' => $this->domain . '.',
			         'kind' => $kind,
			         'nameservers' => []];

			return $this->api('zones', 'POST', json_encode($data));
		}

		public function notify() {
			$res = $this->api('zones/' . $this->domain . './notify');
			return $res;
		}

		public function api($apimethod, $method = 'GET', $data = []) {
			$host = array_key_exists('host', $this->server) ? $this->server['host'] : '127.0.0.1';
			$port = array_key_exists('port', $this->server) ? $this->server['port'] : '8081';
			$key = array_key_exists('apikey', $this->server) ? $this->server['apikey'] : 'changeme';
			$servername = array_key_exists('servername', $this->server) ? $this->server['servername'] : 'localhost';

			$headers = ['X-API-Key: ' . $key];

			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, sprintf('http://%s:%d/api/v1/servers/%s/%s', $host, $port, $servername, $apimethod));
			if ($method != 'GET') {
				curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
			}

			if ($method == 'POST' || $method == 'PUT' || $method == 'PATCH') {
				curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
				$headers[] = 'Content-Type: application/json';
			}
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

			$result = curl_exec($ch);
			curl_close($ch);
			return $result;
		}
	}
