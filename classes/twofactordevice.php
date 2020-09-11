<?php

use shanemcc\phpdb\DBObject;
use shanemcc\phpdb\ValidationFailed;

class TwoFactorDevice extends DBObject {
	protected static $_fields = ['id' => NULL,
	                             'user_id' => NULL,
	                             'deviceid' => NULL,
	                             'description' => NULL,
	                             'created' => 0,
	                             'lastused' => 0
	                            ];
	protected static $_key = 'id';
	protected static $_table = 'twofactordevices';

	public function __construct($db) {
		parent::__construct($db);
	}

	public function setDeviceID($value) {
		if ($value === TRUE) {
			$value = genUUID();
		}
		return $this->setData('deviceid', $value);
	}

	public function setUserID($value) {
		return $this->setData('user_id', $value);
	}

	public function setDescription($value) {
		return $this->setData('description', $value);
	}

	public function setLastUsed($value) {
		return $this->setData('lastused', $value);
	}

	public function setCreated($value) {
		return $this->setData('created', $value);
	}

	public function getID() {
		return intval($this->getData('id'));
	}

	public function getDeviceID() {
		return $this->getData('deviceid');
	}

	public function getUserID() {
		return intval($this->getData('user_id'));
	}

	public function getDescription() {
		return $this->getData('description');
	}

	public function getLastUsed() {
		return intval($this->getData('lastused'));
	}

	public function getCreated() {
		return intval($this->getData('created'));
	}

	/**
	 * Load an object from the database based on user_id AND the device id.
	 *
	 * @param $db Database object to load from.
	 * @param $user user id to look for
	 * @param $deviceid device id to look for
	 * @return FALSE if no object exists, else the object.
	 */
	public static function loadFromUserDeviceID($db, $user, $deviceid) {
		$result = static::find($db, ['user_id' => $user, 'deviceid' => $deviceid]);
		if ($result) {
			return $result[0];
		} else {
			return FALSE;
		}
	}

	/**
	 * Load an object from the database based on user_id AND the device id.
	 *
	 * @param $db Database object to load from.
	 * @param $user user id to look for
	 * @param $id id to look for
	 * @return FALSE if no object exists, else the object.
	 */
	public static function loadFromUserAndID($db, $user, $id) {
		$result = static::find($db, ['user_id' => $user, 'id' => $id]);
		if ($result) {
			return $result[0];
		} else {
			return FALSE;
		}
	}

	public function validate() {
		$required = ['deviceid', 'user_id', 'description'];
		foreach ($required as $r) {
			if (!$this->hasData($r)) {
				throw new ValidationFailed('Missing required field: '. $r);
			}
		}

		return TRUE;
	}

	public function toArray() {
		$result = parent::toArray();
		foreach (['id', 'user_id', 'created', 'lastused'] as $k) { if (!isset($result[$k])) { continue; }; $v = $this->getData($k); $result[$k] = ($v == null) ? $v : intval($v); }
		return $result;
	}
}
