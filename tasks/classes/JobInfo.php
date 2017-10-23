<?php

	class JobInfo {
		private $jobid = 0;
		private $function = '';
		private $payload = '';
		private $result = '';
		private $hasResult = false;
		private $errorMessage = 'Unknown Error.';
		private $hasError = false;

		public function __construct($jobid, $function, $payload = '') {
			$this->jobid = $jobid;
			$this->function = $function;
			$this->payload = $payload;
		}

		public function getJobID() {
			return $this->jobid;
		}

		public function getFunction() {
			return $this->function;
		}

		public function getPayload() {
			return $this->payload;
		}

		public function setResult($result = '') {
			$this->result = $result;
			$this->hasResult = true;
		}

		public function getResult() {
			return $this->result;
		}

		public function hasResult() {
			return $this->hasResult;
		}

		public function setError($message = '') {
			$this->errorMessage = $message;
			$this->hasError = true;
		}

		public function getError() {
			return $this->errorMessage;
		}

		public function hasError() {
			return $this->hasError;
		}
	}
