<?php

	use PhpAmqpLib\Connection\AMQPStreamConnection;
	use PhpAmqpLib\Message\AMQPMessage;

	class RabbitMQ {
		// Instance of RabbitMQ.
		private static $instance = null;

		private $rabbitmq;
		private $connection;
		private $channel;
		private $myqueue;

		private $subscribers = [];

		/**
		 * Get the RabbitMQ instance.
		 *
		 * @return RabbitMQ instance.
		 */
		public static function get() {
			if (self::$instance == null) {
				self::$instance = new RabbitMQ();
			}

			return self::$instance;
		}

		public function setRabbitMQ($rabbitmq) {
			$this->rabbitmq = $rabbitmq;
		}

		private function connect() {
			if ($this->connection !== null) { return; }

			$this->connection = new AMQPStreamConnection($this->rabbitmq['host'], $this->rabbitmq['port'], $this->rabbitmq['user'], $this->rabbitmq['pass']);
			$this->channel = $this->connection->channel();

			list($this->myqueue, ,) = $this->channel->queue_declare("", false, false, true, false);
		}

		/**
		 * Start consuming from Rabbit MQ..
		 */
		public function stopConsume() {
			foreach (array_keys($this->channel->callbacks) as $key) {
				$this->channel->basic_cancel($key);
			}
		}

		/**
		 * Start consuming from Rabbit MQ..
		 */
		public function consume() {
			$this->connect();

			while ($this->channel->is_consuming()) {
				$this->channel->wait();
			}

			$this->channel->close();
			$this->connection->close();
		}

		/**
		 * Get RabbitMQ Channel
		 */
		public function getChannel() {
			$this->connect();

			return $this->channel;
		}

		/**
		 * Get RabbitMQ Channel
		 */
		public function getQueue() {
			$this->connect();

			return $this->myqueue;
		}

	}
