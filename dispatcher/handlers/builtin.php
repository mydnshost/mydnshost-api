<?php
	use shanemcc\phpdb\DB;

	EventQueue::get()->subscribe('mail.send', function($to, $subject, $message, $htmlmessage = NULL) {
		dispatchJob(createJob('sendmail', ['to' => $to, 'subject' => $subject, 'message' => $message, 'htmlmessage' => $htmlmessage]));
	});

	EventQueue::get()->subscribe('2fa.push.verify', function($keyid, $message) {
		$key = TwoFactorKey::load(DB::get(), $keyid);

		dispatchJob(createJob('verify_2fa_push', ['keyid' => $key->getID(), 'userid' => $key->getUserID(), 'message' => $message]));
	});

