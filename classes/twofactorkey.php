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
	                             'code' => true,
	                             'push' => false,
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
		global $config;

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
				case "authy":
					throw new TwoFactorKeyAutoValueException($type . ' does not use auto-generated keys.');
					break;

				default:
					throw new Exception('Unknown key type: ' . $type);
			}
		}

		switch ($type) {
			case "yubikeyotp":
				if (self::canUseYubikey() && $value) {
					if (is_array($value)) {
						if (!isset($value['secret'])) { throw new Exception('Missing "secret" in value array.'); }
						$value = $value['secret'];
					}
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

			case "authy":
				if (self::canUseAuthy() && $value) {
					if (is_array($value)) {
						if (isset($value['authyid'])) {
							$value = $value['authyid'];
						} else {
							if (!isset($value['email'])) { throw new Exception('Missing "email" in value array.'); }
							if (!isset($value['countrycode'])) { throw new Exception('Missing "countrycode" in value array.'); }
							if (!isset($value['phone'])) { throw new Exception('Missing "phone" in value array.'); }

							// Create user.
							$authy_api = new Authy\AuthyApi($config['twofactor']['authy']['apikey']);
							$authy_user = $authy_api->registerUser($value['email'], $value['phone'], $value['countrycode']);

							if ($authy_user->ok()) {
								$value = $authy_user->id();

							} else {
								$errorData = [];
								foreach ($authy_user->errors() as $field => $message) {
									$errorData[] = $field . ': ' . $message;
								}

								throw new Exception('Error creating authy user. ' . implode('/', $errorData));
							}
						}

					} else {
						throw new Exception('Value must be an array to create data from.');
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

	public function getRequiredPermissionsForType() {
		switch ($this->getType()) {
			case "authy":
				return ['2fa_push'];

			default:
				return [];
		}
	}

	public function setType($value) {
		// Clear key when changing type.
		$this->setData('key', NULL);

		switch (strtolower($value)) {
			case "authy":
				$this->setPush(true);
				break;

			default:
				$this->setPush(false);
				break;
		}

		return $this->setData('type', strtolower($value));
	}

	public function setCode($value) {
		return $this->setData('code', parseBool($value) ? 'true' : 'false');
	}

	public function setPush($value) {
		return $this->setData('push', parseBool($value) ? 'true' : 'false');
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

	public function isCode() {
		return parseBool($this->getData('code'));
	}

	public function isPush() {
		return parseBool($this->getData('push'));
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

		if (self::canUseAuthy()) {
			$result[] = "authy";
		}

		return $result;
	}

	/**
	 * Keys are usable if:
	 *   - They are active
	 *   - They are not one time, or they have not been used
	 *   - expiry date is "0" or in the future
	 *   - We have the required ability to validate
	 *   - If a user is passed, also check that they have the appropriate permissions.
	 *
	 * @return True if key is usable.
	 */
	public function isUsableKey($user = FALSE) {
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

		if ($this->getType() == 'authy' && !self::canUseAuthy()) {
			$usable = false;
		}

		if ($usable && $user !== FALSE) {
			// Can we use this type of key.
			$usable &= (($this->isPush() && $user->getPermission('2fa_push')) || $this->isCode());

			// Check that the user still has the right permissions for this
			// key.
			foreach ($this->getRequiredPermissionsForType() as $perm) {
				$usable &= $user->getPermission($perm);
			}
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
		// Only allow code-based tokens.
		if (!$this->isCode()) { return FALSE; }

		$type = $this->getType();
		switch ($type) {
			case "rfc6238":
				return $this->verify_rfc6238($code, $discrepancy);

			case "plain":
				return strtoupper($code) == strtoupper($this->getKey());

			case "yubikeyotp":
				return $this->verify_yubikey($code);

			case "authy":
				return $this->verify_authycode($code);

			default:
				throw new Exception('Unknown key type: ' . $type);
		}
	}

	public function pushVerify($message) {
		// Non-Push-Based tokens verify with a code.
		if (!$this->isPush()) { return FALSE; }

		$type = $this->getType();
		switch ($type) {
			case "authy":
				return $this->verify_authypush($message);

			default:
				throw new Exception('Unknown key type: ' . $type);
		}
	}

	private static function canUseYubikey() {
		global $config;

		return isset($config['twofactor']['yubikey']['enabled']) && $config['twofactor']['yubikey']['enabled'];
	}

	private static function canUseAuthy() {
		global $config;

		return isset($config['twofactor']['authy']['enabled']) && $config['twofactor']['authy']['enabled'];
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

	private function verify_authypush($message) {
		global $config;
		if (!self::canUseAuthy()) { return FALSE; }

		$authy_api = new Authy\AuthyApi($config['twofactor']['authy']['apikey']);

		$response = $authy_api->createApprovalRequest($this->getKey(), $message);
		$uuid = $response->bodyvar('approval_request')->uuid;

		// 20 second time out.
		for ($i = 0; $i < 20; $i++) {
			$response = $authy_api->getApprovalRequest($uuid);
			$status = $response->bodyvar('approval_request')->status;

			if ($status == 'approved') { return TRUE; }
			if ($status == 'denied') { return FALSE; }

			// Sleep a bit.
			sleep(1);
		}

		return FALSE;
	}

	private function verify_authycode($code) {
		global $config;
		if (!self::canUseAuthy()) { return FALSE; }

		$authy_api = new Authy\AuthyApi($config['twofactor']['authy']['apikey']);

		$verification = $authy_api->verifyToken($this->getKey(), $code);

		return $verification->ok();
	}

	public function postDelete($result) {
		global $config;

		if (!$result) { return; }

		if ($this->getType() == 'authy' && self::canUseAuthy()) {
			$authy_api = new Authy\AuthyApi($config['twofactor']['authy']['apikey']);
			$authy_api->deleteUser($this->getKey());
		}
	}
}
