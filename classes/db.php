<?php

class DB {
	private static $instance = null;

	private $pdo = null;

	public static function get() {
		if (self::$instance == null) {
			self::$instance = new DB();
		}

		return self::$instance;
	}

	public function setPDO($pdo) {
		$this->pdo = $pdo;
	}

	public function getPDO() {
		return $this->pdo;
	}

	public function getMetaData($key, $default = FALSE) {
		$query = "SELECT `value` FROM `__MetaData` WHERE `key` = :key";
		$params = array(":key" => $key);

		$statement = $this->pdo->prepare($query);
		$statement->execute($params);
		$result = $statement->fetch(PDO::FETCH_ASSOC);

		if ($result) {
			return $result['value'];
		} else {
			return $default;
		}
	}

	public function setMetaData($key, $value) {
		$query = "INSERT INTO `__MetaData` (`key`, `value`) VALUES (:key, :value) ON DUPLICATE KEY UPDATE `value` = :value";
		$params = array(":key" => $key, ":value" => $value);

		$statement = $this->pdo->prepare($query);
		$result = $statement->execute($params);
		return $result;
	}

	public function getUserCount() {
		$query = "SELECT count(id) AS `count` FROM `users`";
		$statement = $this->pdo->prepare($query);
		$statement->execute();
		$result = $statement->fetch(PDO::FETCH_ASSOC);

		return !isset($result['count']) ? 0 : $result['count'];
	}

	public function getLastError() {
		return $this->pdo->errorInfo();
	}
}
