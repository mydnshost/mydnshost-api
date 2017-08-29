<?php

use shanemcc\phpdb\DBObject;

class Hook extends DBObject {
	protected static $_fields = ['id' => NULL,
	                             'hook' => NULL,
	                             'args' => NULL,
	                            ];
	protected static $_key = 'id';
	protected static $_table = 'hooks';

	public function __construct($db) {
		parent::__construct($db);
	}

	public function setHook($value) {
		return $this->setData('hook', $value);
	}

	public function setArgs($value) {
		return $this->setData('args', serialize($value));
	}

	public function getID() {
		return $this->getData('id');
	}

	public function getHook() {
		return $this->getData('hook');
	}

	public function getArgs() {
		return unserialize($this->getData('args'));
	}

	public function validate() {
		return true;
	}
}
