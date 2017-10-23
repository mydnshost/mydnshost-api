<?php
	require_once(dirname(__FILE__) . '/bind_add_domain.php');

	/**
	 * Task to change records in a zone.
	 * This does the same as adding a new zone.
	 *
	 * Payload should be a json string with 'domain' field.
	 */
	class bind_records_changed extends bind_add_domain { }
