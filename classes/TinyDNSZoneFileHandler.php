<?php

	class TinyDNSZoneFileHandler extends ZoneFileHandler {

		public function parseZoneFile($domainName, $data) {
			$result = [];
			$result['soa'] = ['Nameserver' => null,
			                  'Email' => null,
			                  'Serial' => 0,
			                  'Refresh' => 16384,
			                  'Retry' => 2048,
			                  'Expire' => 1048576,
			                  'MinTTL' => 2560];
			$result['records'] = [];

			$records = new RecordsInfo();

			$data = explode("\n", $data);
			foreach ($data as $line) {
				$line = trim($line);
				if (empty($line)) { continue; }

				$type = substr($line, 0, 1);
				$bits = explode(':', substr($line, 1));

				if ($type == '#') { // Comment
					continue;
				} else if ($type == '-') { // Ignored
					continue;
				} else if ($type == '%') { // Location
					throw new Exception('Location-Data is not supported: ' . $line);

				} else if ($type == '.' || $type == '&') { // Nameserver (and SOA if '.')
					if (!isset($bits[1])) { throw new Exception('Invalid Line: ' . $line); }

					$fqdn = $bits[0] . '.';
					$ip = $bits[1];
					$x = isset($bits[2]) ? $bits[2] : 'ns.' . $fqdn;
					$ttl = isset($bits[3]) ? $bits[3] : '';
					$timestamp = isset($bits[4]) ? $bits[4] : '';
					$location = isset($bits[5]) ? $bits[5] : '';

					if (empty($ttl) || $ttl < 0) { $ttl = 300; }
					if (!empty($timestamp)) { throw new Exception('Timestamped records are not supported: ' . $line); }
					if (!empty($location)) { throw new Exception('Location-Specific records are not supported: ' . $line); }

					$ns = strstr($x, '.') === FALSE ? $x . '.ns.' . $fqdn : $x . '.';
					$records->addRecord($fqdn, 'NS', $ns, $ttl);

					if (!empty($ip)) {
						if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== FALSE) {
							$records->addRecord($ns, 'A', $ip, $ttl);
						} else {
							$ip = implode(':', str_split($ip, 4));
							if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== FALSE) {
								$records->addRecord($ns, 'AAAA', $ip, $ttl);
							}
						}
					}

					if ($type == '.' && $result['soa']['Nameserver'] == null) {
						$result['soa']['Nameserver'] = $ns;
						$result['soa']['Email'] = 'hostmaster.' . $fqdn;
					}
				} else if ($type == '=' || $type == '+') { // A Record (+ PTR for '=')
					if (!isset($bits[1])) { throw new Exception('Invalid Line: ' . $line); }

					$fqdn = $bits[0] . '.';
					$ip = $bits[1];
					$ttl = isset($bits[2]) ? $bits[2] : '';
					$timestamp = isset($bits[3]) ? $bits[3] : '';
					$location = isset($bits[4]) ? $bits[4] : '';

					if (empty($ttl) || $ttl < 0) { $ttl = 300; }
					if (!empty($timestamp)) { throw new Exception('Timestamped records are not supported: ' . $line); }
					if (!empty($location)) { throw new Exception('Location-Specific records are not supported: ' . $line); }

					$records->addRecord($fqdn, 'A', $ip, $ttl);
				} else if ($type == '6' || $type == '3') { // AAAA Record (+ PTR for '6')
					if (!isset($bits[1])) { throw new Exception('Invalid Line: ' . $line); }

					$fqdn = $bits[0] . '.';
					$ip = $bits[1];
					$ttl = isset($bits[2]) ? $bits[2] : '';
					$timestamp = isset($bits[3]) ? $bits[3] : '';
					$location = isset($bits[4]) ? $bits[4] : '';

					if (empty($ttl) || $ttl < 0) { $ttl = 300; }
					if (!empty($timestamp)) { throw new Exception('Timestamped records are not supported: ' . $line); }
					if (!empty($location)) { throw new Exception('Location-Specific records are not supported: ' . $line); }

					$ip = implode(':', str_split($ip, 4));

					$records->addRecord($fqdn, 'AAAA', $ip, $ttl);
				} else if ($type == '@') { // MX Record
					if (!isset($bits[1])) { throw new Exception('Invalid Line: ' . $line); }

					$fqdn = $bits[0] . '.';
					$ip = $bits[1];
					$x = isset($bits[2]) ? $bits[2] : 'mx.' . $fqdn;
					$priority = isset($bits[3]) ? $bits[3] : 0;
					$ttl = isset($bits[4]) ? $bits[4] : '';
					$timestamp = isset($bits[5]) ? $bits[5] : '';
					$location = isset($bits[6]) ? $bits[6] : '';

					if (empty($ttl) || $ttl < 0) { $ttl = 300; }
					if (!empty($timestamp)) { throw new Exception('Timestamped records are not supported: ' . $line); }
					if (!empty($location)) { throw new Exception('Location-Specific records are not supported: ' . $line); }

					$mx = strstr($x, '.') === FALSE ? $x . '.mx.' . $fqdn : $x . '.';
					$records->addRecord($fqdn, 'MX', $mx, $ttl, $priority);

					if (!empty($ip)) {
						if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== FALSE) {
							$records->addRecord($mx, 'A', $ip, $ttl);
						} else {
							$ip = implode(':', str_split($ip, 4));
							if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== FALSE) {
								$records->addRecord($mx, 'AAAA', $ip, $ttl);
							}
						}
					}

				} else if ($type == '\'') { // TXT Record
					if (!isset($bits[1])) { throw new Exception('Invalid Line: ' . $line); }

					$fqdn = $bits[0] . '.';
					$s = $bits[1];
					$ttl = isset($bits[2]) ? $bits[2] : '';
					$timestamp = isset($bits[3]) ? $bits[3] : '';
					$location = isset($bits[4]) ? $bits[4] : '';

					if (empty($ttl) || $ttl < 0) { $ttl = 300; }
					if (!empty($timestamp)) { throw new Exception('Timestamped records are not supported: ' . $line); }
					if (!empty($location)) { throw new Exception('Location-Specific records are not supported: ' . $line); }

					$records->addRecord($fqdn, 'TXT', $s, $ttl);
				} else if ($type == '^') { // PTR Record
					if (!isset($bits[1])) { throw new Exception('Invalid Line: ' . $line); }

					$fqdn = $bits[0] . '.';
					$p = $bits[1] . '.';
					$ttl = isset($bits[2]) ? $bits[2] : '';
					$timestamp = isset($bits[3]) ? $bits[3] : '';
					$location = isset($bits[4]) ? $bits[4] : '';

					if (empty($ttl) || $ttl < 0) { $ttl = 300; }
					if (!empty($timestamp)) { throw new Exception('Timestamped records are not supported: ' . $line); }
					if (!empty($location)) { throw new Exception('Location-Specific records are not supported: ' . $line); }

					$records->addRecord($fqdn, 'PTR', $p, $ttl);

				} else if ($type == 'S') { // SRV Record
					if (!isset($bits[1])) { throw new Exception('Invalid Line: ' . $line); }

					$fqdn = $bits[0] . '.';
					$ip = $bits[1];
					$host = $bits[2] . '.';
					$port = $bits[3];
					$priority = $bits[4];
					$weight = $bits[5];
					$ttl = isset($bits[6]) ? $bits[6] : '';
					$timestamp = isset($bits[7]) ? $bits[7] : '';
					$location = isset($bits[8]) ? $bits[8] : '';

					if (empty($ttl) || $ttl < 0) { $ttl = 300; }
					if (!empty($timestamp)) { throw new Exception('Timestamped records are not supported: ' . $line); }
					if (!empty($location)) { throw new Exception('Location-Specific records are not supported: ' . $line); }

					$records->addRecord($fqdn, 'SRV', $weight . ' ' . $port . ' ' . $host, $ttl, $priority);

					if (!empty($ip)) {
						if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== FALSE) {
							$records->addRecord($host, 'A', $ip, $ttl);
						} else {
							$ip = implode(':', str_split($ip, 4));
							if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== FALSE) {
								$records->addRecord($host, 'AAAA', $ip, $ttl);
							}
						}
					}

				} else if ($type == 'C') { // CNAME Record
					if (!isset($bits[1])) { throw new Exception('Invalid Line: ' . $line); }

					$fqdn = $bits[0] . '.';
					$p = $bits[1] . '.';
					$ttl = isset($bits[2]) ? $bits[2] : '';
					$timestamp = isset($bits[3]) ? $bits[3] : '';
					$location = isset($bits[4]) ? $bits[4] : '';

					if (empty($ttl) || $ttl < 0) { $ttl = 300; }
					if (!empty($timestamp)) { throw new Exception('Timestamped records are not supported: ' . $line); }
					if (!empty($location)) { throw new Exception('Location-Specific records are not supported: ' . $line); }

					$records->addRecord($fqdn, 'CNAME', $p, $ttl);
				} else if ($type == 'Z') { // SOA Record
					if (!isset($bits[2])) { throw new Exception('Invalid Line: ' . $line); }

					$fqdn = $bits[0] . '.';
					$mname = $bits[1] . '.';
					$rname = $bits[2];
					$ser = isset($bits[3]) ? $bits[3] : '';
					$ref = isset($bits[4]) ? $bits[4] : '';
					$ret = isset($bits[5]) ? $bits[5] : '';
					$exp = isset($bits[6]) ? $bits[6] : '';
					$min = isset($bits[7]) ? $bits[7] : '';
					$ttl = isset($bits[8]) ? $bits[8] : '';
					$timestamp = isset($bits[9]) ? $bits[9] : '';
					$location = isset($bits[10]) ? $bits[10] : '';

					if (empty($ser)) { $ser = 0; }
					if (empty($ref)) { $ref = 16384; }
					if (empty($ret)) { $ret = 2048; }
					if (empty($exp)) { $exp = 1048576; }
					if (empty($min)) { $min = 2560; }

					if (empty($ttl) || $ttl < 0) { $ttl = 300; }
					if (!empty($timestamp)) { throw new Exception('Timestamped records are not supported: ' . $line); }
					if (!empty($location)) { throw new Exception('Location-Specific records are not supported: ' . $line); }

					$result['soa'] = ['Nameserver' => $mname,
					                  'Email' => $rname,
					                  'Serial' => $ser,
					                  'Refresh' => $ref,
					                  'Retry' => $ret,
					                  'Expire' => $exp,
					                  'MinTTL' => $min];
				} else if ($type == ':') { // Custom Record
					throw new Exception('Custom records are not supported: ' . $line);
				} else {
					throw new Exception('Unknown Record Type: ' . $line);
				}
			}

			$result['records'] = $records->get();

			return $result;
		}

		public function generateZoneFile($domainName, $data) {
			throw new Exception('Export to TinyDNS is not supported.');
		}
	}

