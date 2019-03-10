<?php
	use shanemcc\phpdb\DB;

	/**
	 * Task to verify 2FA Keys.
	 *
	 * Payload should be a json string with 'keyid', 'userid' and 'message' fields.
	 */
	class verify_2fa_push extends TaskWorker {
		public function run($job) {
			$payload = $job->getPayload();

			if (isset($payload['keyid']) && isset($payload['userid']) && isset($payload['message'])) {

				$key = TwoFactorKey::loadFromUserKey(DB::get(), $payload['userid'], $payload['keyid']);

				if ($key != FALSE && $key->isPush()) {
					if ($key->pushVerify($message)) {
						$key->setActive(true);
						if (!$key->isOneTime()) { $key->setLastUsed(time()); }
						$key->save();

						$job->setResult('OK');
					} else {
						$job->setError('Push not approved.');
					}
				} else {
					$job->setError('Key not found');
				}


			} else {
				$job->setError('Missing fields in payload.');
			}
		}
	}
