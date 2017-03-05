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

	/**
	 * Get all the domains for this user.
	 *
	 * @param $db Database instance to look in.
	 * @return List of domain objects for this user.
	 */
	public function getDomains($db) {
		$result = Domain::find($db, ['owner' => $this->getID()]);
		return ($result) ? $result : [];
	}

	/**
	 * Get a specific domain if it is owned by this user.
	 *
	 * @param $db Database instance to look in.
	 * @param $id Domain ID to look for.
	 * @return Domain object if found else FALSE.
	 */
	public function getDomainByID($db, $id) {
		$result = Domain::find($db, ['owner' => $this->getID(), 'id' => $id]);
		return ($result) ? $result[0] : FALSE;
	}

	/**
	 * Get a specific domain if it is owned by this user.
	 *
	 * @param $db Database instance to look in.
	 * @param $id Domain ID to look for.
	 * @return Domain object if found else FALSE.
	 */
	public function getDomainByName($db, $name) {
		$result = Domain::find($db, ['owner' => $this->getID(), 'domain' => $name]);
		return ($result) ? $result[0] : FALSE;
	}
}
