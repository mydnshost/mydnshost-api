<?php

use shanemcc\phpdb\DBObject;
use shanemcc\phpdb\ValidationFailed;

class BlockRegex extends DBObject {
	protected static $_fields = ['id' => NULL,
	                             'comment' => NULL,
	                             'regex' => NULL,
	                             'created' => 0,
	                             'signup_name' => false,
	                             'signup_email' => false,
	                             'domain_name' => false,
	                            ];
	protected static $_key = 'id';
	protected static $_table = 'blockregexes';

	public function __construct($db) {
		parent::__construct($db);
	}

	public function setComment($value) {
		return $this->setData('comment', $value);
	}

	public function setRegex($value) {
		return $this->setData('regex', $value);
	}

	public function setCreated($value) {
		return $this->setData('created', $value);
	}

	public function setSignupName($value) {
		return $this->setData('signup_name', parseBool($value) ? 'true' : 'false');
	}

	public function setSignupEmail($value) {
		return $this->setData('signup_email', parseBool($value) ? 'true' : 'false');
	}

	public function setDomainName($value) {
		return $this->setData('domain_name', parseBool($value) ? 'true' : 'false');
	}

	public function getID() {
		return intvalOrNull($this->getData('id'));
	}

	public function getComment() {
		return $this->getData('comment');
	}

	public function getRegex() {
		return $this->getData('regex');
	}

	public function getCreated() {
		return intvalOrNull($this->getData('created'));
	}

	public function isSignupName() {
		return parseBool($this->getData('signup_name'));
	}

	public function isSignupEmail() {
		return parseBool($this->getData('signup_email'));
	}

	public function isDomainName() {
		return parseBool($this->getData('domain_name'));
	}

	public function validate() {
		$required = ['regex', 'created'];
		foreach ($required as $r) {
			if (!$this->hasData($r)) {
				throw new ValidationFailed('Missing required field: '. $r);
			}
		}

		return TRUE;
	}

	public function toArray() {
		$result = parent::toArray();
		foreach (['signup_email', 'signup_name', 'domain_name'] as $k) { if (!isset($result[$k])) { continue; }; $result[$k] = parseBool($this->getData($k)); }
		foreach (['id', 'created'] as $k) { if (!isset($result[$k])) { continue; }; $result[$k] = intvalOrNull($this->getData($k)); }
		return $result;
	}
}
