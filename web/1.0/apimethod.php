<?php

abstract class APIMethod {
	private $context;

	/**
	 * Called before get()/post()/delete() to check that we are in a valid
	 * context for calling this method.
	 *
	 * @param $requestMethod How was this method called (GET/POST/DELETE)
	 * @param $matches The URL matches when finding the route.
	 * @throws An exception if we are not in a position to call this method.
	 */
	public function check($requestMethod, $matches) { }

	/**
	 * Called for a GET request that has been routed to this object.
	 *
	 * @param $matches The URL matches when finding the route.
	 * @return true if the method was handled, else false.
	 */
	public function get($matches) { return false; }

	/**
	 * Called for a POST/PUT request that has been routed to this object.
	 *
	 * @param $matches The URL matches when finding the route.
	 * @return true if the method was handled, else false.
	 */
	public function post($matches) { return false; }

	/**
	 * Called for a DELETE request that has been routed to this object.
	 *
	 * @param $matches The URL matches when finding the route.
	 * @return true if the method was handled, else false.
	 */
	public function delete($matches) { return false; }

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
		return array_key_exists($key, $this->context) ? $this->context[$key] : NULL;
	}

	/**
	 * Set the API Context for this method.
	 *
	 * @param $context The new API context for this method.
	 */
	public function setContext($context) {
		$this->context = $context;
	}

	/**
	 * Handle calling this method.
	 *
	 * @param $requestMethod How was this method called (GET/POST/DELETE)
	 * @param $matches The URL matches when finding the route.
	 * @return true if the method was handled, else false.
	 */
	public final function call($requestMethod, $matches) {
		switch (strtoupper($requestMethod)) {
			case "GET":
				$this->check("GET", $matches);
				return $this->get($matches);
			case "POST":
				$this->check("POST", $matches);
				return $this->post($matches);
			case "DELETE":
				$this->check("DELETE", $matches);
				return $this->delete($matches);
			default:
				return FALSE;
		}
	}
}

class APIMethod_NeedsAuthentication extends Exception { }

class APIMethod_AccessDenied extends Exception { }
