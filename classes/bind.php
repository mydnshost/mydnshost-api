<?php

	/**
	 * This class allows for manipulating bind zone files.
	 */
	class Bind {
		/** Where are zone files stored? (No trailing /) */
		private $zonedirectory = '';
		/** What domain is this instance of the class for? */
		private $domain = 'example.com';
		/** What file should we use? */
		private $file = 'example.com';
		/** This array stores all the actual information we read/write */
		private $domainInfo = array();
		/** This stores the file contents to save opening the file more than needed */
		private $zoneFile = NULL;
		/** Debugging enabled? */
		private $debugging = FALSE;

		/**
		 * This function writes debugging information
		 *
		 * @param $type Type of information
		 * @param $info Information to write
		 */
		function debug($type, $info) {
			if ($this->debugging) {
				echo '[DNS/'.$this->domain.'] {'.$type.'} '.$info."\r\n";
			}
		}

		/**
		 * This function allow enabling/disabling debug.
		 *
		 * @param $value New value for debugging.
		 */
		function setDebug($value) {
			$this->debugging = $value;
		}

		/**
		 * Is debugging enabled?
		 *
		 * @return Value of debugging.
		 */
		function isDebug() {
			return $this->debugging;
		}

		/**
		 * Create an instance of 'Bind' for the specified domain.
		 *
		 * @param $domain Domain to work with.
		 * @param $zonedirectory Where are zone files stored? (No trailing /)
		 * @param $file (optional) File to load domain info from
		 */
		function __construct($domain, $zonedirectory, $file = '') {
			$domain = idn_to_ascii($domain);
			$this->domain = $domain;
			$this->zonedirectory = $zonedirectory;
			if ($file == '' || !file_exists($file) || !is_file($file) || !is_readable($file)) {
				$this->file = $this->zonedirectory.'/'.strtolower($domain).'.db';
			} else {
				$this->file = $file;
			}
			$this->debug('__construct', 'Using file: '.$this->file);
		}

		/**
		 * Get the file names for this,
		 *
		 * @return Array with filenames.
		 *         array[0] = File that was loaded;
		 *         array[1] = Default file
		 */
		function getFileNames() {
			$def = $this->zonedirectory.'/'.strtolower($this->domain).'.db';
			return array($this->file, $def);
		}

		/**
		 * Get the contents of the zone file.
		 *
		 * @param $force Force a new read of the file rather than returning the
		 *               cached value.
		 * @return Zone file as an Array of lines. (empty array for non-existant file)
		 */
		function getZoneFileContents($force = false) {
			if ($force || $this->zoneFile == NULL) {
				$this->debug('getZoneFileContents', 'Getting file contents from: '.$this->file);
				if ($force) { $this->debug('getZoneFileContents', 'Forced'); }
				if (file_exists($this->file)) {
					$this->debug('getZoneFileContents', 'File exists');
					$this->zoneFile = file($this->file);
				} else {
					$this->debug('getZoneFileContents', 'File doesn\'t exist');
					$this->zoneFile = array();
				}
			}
			$this->debug('getZoneFileContents', 'Returning file contents');
			return $this->zoneFile;
		}

		function ttlToInt($ttl) {
			if (preg_match('#^([0-9]+)([smhdw])$#i', $ttl, $m)) {
				$ttl = $m[1];

				if ($m[2] == 'm') {
					$ttl *= 60;
				} else if ($m[2] == 'h') {
					$ttl *= 3600;
				} else if ($m[2] == 'd') {
					$ttl = 86400;
				} else if ($m[2] == 'w') {
					$ttl *= 604800;
				}
			}

			return $ttl;
		}

		/**
		 * Parse the Zone file.
		 */
		function parseZoneFile() {
			$file = $this->getZoneFileContents();
			$ttl = '2d';
			$origin = $this->domain.'.';
			$startname = $origin;

			$domainInfo = $this->domainInfo;
			for ($i = 0; $i < count($file); $i++) {
				$testline = trim($file[$i]);
				if (empty($testline) || $testline{0} == ';' || $testline == ')') { continue; }
				$line = rtrim($file[$i]);

				$pos = 0;

				$bits = preg_split('/\s+/', $line);
				if (strtolower($bits[0]) == '$ttl') {
					$ttl = $bits[++$pos];
					$this->debug('parseZoneFile', 'TTL is now: '.$ttl);
					if (!isset($domainInfo[' META ']['TTL'])) { $domainInfo[' META ']['TTL'] = $ttl; }
				} else if (strtolower($bits[0]) == '$origin') {
					$origin = $bits[++$pos];
					$this->debug('parseZoneFile', 'Origin is now: '.$origin);
					if ($origin == '.') { $origin = ''; }
				} else {
					// Zone stuff!
					$pos = 0;
					$thisttl = $ttl;

					$name = $bits[0];

					for ($pos = 1; $pos < count($bits); $pos++) {
						if (is_numeric($bits[$pos])) {
							$ttl = $bits[$pos];
						} else if (strtoupper($bits[$pos]) == 'IN') {
							continue;
						} else {
							break;
						}
					}

					$type = strtoupper(isset($bits[$pos]) ? $bits[$pos] : '');
					$pos++;
					$this->debug('parseZoneFile', 'Got Line of Type: '.$type.' ('.$line.')');

					// We don't store origin changes, so add the origin if its not there
					if ((empty($name) && $name != "0") || $name == '@') {
						$name = $origin;
					} else if ($name[strlen($name)-1] != '.') {
						$name = $name.'.'.$origin;
					}

					// Now check to see if the name ends with domain.com. if it does,
					// remove it.
					$len = strlen($this->domain)+1;
					$end = substr($name, strlen($name) - $len);

					if ($type == 'SOA') {
						if ($name == $origin) {
							$name = $this->domain . '.';
						}
					} else {
						if ($end == $this->domain.'.') {
							if ($name != $end) {
								$name = substr($name, 0,  strlen($name) - $len - 1);
							} else {
								$name = '';
							}
						}
					}

					// Add type to domainInfo
					if (!isset($domainInfo[$type])) { $domainInfo[$type] = array(); }
					// Add value to domainInfo
					if (!isset($domainInfo[$type][$name])) { $domainInfo[$type][$name] = array(); }

					// Add params to this bit first, we add it to domainInfo afterwards
					$info = array();
					switch ($type) {
						case 'SOA':
							// SOAs can span multiple lines.
							$info['Nameserver'] = $bits[$pos++];
							$info['Email'] = $bits[$pos++];

							// Fully-Qualify the SOA.
							if ($info['Nameserver'][strlen($info['Nameserver'])-1] != '.') {
								$info['Nameserver'] .= '.' . $origin;
							}
							if ($info['Email'][strlen($info['Email'])-1] != '.') {
								$info['Email'] .= '.' . $origin;
							}

							$soabits = array();
							$multiLine = ($bits[$pos] == '(');
							while (count($soabits) < 5) {
								if ($multiLine) {
									$line = trim($file[++$i]);
									$bits = preg_split('/\s+/', $line);
									foreach ($bits as $bit) {
										if (trim($bit) == '' || $bit{0} == ';') { break; }
										$soabits[] = $bit;
									}
								} else {
									$soabits[] = $bits[$pos++];
								}
							}
							$info['Serial'] = $soabits[0];
							$info['Refresh'] = $soabits[1];
							$info['Retry'] = $soabits[2];
							$info['Expire'] = $soabits[3];
							$info['MinTTL'] = rtrim($soabits[4], ')');
							break;
						case 'MX':
						case 'SRV':
							$info['Priority'] = $bits[$pos++];
							// Fall through
						default:
							// Remove any comments stuck to the end.
							$addr = array();
							for ($j = $pos; $j < count($bits); $j++) { $addr[] = $bits[$j]; }
							$info['Address'] = trim(implode(' ', $addr), ';');
							$info['TTL'] = $thisttl;
							break;
					}

					if (!isset($domainInfo[' META ']['TTL'])) { $domainInfo[' META ']['TTL'] = $ttl; }

					// And finally actually add to the domainInfo array:
					$domainInfo[$type][$name][] = $info;
				}
			}

			// Update the domainInfo
			$this->domainInfo = $domainInfo;

			if ($this->debugging) {
				foreach (explode("\n", print_r($domainInfo, true)) as $line) {
					$this->debug('parseZoneFile', $line);
				}
			}
		}

		/**
		 * Get the next Serial for this domain in the form YYYYMMDDnn.
		 * where nn is an ID for the change (first change of the day is 00, second
		 * is 01 etc.
		 *
		 * @return Next serial to use.
		 */
		function getNextSerial() {
			$domainInfo = $this->domainInfo;
			if (!isset($domainInfo['SOA'][$this->domain.'.'])) {
				$oldSerial = 0;
			} else {
				$soa = $domainInfo['SOA'][$this->domain.'.'][0];
				$oldSerial = $soa['Serial'];
			}

			$newSerial = date('Ymd').'00';

			$diff = ($oldSerial - $newSerial);

			// Is this the first serial of the day?
			if ($diff < 0) {
				return $newSerial;
			} else {
				return $newSerial + $diff + 1;
			}
		}

		/**
		 * Get the SOA record for this domain.
		 *
		 * @return The SOA record for this domain.
		 */
		function getSOA() {
			$domainInfo = $this->domainInfo;
			if (!isset($domainInfo['SOA'][$this->domain.'.'])) {
				if (count($domainInfo['SOA']) > 0) {
					throw new Exception('SOA for domain not found. Found: ' . implode(', ', array_keys($domainInfo['SOA'])));
				} else {
					throw new Exception('Invalid zone file. (No SOA found)');
				}
			}
			return $domainInfo['SOA'][$this->domain.'.'][0];
		}

		/**
		 * Set the SOA record for this domain.
		 *
		 * @param $soa The SOA record for this domain.
		 */
		function setSOA($soa) {
			$soa['Nameserver'] = idn_to_ascii(substr($soa['Nameserver'], -1) == '.' ? substr($soa['Nameserver'], 0, -1) : $soa['Nameserver']) . '.';
			$soa['Email'] = idn_to_ascii(substr($soa['Email'], -1) == '.' ? substr($soa['Email'], 0, -1) : $soa['Email']) . '.';
			$this->domainInfo['SOA'][$this->domain.'.'][0] = $soa;
		}

		/**
		 * Get the META record for this domain.
		 *
		 * @return The META record for this domain.
		 */
		function getMETA() {
			return $this->domainInfo[' META '];
		}

		/**
		 * Set the META record for this domain.
		 *
		 * @param $meta The META record for this domain.
		 */
		function setMETA($meta) {
			$this->domainInfo[' META '] = $meta;
		}

		/**
		 * Set a record information.
		 * Will add a new record.
		 *
		 * @param $name The name of the record. (ie www)
		 * @param $type The type of record (ie A)
		 * @param $data The data for the record (ie 127.0.0.1)
		 * @param $ttl (optional) TTL for the record.
		 * @param $priority (optional) Priority of the record (for mx)
		 */
		function setRecord($name, $type, $data, $ttl = '', $priority = '') {
			$name = idn_to_ascii($name);
			$domainInfo = $this->domainInfo;
			if ($ttl == '') { $ttl = $domainInfo[' META ']['TTL']; }

			$info['Address'] = $data;
			$info['TTL'] = $ttl;
			if ($type == 'MX' || $type == 'SRV') {
				$info['Priority'] = $priority;
			}

			if ($type == 'MX' || $type == 'CNAME' || $type == 'PTR' || $type == 'NS') {
				$info['Address'] = idn_to_ascii($info['Address']);
			}

			if (!isset($domainInfo[$type][$name])) { $domainInfo[$type][$name] = array(); };
			$domainInfo[$type][$name][] = $info;

			$this->domainInfo = $domainInfo;
		}

		/**
		 * Unset record information.
		 * Will delete all records for the name
		 *
		 * @param $name The name of the record. (ie www)
		 * @param $type The type of record (ie A)
		 */
		function unsetRecord($name, $type) {
			$domainInfo = $this->domainInfo;

			if (isset($domainInfo[$type][$name])) {
				unset($domainInfo[$type][$name]);
			}

			$this->domainInfo = $domainInfo;
		}

		/**
		 * Get all record information.
		 *
		 * @param $name The name of the record. (ie www)
		 * @param $type The type of record (ie A)
		 */
		function getRecords($name, $type) {
			$domainInfo = $this->domainInfo;

			if (isset($domainInfo[$type][$name])) {
				return $domainInfo[$type][$name];
			} else {
				return array();
			}
		}

		private function ksortr(&$array) {
			foreach ($array as &$value) {
				if (is_array($value)) {
					$this->ksortr($value);
				}
			}

			return ksort($array);
		}

		function getZoneHash() {
			$hashData = $this->domainInfo;
			$this->ksortr($hashData);
			return base_convert(crc32(json_encode($hashData)), 10, 36);
		}

		/**
		 * Clear all records (does not clear SOA or META)
		 */
		function clearRecords() {
			$meta = isset($this->domainInfo[' META ']) ? $this->domainInfo[' META '] : array();
			$soa = isset($this->domainInfo['SOA']) ? $this->domainInfo['SOA'] : array();
			$this->domainInfo = array();
			$this->domainInfo['SOA'] = $soa;
			$this->domainInfo[' META '] = $meta;
		}

		/**
		 * Return the parsed version of the Zone file.
		 *
		 * @return this returns an array of lines.
		 */
		function getParsedZoneFile() {
			$domainInfo = $this->domainInfo;

			// The file gets writen to this array first, then stored in a file afterwards.
			$lines = array();

			$lines[] = '; Written at '.date('r');
			$lines[] = '; Zone Hash: '.$this->getZoneHash();

			// TTL and ORIGIN First
			if (isset($domainInfo[' META ']['TTL'])) {
				$lines[] = '$TTL ' . $domainInfo[' META ']['TTL'];
			} else {
				$lines[] = '$TTL 86400';
			}
			$lines[] = '$ORIGIN '.$this->domain.'.';
			// Now SOA
			if (!isset($domainInfo['SOA'][$this->domain.'.'])) {
				if (count($domainInfo['SOA']) > 0) {
					throw new Exception('SOA for domain not found. Found: ' . implode(', ', array_keys($domainInfo['SOA'])));
				} else {
					throw new Exception('Invalid zone file. (No SOA found)');
				}
			}
			$soa = $domainInfo['SOA'][$this->domain.'.'][0];

			$lines[] = sprintf('%-30s IN    SOA %s %s (', $this->domain.'.', $soa['Nameserver'], $soa['Email']);
			$lines[] = sprintf('%40s %s', '', $soa['Serial']);
			$lines[] = sprintf('%40s %s', '', $soa['Refresh']);
			$lines[] = sprintf('%40s %s', '', $soa['Retry']);
			$lines[] = sprintf('%40s %s', '', $soa['Expire']);
			$lines[] = sprintf('%40s %s )', '', $soa['MinTTL']);

			$lines[] = '';
			// Now the rest.
			foreach ($domainInfo as $type => $bits) {
				if ($type == 'SOA' || $type == ' META ') { continue; }

				foreach ($bits as $bit => $names) {
					foreach ($names as $name) {
						if (isset($domainInfo[' META ']['TTL']) != $name['TTL']) { $ttl = $name['TTL']; } else { $ttl = ''; }
						if ($type == 'MX' || $type == 'SRV') { $priority = $name['Priority']; } else { $priority = ''; }
						$address = $name['Address'];

						if ($bit !== 0 && empty($bit)) { $bit = $this->domain.'.'; }

						$lines[] = sprintf('%-30s %7s    IN %7s   %-6s %s', $bit, $ttl, $type, $priority, $address);
					}
				}
			}

			// Blank last line
			$lines[] = '';

			if ($this->debugging) {
				foreach ($lines as $line) {
					$this->debug('parseZoneFile', $line);
				}
			}

			return $lines;
		}

		/**
		 * Get a copy of the domainInfo for this domain
		 *
		 * @return Copy of the domainInfo for this domain;
		 */
		function getDomainInfo() {
			return $this->domainInfo;
		}

		/**
		 * Save the zone file to a file.
		 *
		 * @param $file File to save to. Defaults to the file we loaded from.
		 * @return Number of bytes written to file (result of file_put_contents)
		 */
		function saveZoneFile($savefile = '') {
			if ($savefile == '') { $savefile = $this->file; }

			$res = $this->file_put_contents_atomic($savefile, implode("\n", $this->getParsedZoneFile()));

			if ($res > 0) {
				// Update the stored contents to use the version we just saved
				if ($savefile == $this->file) { $this->getZoneFileContents(true); }
			}

			return $res;
		}

		function file_put_contents_atomic($filename, $data, $flags = 0, $context = null) {
			$tempFile = tempnam(sys_get_temp_dir(), 'ZONE');

			if (file_put_contents($tempFile, $data, $flags, $context) === strlen($data)) {
				return $context == null ? rename($tempFile, $filename) : rename($tempFile, $filename, $context);
			}

			@unlink($tempFile, $context);
			return FALSE;
		}
	}

