<?php

	EventQueue::get()->subscribe('send_mail', function($to, $subject, $message, $htmlmessage = NULL) use ($gmc) {
		$gmc->doBackground('sendmail', json_encode(['to' => $to, 'subject' => $subject, 'message' => $message, 'htmlmessage' => $htmlmessage]));
	});

	EventQueue::get()->subscribe('verify_2fa_push', function($key, $message) use ($gmc) {
		$gmc->doBackground('verify_2fa_push', json_encode(['keyid' => $key->getID(), 'userid' => $key->getUserID(), 'message' => $message]));
	});

