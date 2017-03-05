<?php

abstract class APIMethod {
	private $context;

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
	 * @param The API context for this method.
	 */
	public function getContext() {
		return $this->context;
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
	 * @param $requestMethod How was this method called (GET/POST/PUT/DELETE)
	 * @param $matches The URL matches when finding the route.
	 * @return true if the method was handled, else false.
	 */
	public final function call($requestMethod, $matches) {
		switch (strtoupper($requestMethod)) {
			case "GET":
				return $this->get($matches);
			case "PUT":
			case "POST":
				return $this->post($matches);
			case "DELETE":
				return $this->delete($matches);
			default:
				return FALSE;
		}
	}
}
