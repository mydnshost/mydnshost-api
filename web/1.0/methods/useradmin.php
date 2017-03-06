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

		public function get($params) {
			if (isset($params['userid'])) {
				$user = User::load($this->getContextKey('db'), $params['userid'] == 'self' ? $this->getContextKey('user')->getID() : $params['userid']);
				if ($user === FALSE) {
					$this->getContextKey('response')->sendError('Unknown User ID: ' . $params['userid']);
				} else {
					$u = $user->toArray();
					unset($u['password']);
					$list[] = $u;
					$this->getContextKey('response')->data($u);
					return true;
				}
			} else {
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

			return FALSE;
		}

		public function post($params) {
			$data = $this->getContextKey('data');
			if (!isset($data['data']) || !is_array($data['data'])) {
				$this->getContextKey('response')->sendError('No data provided for update.');
			}

			if (isset($params['userid'])) {
				$user = User::load($this->getContextKey('db'), $params['userid'] == 'self' ? $this->getContextKey('user')->getID() : $params['userid']);
				if ($user === FALSE) {
					$this->getContextKey('response')->sendError('Unknown User ID: ' . $params['userid']);
				}
			} else if (isset($params['create'])) {
				$user = new User($this->getContextKey('db'));
			}

			if ($user !== FALSE) {
				$this->doUpdateUser($user, $data['data']);
				try {
					$user->validate();
				} catch (ValidationFailed $ex) {
					if (isset($params['create'])) {
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
					if (isset($params['create'])) {
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

		public function delete($params) {
			if (isset($params['userid'])) {
				$user = User::load($this->getContextKey('db'), $params['userid'] == 'self' ? $this->getContextKey('user')->getID() : $params['userid']);
				if ($user === FALSE) {
					$this->getContextKey('response')->sendError('Unknown User ID: ' . $params['userid']);
				}

				if ($this->getContextKey('user')->getID() === $user->getID()) {
					$this->getContextKey('response')->sendError('You can not delete yourself.');
				}

				$this->getContextKey('response')->data('deleted', $user->delete() ? 'true' : 'false');
				return TRUE;
			}

			return FALSE;
		}
	}

	$router->addRoute('GET /users', new UserAdmin());
	$router->addRoute('(GET|POST|DELETE) /users/(?P<userid>self|[0-9]+)', new UserAdmin());
	$router->addRoute('POST /users/(?P<create>create)', new UserAdmin());
