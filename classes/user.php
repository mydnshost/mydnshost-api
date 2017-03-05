<?php

class User extends DBObject {
	protected static $_fields = ['id' => NULL,
	                             'email' => NULL,
	                             'password' => NULL,
	                             'realname' => NULL,
	                             'admin' => false,
	                            ];
	protected static $_key = 'id';
	protected static $_table = 'users';

	public function setEmail($value) {
		$this->setData('email', $value);
	}

	public function setRealName($value) {
		$this->setData('realname', $value);
	}

	public function setPassword($value) {
		$this->setData('password', bcrypt::hash($value));
	}

	public function setAdmin($value) {
		$this->setData('admin', parseBool($value) ? 'true' : 'false');
	}

	public function getID() {
		return $this->getData('id');
	}

	public function getEmail() {
		return $this->getData('email');
	}

	public function getRealName() {
		return $this->getData('realname');
	}

	public function checkPassword($password) {
		$testPass = $this->getData('password');

		return bcrypt::check($password, $testPass);
	}

	public function isAdmin() {
		return parseBool($this->getData('admin'));
	}

	/**
	 * Load an object from the database based on email address.
	 *
	 * @param $db Database object to load from.
	 * @param $address Address to look for.
	 * @return FALSE if no object exists, else the object.
	 */
	public static function loadFromEmail($db, $address) {
		$result = static::find($db, ['email' => $address]);
		if ($result) {
			return $result[0];
		} else {
			return FALSE;
		}
	}
}
