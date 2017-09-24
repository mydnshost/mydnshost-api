<?php

use shanemcc\phpdb\DBObject;
use shanemcc\phpdb\ValidationFailed;

class Record extends DBObject {
	protected static $_fields = ['id' => NULL,
	                             'domain_id' => NULL,
	                             'name' => NULL,
	                             'type' => NULL,
	                             'content' => NULL,
	                             'ttl' => '86400',
	                             'priority' => NULL,
	                             'changed_at' => 0,
	                             'changed_by' => NULL,
	                             'disabled' => false,
	                            ];
	protected static $_key = 'id';
	protected static $_table = 'records';

	protected static $VALID_RRs = ['A', 'AAAA', 'TXT', 'SRV', 'SOA', 'MX', 'TXT', 'PTR', 'CNAME', 'NS', 'CAA', 'DS', 'SSHFP', 'TLSA'];

	public static function getValidRecordTypes() {
		return User::$VALID_RRs;
	}

	public function __construct($db) {
		parent::__construct($db);
	}

	public function setDomainID($value) {
		return $this->setData('domain_id', $value);
	}

	public function setName($value) {
		return $this->setData('name', idn_to_ascii($value));
	}

	public function setType($value) {
		return $this->setData('type', strtoupper($value));
	}

	public function setContent($value) {
		return $this->setData('content', trim($value));
	}

	public function setTTL($value) {
		return $this->setData('ttl', $value);
	}

	public function setPriority($value) {
		if ($value == '') { $value = NULL; }
		return $this->setData('priority', $value);
	}

	public function setChangedAt($value) {
		return $this->setData('changed_at', $value);
	}

	public function setChangedBy($value) {
		return $this->setData('changed_by', $value);
	}

	public function setDisabled($value) {
		return $this->setData('disabled', parseBool($value) ? 'true' : 'false');
	}

	public function getID() {
		return $this->getData('id');
	}

	public function getDomainID() {
		return $this->getData('domain_id');
	}

	public function getName() {
		return idn_to_utf8($this->getData('name'));
	}

	public function getNameRaw() {
		return $this->getData('name');
	}

	public function getType() {
		return $this->getData('type');
	}

	public function getContent() {
		return $this->getData('content');
	}

	public function getTTL() {
		return $this->getData('ttl');
	}

	public function getPriority() {
		return $this->getData('priority');
	}

	public function getChangedAt() {
		return $this->getData('changed_at');
	}

	public function getChangedBy() {
		return $this->getData('changed_by');
	}

	public function isDisabled() {
		return parseBool($this->getData('disabled'));
	}

	public function parseSOA() {
		if ($this->getType() != 'SOA') { return FALSE; }

		$bits = explode(' ', $this->getContent());
		$result = array();

		$result['primaryNS'] = idn_to_utf8($bits[0]);
		$result['adminAddress'] = idn_to_utf8($bits[1]);
		$result['serial'] = $bits[2];
		$result['refresh'] = $bits[3];
		$result['retry'] = $bits[4];
		$result['expire'] = $bits[5];
		$result['minttl'] = $bits[6];

		return $result;
	}

	public function postLoad() {
		$type = $this->getType();
		$content = $this->getContent();
		if ($type == 'MX' || $type == 'CNAME' || $type == 'PTR' || $type == 'NS') {
			$this->setContent(idn_to_utf8($content));
		}
	}

	public function preSave() {
		$type = $this->getType();
		$content = $this->getContent();
		if ($type == 'MX' || $type == 'CNAME' || $type == 'PTR' || $type == 'NS') {
			$this->setContent(idn_to_ascii($content));
		}
	}

	public function updateSOAContent($parsed) {
		if ($this->getType() != 'SOA') { return FALSE; }

		$content = sprintf('%s %s %s %s %s %s %s', idn_to_ascii($parsed['primaryNS']), idn_to_ascii($parsed['adminAddress']), $parsed['serial'], $parsed['refresh'], $parsed['retry'], $parsed['expire'], $parsed['minttl']);

		$this->setContent($content);
	}

	public function validate() {
		$type = $this->getType();
		$content = $this->getContent();

		$testName = $this->getName();
		$testName = preg_replace('#^\*\.#', 'WILDCARD.', $testName);

		if (!empty($testName) && !Domain::validDomainName()) {
			throw new ValidationFailed('Invalid name: ' . $this->getName());
		}

		if (!in_array($type, Record::$VALID_RRs)) {
			throw new ValidationFailed('Unknown record type: '. $type);
		}

		if ($type == 'SOA') {
			if (preg_match('#^[^\s]+ [^\s]+ [0-9]+ [0-9]+ [0-9]+ [0-9]+ [0-9]+$#', $content, $m)) {
				$soa = $this->parseSOA();

				$testAddress = substr($soa['primaryNS'], 0, -1);
				if (!Domain::validDomainName($testAddress) || substr($soa['primaryNS'], -1) != '.') {
					throw new ValidationFailed('Primary Nameserver in SOA (' . $soa['primaryNS'] . ') does not look valid.');
				}

				$testAddress = substr($soa['adminAddress'], 0, -1);
				if (!Domain::validDomainName($testAddress) || substr($soa['adminAddress'], -1) != '.') {
					throw new ValidationFailed('Admin address in SOA (' . $soa['adminAddress'] . ') does not look valid.');
				}
			} else {
				throw new ValidationFailed('SOA is invalid.');
			}
		}

		if ($type == 'MX' || $type == 'SRV') {
			if ($this->getPriority() === NULL || $this->getPriority() === '') {
				throw new ValidationFailed('Records of '. $type . ' require a priority.');
			} else if (!preg_match('#^[0-9]+$#', $this->getPriority())) {
				throw new ValidationFailed('Priority must be numeric.');
			}
		} else if (empty($this->getPriority())) {
			$this->setPriority(NULL);
		} else if ($this->getPriority() !== NULL) {
			throw new ValidationFailed('Priority should not be set for records of type: ' . $type);
		}

		if (empty($this->getTTL())) {
			$this->setTTL(NULL);
		} else if (!preg_match('#^[0-9]+$#', $this->getTTL())) {
			throw new ValidationFailed('TTL must be numeric.');
		}

		if ($type == 'A' && filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === FALSE) {
			throw new ValidationFailed('Content must be a valid IPv4 Address.');
		}

		if ($type == 'AAAA' && filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === FALSE) {
			throw new ValidationFailed('Content must be a valid IPv4 Address.');
		}

		if ($type == 'MX' || $type == 'CNAME' || $type == 'PTR' || $type == 'NS') {
			$testName = $content;
			if (substr($testName, -1) == '.') {
				$testName = substr($testName, 0, -1);
			}

			if (filter_var($testName, FILTER_VALIDATE_IP) !== FALSE) {
				throw new ValidationFailed('Content must be a name not an IP.');
			} else if (!Domain::validDomainName($testName)) {
				throw new ValidationFailed('Content must be a valid name.');
			} else if ($testName != $content) {
				$this->setContent($testName);
			}
		}

		if ($type == 'SRV') {
			if (preg_match('#^([0-9]+ [0-9]+) ([^\s]+)$#', $content, $m)) {
				if (filter_var($m[2], FILTER_VALIDATE_IP) !== FALSE) {
					throw new ValidationFailed('Target must be a name not an IP.');
				}

				if ($m[2] != ".") {
					$testName = $m[2];
					if (substr($testName, -1) == '.') {
						$testName = substr($testName, 0, -1);
					}

					if (!Domain::validDomainName($testName)) {
						throw new ValidationFailed('Target must be a valid name.');
					} else {
						$this->setContent($m[1] . ' ' . $testName);
					}
				}
			} else {
				throw new ValidationFailed('SRV Record content should have the format: <weight> <port> <target>');
			}
		}

		if ($type == 'CAA') {
			if (!preg_match('#^[0-9]+ [a-z]+ "[^\s]+"$#i', $content, $m)) {
				throw new ValidationFailed('CAA Record content should have the format: <flag> <tag> "<value>"');
			}
		}

		if ($type == 'SSHFP') {
			if (!preg_match('#^[0-9]+ [0-9]+ [0-9A-F]+$#i', $content, $m)) {
				throw new ValidationFailed('SSHFP Record content should have the format: <algorithm> <fingerprint type> <fingerprint>');
			}
		}

		if ($type == 'TLSA') {
			if (!preg_match('#^[0-9]+ [0-9]+ [0-9]+ [0-9A-F]+$#i', $content, $m)) {
				throw new ValidationFailed('TLSA Record content should have the format: <usage> <selector> <matching type> <fingerprint>');
			}
		}

		if ($type == 'DS') {
			if (!preg_match('#^[0-9]+ [0-9]+ [0-9]+ [0-9A-F]+$#i', $content, $m)) {
				throw new ValidationFailed('DS Record content should have the format: <keytag> <algorithm> <digesttype> <digest>');
			}
		}

		return TRUE;
	}
}
