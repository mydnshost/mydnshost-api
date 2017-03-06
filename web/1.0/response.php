<?php

	/**
	 * Class representing a response from the API
	 */
	class api_response {
		private $reqid;
		private $respid;
		private $method;
		private $data = array();
		private $headers = array();
		private $error;
		private $errorData = array();

		private $errorCode = '400';
		private $errorDescription = 'Bad Request';

		public function __construct($reqid = '', $method = '', $data = array(), $headers = array()) {
			$this->respid = uniqid();

			$this->reqid = $reqid;
			$this->method = $method;
			$this->data = $data;
			$this->headers = $headers;
		}

		public function setErrorCode($code, $description) {
			$this->errorCode = $code;
			$this->errorDescription = $description;
		}

		public function error($errorMessage, $errorData = array()) {
			$this->error = $errorMessage;
			$this->errorData = $errorData;

			return $this;
		}

		public function set($name, $value) {
			$this->data[$name] = $value;

			return $this;
		}

		public function get($name, $fallback = null) {
			return isset($this->data[$name]) ? $this->data[$name] : $fallback;
		}

		public function has($name) {
			return isset($this->data[$name]);
		}

		public function remove($name) {
			unset($this->data[$name]);

			return $this;
		}

		public function setHeader($name, $value) {
			$this->headers[$name] = $value;

			return $this;
		}

		public function getHeader($name, $fallback = null) {
			return $this->hasHeader($name) ? $this->headers[$name] : $fallback;
		}

		public function hasHeader($name) {
			return isset($this->headers[$name]) || array_key_exists($name, $this->headers);
		}

		public function removeHeader($name) {
			unset($this->headers[$name]);

			return $this;
		}

		public function data($data) {
			if (!is_array($data)) {
				$data = array($data);
			}
			$this->data = $data;

			return $this;
		}

		public function reqid($reqid) {
			$this->reqid = $reqid;

			return $this;
		}

		public function method($method) {
			$this->method = $method;

			return $this;
		}

		public function respid($respid) {
			$this->respid = $respid;

			return $this;
		}

		public function send() {
			$data = array();
			if (!empty($this->reqid)) { $data['reqid'] = $this->reqid; }
			if (!empty($this->respid)) { $data['respid'] = $this->respid; }
			if (!empty($this->method)) { $data['method'] = $this->method; }

			if (count($this->data) > 0) {
				$data['response'] = $this->data;
			}

			if (!empty($this->error)) {
				header('HTTP/1.1 ' . $this->errorCode . ' ' . $this->errorDescription);

				$data['error'] = $this->error;
				if (count($this->errorData) > 0) {
					$data['errorData'] = $this->errorData;
				}
			}

			foreach ($this->headers as $header => $value) {
				if (!array_key_exists($header, $data)) {
					$data[$header] = $value;
				}
			}

			$response = json_encode($data);

			die($response);
		}

		public function sendError($error, $errorData = array()) {
			$this->data(array())->error($error, $errorData)->send();
		}
	}
