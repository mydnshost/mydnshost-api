<?php

use shanemcc\phpdb\DBObject;
use shanemcc\phpdb\ValidationFailed;

class Article extends DBObject {
	protected static $_fields = ['id' => NULL,
	                             'title' => '',
	                             'content' => '',
	                             'contentfull' => NULL,
	                             'created' => 0,
	                             'visiblefrom' => 0,
	                             'visibleuntil' => 0
	                            ];
	protected static $_key = 'id';
	protected static $_table = 'articles';

	public function __construct($db) {
		parent::__construct($db);
	}

	public function setTitle($value) {
		return $this->setData('title', $value);
	}

	public function setContent($value) {
		return $this->setData('content', $value);
	}

	public function setContentFull($value) {
		return $this->setData('contentfull', $value);
	}

	public function setCreated($value) {
		return $this->setData('created', $value);
	}

	public function setVisibleFrom($value) {
		return $this->setData('visiblefrom', $value);
	}

	public function setVisibleUntil($value) {
		return $this->setData('visibleuntil', $value);
	}

	public function getID() {
		return intval($this->getData('id'));
	}

	public function getTitle() {
		return $this->getData('title');
	}

	public function getContent() {
		return $this->getData('content');
	}

	public function getContentFull() {
		return $this->getData('contentfull');
	}

	public function getCreated() {
		return intval($this->getData('created'));
	}

	public function getVisibleFrom() {
		return intval($this->getData('visiblefrom'));
	}

	public function getVisibleUntil() {
		return intval($this->getData('visibleuntil'));
	}

	public function isVisible() {
		$from = $this->getVisibleFrom();
		$until = $this->getVisibleUntil();

		return time() >= $from && (time() <= $until || $until < 0);
	}

	public function validate() {
		$required = ['title', 'content', 'created', 'visiblefrom', 'visibleuntil'];
		foreach ($required as $r) {
			if (!$this->hasData($r)) {
				throw new ValidationFailed('Missing required field: '. $r);
			}
		}

		return TRUE;
	}

	public function toArray() {
		$result = parent::toArray();
		foreach (['id', 'created', 'visiblefrom', 'visibleuntil'] as $k) { if (!isset($result[$k])) { continue; }; $v = $this->getData($k); $result[$k] = ($v == null) ? $v : intval($v); }
		return $result;
	}
}
