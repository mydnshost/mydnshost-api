<?php
	use shanemcc\phpdb\DB;

	EventQueue::get()->subscribe('mail.send', function($to, $subject, $message, $htmlmessage = NULL) {
		dispatchJob('sendmail', json_encode(['to' => $to, 'subject' => $subject, 'message' => $message, 'htmlmessage' => $htmlmessage]));
	});

	EventQueue::get()->subscribe('2fa.push.verify', function($keyid, $message) {
		$key = TwoFactorKey::load(DB::get(), $keyid);

		dispatchJob('verify_2fa_push', json_encode(['keyid' => $key->getID(), 'userid' => $key->getUserID(), 'message' => $message]));
	});

