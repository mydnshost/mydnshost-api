<?php

	class UserAdmin extends MultiMethodAPIMethod {
		public function check($requestMethod, $params) {
			$user = $this->getContextKey('user');
			if ($user == NULL) {
				throw new APIMethod_NeedsAuthentication();
			}

			if ($this->checkPermissions(['manage_users'], true)) { return true; }

			if (isset($params['userid']) && ($params['userid'] != 'self' && $params['userid'] != $user->getID())) {
				throw new APIMethod_AccessDenied();
			}

			if (isset($params['create'])) {
				throw new APIMethod_AccessDenied();
			}
		}

		protected function getUserFromParam($params) {
			$user = FALSE;
			if (isset($params['userid'])) {
				$userid = ($params['userid'] == 'self') ? $this->getContextKey('user')->getID() : $params['userid'];

				$user = User::load($this->getContextKey('db'), $userid);
				if ($user === FALSE) {
					$this->getContextKey('response')->sendError('Unknown userid: ' . $params['userid']);
				}
			}

			return $user;
		}

		protected function getKeyFromParam($params) {
			$key = FALSE;
			if (isset($params['keyid'])) {
				$userid = ($params['userid'] == 'self') ? $this->getContextKey('user')->getID() : $params['userid'];
				$key = APIKey::loadFromUserKey($this->getContextKey('db'), $userid, $params['keyid']);
				if ($key === FALSE) {
					$this->getContextKey('response')->sendError('Unknown apikey: ' . $params['keyid']);
				}
			}

			return $key;
		}

		protected function get2FAKeyFromParam($params) {
			$key = FALSE;
			if (isset($params['secretid'])) {
				$userid = ($params['userid'] == 'self') ? $this->getContextKey('user')->getID() : $params['userid'];
				$key = TwoFactorKey::loadFromUserKey($this->getContextKey('db'), $userid, $params['secretid']);
				if ($key === FALSE) {
					$this->getContextKey('response')->sendError('Unknown 2fakey: ' . $params['secretid']);
				}
			}

			return $key;
		}

		protected function getUserData($user) {
			if ($user === FALSE) {
				$this->getContextKey('response')->sendError('Unknown User ID: ' . $params['userid']);
			} else {
				$u = $user->toArray();
				unset($u['password']);
				$list[] = $u;
				$this->getContextKey('response')->data($u);
				return true;
			}
		}

		protected function listUsers() {
			if ($this->checkPermissions(['manage_users'], true)) {
				$users = User::find($this->getContextKey('db'), []);
			} else {
				$users = [$this->getContextKey('user')];
			}

			$list = [];
			foreach ($users as $user) {
				$u = $user->toArray();
				unset($u['password']);
				$list[] = $u;
			}
			$this->getContextKey('response')->data($list);
			return true;
		}

		protected function createUser() {
			$user = new User($this->getContextKey('db'));
			return $this->updateUser($user, true);
		}

		protected function updateUser($user, $isCreate = false) {
			$data = $this->getContextKey('data');
			if (!isset($data['data']) || !is_array($data['data'])) {
				$this->getContextKey('response')->sendError('No data provided for update.');
			}

			if ($user !== FALSE) {
				$this->doUpdateUser($user, $data['data']);
				try {
					$user->validate();
				} catch (ValidationFailed $ex) {
					if ($isCreate) {
						$this->getContextKey('response')->sendError('Error creating user.', $ex->getMessage());
					} else {
						$this->getContextKey('response')->sendError('Error updating user: ' . $user->getID(), $ex->getMessage());
					}
				}

				$u = $user->toArray();
				unset($u['password']);
				$u['updated'] = $user->save();
				$u['id'] = $user->getID();
				if (!$u['updated']) {
					if ($isCreate) {
						$this->getContextKey('response')->sendError('Error creating user.', $user->getLastError());
					} else {
						$this->getContextKey('response')->sendError('Error updating user: ' . $user->getID());
					}
				} else {
					$this->getContextKey('response')->data($u);
				}

				return TRUE;
			}

			return FALSE;
		}

		private function doUpdateUser($user, $data) {
			$keys = array('email' => 'setEmail',
			              'realname' => 'setRealName',
			              'password' => 'setPassword',
			             );

			// Can this user disable/enable user accounts?
			if ($this->checkPermissions(['manage_users'], true)) {
				// Don't allow disabling own account.
				if ($this->getContextKey('user')->getID() !== $user->getID()) {
					$keys['disabled'] = 'setDisabled';
				}
			}

			foreach ($keys as $k => $f) {
				if (array_key_exists($k, $data)) {
					$user->$f($data[$k]);
				}
			}

			// Can this user set permissions?
			if (isset($data['permissions']) && $this->checkPermissions(['manage_permissions'], true)) {
				$oldPermissions = $user->getPermissions();

				// Set requested permissions.
				foreach ($data['permissions'] as $permission => $value) {
					$user->setPermission($permission, $value);
				}

				// Don't allow users to remove their own ability to edit permissions.
				if ($this->getContextKey('user')->getID() == $user->getID()) {
					foreach (['manage_permissions', 'manage_users'] as $p) {
						if ($oldPermissions[$p] === true) {
							$user->setPermission($p, true);
						}
					}
				}
			}

			return $user;
		}

		public function deleteUser($user) {
			if ($this->getContextKey('user')->getID() === $user->getID()) {
				$this->getContextKey('response')->sendError('You can not delete yourself.');
			}

			$this->getContextKey('response')->data('deleted', $user->delete() ? 'true' : 'false');
			return TRUE;
		}

		protected function getAPIKeys($user) {
			$keys = APIKey::getSearch($this->getContextKey('db'))->where('user_id', $user->getID())->find('apikey');

			$result = [];
			foreach ($keys as $k => $v) {
				$result[$k] = $v->toArray();
				unset($result[$k]['id']);
				unset($result[$k]['user_id']);
				unset($result[$k]['apikey']);
			}

			$this->getContextKey('response')->data($result);

			return TRUE;
		}

		protected function getAPIKey($user, $key) {
			$k = $key->toArray();
			unset($k['id']);
			unset($k['user_id']);
			unset($k['apikey']);

			$this->getContextKey('response')->data($k);

			return TRUE;
		}

		protected function createAPIKey($user) {
			$key = (new APIKey($this->getContextKey('db')))->setKey(TRUE)->setUserID($user->getID());
			return $this->updateAPIKey($user, $key, true);
		}

		protected function updateAPIKey($user, $key, $isCreate = false) {
			$data = $this->getContextKey('data');
			if (!isset($data['data']) || !is_array($data['data'])) {
				$this->getContextKey('response')->sendError('No data provided for update.');
			}

			if ($key !== FALSE) {
				$this->doUpdateKey($key, $data['data']);

				try {
					$key->validate();
				} catch (ValidationFailed $ex) {
					if ($isCreate) {
						$this->getContextKey('response')->sendError('Error creating key.', $ex->getMessage());
					} else {
						$this->getContextKey('response')->sendError('Error updating key: ' . $key->getKey(), $ex->getMessage());
					}
				}

				$k = $key->toArray();
				unset($k['id']);
				unset($k['user_id']);
				unset($k['apikey']);
				$k['updated'] = $key->save();
				if (!$k['updated']) {
					if ($isCreate) {
						$this->getContextKey('response')->sendError('Error creating key.', $ex->getMessage());
					} else {
						$this->getContextKey('response')->sendError('Error updating key: ' . $key->getKey(), $ex->getMessage());
					}
				} else if ($isCreate) {
					$this->getContextKey('response')->data([$key->getKey() => $k]);
				} else {
					$this->getContextKey('response')->data($k);
				}

				return TRUE;

			}
		}

		private function doUpdateKey($key, $data) {
			$keys = array('description' => 'setDescription',
			              'domains_read' => 'setDomainRead',
			              'domains_write' => 'setDomainWrite',
			              'user_read' => 'setUserRead',
			              'user_write' => 'setUserWrite',
			             );

			foreach ($keys as $k => $f) {
				if (array_key_exists($k, $data)) {
					$key->$f($data[$k]);
				}
			}

			return $key;
		}

		protected function deleteAPIKey($user, $key) {
			$this->getContextKey('response')->data('deleted', $key->delete() ? 'true' : 'false');
			return TRUE;
		}

		protected function get2FAKeys($user) {
			$keys = TwoFactorKey::getSearch($this->getContextKey('db'))->where('user_id', $user->getID())->find('id');

			$result = [];
			foreach ($keys as $k => $v) {
				$result[$k] = $v->toArray();
				unset($result[$k]['id']);
				unset($result[$k]['user_id']);
				if ($v->isActive()) {
					unset($result[$k]['key']);
				}
			}

			$this->getContextKey('response')->data($result);

			return TRUE;
		}

		protected function get2FAKey($user, $key) {
			$k = $key->toArray();
			unset($k['user_id']);
			if ($key->isActive()) {
				unset($k['key']);
			}

			$this->getContextKey('response')->data($k);

			return TRUE;
		}

		protected function create2FAKey($user) {
			$key = (new TwoFactorKey($this->getContextKey('db')))->setKey(TRUE)->setUserID($user->getID())->setCreated(time());
			return $this->update2FAKey($user, $key, true);
		}

		protected function update2FAKey($user, $key, $isCreate = false) {
			$data = $this->getContextKey('data');
			if (!isset($data['data']) || !is_array($data['data'])) {
				$this->getContextKey('response')->sendError('No data provided for update.');
			}

			if ($key !== FALSE) {
				$this->doUpdate2FAKey($key, $data['data']);

				try {
					$key->validate();
				} catch (ValidationFailed $ex) {
					if ($isCreate) {
						$this->getContextKey('response')->sendError('Error creating key.', $ex->getMessage());
					} else {
						$this->getContextKey('response')->sendError('Error updating key: ' . $key->getKey(), $ex->getMessage());
					}
				}

				$k = $key->toArray();
				unset($k['user_id']);
				if ($key->isActive()) {
					unset($k['key']);
				}
				$k['updated'] = $key->save();
				$k['id'] = $key->getID();
				if (!$k['updated']) {
					if ($isCreate) {
						$this->getContextKey('response')->sendError('Error creating key.', $ex->getMessage());
					} else {
						$this->getContextKey('response')->sendError('Error updating key: ' . $key->getKey(), $ex->getMessage());
					}
				} else if ($isCreate) {
					$this->getContextKey('response')->data($k);
				} else {
					$this->getContextKey('response')->data($k);
				}

				return TRUE;

			}
		}

		private function doUpdate2FAKey($key, $data) {
			$keys = array('description' => 'setDescription',
			             );

			foreach ($keys as $k => $f) {
				if (array_key_exists($k, $data)) {
					$key->$f($data[$k]);
				}
			}

			return $key;
		}

		protected function verify2FAKey($user, $key) {
			$data = $this->getContextKey('data');
			if (!isset($data['data']) || !is_array($data['data']) || !isset($data['data']['code'])) {
				$this->getContextKey('response')->sendError('No code provided for verification.');
			}

			if (!$key->verify($data['data']['code'], 1)) {
				$this->getContextKey('response')->sendError('Invalid code provided for verification.');
			}

			// Activate the key once verified.
			$key->setActive(true)->setLastUsed(time())->save();
			$this->getContextKey('response')->data(['success' => 'Valid code provided.']);

			return TRUE;
		}

		protected function delete2FAKey($user, $key) {
			$this->getContextKey('response')->data('deleted', $key->delete() ? 'true' : 'false');
			return TRUE;
		}
	}

	$router->addRoute('GET /users', new class extends UserAdmin {
		function get($params) {
			$this->checkPermissions(['user_read']);
			$user = $this->getUserFromParam($params);

			return $this->listUsers();
		}
	});

	$router->addRoute('(GET|POST|DELETE) /users/(?P<userid>self|[0-9]+)', new class extends UserAdmin {
		function get($params) {
			$this->checkPermissions(['user_read']);
			$user = $this->getUserFromParam($params);

			return $this->getUserData($user);
		}

		function post($params) {
			$this->checkPermissions(['user_write']);
			$user = $this->getUserFromParam($params);

			return $this->updateUser($user);
		}

		function delete($params) {
			$this->checkPermissions(['user_write']);
			$user = $this->getUserFromParam($params);

			return $this->deleteUser($user);
		}
	});

	$router->addRoute('(GET|POST) /users/(?P<userid>self|[0-9]+)/(?P<keys>keys)', new class extends UserAdmin {
		function get($params) {
			$this->checkPermissions(['user_read']);
			$user = $this->getUserFromParam($params);

			return $this->getAPIKeys($user);
		}

		function post($params) {
			$this->checkPermissions(['user_write']);
			$user = $this->getUserFromParam($params);

			return $this->createAPIKey($user);
		}
	});

	$router->addRoute('(GET|POST|DELETE) /users/(?P<userid>self|[0-9]+)/(?P<keys>keys)/(?P<keyid>[^/]+)', new class extends UserAdmin {
		function get($params) {
			$this->checkPermissions(['user_read']);
			$user = $this->getUserFromParam($params);

			$key = $this->getKeyFromParam($params);
			return $this->getAPIKey($user, $key);
		}

		function post($params) {
			$this->checkPermissions(['user_write']);
			$user = $this->getUserFromParam($params);

			$key = $this->getKeyFromParam($params);
			return $this->updateAPIKey($user, $key);
		}

		function delete($params) {
			$this->checkPermissions(['user_write']);
			$user = $this->getUserFromParam($params);

			$key = $this->getKeyFromParam($params);
			return $this->deleteAPIKey($user, $key);
		}
	});

	$router->addRoute('(GET|POST) /users/(?P<userid>self|[0-9]+)/(?P<secret>2fa)', new class extends UserAdmin {
		function get($params) {
			$this->checkPermissions(['user_read']);
			$user = $this->getUserFromParam($params);

			return $this->get2FAKeys($user);
		}

		function post($params) {
			$this->checkPermissions(['user_write']);
			$user = $this->getUserFromParam($params);

			return $this->create2FAKey($user);
		}
	});

	$router->addRoute('(GET|POST|DELETE) /users/(?P<userid>self|[0-9]+)/(?P<secret>2fa)/(?P<secretid>[0-9]+)', new class extends UserAdmin {
		function get($params) {
			$this->checkPermissions(['user_read']);
			$user = $this->getUserFromParam($params);

			$key = $this->get2FAKeyFromParam($params);
			return $this->get2FAKey($user, $key);
		}

		function post($params) {
			$this->checkPermissions(['user_write']);
			$user = $this->getUserFromParam($params);

			$key = $this->get2FAKeyFromParam($params);
			return $this->update2FAKey($user, $key);
		}

		function delete($params) {
			$this->checkPermissions(['user_write']);
			$user = $this->getUserFromParam($params);

			$key = $this->get2FAKeyFromParam($params);
			return $this->delete2FAKey($user, $key);
		}
	});

	$router->addRoute('(POST) /users/(?P<userid>self|[0-9]+)/(?P<secret>2fa)/(?P<secretid>[0-9]+)/(?P<verify>verify)', new class extends UserAdmin {
		function post($params) {
			$this->checkPermissions(['user_write']);
			$user = $this->getUserFromParam($params);

			$key = $this->get2FAKeyFromParam($params);
			return $this->verify2FAKey($user, $key);
		}
	});

	$router->addRoute('POST /users/(?P<create>create)', new class extends UserAdmin {
		function post($params) {
			$this->checkPermissions(['user_write']);
			$user = $this->getUserFromParam($params);

			return $this->createUser();
		}
	});
