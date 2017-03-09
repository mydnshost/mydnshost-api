<?php
	if (isset($config['bind']['enabled']) && parseBool($config['bind']['enabled'])) {
		// Default config settings
		$config['bind']['defaults']['zonedir'] = '/etc/bind/zones';
		$config['bind']['defaults']['addZoneCommand'] = 'chmod a+r %2$s; /usr/bin/sudo -n /usr/sbin/rndc addzone %1$s \'{type master; file "%2$s";};\' >/dev/null 2>&1';
		$config['bind']['defaults']['reloadZoneCommand'] = 'chmod a+r %2$s; /usr/bin/sudo -n /usr/sbin/rndc reload %1$s >/dev/null 2>&1';

		foreach ($config['bind']['defaults'] as $setting => $value) {
			if (!isset($config['bind'][$setting])) {
				$config['bind'][$setting] = $value;
			}
		}

		$bindConfig = $config['bind'];

		$writeZoneFile = function($domain) use ($bindConfig) {
			$bind = new Bind($domain->getDomain(), $bindConfig['zonedir']);
			list($filename, $filename2) = $bind->getFileNames();
			$new = file_exists($filename);
			$bind->clearRecords();

			$soa = $domain->getSOARecord()->parseSOA();
			$bindSOA = array('Nameserver' => $soa['primaryNS'],
			                 'Email' => $soa['adminAddress'],
			                 'Serial' => $soa['serial'],
			                 'Refresh' => $soa['refresh'],
			                 'Retry' => $soa['retry'],
			                 'Expire' => $soa['expire'],
			                 'MinTTL' => $soa['minttl']);

			$bind->setSOA($bindSOA);

			foreach ($domain->getRecords() as $record) {
				$name = $record->getName();
				// $name = endsWith($name, '.') ? $name : $name '.' . $domain->getDomain() . '.';

				$content = $record->getContent();
				if ($record->getType() == "TXT") {
					$content = '"' . $record->getContent() . '"';
				}

				$bind->setRecord($name, $record->getType(), $content, $record->getTTL(), $record->getPriority());
			}

			$bind->saveZoneFile();
			if ($new) {
				HookManager::get()->handle('bind_zone_added', [$domain, $bind]);
			} else {
				HookManager::get()->handle('bind_zone_changed', [$domain, $bind]);
			}
		};

		HookManager::get()->addHookType('bind_zone_added');
		HookManager::get()->addHookType('bind_zone_changed');

		HookManager::get()->addHook('add_domain', $writeZoneFile);
		HookManager::get()->addHook('records_changed', $writeZoneFile);

		HookManager::get()->addHook('bind_zone_added', function($domain, $bind) use ($bindConfig) {
			list($filename, $filename2) = $bind->getFileNames();

			$cmd = sprintf($bindConfig['addZoneCommand'], escapeshellarg($domain->getDomain()), $filename);
			exec($cmd);
		});

		HookManager::get()->addHook('bind_zone_changed', function($domain, $bind) use ($bindConfig) {
			list($filename, $filename2) = $bind->getFileNames();

			$cmd = sprintf($bindConfig['reloadZoneCommand'], escapeshellarg($domain->getDomain()), $filename);
			exec($cmd);
		});
	}


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
		 * Create an instance of 'Bind' for the specified domain.
		 *
		 * @param $domain Domain to work with.
		 * @param $zonedirectory Where are zone files stored? (No trailing /)
		 * @param $file (optional) File to load domain info from
		 */
		function __construct($domain, $zonedirectory, $file = '') {
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
				$line = trim($file[$i]);
				if ($line[0] == ';' || $line == '' || $line == ')') { continue; }

				$pos = 0;

				$bits = preg_split('/\s+/', $line);
				if (strtolower($bits[0]) == '$ttl') {
					$ttl = $bits[++$pos];
					$this->debug('parseZoneFile', 'TTL is now: '.$ttl);
					if (!isset($domainInfo[' META ']['TTL'])) { $domainInfo[' META ']['TTL'] = $ttl; }
				} else if (strtolower($bits[0]) == '$origin') {
					$origin = $bits[++$pos];
					$this->debug('parseZoneFile', 'Origin is now: '.$origin);
				} else {
					// Zone stuff!
					$pos = 0;
					$start = $startname;
					$thisttl = $ttl;
					if (strtoupper($bits[$pos]) != 'IN') {
						// @ = $ORIGIN
						if ($bits[$pos] == '@') {
							$start = $origin;
						} else {
							$start = $bits[$pos];
						}

						$pos++;
						if (strtoupper($bits[$pos]) != 'IN') {
							$thisttl = $bits[$pos];
							$pos++;
							if (strtoupper($bits[$pos]) != 'IN') {
								print_r($bits);
								throw new Exception('Invalid zone file. (Got: "'.$bits[$pos].'", expected "IN", {'.$line.'})');
							}
						}
					}
//					$startname = $start;
					$pos++;
					$type = strtoupper($bits[$pos]);
					$pos++;
					$this->debug('parseZoneFile', 'Got Line of Type: '.$type.' ('.$line.')');

					// We don't store origin changes, so add the origin if its not there
					if ($start[strlen($start)-1] != '.') {
						$start = $start.'.'.$origin;
					}

					// Now check to see if the name ends with domain.com. if it does,
					// remove it.
					$len = strlen($this->domain)+1;
					$end = substr($start, strlen($start) - $len);

					if ($type != 'SOA') {
						if ($end == $this->domain.'.') {
							if ($start != $end) {
								$start = substr($start, 0,  strlen($start) - $len - 1);
							} else {
								$start = '';
							}
						}
					}

					// Add type to domainInfo
					if (!isset($domainInfo[$type])) { $domainInfo[$type] = array(); }
					// Add value to domainInfo
					if (!isset($domainInfo[$type][$start])) { $domainInfo[$type][$start] = array(); }

					// Add params to this bit first, we add it to domainInfo afterwards
					$info = array();

					switch ($type) {
						case 'SOA':
							// SOAs span multiple lines.
							$info['Nameserver'] = $bits[$pos++];
							$info['Email'] = $bits[$pos++];
							$soabits = array();
							while (count($soabits) < 5) {
								$line = trim($file[++$i]);
								$bits = preg_split('/\s+/', $line);
								$soabits[] = $bits[0];
							}
							$info['Serial'] = $soabits[0];
							$info['Refresh'] = $soabits[1];
							$info['Retry'] = $soabits[2];
							$info['Expire'] = $soabits[3];
							$info['MinTTL'] = $soabits[4];
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
					$domainInfo[$type][$start][] = $info;
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
				throw new Exception('SOA for domain not found..');
			}
			return $domainInfo['SOA'][$this->domain.'.'][0];
		}

		/**
		 * Set the SOA record for this domain.
		 *
		 * @param $soa The SOA record for this domain.
		 */
		function setSOA($soa) {
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
			$domainInfo = $this->domainInfo;
			if ($ttl == '') { $ttl = $domainInfo[' META ']['TTL']; }

			$info['Address'] = $data;
			$info['TTL'] = $ttl;
			if ($type == 'MX' || $type == 'SRV') {
				$info['Priority'] = $priority;
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

			// TTL and ORIGIN First
			if (isset($domainInfo[' META ']['TTL'])) {
				$lines[] = '$TTL ' . $domainInfo[' META ']['TTL'];
			} else {
				$lines[] = '$TTL 86400';
			}
			$lines[] = '$ORIGIN '.$this->domain.'.';
			// Now SOA
			if (!isset($domainInfo['SOA'][$this->domain.'.'])) {
				throw new Exception('SOA for domain not found..');
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

			$res = file_put_contents($savefile, implode("\n", $this->getParsedZoneFile()));

			if ($res > 0) {
				// Update the stored contents to use the version we just saved
				if ($savefile == $this->file) { $this->getZoneFileContents(true); }
			}

			return $res;
		}
	}

