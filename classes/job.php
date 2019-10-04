<?php

use shanemcc\phpdb\DBObject;

class Job extends DBObject {
	protected static $_fields = ['id' => NULL,
	                             'name' => NULL,
	                             'data' => NULL,
	                             'created' => NULL,
	                             'started' => NULL,
	                             'finished' => NULL,
	                             'state' => NULL,
	                             'result' => NULL,
	                            ];
	protected static $_key = 'id';
	protected static $_table = 'jobs';

	// Depends on levels for unknown objects.
	protected $_depends = [];

	public function __construct($db) {
		parent::__construct($db);
	}

	public function setName($value) {
		return $this->setData('name', $value);
	}

	public function setJobData($value) {
		return $this->setData('data', json_encode($value));
	}

	public function setCreated($value) {
		return $this->setData('created', $value);
	}

	public function setStarted($value) {
		return $this->setData('started', $value);
	}

	public function setFinished($value) {
		return $this->setData('finished', $value);
	}

	public function setState($value) {
		return $this->setData('state', $value);
	}

	public function setResult($value) {
		return $this->setData('result', $value);
	}

	public function getID() {
		return $this->getData('id');
	}

	public function getName() {
		return $this->getData('name');
	}

	public function getJobData() {
		return json_decode($this->getData('data'), true);
	}

	public function getCreated() {
		return $this->getData('created');
	}

	public function getStarted() {
		return $this->getData('started');
	}

	public function getFinished() {
		return $this->getData('finished');
	}

	public function getState() {
		return $this->getData('state');
	}

	public function getResult() {
		return $this->getData('result');
	}

	/**
	 * Find all objects that match an array of IDs.
	 *
	 * @param $db Database object to load from.
	 * @param $id Array of IDs to find.
	 * @return FALSE if no objects exist, else the objects.
	 */
	public static function findByID($db, $id) {
		if (empty($id)) { return []; }

		$jobSearch = Job::getSearch($db);
		$jobSearch->where('id', $id);
		return $jobSearch->find('id');
	}

	/**
	 * Add a job that must complete before we can start.
	 */
	public function addDependency($parentid) {
		$this->_depends[$parentid] = true;
		$this->setChanged();
		return $this;
	}

	/**
	 * Remove a job that must complete before we can start.
	 */
	public function removeDependency($parentid) {
		if (isset($this->_depends[$parentid])) {
			$this->_depends[$parentid] = false;
		}
		$this->setChanged();

		return $this;
	}

	/**
	 * Get jobs that must complete before we can start.
	 */
	public function getDependsOn() {
		$jobs = Job::findByID($this->getDB(), array_keys($this->_depends));

		$result = [];
		foreach ($this->_depends as $k => $v) {
			if (isset($jobs[$k]) && $v !== false) {
				$result[$k] = $jobs[$k];
			}
		}

		return $result;
	}

	/**
	 * Get jobs that require us to complete before they can start.
	 */
	public function getDependants() {
		// Get dependencies
		$query = 'SELECT `child_id` FROM job_depends WHERE `parent_id` = :parent';
		$params = [':parent' => $this->getID()];
		$statement = $this->getDB()->getPDO()->prepare($query);
		$statement->execute($params);
		$result = $statement->fetchAll(PDO::FETCH_ASSOC);

		$children = [];
		foreach ($result as $row) {
			$children[] = $row['child_id'];
		}

		$jobs = Job::findByID($this->getDB(), $children);

		return $jobs;

	}

	public function validate() {
		return true;
	}

	public function postSave($result) {
		if ($result) {
			// Persist dependency changes
			$setQuery = 'INSERT INTO job_depends (`parent_id`, `child_id`) VALUES (:parent, :child) ON DUPLICATE KEY UPDATE child_id = :child';
			$setStatement = $this->getDB()->getPDO()->prepare($setQuery);

			$removeQuery = 'DELETE FROM job_depends WHERE `parent_id` = :parent AND `child_id` = :child';
			$removeStatement = $this->getDB()->getPDO()->prepare($removeQuery);

			$params = [':child' => $this->getID()];
			foreach ($this->_depends as $parent => $required) {
				$params[':parent'] = $parent;
				if ($required == false) {
					$removeStatement->execute($params);
				} else {
					$setStatement->execute($params);
				}
			}
		}
	}

	public function postLoad() {
		// Get dependencies
		$query = 'SELECT `parent_id` FROM job_depends WHERE `child_id` = :child';
		$params = [':child' => $this->getID()];
		$statement = $this->getDB()->getPDO()->prepare($query);
		$statement->execute($params);
		$result = $statement->fetchAll(PDO::FETCH_ASSOC);

		foreach ($result as $row) {
			$this->addDependency($row['parent_id']);
		}
	}

	/**
	 * Get the logs for this job from the database.
	 */
	public function getLogs() {
		$result = [];

		if ($this->getID() == null) { return []; }

		// Get access levels;
		$query = 'SELECT `time`, `data` FROM `joblogs` WHERE `job_id` = :jobid';
		$params = [':jobid' => $this->getID()];
		$statement = $this->getDB()->getPDO()->prepare($query);
		$statement->execute($params);
		$sqlresult = $statement->fetchAll(PDO::FETCH_ASSOC);

		foreach ($sqlresult as $row) {
			$result[] = $row;
		}

		return $result;
	}

	/**
	 * Add a new log entry for this job from the database.
	 *
	 * This is immediately added, and is a noop if this job has not yet been
	 * persisted to the database.
	 */
	public function addLog($text) {
		if ($this->getID() == null) { return; }

		$setQuery = 'INSERT INTO `joblogs` (`job_id`, `time`, `data`) VALUES (:jobid, :time, :data)';
		$setStatement = $this->getDB()->getPDO()->prepare($setQuery);

		$setStatement->execute([':jobid' => $this->getID(), ':time' => time(), ':data' => $text]);
	}
}
