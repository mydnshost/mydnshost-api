<?php
	// We only output json.
	header('Content-Type: application/json');

	require_once(dirname(__FILE__) . '/functions.php');
	require_once(dirname(__FILE__) . '/response.php');
	require_once(dirname(__FILE__) . '/routes.php');

	foreach (recursiveFindFiles(__DIR__ . '/methods') as $file) { include_once($file); }

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

	// Look for impersonation header.
	if (isset($_SERVER['HTTP_X_IMPERSONATE'])) {
		$postdata['impersonate'] = ['email', $_SERVER['HTTP_X_IMPERSONATE']];
	} else if (isset($_SERVER['HTTP_X_IMPERSONATE_ID'])) {
		$postdata['impersonate'] = ['id', $_SERVER['HTTP_X_IMPERSONATE_ID']];
	}

	// Set the execution context, used by API Methods.
	$context = ['response' => $resp,
	            'data' => $postdata,
	            'db' => DB::get(),
	           ];

	// Look for authentication.
	// This can either be a session ID from a previous login, or for new logins
	// this can be a USER/PASSWORD Basic auth, or an API Key.
	//
	// Priority:
	//   - Session
	//   - API Keys
	//   - Basic Auth
	//
	// If you attempt to use multiple, then we only try the first one.
	$user = FALSE;

	if (isset($_SERVER['HTTP_X_SESSION_ID'])) {
		session_id($_SERVER['HTTP_X_SESSION_ID']);
		session_start(['use_cookies' => '0', 'cache_limiter' => '']);

		if (isset($_SESSION['userid']) && isset($_SESSION['access'])) {
			$context['sessionid'] = $_SERVER['HTTP_X_SESSION_ID'];
			$context['access'] = $_SESSION['access'];
			$user = User::load($context['db'], $_SESSION['userid']);
			$context['user'] = $user;
		}

		session_commit();
	} else if (isset($_SERVER['HTTP_X_API_USER']) && isset($_SERVER['HTTP_X_API_KEY'])) {
		$user = User::loadFromEmail($context['db'], $_SERVER['HTTP_X_API_USER']);
		if ($user != FALSE) {
			$key = APIKey::loadFromUserKey($context['db'], $user->getID(), $_SERVER['HTTP_X_API_KEY']);

			if ($key != FALSE) {
				$context['user'] = $user;
				$context['access'] = ['domains_read' => $key->getDomainRead(),
				                      'domains_write' => $key->getDomainWrite(),
				                      'user_read' => $key->getUserRead(),
				                      'user_write' => $key->getUserWrite(),
				                     ];
			} else {
				// Invalid Key, reset user.
				$user = FALSE;
			}
		}
	} else if (isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW'])) {
		$user = User::loadFromEmail($context['db'], $_SERVER['PHP_AUTH_USER']);

		if ($user !== FALSE && $user->checkPassword($_SERVER['PHP_AUTH_PW'])) {
			$context['user'] = $user;
			$context['access'] = ['domains_read' => true,
			                      'domains_write' => true,
			                      'user_read' => true,
			                      'user_write' => true,
			                     ];
		} else {
			// Failed password check, reset user.
			$user = FALSE;
		}
	}

	// Is this account disabled?
	if ($user != FALSE && $user->isDisabled()) {
		$user = FALSE;

		// Accounts are currently silently disabled, and act as if
		// authentication failed. If this changes, uncomment the below 2 lines.
		//
		// $resp->setErrorCode('403', 'Forbidden');
		// $resp->sendError('Access denied.');
	}

	// Handle impersonation.
	if ($user != FALSE && array_key_exists('user', $context) && isset($postdata['impersonate'])) {
		if ($user->isAdmin()) {
			if ($postdata['impersonate'][0] == 'id') {
				$impersonating = User::load($context['db'], $postdata['impersonate'][1]);
			} else if ($postdata['impersonate'][0] == 'email') {
				$impersonating = User::loadFromEmail($context['db'], $postdata['impersonate'][1]);
			} else {
				$impersonating = false;
			}

			if ($impersonating !== FALSE) {
				// All the API Methods only look for user, so change it.
				$context['user'] = $impersonating;
				$context['impersonator'] = $user;

				// Add some extra responses so that it's obvious what is happening.
				$resp->setHeader('impersonator', $user->getEmail());
				$resp->setHeader('impersonating', $impersonating->getEmail());
			} else {
				$resp->sendError('No such user to impersonate.');
			}
		} else {
			// Only admins can impersonate.
			$resp->setErrorCode('403', 'Forbidden');
			$resp->sendError('Access denied.');
		}
	}

	// Now, look for the API Method that does what we want!
	list($apimethod, $matches) = $router->findRoute($requestMethod,  '/' . $method);
	if ($apimethod !== FALSE) {
		// Give it a context
		$apimethod->setContext($context);

		// And run it!
		try {
			if ($apimethod->call($requestMethod, $matches)) {
				$resp->send();
			}
		} catch (APIMethod_NeedsAuthentication $ex) {
			header('WWW-Authenticate: Basic realm="API"');
			$resp->setErrorCode('401', 'Unauthorized');
			$resp->sendError('Authentication required.');
		} catch (APIMethod_AccessDenied $ex) {
			$resp->setErrorCode('403', 'Forbidden');
			$resp->sendError('Access denied.');
		} catch (APIMethod_PermissionDenied $ex) {
			$resp->setErrorCode('403', 'Forbidden');
			$resp->sendError('Permission Denied', 'You do not have the required permission: ' . $ex->getMessage());
		} catch (Exception $ex) {
			$resp->setErrorCode('500', 'Internal Server Error');
			$resp->sendError('Internal Server Error.');
		}


		// If we get here, the APIMethod responded negatively towards the method
		// throw an error
		$resp->setErrorCode('405', 'Method Not Allowed');
		$resp->sendError('Unsupported request method (' . $requestMethod . ').');
	} else {
		// No such method known
		$resp->setErrorCode('404', 'Not Found');
		$resp->sendError('Unknown method requested (' . $method . ').');
	}

	// Shouldn't get here, but exit anyway just in case.
	$resp->sendError('Unknown error.');
