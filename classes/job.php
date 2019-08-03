<?php

use shanemcc\phpdb\DBObject;

class job extends DBObject {
	protected static $_fields = ['id' => NULL,
	                             'name' => NULL,
	                             'data' => NULL,
	                             'created' => NULL,
	                             'started' => NULL,
	                             'finished' => NULL,
	                             'state' => NULL,
	                            ];
	protected static $_key = 'id';
	protected static $_table = 'jobs';

	public function __construct($db) {
		parent::__construct($db);
	}

	public function setName($value) {
		return $this->setData('name', $value);
	}

	public function setData($value) {
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

	public function getID() {
		return $this->getData('id');
	}

	public function getName() {
		return $this->getData('name');
	}

	public function getData() {
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

	public function validate() {
		return true;
	}
}
