<?php

	class Mongo {
		// Instance of Mongo.
		private static $instance = null;

		private $client;
		private $config;

		/**
		 * Get the Mongo instance.
		 *
		 * @return Mongo instance.
		 */
		public static function get() {
			if (self::$instance == null) {
				self::$instance = new Mongo();
			}

			return self::$instance;
		}

		public function setMongoConfig($config) {
			$this->config = $config;
		}

		public function connect() {
			$this->client = new \MongoDB\Client('mongodb://' . $this->config['server'], [], ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]);
		}

		public function getMongo() {
			if ($this->client == null) {
				$this->connect();
			}

			return $this->client;
		}

		public function getMongoDB() {
			return $this->getMongo()->selectDatabase($this->config['database']);
		}

		public function getCollection($collection) {
			return $this->getMongoDB()->selectCollection($collection);
		}
	}
