<?php

use shanemcc\phpdb\DB;
use shanemcc\phpdb\DBObject;
use shanemcc\phpdb\ValidationFailed;
use shanemcc\phpdb\Operations\OrderByFunction;
use shanemcc\phpdb\Operations\DBOperation;

class Domain extends DBObject {
	protected static $_fields = ['id' => NULL,
	                             'domain' => NULL,
	                             'disabled' => false,
	                             'defaultttl' => 86400,
	                            ];
	protected static $_key = 'id';
	protected static $_table = 'domains';

	// SOA for unknown objects.
	protected $_soa = FALSE;
	// Access levels for unknown objects.
	protected $_access = [];

	public function __construct($db) {
		parent::__construct($db);
	}

	public function setDomain($value) {
		return $this->setData('domain', idn_to_ascii($value));
	}

	public function setDisabled($value) {
		return $this->setData('disabled', parseBool($value) ? 'true' : 'false');
	}

	public function setDefaultTTL($value) {
		return $this->setData('defaultttl', $value);
	}

	/**
	 * Load an object from the database based on domain name.
	 *
	 * @param $db Database object to load from.
	 * @param $name Name to look for.
	 * @return FALSE if no object exists, else the object.
	 */
	public static function loadFromDomain($db, $name) {
		$result = static::find($db, ['domain' => idn_to_ascii($name)]);
		if ($result) {
			return $result[0];
		} else {
			return FALSE;
		}
	}

	public function getAccess($user) {
		if ($user instanceof DomainKeyUser) {
			$key = $user->getDomainKey();
			if ($key->getDomainID() != $this->getID()) { return 'none'; }

			return $key->getDomainWrite() ? 'write' : 'read';
		} else if ($user instanceof User) {
			$user = $user->getID();
		}

		return array_key_exists($user, $this->_access) ? $this->_access[$user] : 'none';
	}

	public function setAccess($user, $level) {
		if ($user instanceof DomainKeyUser) {
			return $this;
		} else if ($user instanceof User) {
			$user = $user->getID();
		}

		$level = strtolower($level);
		if (in_array($level, ['none', 'read', 'write', 'admin', 'owner'])) {
			$this->_access[$user] = $level;
			$this->setChanged();
		}
		return $this;
	}

	public function getAccessUsers() {
		$users = User::findByID(DB::get(), array_keys($this->_access));

		$result = [];
		foreach ($this->_access as $k => $v) {
			if (isset($users[$k]) && $v != 'none') {
				$result[$users[$k]->getEmail()] = $v;
			}
		}

		return $result;
	}

	public function postSave($result) {
		if ($result) {
			// Persist access changes
			$setQuery = 'INSERT INTO domain_access (`user_id`, `domain_id`, `level`) VALUES (:user, :domain, :level) ON DUPLICATE KEY UPDATE `level` = :level';
			$setStatement = $this->getDB()->getPDO()->prepare($setQuery);

			$removeQuery = 'DELETE FROM domain_access WHERE `user_id` = :user AND `domain_id` = :domain';
			$removeStatement = $this->getDB()->getPDO()->prepare($removeQuery);

			$params = [':domain' => $this->getID()];
			foreach ($this->_access as $user => $access) {
				$params[':user'] = $user;
				$params[':level'] = $access;
				if ($access == 'none') {
					unset($params[':level']);
					$removeStatement->execute($params);
				} else {
					$setStatement->execute($params);
				}
			}
		}
	}

	public function postLoad() {
		// Get access levels;
		$query = 'SELECT `user_id`,`level` FROM domain_access WHERE `domain_id` = :domain';
		$params = [':domain' => $this->getID()];
		$statement = $this->getDB()->getPDO()->prepare($query);
		$statement->execute($params);
		$result = $statement->fetchAll(PDO::FETCH_ASSOC);

		foreach ($result as $row) {
			$this->setAccess($row['user_id'], $row['level']);
		}
	}

	public function getID() {
		return $this->getData('id');
	}

	public function getDomain() {
		return idn_to_utf8($this->getData('domain'));
	}

	public function getDomainRaw() {
		return $this->getData('domain');
	}

	public function isDisabled() {
		return parseBool($this->getData('disabled'));
	}

	public function getDefaultTTL() {
		return $this->getData('defaultttl');
	}

	/**
	 * Get all the records for this domain.
	 *
	 * @param $name (Optional) Limit results to this name.
	 * @param $type (Optional) Limit results to this rrtype.
	 * @return List of record objects for this domain
	 */
	public function getRecords($name = NULL, $rrtype = NULL) {
		$searchParams = ['domain_id' => $this->getID(), 'type' => 'SOA'];
		$searchFilters = ['type' => '!='];

		if ($name !== NULL) {
			if ($name == '@' || $name == '') {
				$name = $this->getDomain();
			} else {
				$name .= '.' . $this->getDomain();
			}

			$searchParams['name'] = $name;
		}

		if ($rrtype !== NULL) {
			if ($rrtype == 'SOA') {
				return [];
			} else {
				$searchParams['type'] = $rrtype;
				unset($searchFilters['type']);
			}
		}

		$search = Record::getSearch($this->getDB());

		if (endsWith($this->getDomain(), 'ip6.arpa')) {
			$search = $search->addOperation(new OrderByFunction('reverse', 'name'));
		} else if (endsWith($this->getDomain(), 'in-addr.arpa')) {
			$search = $search->addOperation(new OrderByFunction('length', 'name'));
		} else {
			$rawDomain = $this->getDomainRaw();
			$search = $search->addOperation(new class($rawDomain) extends DBOperation {
				private $rawDomain;
				public function __construct($rawDomain) { $this->rawDomain = $rawDomain; }
				public function __toString() { return 'SUBSTRING(name, 1, LENGTH(name) - ' . strlen($this->rawDomain) . ')'; }
				public static function operation() { return 'ORDER BY'; }
			});
		}

		$search = $search->order('type')->order('priority');
		$result = $search->search($searchParams, $searchFilters);
		return ($result) ? $result : [];
	}

	/**
	 * Get a specific record ID if it is owned by this domain.
	 *
	 * @param $id Record ID to look for.
	 * @return Record object if found else FALSE.
	 */
	public function getRecord($id) {
		$result = Record::find($this->getDB(), ['domain_id' => $this->getID(), 'id' => $id, 'type' => 'SOA'], ['type' => '!=']);
		return ($result) ? $result[0] : FALSE;
	}

	/**
	 * Get the SOA record for this domain.
	 *
	 * @param $fresh Get a fresh copy from the DB rather than using our cached copy.
	 * @return Record object if found else FALSE.
	 */
	public function getSOARecord($fresh = FALSE) {
		$soa = $this->_soa;
		if (($soa === FALSE || $fresh) && $this->isKnown()) {
			$soa = Record::find($this->getDB(), ['domain_id' => $this->getID(), 'type' => 'SOA']);
		}

		if ($soa === FALSE) {
			$soa = new Record($this->getDB());
			$soa->setDomainID($this->getID());
			$soa->setName($this->getDomain());
			$soa->setType('SOA');
			$soa->setContent(sprintf('ns1.%s. dnsadmin.%s. %d 86400 7200 2419200 60', $this->getDomain(), $this->getDomain(), $this->getNextSerial()));
			$soa->setTTL(86400);
			$soa->setChangedAt(time());
			if ($this->isKnown()) {
				$soa->save();
			}
			$this->_soa = [$soa];
			return $soa;
		} else {
			$this->_soa = $soa;
			return $soa[0];
		}
	}

	/**
	 * Get the next serial number to use.
	 *
	 * @param $oldSerial Current serial to ensure we are greater than.
	 * @return New serial to use.
	 */
	function getNextSerial($oldSerial = 0) {
		$serial = date('Ymd').'00';
		$diff = ($oldSerial - $serial);

		// If we already have a serial for today, the difference will be
		// >= 0. Older days serials are < 0.
		if ($diff >= 0) {
			$serial += ($diff + 1);
		}

		return $serial;
	}

	/**
	 * Update the domain serial.
	 *
	 * @param $serial Serial to set. Use null to auto-generate.
	 * @return Record object if found else FALSE.
	 */
	public function updateSerial($serial = null) {
		$soa = $this->getSOARecord();
		$parsed = $soa->parseSOA();

		$serial = $this->getNextSerial($parsed['serial']);

		$parsed['serial'] = $serial;
		$soa->updateSOAContent($parsed);

		$soa->save();

		return $serial;
	}

	/**
	 * Look for a parent for this domain.
	 *
	 * @return Parent domain object, or FALSE if no parent found.
	 */
	public function findParent() {
		$bits = explode('.', $this->getDomain());
		while (!empty($bits)) {
			array_shift($bits);

			$p = Domain::loadFromDomain($this->getDB(), implode('.', $bits));
			if ($p !== FALSE) {
				return $p;
			}
		}

		return FALSE;
	}

	public function validate() {
		$required = ['domain'];
		foreach ($required as $r) {
			if (!$this->hasData($r)) {
				throw new ValidationFailed('Missing required field: '. $r);
			}
		}

		if (!self::validDomainName($this->getDomain())) {
			throw new ValidationFailed($this->getDomain() . ' is not a valid domain name');
		}

		return TRUE;
	}

	public static function validDomainName($name) {
		// https://www.safaribooksonline.com/library/view/regular-expressions-cookbook/9781449327453/ch08s15.html
		return preg_match('#^((?=[_a-z0-9-]{1,63}\.)(xn--)?[_a-z0-9]+(-[_a-z0-9]+)*\.)+[a-z]{2,63}$#i', idn_to_ascii($name));
	}
}
