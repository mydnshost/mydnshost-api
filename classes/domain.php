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

	/**
	 * Get all the records for this domain.
	 *
	 * @param $db Database instance to look in.
	 * @return List of record objects for this domain.
	 */
	public function getRecords($db) {
		$result = Record::find($db, ['domain_id' => $this->getID()]);
		return ($result) ? $result : [];
	}

	/**
	 * Get a specific record ID if it is owned by this domain.
	 *
	 * @param $db Database instance to look in.
	 * @param $id Record ID to look for.
	 * @return Record object if found else FALSE.
	 */
	public function getRecord($db, $id) {
		$result = Record::find($db, ['domain_id' => $this->getID(), 'id' => $id]);
		return ($result) ? $result[0] : FALSE;
	}
}
