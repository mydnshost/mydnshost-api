<?php

use shanemcc\phpdb\DBObject;
use shanemcc\phpdb\ValidationFailed;

class ZoneKey extends DBObject {
	protected static $_fields = ['id' => NULL,
	                             'domain_id' => NULL,
	                             'key_id' => NULL,
	                             'flags' => 0,
	                             'keyprivate' => 0,
	                             'keypublic' => 0,
	                             'created' => NULL,
	                             'publish' => NULL,
	                             'activate' => NULL,
	                             'revoke' => NULL,
	                             'inactive' => NULL,
	                             'delete' => NULL,
	                             'syncPublish' => NULL,
	                             'syncDelete' => NULL,
	                             'comment' => NULL,
	                            ];
	protected static $_key = 'id';
	protected static $_table = 'zonekeys';
	private static $_dates = ['created', 'publish', 'activate', 'revoke', 'inactive', 'delete', 'syncPublish', 'syncDelete'];

	public function __construct($db) {
		parent::__construct($db);
	}

	public function setDomainID($value) {
		return $this->setData('domain_id', $value);
	}

	public function setKeyID($value) {
		return $this->setData('key_id', ltrim($value, '0'));
	}

	public function setFlags($value) {
		return $this->setData('flags', $value);
	}

	public function setKeyPrivate($value) {
		return $this->setData('keyprivate', $value);
	}

	public function setKeyPublic($value) {
		return $this->setData('keypublic', $value);
	}

	private function parseDate($date) {
		return strtotime($date . '+0000');
	}

	public function setCreated($value) {
		return $this->setData('created', $this->parseDate($value));
	}

	public function setPublish($value) {
		return $this->setData('publish', $this->parseDate($value));
	}

	public function setActivate($value) {
		return $this->setData('activate', $this->parseDate($value));
	}

	public function setRevoke($value) {
		return $this->setData('revoke', $this->parseDate($value));
	}

	public function setInactive($value) {
		return $this->setData('inactive', $this->parseDate($value));
	}

	public function setDelete($value) {
		return $this->setData('delete', $this->parseDate($value));
	}

	public function setSyncPublish($value) {
		return $this->setData('syncPublish', $this->parseDate($value));
	}

	public function setSyncDelete($value) {
		return $this->setData('syncDelete', $this->parseDate($value));
	}

	public function setComment($value) {
		return $this->setData('comment', $value);
	}

	public function getID() {
		return $this->getData('id');
	}

	public function getDomainID() {
		return $this->getData('domain_id');
	}

	public function getKeyID() {
		return $this->getData('key_id');
	}

	public function getFlags() {
		return $this->getData('flags');
	}

	public function getKeyPrivate() {
		return $this->getData('keyprivate');
	}

	public function getKeyPublic() {
		return $this->getData('keypublic');
	}

	public function getDates() {
		$dates = [];

		foreach (static::$_dates as $type) {
			$date = call_user_func([$this, 'get' . ucfirst($type)]);
			if ($date != NULL && $date > 0) {
				$dates[$type] = $date;
			}
		}

		return $dates;
	}

	public function getPrivateDataValue($key) {
		$data = explode("\n", trim($this->getData('keyprivate')));
		foreach ($data as $line) {
			$bits = explode(':', $line, 2);
			if (strtolower($bits[0]) == strtolower($key)) {
				return trim($bits[1]);
			}
		}

		return FALSE;
	}

	public function getKeyFileName($ext = '') {
		$domain = $this->getDomainID() !== NULL ? Domain::load($this->getDB(), $this->getDomainID()) : FALSE;

		if ($domain instanceof Domain) {
			$domainStr = $domain->getDomainRaw();
		} else {
			$domainStr = 'UNKNOWN';
		}

		$alg = $this->getPrivateDataValue('algorithm');
		$alg = explode(' ', $alg)[0];
		$filename = sprintf('K%s.+%03d+%05d', $domainStr, $alg, $this->getKeyID());

		return $filename . (empty($ext) ? '' : '.' . $ext);
	}

	public function getKeyPrivateFileContent() {
		$data = explode("\n", trim($this->getData('keyprivate')));

		foreach ($this->getDates() as $type => $date) {
			$data[] = ucfirst($type) . ': ' . date('YmdHis', $date);
		}

		return implode("\n", $data) . "\n";
	}

	public function getKeyPublicRecords() {
		$records = [];

		$publicData = explode("\n", trim($this->getData('keypublic')));
		foreach ($publicData as $line) {
			if (!empty($line)) {
				$records[] = (new Record($this->getDB()))->parseString($line);
			}
		}

		return $records;
	}

	public function getKeyPublicFileContent() {
		$domain = $this->getDomainID() !== NULL ? Domain::load($this->getDB(), $this->getDomainID()) : FALSE;

		if ($domain instanceof Domain) {
			$domainStr = $domain->getDomainRaw();
		} else {
			$domainStr = 'UNKNOWN';
		}

		$data = [];
		$keyType = 'UNKNOWN';
		if ($this->getFlags() == 257) { $keyType = 'key-signing'; }
		else if ($this->getFlags() == 256) { $keyType = 'zone-signing'; }

		$data[] = sprintf('; This is a %s key, keyid %d, for %s.', $keyType, $this->getKeyID(), $domainStr);

		foreach ($this->getDates() as $type => $date) {
			$data[] = sprintf('; %s: %s (%s)', ucfirst($type), date('YmdHis', $date), date('D M d H:i:s Y', $date));
		}

		foreach ($this->getKeyPublicRecords() as $record) {
			if ($record->getType() == 'DNSKEY') {
				$record->setTTL('');
				$data[] = $record->__toString();
			}
		}

		return implode("\n", $data) . "\n";
	}


	public function getCreated() {
		return $this->getData('created');
	}

	public function getPublish() {
		return $this->getData('publish');
	}

	public function getActivate() {
		return $this->getData('activate');
	}

	public function getRevoke() {
		return $this->getData('revoke');
	}

	public function getInactive() {
		return $this->getData('inactive');
	}

	public function getDelete() {
		return $this->getData('delete');
	}

	public function getSyncPublish() {
		return $this->getData('syncPublish');
	}

	public function getSyncDelete() {
		return $this->getData('syncDelete');
	}

	public function getComment() {
		return $this->getData('comment');
	}

	/**
	 * Load an object from the database based on domain_id AND the key id.
	 *
	 * @param $db Database object to load from.
	 * @param $domain Domain id to look for
	 * @param $key key id to look for
	 * @return FALSE if no object exists, else the object.
	 */
	public static function loadFromDomainKey($db, $domain, $key) {
		$result = static::find($db, ['domain_id' => $domain, 'key_id' => ltrim($key, '0')]);
		if ($result) {
			return $result[0];
		} else {
			return FALSE;
		}
	}

	public function validate() {
		$required = ['flags', 'keyprivate', 'keypublic', 'domain_id', 'created', 'publish', 'activate'];
		foreach ($required as $r) {
			if (!$this->hasData($r)) {
				throw new ValidationFailed('Missing required field: '. $r);
			}
		}

		return TRUE;
	}

	/**
	 * Import key data into this key.
	 *
	 * @param $private Content of .private file from dnssec-keygen
	 * @param $public Content of .key file from dnssec-keygen
	 * @return $this for chaining.
	 */
	public function importKeyData($private, $public) {
		$private = explode("\n", $private);
		$public = explode("\n", $public);

		$privateData = [];
		$publicData = [];

		// Parse private key data.
		foreach ($private as $line) {
			$bits = explode(':', $line, 2);
			if (in_array(lcfirst($bits[0]), static::$_dates)) {
				call_user_func([$this, 'set' . $bits[0]], trim($bits[1]));
			} else {
				$privateData[] = $line;
			}
		}
		$this->setKeyPrivate(implode("\n", $privateData));

		// Remove comments from public key data.
		foreach ($public as $line) {
			if (!startsWith($line, '; ')) {
				$publicData[] = $line;
			}
		}

		// Extract DS records from DNSKEY records and store public key data.
		$tempdir = tempdir(sys_get_temp_dir(), 'zonekey');
		file_put_contents($tempdir . '/zone.key', implode("\n", $public));
		exec('/usr/sbin/dnssec-dsfromkey ' . escapeshellarg($tempdir . '/zone.key') . ' 2>/dev/null', $publicData);
		deleteDir($tempdir);
		$this->setKeyPublic(implode("\n", $publicData));

		// Extract Flags and Key ID from public data.
		foreach ($publicData as $line) {
			if (!empty($line)) {
				$record = (new Record($this->getDB()))->parseString($line);
				if ($record->getType() == 'DNSKEY') {
					$bits = explode(' ', $record->getContent());
					$this->setFlags($bits[0]);
				} else if ($record->getType() == 'DS') {
					$bits = explode(' ', $record->getContent());
					$this->setKeyID($bits[0]);
				}
			}
		}

		return $this;
	}

	/**
	 * Generate a new zonekey.
	 *
	 * @param $db Database for storing the key
	 * @param $domain Domain name
	 * @param $flags [Default: 256] Flags for the key. (256 = ZSK, 257 = KSK)
	 * @param $algorithm [Default: NULL] Set specific algorithm (If NULL, let dnssec-keygen decide)
	 * @param $bits [Default: NULL] Set specific keysize (If NULL, let dnssec-keygen decide)
	 */
	public static function generateKey($db, $domain, $flags = 256, $algorithm = NULL, $bits = NULL) {
		$dir = tempdir(null, 'zonekeys');

		// Build command to generate keys
		$cmd = '/usr/sbin/dnssec-keygen -r /dev/urandom -K ' . escapeshellarg($dir);
		if ($algorithm != NULL) { $cmd .= ' -a ' . escapeshellarg($algorithm); }
		if ($bits != NULL) { $cmd .= ' -b ' . escapeshellarg($bits); }

		if ($flags == '257') { $cmd .= ' -f KSK'; }
		else if ($flags != '256') { throw new Exception('Unknown flags: ' . $flags); }

		$cmd .= ' ' . escapeshellarg($domain instanceof Domain ? $domain->getDomainRaw() : $domain);
		$cmd .= ' >/dev/null 2>&1';

		// Try to generate keys
		$return = 0;
		exec($cmd, $output, $return);

		// Get generated key data
		$public = glob($dir . '/*.key');
		$private = glob($dir . '/*.private');

		// Check that we actually succeeded or not
		if ($return != 0 || empty($public) || empty($private)) {
			deleteDir($dir);
			throw new Exception('Error generating keys.');
		}

		$public = file_get_contents($public[0]);
		$private = file_get_contents($private[0]);

		$zonekey = new ZoneKey($db);
		$zonekey->importKeyData($private, $public);

		if ($domain instanceof Domain) {
			$zonekey->setDomainID($domain->getID());
		}

		deleteDir($dir);

		return $zonekey;
	}
}
