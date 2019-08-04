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
		return json_decode($this->getData('data'));
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

	public function getLogs() {
		$result = [];

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

	public function addLog($text) {
		$setQuery = 'INSERT INTO `joblogs` (`job_id`, `time`, `data`) VALUES (:jobid, :time, :data)';
		$setStatement = $this->getDB()->getPDO()->prepare($setQuery);

		$setStatement->execute([':jobid' => $this->getID(), ':time' => time(), ':data' => $text]);
	}

	public function validate() {
		return true;
	}
}
