<?php

	class UserAdmin extends APIMethod {
		public function check($requestMethod, $params) {
			$user = $this->getContextKey('user');
			if ($user == NULL) {
				throw new APIMethod_NeedsAuthentication();
			}
			if ($user->isAdmin()) { return true; }

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
				if ($params['userid'] == 'self') {
					$params['userid'] = $this->getContextKey('user')->getID();
				}

				$user = User::load($this->getContextKey('db'), $params['userid']);
			}

			return $user;
		}

		public function get($params) {
			$user = $this->getUserFromParam($params);

			if (isset($params['userid']) && isset($params['keys'])) {
				return $this->getAPIKeys($user);
			} else if (isset($params['userid'])) {
				return $this->getUserData($user);
			} else {
				return $this->listUsers();
			}

			return FALSE;
		}

		public function post($params) {
			$user = $this->getUserFromParam($params);

			if (isset($params['keyid'])) {
				return $this->updateAPIKey($user);
			} else if (isset($params['keys'])) {
				return $this->createAPIKey($user);
			} else if (isset($params['create'])) {
				return $this->createUser();
			} else if (isset($params['userid'])) {
				return $this->updateUser($user);
			}

			return FALSE;
		}

		public function delete($params) {
			$user = $this->getUserFromParam($params);

			if (isset($params['keyid'])) {
				return $this->deleteAPIKey($user);
			} else if (isset($params['userid'])) {
				return $this->deleteUser($user);
			}
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
			if ($this->getContextKey('user')->isAdmin()) {
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
						$this->getContextKey('response')->sendError('Error updating user: ' . $params['userid'], $ex->getMessage());
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
						$this->getContextKey('response')->sendError('Error updating user: ' . $params['userid']);
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

			if ($this->getContextKey('user')->isAdmin()) {
				$keys['admin'] = 'setAdmin';
			}

			foreach ($keys as $k => $f) {
				if (array_key_exists($k, $data)) {
					$user->$f($data[$k]);
				}
			}

			return $user;
		}

		public function deleteUser($user) {
			if ($user === FALSE) {
				$this->getContextKey('response')->sendError('Unknown User ID: ' . $params['userid']);
			}

			if ($this->getContextKey('user')->getID() === $user->getID()) {
				$this->getContextKey('response')->sendError('You can not delete yourself.');
			}

			$this->getContextKey('response')->data('deleted', $user->delete() ? 'true' : 'false');
			return TRUE;
		}

		protected function getAPIKeys($user) {

		}

		protected function createAPIKey($user) {

		}

		protected function updateAPIKey($user, $key) {

		}

		protected function deleteAPIKey($user, $key) {

		}
	}

	$router->addRoute('GET /users', new UserAdmin());
	$router->addRoute('(GET|POST|DELETE) /users/(?P<userid>self|[0-9]+)', new UserAdmin());

	$router->addRoute('(GET|POST) /users/(?P<userid>self|[0-9]+)/(?P<keys>keys)', new UserAdmin());
	$router->addRoute('(POST|DELETE) /users/(?P<userid>self|[0-9]+)/(?P<keys>keys)/(?P<key>[^/]+)', new UserAdmin());

	$router->addRoute('POST /users/(?P<create>create)', new UserAdmin());
