<?php

class APIKey extends DBObject {
	protected static $_fields = ['id' => NULL,
	                             'apikey' => NULL,
	                             'user_id' => NULL,
	                             'description' => NULL,
	                             'domains_read' => false,
	                             'domains_write' => false,
	                             'user_read' => false,
	                             'user_write' => false,
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

	public function getID() {
		return $this->getData('id');
	}

	public function getKey() {
		return $this->getData('apikey');
	}

	public function getUserID() {
		return $this->getData('user_id');
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
}
