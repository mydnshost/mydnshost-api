<?php
	require_once(dirname(__FILE__) . '/../functions.php');

	class DBChange {
		private $query = '';
		private $result = null;

		public function __construct($query) {
			$this->query = $query;
		}

		public function run($pdo) {
			if ($pdo->exec($this->query) !== FALSE) {
				$this->result = TRUE;
			} else {
				$ei = $pdo->errorInfo();
				$this->result = $ei[2];
			}

			return $this->getLastResult();
		}

		public function getLastResult() {
			return ($this->result === TRUE);
		}

		public function getLastError() {
			return ($this->result === TRUE) ? NULL : $this->result;
		}
	}

	function initDataServer($db) {
		global $dataChanges;
		return runChanges($db, $dataChanges, 'dataVersion');
	}

	function runChanges($db, $changes, $versionField) {
		$currentVersion = (int)$db->getMetaData($versionField, 0);

		echo 'Current Version: ', $currentVersion, "\n";

		foreach ($changes as $version => $change) {
			if ($version <= $currentVersion) { continue; }
			if ($change->run($db->getPDO())) {
				echo 'Updated to version ', $version, ' success', "\n";
				$db->setMetaData($versionField, $version);
				$currentVersion = $version;
			} else {
				echo 'Error updating to version ', $version, ': ', $change->getLastError(), "\n";
				return $currentVersion;
			}
		}

		return $currentVersion;
	}



	// -------------------------------------------------------------------------
	// Meta Changes
	// -------------------------------------------------------------------------
	$dataChanges = array();

	// -------------------------------------------------------------------------
	// Create metadata table in DB.
	// -------------------------------------------------------------------------
	$dataChanges[1] = new DBChange(<<<MYSQLQUERY
CREATE TABLE IF NOT EXISTS `__MetaData` (
  `key` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `value` varchar(255) COLLATE utf8_unicode_ci NOT NULL
,  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
MYSQLQUERY
);

  // ------------------------------------------------------------------------
  // Initial Schema
  // ------------------------------------------------------------------------
	$dataChanges[2] = new DBChange(<<<MYSQLQUERY
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(250) NOT NULL,
  `password` VARCHAR(250) NOT NULL,
  `realname` VARCHAR(250) NOT NULL,
  `admin` ENUM('false', 'true') NOT NULL DEFAULT 'false',
  PRIMARY KEY (`id`),
  UNIQUE INDEX `users_email_unique` (`email` ASC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


CREATE TABLE `domains` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `domain` varchar(255) NOT NULL,
  `disabled` ENUM('false', 'true') NOT NULL DEFAULT 'false',
  PRIMARY KEY (`id`),
  UNIQUE KEY `domain_domain_unique` (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


CREATE TABLE `records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `domain_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `type` varchar(10) NOT NULL,
  `content` varchar(8192) NOT NULL,
  `ttl` int(11) NOT NULL,
  `priority` int(11),
  `changed_at` int(11) NOT NULL,
  `changed_by` int(11),
  `disabled` ENUM('false', 'true') NOT NULL DEFAULT 'false',
  `synced` ENUM('false', 'true') NOT NULL DEFAULT 'false',
  PRIMARY KEY (`id`),
  CONSTRAINT `records_domain_id` FOREIGN KEY (`domain_id`) REFERENCES `domains` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `records_changed_by` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


CREATE TABLE `domain_access` (
  `user_id` int(11) NOT NULL,
  `domain_id` int(11) NOT NULL,
  `level` ENUM('read', 'write', 'admin', 'owner') NOT NULL DEFAULT 'read',
  PRIMARY KEY (`user_id`,`domain_id`),
  CONSTRAINT `domain_access_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `domain_access_domain_id` FOREIGN KEY (`domain_id`) REFERENCES `domains` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
MYSQLQUERY
);
