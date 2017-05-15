<?php

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

	public function __construct($db) {
		parent::__construct($db);
	}

	public function setDomainID($value) {
		return $this->setData('domain_id', $value);
	}

	public function setName($value) {
		return $this->setData('name', $value);
	}

	public function setType($value) {
		return $this->setData('type', $value);
	}

	public function setContent($value) {
		return $this->setData('content', $value);
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

		$result['primaryNS'] = $bits[0];
		$result['adminAddress'] = $bits[1];
		$result['serial'] = $bits[2];
		$result['refresh'] = $bits[3];
		$result['retry'] = $bits[4];
		$result['expire'] = $bits[5];
		$result['minttl'] = $bits[6];

		return $result;
	}

	public function updateSOAContent($parsed) {
		if ($this->getType() != 'SOA') { return FALSE; }

		$content = sprintf('%s %s %s %s %s %s %s', $parsed['primaryNS'], $parsed['adminAddress'], $parsed['serial'], $parsed['refresh'], $parsed['retry'], $parsed['expire'], $parsed['minttl']);

		$this->setContent($content);
	}

	public function validate() {
		$type = $this->getType();
		$content = $this->getContent();

		if (!preg_match('#^[a-z0-9-._*]*$#i', $this->getName())) {
			throw new ValidationFailed('Invalid name: ' . $this->getName());
		}

		if (!in_array($type, ['A', 'AAAA', 'TXT', 'SRV', 'SOA', 'MX', 'TXT', 'PTR', 'CNAME', 'NS', 'CAA'])) {
			throw new ValidationFailed('Unknown record type: '. $type);
		}

		if ($type == 'SOA') {
			if (preg_match('#^[^\s]+ [^\s]+ [0-9]+ [0-9]+ [0-9]+ [0-9]+ [0-9]+$#', $content, $m)) {
				$soa = $this->parseSOA();
				if (!preg_match('#^[a-z0-9-._]+\.$#i', $soa['primaryNS'])) {
					throw new ValidationFailed('Primary Nameserver in SOA (' . $soa['primaryNS'] . ') does not look valid.');
				}

				if (!preg_match('#^[a-z0-9-._]+\.[a-z0-9-._]+\.[a-z]+\.$#i', $soa['adminAddress'])) {
					throw new ValidationFailed('Admin address in SOA does not look valid.');
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
			if (filter_var($content, FILTER_VALIDATE_IP) !== FALSE) {
				throw new ValidationFailed('Content must be a name not an IP.');
			} else if (!preg_match('#^[a-z0-9-._]+$#i', $content)) {
				throw new ValidationFailed('Content must be a valid name.');
			}
		}

		if ($type == 'SRV') {
			if (preg_match('#^[0-9]+ [0-9]+ ([^\s]+)$#', $content, $m)) {
				if (filter_var($m[1], FILTER_VALIDATE_IP) !== FALSE) {
					throw new ValidationFailed('Target must be a name not an IP.');
				}
			} else {
				throw new ValidationFailed('SRV Record content should have the format: <weight> <port> <target>');
			}
		}

		if ($type == 'CAA') {
			if (!preg_match('#^[0-9]+ [a-z]+ "[^\s]+"$#', $content, $m)) {
				throw new ValidationFailed('SRV Record content should have the format: <flag> <tag> "<value>"');
			}
		}

		return TRUE;
	}
}
