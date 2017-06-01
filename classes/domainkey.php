<?php

class DomainKeyUser extends User {
	private $domainkey;

	public function __construct($db, $domainkey) {
		parent::__construct($db);
		$this->domainkey = $domainkey;
	}

	public function save() {
		/* Do Nothing */
	}

	/**
	 * Get a domain searcher that limits us to domains we have access to.
	 */
	protected function getDomainSearch() {
		$domainSearch = Domain::getSearch($this->getDB());
		$domainSearch->where('id', $this->domainkey->getDomainID());
		$domainSearch->order('domain');

		return $domainSearch;
	}

	public function getDomainKey() {
		return $this->domainkey;
	}
}

class DomainKey extends DBObject {
	protected static $_fields = ['id' => NULL,
	                             'domainkey' => NULL,
	                             'domain_id' => NULL,
	                             'description' => NULL,
	                             'domains_write' => false,
	                             'created' => 0,
	                             'lastused' => 0,
	                            ];
	protected static $_key = 'id';
	protected static $_table = 'domainkeys';

	public function __construct($db) {
		parent::__construct($db);
	}

	public function setKey($value) {
		if ($value === TRUE) {
			$value = genUUID();
		}
		return $this->setData('domainkey', $value);
	}

	public function setDomainID($value) {
		return $this->setData('domain_id', $value);
	}

	public function setDescription($value) {
		return $this->setData('description', $value);
	}

	public function setDomainWrite($value) {
		return $this->setData('domains_write', parseBool($value) ? 'true' : 'false');
	}

	public function setLastUsed($value) {
		return $this->setData('lastused', $value);
	}

	public function setCreated($value) {
		return $this->setData('created', $value);
	}

	public function getID() {
		return $this->getData('id');
	}

	public function getKey() {
		return $this->getData('domainkey');
	}

	public function getDomainID() {
		return $this->getData('domain_id');
	}

	public function getDescription() {
		return $this->getData('description');
	}

	public function getDomainWrite() {
		return parseBool($this->getData('domains_write'));
	}

	public function getLastUsed() {
		return $this->getData('lastused');
	}

	public function getCreated() {
		return $this->getData('created');
	}

	public function getDomainKeyUser() {
		 return new DomainKeyUser($this->getDB(), $this);
	}

	public function getDomain() {
		 return Domain::load($this->getDB(), $this->getDomainID());
	}

	/**
	 * Load an object from the database based on domain_id AND the key.
	 *
	 * @param $db Database object to load from.
	 * @param $domain domain id to look for
	 * @param $key key to look for
	 * @return FALSE if no object exists, else the object.
	 */
	public static function loadFromDomainKey($db, $domain, $key) {
		$result = static::find($db, ['domain_id' => $domain, 'domainkey' => $key]);
		if ($result) {
			return $result[0];
		} else {
			return FALSE;
		}
	}

	public function validate() {
		$required = ['domainkey', 'domain_id', 'description'];
		foreach ($required as $r) {
			if (!$this->hasData($r)) {
				throw new ValidationFailed('Missing required field: '. $r);
			}
		}

		return TRUE;
	}
}
