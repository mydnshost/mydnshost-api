<?php

use shanemcc\phpdb\DBObject;
use shanemcc\phpdb\ValidationFailed;

class User extends DBObject {
	protected static $_fields = ['id' => NULL,
	                             'email' => NULL,
	                             'password' => NULL,
	                             'realname' => NULL,
	                             'disabled' => false,
	                             'verifycode' => NULL,
	                             'disabledreason' => NULL,
	                            ];
	protected static $_key = 'id';
	protected static $_table = 'users';

	protected static $VALID_PERMISSIONS = ['manage_domains', 'domains_create', 'manage_users', 'manage_permissions', 'impersonate_users'];

	// Permissions levels for unknown objects.
	protected $_permissions = [];

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

	public function setRawPassword($value) {
		return $this->setData('password', $value);
	}

	public function setDisabled($value) {
		if (!parseBool($value)) {
			$this->setDisabledReason(NULL);
		}
		return $this->setData('disabled', parseBool($value) ? 'true' : 'false');
	}

	public function setDisabledReason($value) {
		return $this->setData('disabledreason', $value);
	}

	public function setVerifyCode($value) {
		return $this->setData('verifycode', $value);
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

	public function getRawPassword() {
		return $this->getData('password');
	}

	public function isDisabled() {
		return parseBool($this->getData('disabled'));
	}

	public function getDisabledReason() {
		return $this->getData('disabledreason');
	}

	public function getVerifyCode() {
		return $this->getData('verifycode');
	}

	public function getPermissions() {
		return $this->_permissions;
	}

	public function getPermission($permission) {
		return array_key_exists($permission, $this->_permissions) ? $this->_permissions[$permission] : false;
	}

	public function setPermission($permission, $value) {
		$value = parseBool($value);
		if ($permission == 'all') {
			foreach (User::$VALID_PERMISSIONS as $p) {
				$this->setPermission($p, $value);
			}
			return $this;
		}

		if (in_array($permission, User::$VALID_PERMISSIONS)) {
			if ($value && !array_key_exists($permission, $this->_permissions)) {
				$this->_permissions[$permission] = true;
				$this->setChanged();
			} else if (!$value && array_key_exists($permission, $this->_permissions)) {
				unset($this->_permissions[$permission]);
				$this->setChanged();
			}
		}
		return $this;
	}


	public function isUnVerified() {
		return !empty($this->getVerifyCode()) && $this->getRawPassword() == '-' && $this->isDisabled();
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
	protected function getDomainSearch() {
		$domainSearch = Domain::getSearch($this->getDB());
		$domainSearch->join('domain_access', '`domains`.`id` = `domain_access`.`domain_id`', 'LEFT');
		$domainSearch->select('domain_access', 'level');
		$domainSearch->select('domain_access', 'user_id');
		$domainSearch->where('user_id', $this->getID());
		$domainSearch->order('domain');

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

	/**
	 * Validate the user account.
	 *
	 * @return TRUE if validation succeeded
	 * @throws ValidationFailed if there is an error.
	 */
	public function validate() {
		$required = ['password', 'email', 'realname'];
		foreach ($required as $r) {
			if (!$this->hasData($r)) {
				throw new ValidationFailed('Missing required field: '. $r);
			}
		}

		if (filter_var($this->getEmail(), FILTER_VALIDATE_EMAIL) === FALSE) {
			throw new ValidationFailed('Email address is invalid.');
		}

		return TRUE;
	}

	public function postSave($result) {
		if ($result) {
			// Persist permission changes
			$setQuery = 'INSERT INTO permissions (`user_id`, `permission`) VALUES (:user, :permission)';
			$setStatement = $this->getDB()->getPDO()->prepare($setQuery);

			$params = [':user' => $this->getID()];
			$removeParams = [];
			$removeID = 0;
			foreach ($this->_permissions as $permission => $access) {
				$params[':permission'] = $permission;
				$setStatement->execute($params);

				$removeParams[':permission_' . $removeID++] = $permission;
			}

			if (count($removeParams) > 0) {
				$removeQuery = sprintf('DELETE FROM permissions WHERE `user_id` = :user AND `permission` NOT IN (%s)', implode(', ', array_keys($removeParams)));
			} else {
				$removeQuery = sprintf('DELETE FROM permissions WHERE `user_id` = :user');
			}
			$removeStatement = $this->getDB()->getPDO()->prepare($removeQuery);
			$removeStatement->execute(array_merge([':user' => $this->getID()], $removeParams));
		}
	}

	public function postLoad() {
		// Get access levels;
		$query = 'SELECT `permission` FROM permissions WHERE `user_id` = :user';
		$params = [':user' => $this->getID()];
		$statement = $this->getDB()->getPDO()->prepare($query);
		$statement->execute($params);
		$result = $statement->fetchAll(PDO::FETCH_ASSOC);

		foreach ($result as $row) {
			$this->setPermission($row['permission'], true);
		}
	}

	public function toArray() {
		$result = parent::toArray();
		$result['permissions'] = $this->_permissions;

		return $result;
	}
}
