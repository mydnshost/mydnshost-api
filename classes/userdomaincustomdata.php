<?php

use shanemcc\phpdb\DBObject;
use shanemcc\phpdb\ValidationFailed;

class UserDomainCustomData extends DBObject {
	protected static $_fields = ['id' => NULL,
	                             'user_id' => NULL,
	                             'domain_id' => NULL,
	                             'key' => NULL,
	                             'value' => NULL
	                            ];
	protected static $_key = 'id';
	protected static $_table = 'userdomaincustomdata';

	public function __construct($db) {
		parent::__construct($db);
	}

	public function setKey($value) {
		return $this->setData('key', strtolower($value));
	}

	public function setUserID($value) {
		return $this->setData('user_id', $value);
	}

	public function setDomainID($value) {
		return $this->setData('domain_id', $value);
	}

	public function setValue($value) {
		return $this->setData('value', $value);
	}

	public function getID() {
		return $this->getData('id');
	}

	public function getKey() {
		return $this->getData('key');
	}

	public function getUserID() {
		return $this->getData('user_id');
	}

	public function getDomainID() {
		return $this->getData('domain_id');
	}

	public function getValue() {
		return $this->getData('value');
	}

	/**
	 * Load an object from the database based on user_id, domain_id and
	 * optionally the key.
	 *
	 * @param $db Database object to load from.
	 * @param $user user id to look for
	 * @param $domain domain id to look for
	 * @param $key key name to look for
	 * @return FALSE if no object exists, else the object.
	 */
	public static function loadFromUserDomainKey($db, $user, $domain, $key = null) {
		$find = ['user_id' => $user, 'domain_id' => $domain];
		if ($key !== null) { $find['key'] = strtolower($key); }
		$result = static::find($db, $find);
		if ($result) {
			return ($key === null) ? $result : $result[0];
		} else {
			return FALSE;
		}
	}

	public function validate() {
		$required = ['key', 'user_id', 'domain_id', 'value'];
		foreach ($required as $r) {
			if (!$this->hasData($r)) {
				throw new ValidationFailed('Missing required field: '. $r);
			}
		}

		return TRUE;
	}
}
