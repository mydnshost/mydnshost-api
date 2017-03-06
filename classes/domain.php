<?php

class Domain extends DBObject {
	protected static $_fields = ['id' => NULL,
	                             'domain' => NULL,
	                             'owner' => NULL,
	                             'disabled' => false,
	                            ];
	protected static $_key = 'id';
	protected static $_table = 'domains';

	// SOA for unknown objects.
	protected $_soa = FALSE;

	public function __construct($db) {
		parent::__construct($db);
	}

	public function setDomain($value) {
		return $this->setData('domain', $value);
	}

	public function setOwner($value) {
		return $this->setData('owner', $value);
	}

	public function setDisabled($value) {
		return $this->setData('disabled', parseBool($value) ? 'true' : 'false');
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
	 * @return List of record objects for this domain.
	 */
	public function getRecords() {
		$result = Record::find($this->getDB(), ['domain_id' => $this->getID(), 'type' => 'SOA'], ['type' => '!=']);
		return ($result) ? $result : [];
	}

	/**
	 * Get a specific record ID if it is owned by this domain.
	 *
	 * @param $id Record ID to look for.
	 * @return Record object if found else FALSE.
	 */
	public function getRecord($id) {
		$result = Record::find($this->getDB(), ['domain_id' => $this->getID(), 'id' => $id, 'type' => 'SOA'], ['type' => '!=']);
		return ($result) ? $result[0] : FALSE;
	}

	/**
	 * Get the SOA record for this domain.
	 *
	 * @param $fresh Get a fresh copy from the DB rather than using our cached copy.
	 * @return Record object if found else FALSE.
	 */
	public function getSOARecord($fresh = FALSE) {
		$soa = $this->_soa;
		if (($soa === FALSE || $fresh) && $this->isKnown()) {
			$soa = Record::find($this->getDB(), ['domain_id' => $this->getID(), 'type' => 'SOA']);
		}

		if ($soa === FALSE) {
			$soa = new Record($this->getDB());
			$soa->setDomainID($this->getID());
			$soa->setName('');
			$soa->setType('SOA');
			$soa->setContent(sprintf('ns1.%s. dnsadmin.%s. 0 86400 7200 2419200 60', $this->getDomain(), $this->getDomain()));
			$soa->setTTL(86400);
			$soa->setChangedAt(time());
			if ($this->isKnown()) {
				$soa->save();
			}
			$this->_soa = [$soa];
			return $soa;
		} else {
			$this->_soa = $soa;
			return $soa[0];
		}
	}

	/**
	 * Update the domain serial.
	 *
	 * @param $serial Serial to set. Use null to auto-generate.
	 * @return Record object if found else FALSE.
	 */
	public function updateSerial($serial = null) {
		$soa = $this->getSOARecord();
		$parsed = $soa->parseSOA();

		if ($serial == NULL) {
			$oldSerial = $parsed['serial'];
			$serial = date('Ymd').'00';
			$diff = ($oldSerial - $serial);

			// If we already have a serial for today, the difference will be
			// >= 0. Older days serials are < 0.
			if ($diff >= 0) {
				$serial += ($diff + 1);
			}
		}

		$parsed['serial'] = $serial;
		$soa->updateSOAContent($parsed);

		$soa->save();

		return $serial;
	}

	public function validate() {
		$required = ['domain', 'owner'];
		foreach ($required as $r) {
			if (!$this->hasData($r)) {
				throw new ValidationFailed('Missing required field: '. $r);
			}
		}

		return TRUE;
	}
}
