<?php

class Domain extends DBObject {
	protected static $_fields = ['id' => NULL,
	                             'domain' => NULL,
	                             'owner' => NULL,
	                             'disabled' => false,
	                            ];
	protected static $_key = 'id';
	protected static $_table = 'domains';

	public function setDomain($value) {
		$this->setData('domain', $value);
	}

	public function setOwner($value) {
		$this->setData('owner', $value);
	}

	public function setDisabled($value) {
		$this->setData('disabled', parseBool($value) ? 'true' : 'false');
	}

	public function getID() {
		return $this->getData('id');
	}

	public function getDomain() {
		return $this->getData('domain');
	}

	public function getOwner() {
		return $this->getData('owner');
	}

	public function isDisabled() {
		return parseBool($this->getData('disabled'));
	}
}
