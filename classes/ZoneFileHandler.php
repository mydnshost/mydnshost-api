<?php

	abstract class ZoneFileHandler {
		/**
		 * Take a zone file, and return parsed data.
		 *
		 * @param  $domainName Domain name this data represents.
		 * @param  $data zone data.
		 * @return Array representing parsed data.
		 *         The array will contain an 'soa' and 'records' section.
		 *         'soa' contains the details from the SOA record, Keys: ['Nameserver', 'Email', 'Serial', 'Refresh', 'Retry', 'Expire', 'MinTTL']
		 *         'records' is an array of rrtypes and records, eg:
		 *         'A' => ['www' => [['Address' => '10.0.0.1', 'TTL' => '3600'], ['Address' => '10.0.0.2', 'TTL' => '3600']]],
		 *         'MX' => ['' => [['Address' => 'mail', 'TTL' => '3600', 'Priority' => 10], ['Address' => 'mail2', 'TTL' => '3600', 'Priority' => 20]]],
		 *         etc.
		 */
		abstract public function parseZoneFile($domainName, $data);

		/**
		 * Take parsed zone data and produce a zone file.
		 *
		 * @param  $domainName Domain name this data represents.
		 * @param  $data zone data (in the same format as parseZoneFile outputs)
		 * @return String representing a zone file.
		 */
		abstract public function generateZoneFile($domainName, $data);
	}
