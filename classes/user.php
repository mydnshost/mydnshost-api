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

	public function __construct($db) {
		parent::__construct($db);
	}

	public function setEmail($value) {
		return $this->setData('email', $value);
	}

	public function setRealName($value) {
		return $this->setData('realname', $value);
	}

	public function setPassword($value) {
		return $this->setData('password', bcrypt::hash($value));
	}

	public function setAdmin($value) {
		return $this->setData('admin', parseBool($value) ? 'true' : 'false');
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
	 * Find all objects that match an array of IDs.
	 *
	 * @param $db Database object to load from.
	 * @param $id Array of IDs to find.
	 * @return FALSE if no objects exist, else the objects.
	 */
	public static function findByID($db, $id) {
		$userSearch = User::getSearch($db);
		$userSearch->where('id', $id);
		return $userSearch->find('id');
	}

	/**
	 * Find all objects that match an array of IDs.
	 *
	 * @param $db Database object to load from.
	 * @param $id Array of IDs to find.
	 * @return FALSE if no objects exist, else the objects.
	 */
	public static function findByAddress($db, $address) {
		$userSearch = User::getSearch($db);
		$userSearch->where('email', $address);
		return $userSearch->find('email');
	}

	/**
	 * Get a domain searcher that limits us to domains we have access to.
	 */
	private function getDomainSearch() {
		$domainSearch = Domain::getSearch($this->getDB());
		$domainSearch->join('domain_access', '`domains`.`id` = `domain_access`.`domain_id`', 'LEFT');
		$domainSearch->select('domain_access', 'level');
		$domainSearch->select('domain_access', 'user_id');
		$domainSearch->where('user_id', $this->getID());

		return $domainSearch;
	}

	/**
	 * Get all the domains for this user.
	 *
	 * @return List of domain objects for this user.
	 */
	public function getDomains() {
		$result = $this->getDomainSearch()->find();

		return ($result) ? $result : [];
	}

	/**
	 * Get a specific domain if it is owned by this user.
	 *
	 * @param $id Domain ID to look for.
	 * @return Domain object if found else FALSE.
	 */
	public function getDomainByID($id) {
		$result = $this->getDomainSearch()->where('id', $id)->find();

		return ($result) ? $result[0] : FALSE;
	}

	/**
	 * Get a specific domain if it is owned by this user.
	 *
	 * @param $db Database instance to look in.
	 * @param $id Domain ID to look for.
	 * @return Domain object if found else FALSE.
	 */
	public function getDomainByName($name) {
		$result = $this->getDomainSearch()->where('domain', $name)->find();
		return ($result) ? $result[0] : FALSE;
	}

	public function validate() {
		$required = ['password', 'email', 'realname'];
		foreach ($required as $r) {
			if (!$this->hasData($r)) {
				throw new ValidationFailed('Missing required field: '. $r);
			}
		}

		return TRUE;
	}
}
