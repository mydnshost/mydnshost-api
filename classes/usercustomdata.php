<?php

use shanemcc\phpdb\DBObject;
use shanemcc\phpdb\ValidationFailed;

class UserCustomData extends DBObject {
	protected static $_fields = ['id' => NULL,
	                             'user_id' => NULL,
	                             'key' => NULL,
	                             'value' => NULL
	                            ];
	protected static $_key = 'id';
	protected static $_table = 'usercustomdata';

	public function __construct($db) {
		parent::__construct($db);
	}

	public function setKey($value) {
		return $this->setData('key', strtolower($value));
	}

	public function setUserID($value) {
		return $this->setData('user_id', $value);
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

	public function getValue() {
		return $this->getData('value');
	}

	/**
	 * Load an object from the database based on user_id AND the key id.
	 *
	 * @param $db Database object to load from.
	 * @param $user user id to look for
	 * @param $key key name to look for
	 * @return FALSE if no object exists, else the object.
	 */
	public static function loadFromUserKey($db, $user, $key = null) {
		$find = ['user_id' => $user];
		if ($key !== null) { $find['key'] = strtolower($key); }
		$result = static::find($db, $find);

		if ($result) {
			return ($key === null) ? $result : $result[0];
		} else {
			return FALSE;
		}
	}

	public function validate() {
		$required = ['key', 'user_id', 'value'];
		foreach ($required as $r) {
			if (!$this->hasData($r)) {
				throw new ValidationFailed('Missing required field: '. $r);
			}
		}

		return TRUE;
	}

	public function toArray() {
		$result = parent::toArray();
		foreach (['id', 'user_id'] as $k) { if (!isset($result[$k])) { continue; }; $v = $this->getData($k); $result[$k] = ($v == null) ? $v : intval($v); }
		return $result;
	}
}
