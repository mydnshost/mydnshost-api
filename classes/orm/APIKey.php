<?php

use shanemcc\phpdb\DBObject;
use shanemcc\phpdb\ValidationFailed;

class APIKey extends DBObject {
	protected static $_fields = ['id' => NULL,
	                             'apikey' => NULL,
	                             'user_id' => NULL,
	                             'description' => NULL,
	                             'domains_read' => false,
	                             'domains_write' => false,
	                             'user_read' => false,
	                             'user_write' => false,
	                             'created' => 0,
	                             'lastused' => 0,
	                            ];
	protected static $_key = 'id';
	protected static $_table = 'apikeys';

	public function __construct($db) {
		parent::__construct($db);
	}

	public function setKey($value) {
		if ($value === TRUE) {
			$value = genUUID();
		}
		return $this->setData('apikey', $value);
	}

	public function setUserID($value) {
		return $this->setData('user_id', $value);
	}

	public function setDescription($value) {
		return $this->setData('description', $value);
	}

	public function setDomainRead($value) {
		return $this->setData('domains_read', parseBool($value) ? 'true' : 'false');
	}

	public function setDomainWrite($value) {
		return $this->setData('domains_write', parseBool($value) ? 'true' : 'false');
	}

	public function setUserRead($value) {
		return $this->setData('user_read', parseBool($value) ? 'true' : 'false');
	}

	public function setUserWrite($value) {
		return $this->setData('user_write', parseBool($value) ? 'true' : 'false');
	}

	public function setLastUsed($value) {
		return $this->setData('lastused', $value);
	}

	public function setCreated($value) {
		return $this->setData('created', $value);
	}

	public function getID() {
		return intvalOrNull($this->getData('id'));
	}

	public function getKey($masked = false) {
		$key = $this->getData('apikey');
		if ($masked) {
			$bits = explode('-', $key);
			$key = [];
			foreach ($bits as $i => $bit) {
				if ($i === 0) { $key[] = $bit; }
				else if ($i === 4) { $key[] = preg_replace('#.#', '*', substr($bit, 0, 7)) . substr($bit, 7); }
				else { $key[] = preg_replace('#.#', '*', $bit); }
			}

			$key = implode('-', $key);
		}
		return $key;
	}

	public function getUserID() {
		return intvalOrNull($this->getData('user_id'));
	}

	public function getDescription() {
		return $this->getData('description');
	}

	public function getDomainRead() {
		return parseBool($this->getData('domains_read'));
	}

	public function getDomainWrite() {
		return parseBool($this->getData('domains_write'));
	}

	public function getUserRead() {
		return parseBool($this->getData('user_read'));
	}

	public function getUserWrite() {
		return parseBool($this->getData('user_write'));
	}

	public function getLastUsed() {
		return intval($this->getData('lastused'));
	}

	public function getCreated() {
		return intval($this->getData('created'));
	}

	/**
	 * Load an object from the database based on user_id AND the key.
	 *
	 * @param $db Database object to load from.
	 * @param $user user id to look for
	 * @param $key key to look for
	 * @return FALSE if no object exists, else the object.
	 */
	public static function loadFromUserKey($db, $user, $key) {
		$result = static::find($db, ['user_id' => $user, 'apikey' => $key]);
		if ($result) {
			return $result[0];
		} else {
			return FALSE;
		}
	}

	public function validate() {
		$required = ['apikey', 'user_id', 'description'];
		foreach ($required as $r) {
			if (!$this->hasData($r)) {
				throw new ValidationFailed('Missing required field: '. $r);
			}
		}

		return TRUE;
	}

	public function toArray() {
		$result = parent::toArray();
		foreach (['domains_read', 'domains_write', 'user_read', 'user_write'] as $k) { if (!isset($result[$k])) { continue; }; $result[$k] = parseBool($this->getData($k)); }
		foreach (['id', 'user_id', 'created', 'lastused'] as $k) { if (!isset($result[$k])) { continue; }; $result[$k] = intvalOrNull($this->getData($k)); }
		return $result;
	}
}
