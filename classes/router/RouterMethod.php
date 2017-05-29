<?php

abstract class RouterMethod {
	private $context;

	public function getRequestMethod() {
		return $this->hasContextKey('Request Method') ? strtoupper($this->getContextKey('Request Method')) : 'GET';
	}

	/**
	 * Handle calling this method.
	 *
	 * @param $params The URL matches when finding the route.
	 * @return true if the method was handled, else false.
	 */
	public function call($params) {
		switch ($this->getRequestMethod()) {
			case "GET":
			case "POST":
			case "DELETE":
				$methodName = strtolower($this->getRequestMethod());

				if (method_exists($this, 'run') || method_exists($this, $methodName)) {
					if (method_exists($this, 'check')) {
						call_user_func_array([$this, 'check'], $params);
					}

					if (method_exists($this, 'run')) {
						return call_user_func_array([$this, 'run'], $params);
					} else if (method_exists($this, $methodName)) {
						return call_user_func_array([$this, $methodName], $params);
					}
				}
			default:
				return FALSE;
		}
	}

	/**
	 * Check permissions.
	 * Check if the user has all of the required permissions.
	 *
	 * @param $permissions Permissions required.
	 * @param $silent (Default: False) If true the result of the check will be
	 *                returned, rather than throwing a permission denied exception.
	 */
	public function checkPermissions($permissions, $silent = false) {
		$access = $this->getContextKey('access');

		foreach ($permissions as $permission) {
			if ($access === NULL || !array_key_exists($permission, $access) || !parseBool($access[$permission])) {
				if ($silent) { return false; }
				throw new RouterMethod_PermissionDenied($permission);
			}
		}

		return true;
	}

	/**
	 * Get the API Context for this method.
	 *
	 * @return The API context for this method.
	 */
	public function getContext() {
		return $this->context;
	}

	/**
	 * Get the part of the API Context for this method.
	 *
	 * @param $key The key to get.
	 * @return The value of $key in this context, or NULL if not set.
	 */
	public function getContextKey($key) {
		return $this->hasContextKey($key) ? $this->context[$key] : NULL;
	}

	/**
	 * Check if the context has a given key.
	 *
	 * @param $key The key to check.
	 * @return TRUE if the context has an entry for this key, else false.
	 */
	public function hasContextKey($key) {
		return array_key_exists($key, $this->context);
	}

	/**
	 * Set the API Context for this method.
	 *
	 * @param $context The new API context for this method.
	 */
	public function setContext($context) {
		$this->context = $context;
	}
}

