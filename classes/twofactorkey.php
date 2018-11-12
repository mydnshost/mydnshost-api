<?php

use shanemcc\phpdb\DBObject;
use shanemcc\phpdb\ValidationFailed;

class TwoFactorKeyAutoValueException extends Exception { }

class TwoFactorKey extends DBObject {
	protected static $_fields = ['id' => NULL,
	                             'user_id' => NULL,
	                             'key' => NULL,
	                             'description' => NULL,
	                             'created' => 0,
	                             'lastused' => 0,
	                             'expires' => 0,
	                             'active' => false,
	                             'type' => 'rfc6238',
	                             'onetime' => false,
	                             'internal' => false,
	                             'internaldata' => NULL,
	                            ];
	protected static $_key = 'id';
	protected static $_table = 'twofactorkeys';

	public function __construct($db) {
		parent::__construct($db);
	}

	public function setKey($value) {
		$type = $this->getType();

		if ($value === TRUE) {
			switch ($type) {
				case "rfc6238":
					$ga = new PHPGangsta_GoogleAuthenticator();
					$value = $ga->createSecret();
					break;

				case "plain":
					$value = implode("-", str_split(strtoupper(substr(sha1(openssl_random_pseudo_bytes('512')), 0, 16)), 4));
					break;

				case "yubikeyotp":
					throw new TwoFactorKeyAutoValueException($type . ' does not use auto-generated keys.');
					break;

				default:
					throw new Exception('Unknown key type: ' . $type);
			}
		}

		switch ($type) {
			case "yubikeyotp":
				if (self::canUseYubikey()) {
					$response = $this->yubikey_getData($value);
					if ($response['response']->success()) {
						$value = $response['request']->getYubikeyId();
					} else {
						throw new Exception('Error with key: ' . $response->current()->status);
					}
				} else {
					throw new Exception('Unknown key type: ' . $type);
				}

				break;

			default:
				break;
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

	public function setExpires($value) {
		return $this->setData('expires', $value);
	}

	public function setCreated($value) {
		return $this->setData('created', $value);
	}

	public function setActive($value) {
		return $this->setData('active', parseBool($value) ? 'true' : 'false');
	}

	public function setType($value) {
		// Clear key when changing type.
		$this->setData('key', NULL);

		return $this->setData('type', strtolower($value));
	}

	public function setOneTime($value) {
		return $this->setData('onetime', parseBool($value) ? 'true' : 'false');
	}

	public function setInternal($value) {
		return $this->setData('internal', parseBool($value) ? 'true' : 'false');
	}

	public function setInternalData($value) {
		return $this->setData('internaldata', $value);
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

	public function getExpires() {
		return $this->getData('expires');
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

	public function isInternal() {
		return parseBool($this->getData('internal'));
	}

	public function getInternalData() {
		return parseBool($this->getData('internaldata'));
	}

	public static function getKeyTypes() {
		$result = ["rfc6238", "plain"];

		if (self::canUseYubikey()) {
			$result[] = "yubikeyotp";
		}

		return $result;
	}

	/**
	 * Keys are usable if:
	 *   - They are active
	 *   - They are not one time, or they have not been used
	 *   - expiry date is "0" or in the future
	 *   - We have the required ability to validate
	 *
	 * @return True if key is usable.
	 */
	public function isUsableKey() {
		// Key is usable.
		$usable = $this->isActive();

		// Key is not one time or has not been used
		$usable &= (!$this->isOneTime() || $this->getLastUsed() == 0);

		// Key does not expire, or expiry has not been reached.
		$usable &= ($this->getExpires() <= 0 || $this->getExpires() >= time());

		// Can we validate the key?
		if ($this->getType() == 'yubikeyotp' && !self::canUseYubikey()) {
			$usable = false;
		}

		return $usable;
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

		if (!in_array($this->getType(), self::getKeyTypes())) {
			throw new ValidationFailed('Unknown key type: ' . $this->getType());
		}

		return TRUE;
	}

	public function verify($code, $discrepancy = 1) {
		$type = $this->getType();
		switch ($type) {
			case "rfc6238":
				return $this->verify_rfc6238($code, $discrepancy);

			case "plain":
				return strtoupper($code) == strtoupper($this->getKey());

			case "yubikeyotp":
				return $this->verify_yubikey($code);

			default:
				throw new Exception('Unknown key type: ' . $type);
		}
	}

	private static function canUseYubikey() {
		global $config;

		return isset($config['twofactor']['yubikey']['enabled']) && $config['twofactor']['yubikey']['enabled'];
	}

	private function yubikey_getData($code, $check = true) {
		global $config;
		$code = strtolower($code);

		$clientId = $config['twofactor']['yubikey']['clientid'];
		$apiKey = $config['twofactor']['yubikey']['secret'];

		$v = new \Yubikey\Validate($apiKey, $clientId);
		$v->setOtp($code);
		$v->setYubikeyId();
		$response = $check ? $v->check($code) : null;

		return ['request' => $v, 'response' => $response];
	}

	private function verify_yubikey($code) {
		if (!self::canUseYubikey()) { return FALSE; }

		// 1: Does the provided key match our serial?
		$data = $this->yubikey_getData($code, false);
		if ($data['request']->getYubikeyId() !== $this->getKey()) { return FALSE; }

		// 2: Is the key valid?
		$response = $data['request']->check($code);

		return $response->success();
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
