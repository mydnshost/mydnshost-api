<?php

	use PhpAmqpLib\Connection\AMQPStreamConnection;
	use PhpAmqpLib\Message\AMQPMessage;

	class EventQueue {
		// Instance of EventQueue.
		private static $instance = null;

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

		/**
		 * Publish an event to the bus.
		 *
		 * @param $event Event name
		 * @param $args Event Arguments
		 */
		public function publish($event, $args) {
			RabbitMQ::get()->getChannel()->exchange_declare('events', 'topic', false, false, false);

			$event = strtolower($event);
			$msg = new AMQPMessage(json_encode(['event' => $event, 'args' => $args]));
			RabbitMQ::get()->getChannel()->basic_publish($msg, 'events', 'event.' . $event);
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

		public function handleSubscribers($event) {
			if (is_array($event) && isset($event['event'])) {
				if (array_key_exists($event['event'], $this->subscribers)) {
					foreach ($this->subscribers[$event['event']] as $callable) {
						try {
							call_user_func_array($callable, isset($event['args']) ? $event['args'] : []);
						} catch (Throwable $ex) {
							if ($event['event'] != 'subscriber.error') {
								EventQueue::get()->publish('subscriber.error', [$ex->getMessage(), $ex->getTraceAsString(), $event]);
							}
						}
					}
				}
			}
		}

		/**
		 * Allow consuming events from the bus.
		 *
		 * @param $function If this is given, this will be called instead of
		 *                  our own handling.
		 */
		public function consumeEvents($function = NULL, $bindingKey = '#') {
			RabbitMQ::get()->getChannel()->exchange_declare('events', 'topic', false, false, false);
			RabbitMQ::get()->getChannel()->queue_bind(RabbitMQ::get()->getQueue(), 'events', $bindingKey);

			RabbitMQ::get()->getChannel()->basic_consume(RabbitMQ::get()->getQueue(), '', false, true, false, false, function($msg) use ($function) {
				$event = @json_decode($msg->body, true);
				if (json_last_error() != JSON_ERROR_NONE) { $event = $msg->body; }

				if ($function != null) {
					call_user_func_array($function, [$event]);
				} else {
					$this->handleSubscribers($event);
				}
			});
		}
	}
