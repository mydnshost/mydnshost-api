<?php
	require_once(dirname(__FILE__) . '/../functions.php');

	use shanemcc\phpdb\DBChange;
	use shanemcc\phpdb\DBChanger;
	use shanemcc\phpdb\Search;

	function initDataServer($db) {
		return $db->runChanges(new DataServerChanges());
	}

	class DataServerChanges implements DBChanger {

		public function getVersionField() {
			return 'dataVersion';
		}

		public function getChanges() {
			// -------------------------------------------------------------------------
			// Data Changes
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
  UNIQUE INDEX `domains_domain_unique` (`domain`)
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

			// ------------------------------------------------------------------------
			// API Keys
			// ------------------------------------------------------------------------
			$dataChanges[3] = new DBChange(<<<MYSQLQUERY
CREATE TABLE `apikeys` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `apikey` VARCHAR(250) NOT NULL,
  `user_id` int(11) NOT NULL,
  `description` VARCHAR(250) NOT NULL,
  `domains_read` ENUM('false', 'true') NOT NULL DEFAULT 'false',
  `domains_write` ENUM('false', 'true') NOT NULL DEFAULT 'false',
  `user_read` ENUM('false', 'true') NOT NULL DEFAULT 'false',
  `user_write` ENUM('false', 'true') NOT NULL DEFAULT 'false',
  PRIMARY KEY (`id`),
  CONSTRAINT `apikeys_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  UNIQUE INDEX `apikeys_apikey_user` (`apikey` ASC, `user_id` ASC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
MYSQLQUERY
);

			// ------------------------------------------------------------------------
			// Suspendable Users
			// ------------------------------------------------------------------------
			$dataChanges[4] = new DBChange(<<<MYSQLQUERY
ALTER TABLE `users` ADD COLUMN `disabled` ENUM('false', 'true') NOT NULL DEFAULT 'false' AFTER `admin`;
MYSQLQUERY
);

			// ------------------------------------------------------------------------
			// Background Hooks
			// ------------------------------------------------------------------------
			$dataChanges[5] = new DBChange(<<<MYSQLQUERY
CREATE TABLE `hooks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `hook` varchar(64) NOT NULL,
  `args` varchar(8192) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
MYSQLQUERY
);

			// ------------------------------------------------------------------------
			// User Permissions - Part 1 - Add new permissions table
			// ------------------------------------------------------------------------
			$dataChanges[6] = new DBChange(<<<MYSQLQUERY
CREATE TABLE `permissions` (
  `user_id` int(11) NOT NULL,
  `permission` varchar(64) NOT NULL,
  PRIMARY KEY (`user_id`,`permission`),
  CONSTRAINT `permissions_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
MYSQLQUERY
);

			// ------------------------------------------------------------------------
			// User Permissions - Part 2 - Convert old admins
			// ------------------------------------------------------------------------
			$dataChanges[7] = new class(null) extends DBChange {
				public function run($pdo) {
					$this->result = TRUE;

					$failed = false;
					$setQuery = 'INSERT INTO permissions (`user_id`, `permission`) VALUES (:user, :permission)';
					$setStatement = $pdo->prepare($setQuery);

					$rows = (new Search($pdo, 'users', ['id', 'email']))->where('admin', 'true')->getRows();

					if (count($rows) > 0) {
						echo "\n";
						foreach ($rows as $row) {
							echo "\t", 'Setting admin permissions for: ', $row['email'], ' - ';
							foreach (['domains_stats', 'manage_domains', 'domains_create', 'manage_users', 'manage_permissions', 'impersonate_users'] as $permission) {
								echo $permission, ' ';
								$res = $setStatement->execute([':user' => $row['id'], ':permission' => $permission]);
								if (!$res) {
									$failed = true;
									$this->result = $setStatement->errorInfo()[2];
									break;
								}
							}
							if ($failed) {
								echo '- Failed.', "\n";
								break;
							} else {
								echo '- Done.', "\n";
							}
						}
					} else {
						echo 'success', "\n";
					}

					return $this->result === true;
				}
			};

			// ------------------------------------------------------------------------
			// User Permissions - Part 3 - Remove admin column
			// ------------------------------------------------------------------------
			$dataChanges[8] = new DBChange(<<<MYSQLQUERY
  ALTER TABLE `users` DROP COLUMN `admin`;
MYSQLQUERY
);

			// ------------------------------------------------------------------------
			// 2FA Keys
			// ------------------------------------------------------------------------
			$dataChanges[9] = new DBChange(<<<MYSQLQUERY
CREATE TABLE `twofactorkeys` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `key` VARCHAR(250) NOT NULL,
  `description` VARCHAR(250) NOT NULL,
  `lastused` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `twofactorkeys_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  UNIQUE INDEX `twofactorkeys_apikey_user` (`key` ASC, `user_id` ASC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
MYSQLQUERY
);

			// ------------------------------------------------------------------------
			// Improved 2FA Keys
			// ------------------------------------------------------------------------
			$dataChanges[10] = new DBChange(<<<MYSQLQUERY
ALTER TABLE `twofactorkeys`
  ADD COLUMN `created` int(11) NOT NULL AFTER `description`,
  ADD COLUMN `active` ENUM('false', 'true') NOT NULL DEFAULT 'false' AFTER `lastused`;
MYSQLQUERY
);

			// ------------------------------------------------------------------------
			// Default Record TTL
			// ------------------------------------------------------------------------
			$dataChanges[11] = new DBChange(<<<MYSQLQUERY
ALTER TABLE `domains` ADD COLUMN `defaultttl` int(11) NOT NULL DEFAULT '86400' AFTER `domain`;
MYSQLQUERY
);


			// ------------------------------------------------------------------------
			// Add times to API Keys
			// ------------------------------------------------------------------------
			$dataChanges[12] = new DBChange(<<<MYSQLQUERY
ALTER TABLE `apikeys`
  ADD COLUMN `lastused` int(11) NOT NULL AFTER `description`,
  ADD COLUMN `created` int(11) NOT NULL AFTER `description`;
MYSQLQUERY
);

			// ------------------------------------------------------------------------
			// Domain Keys
			// ------------------------------------------------------------------------
			$dataChanges[13] = new DBChange(<<<MYSQLQUERY
CREATE TABLE `domainkeys` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `domainkey` VARCHAR(250) NOT NULL,
  `domain_id` int(11) NOT NULL,
  `description` VARCHAR(250) NOT NULL,
  `lastused` int(11) NOT NULL,
  `created` int(11) NOT NULL,
  `domains_write` ENUM('false', 'true') NOT NULL DEFAULT 'false',
  PRIMARY KEY (`id`),
  CONSTRAINT `domainkeys_user_id` FOREIGN KEY (`domain_id`) REFERENCES `domains` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  UNIQUE INDEX `domainkeys_domainkey_domain` (`domainkey` ASC, `domain_id` ASC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
MYSQLQUERY
);


			// ------------------------------------------------------------------------
			// User registration
			// ------------------------------------------------------------------------
			$dataChanges[14] = new DBChange(<<<MYSQLQUERY
	ALTER TABLE `users` ADD COLUMN `verifycode` VARCHAR(64) DEFAULT NULL AFTER `password`;
	ALTER TABLE `users` ADD COLUMN `disabledreason` VARCHAR(250) DEFAULT NULL AFTER `disabled`;
MYSQLQUERY
);

			// ------------------------------------------------------------------------
			// User Custom Data
			// ------------------------------------------------------------------------
			$dataChanges[15] = new DBChange(<<<MYSQLQUERY
CREATE TABLE `usercustomdata` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `key` VARCHAR(250) NOT NULL,
  `value` TEXT,
  PRIMARY KEY (`id`),
  CONSTRAINT `usercustomdata_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  UNIQUE INDEX `usercustomdata_key_user` (`key` ASC, `user_id` ASC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
MYSQLQUERY
);

			// ------------------------------------------------------------------------
			// Domain Hooks
			// ------------------------------------------------------------------------
			$dataChanges[16] = new DBChange(<<<MYSQLQUERY
CREATE TABLE `domainhooks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `domain_id` int(11) NOT NULL,
  `url` VARCHAR(250) NOT NULL,
  `password` VARCHAR(250) NOT NULL,
  `lastused` int(11) NOT NULL,
  `created` int(11) NOT NULL,
  `disabled` ENUM('false', 'true') NOT NULL DEFAULT 'false',
  PRIMARY KEY (`id`),
  CONSTRAINT `domainhooks_domain_id` FOREIGN KEY (`domain_id`) REFERENCES `domains` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
MYSQLQUERY
);

			// ------------------------------------------------------------------------
			// 2FA Saved Devices
			// ------------------------------------------------------------------------
			$dataChanges[17] = new DBChange(<<<MYSQLQUERY
CREATE TABLE `twofactordevices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `deviceid` VARCHAR(250) NOT NULL,
  `description` VARCHAR(250) NOT NULL,
  `lastused` int(11) NOT NULL,
  `created` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `twofactordevices_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  UNIQUE INDEX `twofactordevices_deviceid_user` (`deviceid` ASC, `user_id` ASC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
MYSQLQUERY
);

			// ------------------------------------------------------------------------
			// Accept Terms
			// ------------------------------------------------------------------------
			$dataChanges[18] = new DBChange(<<<MYSQLQUERY
	ALTER TABLE `users` ADD COLUMN `acceptterms` int(11) NOT NULL DEFAULT -1 AFTER `verifycode`;
MYSQLQUERY
);


			// ------------------------------------------------------------------------
			// Zone Keys
			// ------------------------------------------------------------------------
			$dataChanges[19] = new DBChange(<<<MYSQLQUERY
CREATE TABLE `zonekeys` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `key_id` int(11) NOT NULL,
  `domain_id` int(11) NOT NULL,
  `flags` int(11) NOT NULL,
  `keyprivate` TEXT,
  `keypublic` TEXT,
  `created` int(11) NOT NULL,
  `publish` int(11) NOT NULL,
  `activate` int(11) NOT NULL,
  `revoke` int(11),
  `inactive` int(11),
  `delete` int(11),
  `syncPublish` int(11),
  `syncDelete` int(11),
  `comment` VARCHAR(250),
  PRIMARY KEY (`id`),
  CONSTRAINT `zonekeys_domain_id` FOREIGN KEY (`domain_id`) REFERENCES `domains` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  UNIQUE INDEX `zonekeys_keyid_domainid` (`domain_id` ASC, `key_id` ASC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
MYSQLQUERY
);

			// ------------------------------------------------------------------------
			// NSEC3PARAMS
			// ------------------------------------------------------------------------
			$dataChanges[20] = new DBChange(<<<MYSQLQUERY
ALTER TABLE `domains` ADD COLUMN `nsec3params` varchar(50) DEFAULT NULL AFTER `defaultttl`;
MYSQLQUERY
);

			// ------------------------------------------------------------------------
			// Additional 2FA Key Types
			// ------------------------------------------------------------------------
			$dataChanges[21] = new DBChange(<<<MYSQLQUERY
ALTER TABLE `twofactorkeys`
  ADD COLUMN `type` VARCHAR(32) NOT NULL DEFAULT 'rfc6238' AFTER `active`,
  ADD COLUMN `onetime` ENUM('false', 'true') NOT NULL DEFAULT 'false' AFTER `type`;
MYSQLQUERY
);

			// ------------------------------------------------------------------------
			// 2FA Key expiry times
			// ------------------------------------------------------------------------
			$dataChanges[22] = new DBChange(<<<MYSQLQUERY
ALTER TABLE `twofactorkeys` ADD COLUMN `expires` int(11) NOT NULL DEFAULT '0' AFTER `lastused`;
MYSQLQUERY
);

			// ------------------------------------------------------------------------
			// 2FA Key "internal" keys.
			// ------------------------------------------------------------------------
			$dataChanges[23] = new DBChange(<<<MYSQLQUERY
ALTER TABLE `twofactorkeys`
  ADD COLUMN `internal` ENUM('false', 'true') NOT NULL DEFAULT 'false' AFTER `onetime`,
  ADD COLUMN `internaldata` TEXT AFTER `internal`;
MYSQLQUERY
);

			// ------------------------------------------------------------------------
			// Articles
			// ------------------------------------------------------------------------
			$dataChanges[24] = new DBChange(<<<MYSQLQUERY
CREATE TABLE `articles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` TEXT NOT NULL,
  `content` TEXT NOT NULL,
  `contentfull` TEXT,
  `created` int(11) NOT NULL,
  `visiblefrom` int(11) NOT NULL,
  `visibleuntil` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Sun Sep 10 22:05:34 2017 +0100
INSERT INTO articles (title, content, created, visiblefrom, visibleuntil) VALUES ('2017-09-10 - Domain Statistics.', 'Domain query statistics are now available from the API and from the zone details section.', 1505077534, 1505077534, -1);

-- Wed Sep 13 00:34:56 2017 +0100
INSERT INTO articles (title, content, created, visiblefrom, visibleuntil) VALUES ('2017-09-13 - DNSSEC Support is now live.', 'Zones are automatically signed, with DS records available in the zone details section.', 1505259296, 1505259296, -1);

-- Sun Sep 24 03:12:30 2017 +0100
INSERT INTO articles (title, content, created, visiblefrom, visibleuntil) VALUES ('2017-09-24 - Domain display default page.', 'A new setting has been added to the user-profile to change the default page displayed when viewing a domain to be either the "Records" or "Zone Details" page.', 1506219150, 1506219150, -1);

-- Sun Nov 26 18:39:30 2017 +0000
INSERT INTO articles (title, content, created, visiblefrom, visibleuntil) VALUES ('2017-11-26 - Domain Hooks, 2FA Changes, CNAME Validation.', 'Support has now been added for Domain Hooks which get called whenever records on a domain are changed. There has also been some minor changes to the 2FA login flow (2FA Code is now requested on a separate page, and devices can be saved to bypass the 2FA requirement in future), and CNAME-and-other-records validation has been added.', 1511721570, 1511721570, -1);

MYSQLQUERY
);

			// ------------------------------------------------------------------------
			// Avatar options
			// ------------------------------------------------------------------------
			$dataChanges[25] = new DBChange(<<<MYSQLQUERY
	ALTER TABLE `users` ADD COLUMN `avatar` varchar(255) NOT NULL DEFAULT 'gravatar' AFTER `acceptterms`;
MYSQLQUERY
);

			// ------------------------------------------------------------------------
			// 2FA Key "push-based" keys.
			// ------------------------------------------------------------------------
			$dataChanges[26] = new DBChange(<<<MYSQLQUERY
ALTER TABLE `twofactorkeys` ADD COLUMN `push` ENUM('false', 'true') NOT NULL DEFAULT 'false' AFTER `active`;
MYSQLQUERY
);

			// ------------------------------------------------------------------------
			// 2FA Key "code-based" keys.
			// ------------------------------------------------------------------------
			$dataChanges[27] = new DBChange(<<<MYSQLQUERY
ALTER TABLE `twofactorkeys` ADD COLUMN `code` ENUM('false', 'true') NOT NULL DEFAULT 'true' AFTER `active`;
MYSQLQUERY
);

			// ------------------------------------------------------------------------
			// Domain Aliases
			// ------------------------------------------------------------------------
			$dataChanges[28] = new DBChange(<<<MYSQLQUERY
ALTER TABLE `domains`
  ADD COLUMN `aliasof` int(11) DEFAULT NULL AFTER `nsec3params`,
  ADD CONSTRAINT `domains_aliasof_domain_id` FOREIGN KEY (`aliasof`) REFERENCES `domains` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
MYSQLQUERY
);

			// ------------------------------------------------------------------------
			// Domain Aliases - Fix Constraint.
			// ------------------------------------------------------------------------
			$dataChanges[29] = new DBChange(<<<MYSQLQUERY
ALTER TABLE `domains` DROP FOREIGN KEY `domains_aliasof_domain_id`;
ALTER TABLE `domains` DROP INDEX `domains_aliasof_domain_id`;
ALTER TABLE `domains` ADD CONSTRAINT `domains_aliasof_domain_id` FOREIGN KEY (`aliasof`) REFERENCES `domains` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;
MYSQLQUERY
);


			return $dataChanges;
		}
	}
