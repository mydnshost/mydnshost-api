<?php

use shanemcc\phpdb\DBObject;
use shanemcc\phpdb\ValidationFailed;

class DomainHook extends DBObject {
	protected static $_fields = ['id' => NULL,
	                             'domain_id' => NULL,
	                             'url' => NULL,
	                             'password' => NULL,
	                             'disabled' => false,
	                             'created' => 0,
	                             'lastused' => 0,
	                            ];
	protected static $_key = 'id';
	protected static $_table = 'domainhooks';

	public function __construct($db) {
		parent::__construct($db);
	}

	public function setUrl($value) {
		return $this->setData('url', $value);
	}

	public function setPassword($value) {
		return $this->setData('password', $value);
	}

	public function setDomainID($value) {
		return $this->setData('domain_id', $value);
	}

	public function setDisabled($value) {
		return $this->setData('disabled', parseBool($value) ? 'true' : 'false');
	}

	public function setLastUsed($value) {
		return $this->setData('lastused', $value);
	}

	public function setCreated($value) {
		return $this->setData('created', $value);
	}

	public function getID() {
		return $this->getData('id');
	}

	public function getUrl() {
		return $this->getData('url');
	}

	public function getPassword() {
		return $this->getData('password');
	}

	public function getDomainID() {
		return $this->getData('domain_id');
	}

	public function getDisabled() {
		return parseBool($this->getData('disabled'));
	}

	public function getLastUsed() {
		return $this->getData('lastused');
	}

	public function getCreated() {
		return $this->getData('created');
	}

	public function getDomain() {
		 return Domain::load($this->getDB(), $this->getDomainID());
	}

	/**
	 * Load an object from the database based on domain_id AND the id.
	 *
	 * @param $db Database object to load from.
	 * @param $domain domain id to look for
	 * @param $id id to look for.
	 * @return FALSE if no object exists, else the object.
	 */
	public static function loadFromDomainHookID($db, $domain, $id) {
		$result = static::find($db, ['domain_id' => $domain, 'id' => $id]);
		if ($result) {
			return $result[0];
		} else {
			return FALSE;
		}
	}

	/**
	 * Load hooks from the database based on domain_id.
	 *
	 * @param $db Database object to load from.
	 * @param $domain domain id to look for
	 * @return FALSE if no object exists, else array of objects.
	 */
	public static function loadFromDomainID($db, $domain) {
		$result = static::find($db, ['domain_id' => $domain]);
		if ($result) {
			return $result;
		} else {
			return FALSE;
		}
	}

	public function validate() {
		$required = ['url', 'domain_id'];
		foreach ($required as $r) {
			if (!$this->hasData($r)) {
				throw new ValidationFailed('Missing required field: '. $r);
			}
		}

		if (filter_var($this->getUrl(), FILTER_VALIDATE_URL, FILTER_FLAG_SCHEME_REQUIRED | FILTER_FLAG_HOST_REQUIRED) === FALSE) {
			throw new ValidationFailed('URL must be a valid url.');
		}

		return TRUE;
	}

	public function call($data) {
		if ($this->getDisabled()) { return; }
		$domain = Domain::load($this->getDB(), $this->getDomainID());

		$headers = [];
		$headers[] = 'Content-type: application/json';
		$content = json_encode($data);
		if (trim($this->getPassword()) != "") {
			$algo = 'sha1';
			$headers[] = 'X-HMAC-SIGNATURE: ' . $algo . '=' . hash_hmac($algo, $content, trim($this->getPassword()));
		}

		$opts = array('http' => array('method'  => 'POST',
		                              'header'  => implode("\r\n", $headers),
		                              'content' => $content,
		                             )
		             );
		$context  = stream_context_create($opts);
		$result = file_get_contents($this->getUrl(), false, $context);

		$this->setLastUsed(time())->save();

		return $result;
	}
}
