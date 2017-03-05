<?php
	// We only output json.
	header('Content-Type: application/json');
	die(json_encode(array('error' => 'Invalid API Version.')));
?>
