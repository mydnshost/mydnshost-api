<?php
	/**
	 * Task to send email.
	 *
	 * Payload should be a json string with 'to', 'subject' and 'message' fields.
	 */
	class sendmail extends TaskWorker {
		public function run($job) {
			$payload = @json_decode($job->getPayload(), true);

			if (isset($payload['to']) && isset($payload['subject']) && isset($payload['message'])) {
				Mailer::get()->send($payload['to'], $payload['subject'], $payload['message'], isset($payload['htmlmessage']) ? $payload['htmlmessage'] : null);

				$job->setResult('OK');
			} else {
				$job->setError('Missing fields in payload.');
			}
		}
	}
