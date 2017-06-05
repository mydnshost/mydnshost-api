<?php

class TwoFactorKey extends DBObject {
	protected static $_fields = ['id' => NULL,
	                             'user_id' => NULL,
	                             'key' => NULL,
	                             'description' => NULL,
	                             'created' => 0,
	                             'lastused' => 0,
	                             'active' => false,
	                            ];
	protected static $_key = 'id';
	protected static $_table = 'twofactorkeys';

	public function __construct($db) {
		parent::__construct($db);
	}

	public function setKey($value) {
		if ($value === TRUE) {
			$ga = new PHPGangsta_GoogleAuthenticator();
			$value = $ga->createSecret();
		}
		return $this->setData('key', $value);
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

	public function setActive($value) {
		return $this->setData('active', parseBool($value) ? 'true' : 'false');
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

	public function getDescription() {
		return $this->getData('description');
	}

	public function getLastUsed() {
		return $this->getData('lastused');
	}

	public function getCreated() {
		return $this->getData('created');
	}

	public function isActive() {
		return parseBool($this->getData('active'));
	}

	/**
	 * Load an object from the database based on user_id AND the key id.
	 *
	 * @param $db Database object to load from.
	 * @param $user user id to look for
	 * @param $key key id to look for
	 * @return FALSE if no object exists, else the object.
	 */
	public static function loadFromUserKey($db, $user, $key) {
		$result = static::find($db, ['user_id' => $user, 'id' => $key]);
		if ($result) {
			return $result[0];
		} else {
			return FALSE;
		}
	}

	public function validate() {
		$required = ['key', 'user_id', 'description'];
		foreach ($required as $r) {
			if (!$this->hasData($r)) {
				throw new ValidationFailed('Missing required field: '. $r);
			}
		}

		return TRUE;
	}

	public function verify($code, $discrepancy = 1) {
		$ga = new PHPGangsta_GoogleAuthenticator();

		$minTimeSlice = floor($this->getLastUsed() / 30);
		$currentTimeSlice = floor(time() / 30);

		for ($i = -$discrepancy; $i <= $discrepancy; ++$i) {
			$thisTimeSlice = $currentTimeSlice + $i;
			if ($thisTimeSlice <= $minTimeSlice) { continue; }

			if ($ga->verifyCode($this->getKey(), $code, 0, $thisTimeSlice)) {
				return true;
			}
		}

		return false;
	}
}