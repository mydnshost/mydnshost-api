<?php

	class Mailer {
		private $config;
		private static $instance = null;

		public static function get() {
			if (self::$instance == null) {
				self::$instance = new Mailer();
			}

			return self::$instance;
		}

		public function __construct() { }

		public function setConfig($config) {
			$this->config = $config;

			return $this;
		}

		public function send($to, $subject, $message, $htmlmessage = NULL) {
			if (!$this->config['enabled']) { return; }

			$mail = new PHPMailer();
			$mail->SMTPDebug = 3;

			if (!empty($this->config['server'])) {
				$mail->isSMTP();
				$mail->Host = $this->config['server'];

				if (!empty($this->config['username']) || !empty($this->config['password'])) {
					$mail->SMTPAuth = true;

					$mail->Username = $this->config['username'];
					$mail->Password = $this->config['password'];
				}
			}

			if (empty($this->config['from_name'])) {
				$mail->setFrom($this->config['from']);
			} else {
				$mail->setFrom($this->config['from'], $this->config['from_name']);
			}

			$mail->addAddress($to);

			$mail->Subject = $subject;

			if ($htmlmessage === NULL) {
				$mail->Body = $message;
			} else {
				$mail->isHTML(true);
				$mail->Body = $htmlmessage;
				$mail->AltBody = $message;
			}

			$result = $mail->send();

			if (!$result) {
				echo 'Mailer Error: ' . $mail->ErrorInfo;
				die();
			}
		}
	}
