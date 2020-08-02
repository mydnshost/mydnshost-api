<?php

	class BindZoneFileHandler extends ZoneFileHandler {

		public function parseZoneFile($domainName, $data) {

			$result = [];

			$tmpname = tempnam('/tmp', 'ZONEIMPORT');
			if ($tmpname === FALSE) {
				throw new Exception('Internal Error.');
			}

			file_put_contents($tmpname, $data);

			$bind = new Bind($domainName, '', $tmpname);
			$bind->parseZoneFile();
			unlink($tmpname);

			$result['soa'] = $bind->getSOA();
			$result['records'] = [];

			foreach ($bind->getDomainInfo() as $rrtype => $info) {
				if ($rrtype == 'SOA' || $rrtype == ' META ') { continue; }

				$result['records'][$rrtype] = $info;
			}

			return $result;
		}

		public function generateZoneFile($domainName, $data) {
			$bind = new Bind($domainName, '');
			$bind->clearRecords();

			$bind->setSOA($data['soa']);

			if ($data['records'] instanceof RecordsInfo) { $data['records'] = $data['records']->get(); }

			foreach ($data['records'] as $type => $entries) {
				foreach ($entries as $rname => $records) {
					foreach ($records as $record) {
						$bind->setRecord($rname, $type, $record['Address'], $record['TTL'], isset($record['Priority']) ? $record['Priority'] : NULL);
					}
				}
			}

			return implode("\n", $bind->getParsedZoneFile());
		}

	}
