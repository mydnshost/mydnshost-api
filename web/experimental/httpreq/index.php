<?php
	use shanemcc\phpdb\DB;

	// We only output json.
	header('Content-Type: application/json');

	require_once(dirname(__FILE__) . '/functions.php');
	require_once(dirname(__FILE__) . '/response.php');

	$router = new MethodRouter();

	foreach (recursiveFindFiles(__DIR__ . '/methods') as $file) { include_once($file); }

	// Set the session handler.
	checkSessionHandler();

	// Initial response object.
	$resp = new api_response();

	// Figure out the method requested
	//
	// This will find the method path relative to where we are, this lets us
	// run in subdomains or at the root of the domain.
	// Firstly find the current path.
	$path = dirname($_SERVER['SCRIPT_FILENAME']);
	$path = preg_replace('#^' . preg_quote($_SERVER['DOCUMENT_ROOT']) . '#', '/', $path);
	$path = preg_replace('#^/+#', '/', $path);
	// Then remove that from the request URI to get the relative path requested
	$method = preg_replace('#^' . preg_quote($path . '/') . '#', '', $_SERVER['REQUEST_URI']);
	// Remove any query strings.
	$method = preg_replace('#\?.*$#', '', $method);
	// Remove leading /
	$method = preg_replace('#^/+#', '/', $method);
	// We have our method!
	$resp->method($method);

	// Request Method
	$requestMethod = $_SERVER['REQUEST_METHOD'];
	// Allow request method hacks for things that can't do it right.
	if (isset($_SERVER['HTTP_X_REQUEST_METHOD'])) {
		$requestMethod = $_SERVER['HTTP_X_REQUEST_METHOD'];
	}
	// Treat PUT and POST the same.
	if ($requestMethod == "PUT") { $requestMethod = "POST"; }

	// If we have POST/PUT data, retrieve it.
	$postdata = file_get_contents("php://input");
	// Allow passing a ?data= GET/POST parameter for compatability.
	if (empty($postdata) && isset($_REQUEST['data'])) {
		$postdata = $_REQUEST['data'];
	}

	// Now decode the postdata...
	if (!empty($postdata)) {
		$postdata = @json_decode($postdata, TRUE);
		if ($postdata == null) {
			$resp->sendError('Error with input.');
		}
	} else {
		$postdata = array();
	}

	// Get request ID
	if (array_key_exists('reqid', $postdata)) {
		$resp->reqid($postdata['reqid']);
	} else if (isset($_SERVER['HTTP_X_REQUEST_ID'])) {
		$postdata['reqid'] = $_SERVER['HTTP_X_REQUEST_ID'];
		$resp->reqid($postdata['reqid']);
	}

	// Set the execution context, used by API Methods.
	$context = ['response' => $resp,
	            'data' => $postdata,
	            'db' => DB::get(),
	           ];
	$user = FALSE;

	if (isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW'])) {
		$hasAt = strpos($_SERVER['PHP_AUTH_USER'], '@') !== FALSE;
		$email = $hasAt ? $_SERVER['PHP_AUTH_USER'] : NULL;
		$domain = $hasAt ? NULL : $_SERVER['PHP_AUTH_USER'];
		$key = $_SERVER['PHP_AUTH_PW'];

		if ($email != null) {
			$user = User::loadFromEmail($context['db'], $email);
			if ($user != FALSE) {
				$key = APIKey::loadFromUserKey($context['db'], $user->getID(), $key);

				if ($key != FALSE) {
					$context['user'] = $user;
					$context['access'] = getAccessPermissions($user, $key, false);
					$context['key'] = $key;
					$key->setLastUsed(time())->save();
				} else {
					$user = FALSE;
				}
			}
		} else if ($domain != null) {
			$domain = Domain::loadFromDomain($context['db'], $domain);
			if ($domain != FALSE) {
				$key = DomainKey::loadFromDomainKey($context['db'], $domain->getID(), $key);

				if ($key != FALSE) {
					$user = $key->getDomainKeyUser();

					$context['user'] = $user;
					$context['access'] = ['domains_read' => true, 'domains_write' => (true && $key->getDomainWrite())];
					$context['domainkey'] = $key;
					$key->setLastUsed(time())->save();
				}
			}
		}
	}

	// Is this account disabled?
	if ($user != FALSE && $user->isDisabled()) {
		$reason = $user->getDisabledReason();
		$user = FALSE;
		unset($context['user']);
		unset($context['access']);

		// If a reason has been specified, show it, otherwise we treat the
		// request as unauthenticated.
		if (!empty($reason)) {
			$resp->setErrorCode('403', 'Forbidden');
			$resp->sendError('Access denied.', 'Account has been suspended: ' . $reason);
		}
	}

	// Now, look for the API Method that does what we want!
	try {
		$router->run($requestMethod, $method, $context);
		$resp->send();
	} catch (RouterMethod_NotAllowed $ex) {
		$resp->setErrorCode('405', 'Method Not Allowed');
		$resp->sendError('Unsupported request method (' . $requestMethod . ').');
	} catch (RouterMethod_NotFound $ex) {
		$resp->setErrorCode('404', 'Not Found');
		$resp->sendError('Unknown method requested (' . $method . ').');
	} catch (RouterMethod_NeedsAuthentication $ex) {
		if (!empty($ex->getMessage())) { $errorExtraData[] = $ex->getMessage(); }
		header('WWW-Authenticate: Basic realm="API"');
		$resp->setErrorCode('401', 'Unauthorized');
		$resp->sendError('Authentication required.', $errorExtraData);
	} catch (RouterMethod_AccessDenied $ex) {
		if (!empty($ex->getMessage())) { $errorExtraData[] = $ex->getMessage(); }
		$resp->setErrorCode('403', 'Forbidden');
		$resp->sendError('Access denied.', $errorExtraData);
	} catch (RouterMethod_PermissionDenied $ex) {
		$resp->setErrorCode('403', 'Forbidden');
		$resp->sendError('Permission Denied', 'You do not have the required permission: ' . $ex->getMessage());
	} catch (Exception $ex) {
		$resp->setErrorCode('500', 'Internal Server Error');
		$resp->sendError('Internal Server Error.', $ex->getMessage());
	}

	// Shouldn't get here, but exit anyway just in case.
	$resp->sendError('Unknown error.');
