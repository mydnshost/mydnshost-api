<?php

use shanemcc\phpdb\DBObject;
use shanemcc\phpdb\ValidationFailed;

class TwoFactorKey extends DBObject {
	protected static $_fields = ['id' => NULL,
	                             'user_id' => NULL,
	                             'key' => NULL,
	                             'description' => NULL,
	                             'created' => 0,
	                             'lastused' => 0,
	                             'active' => false,
	                             'type' => 'rfc6238',
	                             'onetime' => false,
	                            ];
	protected static $_key = 'id';
	protected static $_table = 'twofactorkeys';

	public function __construct($db) {
		parent::__construct($db);
	}

	public function setKey($value) {
		if ($value === TRUE) {
			$type = $this->getType();
			switch ($type) {
				case "rfc6238":
					$ga = new PHPGangsta_GoogleAuthenticator();
					$value = $ga->createSecret();
					break;

				case "plain":
					$value = implode("-", str_split(strtoupper(substr(sha1(openssl_random_pseudo_bytes('512')), 0, 16)), 4));
					break;

				default:
					throw new Exception('Unknown key type: ' . $type);
			}
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

	public function setType($value) {
		return $this->setData('type', strtolower($value));
	}

	public function setOneTime($value) {
		return $this->setData('onetime', parseBool($value) ? 'true' : 'false');
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

	public function getType() {
		return $this->getData('type');
	}

	public function isOneTime() {
		return parseBool($this->getData('onetime'));
	}

	public function isUsableKey() {
		// Key is active and either multi-use or unused.
		return $this->isActive() && (!$this->isOneTime() || $this->getLastUsed() == 0);
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
				throw new ValidationFailed('Missing required field: ' . $r);
			}
		}

		if (!in_array($this->getType(), ["rfc6238", "plain"])) {
			throw new ValidationFailed('Unknown key type: ' . $this->getType());
		}

		return TRUE;
	}

	public function verify($code, $discrepancy = 1) {
		$type = $this->getType();
		switch ($type) {
			case "rfc6238":
				return verify_rfc6238($code, $discrepancy);

			case "plain":
				return strtoupper($code) == strtoupper($this->getKey());

			default:
				throw new Exception('Unknown key type: ' . $type);
		}
	}

	private function verify_rfc6238($code, $discrepancy = 1) {
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
