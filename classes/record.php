<?php

class Record extends DBObject {
	protected static $_fields = ['id' => NULL,
	                             'domain_id' => NULL,
	                             'name' => NULL,
	                             'type' => NULL,
	                             'content' => NULL,
	                             'ttl' => NULL,
	                             'priority' => NULL,
	                             'changed_at' => NULL,
	                             'changed_by' => NULL,
	                             'disabled' => false,
	                             'synced' => false,
	                            ];
	protected static $_key = 'id';
	protected static $_table = 'records';

	public function setName($value) {
		$this->setData('name', $value);
	}

	public function setType($value) {
		$this->setData('type', $value);
	}

	public function setContent($value) {
		$this->setData('content', $value);
	}

	public function setTTL($value) {
		$this->setData('ttl', $value);
	}

	public function setPriority($value) {
		$this->setData('priority', $value);
	}

	public function setChangedAt($value) {
		$this->setData('changed_at', $value);
	}

	public function setChangedBy($value) {
		$this->setData('changed_by', $value);
	}

	public function setDisabled($value) {
		$this->setData('disabled', parseBool($value) ? 'true' : 'false');
	}

	public function setSynced($value) {
		$this->setData('synced', parseBool($value) ? 'true' : 'false');
	}

	public function getID() {
		return $this->getData('id');
	}

	public function getDomainID() {
		return $this->getData('domain_id');
	}

	public function getName() {
		$this->getData('name');
	}

	public function getType() {
		$this->getData('type');
	}

	public function getContent() {
		$this->getData('content');
	}

	public function getTTL() {
		$this->getData('ttl');
	}

	public function getPriority() {
		$this->getData('priority');
	}

	public function getChangedAt() {
		$this->getData('changed_at');
	}

	public function getChangedBy() {
		$this->getData('changed_by');
	}

	public function isDisabled() {
		return parseBool($this->getData('disabled'));
	}

	public function isSynced() {
		return parseBool($this->getData('synced'));
	}
}
