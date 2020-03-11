<?php
	// Global functions
	require_once(dirname(__FILE__) . '/../../functions.php');

	/**
	 * What access permissions does this account have?
	 * If an API Key is provided, a permission is only granted if both the
	 * user and the key allow it.
	 * (This only applies in cases where a permission can be set on a key)
	 *
	 * @param $user User to get permissions for.
	 * @param $key (Optional) API Key to limit permissions by.
	 * @param $impersonating (Optional) Are we impersonating this user?
	 * @return Array of permissions.
	 */
	function getAccessPermissions($user, $key = NULL, $impersonating = false) {
		$access = ['domains_read' => ($key == null) ? true : (true && $key->getDomainRead()),
		           'domains_write' => ($key == null) ? true : (true && $key->getDomainWrite()),
		           'user_read' => ($key == null) ? true : (true && $key->getUserRead()),
		           'user_write' => ($key == null) ? true : (true && $key->getUserWrite()),
		          ];

		foreach ($user->getPermissions() as $permission => $value) {
			if ($value) {
				$access[$permission] = true;
			}
		}

		// Disable all permissions if we have not accepted the minimum terms
		// version required to use the API except user_read and user_write.
		//
		// This will allow the user to still accept the new terms, or update
		// their user info/delete their account etc, but won't allow them to do
		// other useful things.
		if ($user->getAcceptTerms() < getSystemAPIMinimumTermsTime()) {
			foreach ($access as $permission => &$value) {
				if (!in_array($permission, User::getNoTermsPermissions())) {
					$value = false;
				}
			}
		}

		return $access;
	}

	function getKnownDevice($user, &$context) {
		if ($user != false && isset($_SERVER['HTTP_X_2FA_DEVICE_ID'])) {
			$device = TwoFactorDevice::loadFromUserDeviceID($context['db'], $user->getID(), $_SERVER['HTTP_X_2FA_DEVICE_ID']);
			if ($device !== FALSE) {
				if ($device->getCreated() > time() - (60 * 60 * 24 * 30)) {
					$context['device'] = $device;
					$device->setLastUsed(time())->save();
				} else {
					$device->delete();
				}
			}
		}
	}
