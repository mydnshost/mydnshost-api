<?php

abstract class MultiMethodAPIMethod extends APIMethod {

	/**
	 * Called for a GET request that has been routed to this object.
	 *
	 * @param $params The URL matches when finding the route.
	 * @return true if the method was handled, else false.
	 */
	public function get($params) { return false; }

	/**
	 * Called for a POST/PUT request that has been routed to this object.
	 *
	 * @param $params The URL matches when finding the route.
	 * @return true if the method was handled, else false.
	 */
	public function post($params) { return false; }

	/**
	 * Called for a DELETE request that has been routed to this object.
	 *
	 * @param $params The URL matches when finding the route.
	 * @return true if the method was handled, else false.
	 */
	public function delete($params) { return false; }


	/**
	 * Handle calling this method.
	 *
	 * @param $requestMethod How was this method called (GET/POST/DELETE)
	 * @param $params The URL matches when finding the route.
	 * @return true if the method was handled, else false.
	 */
	public function call($requestMethod, $params) {
		switch (strtoupper($requestMethod)) {
			case "GET":
				$this->check("GET", $params);
				return $this->get($params);
			case "POST":
				$this->check("POST", $params);
				return $this->post($params);
			case "DELETE":
				$this->check("DELETE", $params);
				return $this->delete($params);
			default:
				return FALSE;
		}
	}
}

abstract class APIMethod {
	private $context;

	/**
	 * Called before get()/post()/delete() to check that we are in a valid
	 * context for calling this method.
	 *
	 * @param $requestMethod How was this method called (GET/POST/DELETE)
	 * @param $params The URL matches when finding the route.
	 * @throws An exception if we are not in a position to call this method.
	 */
	public function check($requestMethod, $params) { }

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
				throw new APIMethod_PermissionDenied($permission);
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

class APIMethod_NeedsAuthentication extends Exception { }

class APIMethod_AccessDenied extends Exception { }

class APIMethod_PermissionDenied extends Exception { }
