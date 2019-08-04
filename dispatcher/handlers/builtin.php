<?php
	use shanemcc\phpdb\DB;

	EventQueue::get()->subscribe('mail.send', function($to, $subject, $message, $htmlmessage = NULL) {
		dispatchJob('sendmail', ['to' => $to, 'subject' => $subject, 'message' => $message, 'htmlmessage' => $htmlmessage]);
	});

	EventQueue::get()->subscribe('2fa.push.verify', function($keyid, $message) {
		$key = TwoFactorKey::load(DB::get(), $keyid);

		dispatchJob('verify_2fa_push', ['keyid' => $key->getID(), 'userid' => $key->getUserID(), 'message' => $message]);
	});

