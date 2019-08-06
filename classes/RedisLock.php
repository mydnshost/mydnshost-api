<?php

	public abstract class RedisLock {
		private static $redisHost;
		private static $redisPort;
		private static $redis;
		private static $locks;

		public static function setRedisHost($redisHost, $redisPort) {
			static::$redisHost = $redisHost;
			static::$redisPort = $redisPort;
			static::$redis = new Redis();

			static::$locks = [];
		}

		public static function acquireLock($name, $wait = true, $timeout = 30) {
			echo 'acquireLock(', $name, ');', "\n";

			if (isset(static::$locks[$name])) {
				echo 'Lock for ', $name , ' already acquired: ', static::$locks[$name], "\n";

				return TRUE;
			}

			$code = genUUID();

			$count = 0;

			// Would prefer if this let me block until available rather than
			// sleeping and asking it lots.
			do {
				try {
					// Try and connect if we're not connected.
					if (!static::$redis->isConnected()) {
						if (empty(static::$redisPort)) {
							static::$redis->connect(static::$redisHost);
						} else {
							static::$redis->connect(static::$redisHost, static::$redisPort);
						}
					}

					if (static::$redis->set('lock_' . $name, $code, ['nx', 'px' => $timeout * 1000])) {
						static::$locks[$name] = $code;
						echo 'Lock for ', $name , ' acquired: ', $code, "\n";

						return TRUE;
					} else if (!$wait) {
						echo 'Lock for ', $name , ' failed [Lock in use].', "\n";

						return FALSE;
					}

					if ($count == 0) {
						echo 'Waiting for lock for ', $name , ' [Lock in use].', "\n";
					}
					$count = ($count + 1) % 100;

					usleep(10000);
				} catch (Exception $ex) {
					if ($wait) {
						echo 'Waiting for lock for ', $name , ' [Redis has gone away].', "\n";
						sleep(1);
					} else {
						echo 'Lock for ', $name , ' failed [Redis has gone away].', "\n";
						return FALSE;
					}
				}
			} while ($wait);
		}

		public static function releaseLock($name) {
			echo 'releaseLock(', $name, ');', "\n";

			if (!isset(static::$locks[$name])) {
				echo 'Lock for ', $name , ' not acquired.', "\n";
				return FALSE;
			}

			$code = static::$locks[$name];
			unset(static::$locks[$name]);

			try {
				$currentLock = static::$redis->get('lock_' . $name);
				if ($currentLock == $code) {
					static::$redis->del('lock_' . $name);

					echo 'Lock for ', $name , ' released: ', $code, "\n";

					return TRUE;
				} else {
					echo 'Lock for ', $name , ' was lost. (', $currentLock, ' is not me. I am: ', $code, ')', "\n";

					return FALSE;
				}
			} catch (Exception $ex) {
				echo 'Lock for ', $name , ' was lost. (Redis has gone away)', "\n";

				return FALSE;
			}
		}

		public static function lockIsValid($name) {
			if (!isset(static::$locks[$name])) {
				echo 'lockIsValid(', $name, ') == FALSE (Not Locked.)', "\n";

				return FALSE;
			}

			try {
				$result = (static::$redis->get('lock_' . $name) == static::$locks[$name]);
				echo 'lockIsValid(', $name, ') == ', ($result ? 'TRUE' : 'FALSE'), "\n";

				return $result;
			} catch (Exception $ex) {
				echo 'lockIsValid(', $name, ') == FALSE (Redis has gone away.)', "\n";

				return FALSE;
			}
		}
	}
