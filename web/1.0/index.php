<?php
	// We only output json.
	header('Content-Type: application/json');

	require_once(dirname(__FILE__) . '/functions.php');
	require_once(dirname(__FILE__) . '/response.php');
	require_once(dirname(__FILE__) . '/routes.php');

	// Initial response object.
	$resp = new api_response();

	// Figure out the method requested
	$path = dirname($_SERVER['SCRIPT_FILENAME']);
	$path = preg_replace('#^' . preg_quote($_SERVER['DOCUMENT_ROOT']) . '#', '/', $path);
	$path = preg_replace('#^/+#', '/', $path);
	$method = preg_replace('#^' . preg_quote($path . '/') . '#', '', $_SERVER['REQUEST_URI']);
	$method = preg_replace('#\?.*$#', '', $method);
	$resp->method($method);

	$requestMethod = $_SERVER['REQUEST_METHOD'];

	// Now retrieve the data
	$postdata = file_get_contents("php://input");
	if (empty($postdata) && isset($_REQUEST['data'])) {
		$postdata = $_REQUEST['data'];
	}

	// And decode it...
	if (!empty($postdata)) {
		$postdata = @json_decode($postdata, TRUE);
		if ($postdata == null) {
			$resp->sendError('Error with input.');
		}
		$resp->reqid($postdata['reqid']);
	}

	$context = ['response' => $resp];

	if (isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW'])) {
		$user = User::loadFromEmail(DB::get(), $_SERVER['PHP_AUTH_USER']);

		if ($user !== FALSE && $user->checkPassword($_SERVER['PHP_AUTH_PW'])) {
			$context['user'] = $user;
		}
	}

	list($apimethod, $matches) = $router->findRoute($requestMethod,  '/' . $method);
	if ($apimethod !== FALSE) {
		$apimethod->setContext($context);
		try {
			if ($apimethod->call($requestMethod, $matches)) {
				$resp->send();
			}
		} catch (APIMethod_NeedsAuthentication $ex) {
			header('WWW-Authenticate: Basic realm="API"');
			header('HTTP/1.1 401 Unauthorized');
			$resp->sendError('Authentication required.');
		} catch (APIMethod_AccessDenied $ex) {
			$resp->sendError('Access denied.');
		}

		$resp->sendError('Unsupported request method (' . $requestMethod . ').');
	} else {
		$resp->sendError('Unknown method requested (' . $method . ').');
	}


