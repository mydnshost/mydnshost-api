<?php

	use PhpAmqpLib\Connection\AMQPStreamConnection;
	use PhpAmqpLib\Message\AMQPMessage;

	class EventQueue {
		// Instance of EventQueue.
		private static $instance = null;

		private $rabbitmq;
		private $connection;
		private $channel;
		private $myqueue;

		private $subscribers = [];

		/**
		 * Get the EventQueue instance.
		 *
		 * @return EventQueue instance.
		 */
		public static function get() {
			if (self::$instance == null) {
				self::$instance = new EventQueue();
			}

			return self::$instance;
		}

		public function setRabbitMQ($rabbitmq) {
			$this->rabbitmq = $rabbitmq;
			$this->connect();
		}

		private function connect() {
			$this->connection = new AMQPStreamConnection($this->rabbitmq['host'], $this->rabbitmq['port'], $this->rabbitmq['user'], $this->rabbitmq['pass']);
			$this->channel = $this->connection->channel();

			list($this->myqueue, ,) = $this->channel->queue_declare("", false, false, true, false);
		}

		/**
		 * Publish an event to the bus.
		 *
		 * @param $event Event name
		 * @param $args Event Arguments
		 */
		public function publish($event, $args) {
			$this->channel->exchange_declare('events', 'fanout', false, false, false);

			$event = strtolower($event);
			$msg = new AMQPMessage(json_encode(['event' => $event, 'args' => $args]));
			$this->channel->basic_publish($msg, 'events');
		}


		/**
		 * Subscribe to events of a certain type on the bus.
		 *
		 * @param $event Event name
		 * @param $function Function to call
		 */
		public function subscribe($event, $function) {
			$event = strtolower($event);

			if (!array_key_exists($event, $this->subscribers)) { $this->subscribers[$event] = []; }

			$this->subscribers[$event][] = $function;
		}

		/**
		 * Begin consuming events from the bus.
		 */
		public function consumeEvents() {
			$this->channel->exchange_declare('events', 'fanout', false, false, false);
			$this->channel->queue_bind($this->myqueue, 'events');

			$this->channel->basic_consume($this->myqueue, '', false, true, false, false, function($msg) {
				$event = json_decode($msg->body, true);


				if (array_key_exists($event['event'], $this->subscribers)) {
					foreach ($this->subscribers[$event['event']] as $callable) {
						try {
							call_user_func_array($callable, $event['args']);
						} catch (Exception $ex) {
							// TODO: Handle this somewhere?
						}
					}
				}
			});

			while ($this->channel->is_consuming()) {
				$this->channel->wait();
			}

			$this->channel->close();
			$this->connection->close();
		}
	}
