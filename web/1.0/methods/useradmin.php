<?php

	use shanemcc\phpdb\ValidationFailed;

	class UserAdmin extends RouterMethod {
		public function check() {
			$user = $this->getContextKey('user');
			if ($user == NULL) {
				throw new RouterMethod_NeedsAuthentication();
			}
		}

		protected function getUserFromParam($userid) {
			$userid = ($userid == 'self') ? $this->getContextKey('user')->getID() : $userid;

			$user = User::load($this->getContextKey('db'), $userid);
			if ($user === FALSE) {
				$this->getContextKey('response')->sendError('Unknown userid: ' . $userid);
			}

			return $user;
		}

		protected function getKeyFromParam($userid, $keyid) {
			$userid = ($userid == 'self') ? $this->getContextKey('user')->getID() : $userid;
			$key = APIKey::loadFromUserKey($this->getContextKey('db'), $userid, $keyid);
			if ($key === FALSE) {
				$this->getContextKey('response')->sendError('Unknown apikey: ' . $keyid);
			}

			return $key;
		}

		protected function get2FAKeyFromParam($userid, $secretid) {
			$userid = ($userid == 'self') ? $this->getContextKey('user')->getID() : $userid;
			$key = TwoFactorKey::loadFromUserKey($this->getContextKey('db'), $userid, $secretid);
			if ($key === FALSE) {
				$this->getContextKey('response')->sendError('Unknown 2fakey: ' . $secretid);
			}

			return $key;
		}

		protected function getUserData($user) {
			if ($user === FALSE) {
				$this->getContextKey('response')->sendError('Unknown User.');
			} else {
				$u = $user->toArray();
				unset($u['password']);
				unset($u['verifycode']);
				if (!$user->isDisabled()) { unset($u['disabledreason']); }

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
				unset($u['verifycode']);
				if ($user->isUnVerified()) {
					$u['unverified'] = true;
				} else if ($user->isPendingPasswordReset()) {
					$u['pendingreset'] = true;
				}
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
				$oldPass = $user->getRawPassword();
				$oldEmail = $user->getEmail();
				$this->doUpdateUser($user, $data['data']);
				$newPass = $user->getRawPassword();
				$newEmail = $user->getEmail();

				$sendWelcome = false;
				if ($isCreate) {
					// Set default permissions.
					global $config;
					foreach ($config['register_permissions'] as $permission) {
						$permission = trim($permission);
						if (!empty($permission)) {
							$user->setPermission($permission, true);
						}
					}

					if (isset($data['data']['sendWelcome']) && parseBool($data['data']['sendWelcome'])) {
						$sendWelcome = true;
						$user->setVerifyCode(genUUID());
						$user->setRawPassword('-');
						$user->setDisabled(true);
						$user->setDisabledReason("Email address has not yet been verified.");
					}
				}

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
				unset($u['verifycode']);
				if (!$user->isDisabled()) { unset($u['disabledreason']); }
				$u['updated'] = $user->save();
				$u['id'] = $user->getID();
				if (!$u['updated']) {
					if ($isCreate) {
						$this->getContextKey('response')->sendError('Error creating user.', $user->getLastError());
					} else {
						$this->getContextKey('response')->sendError('Error updating user: ' . $user->getID());
					}
				} else {
					if (!$isCreate && !$this->hasContextKey('impersonator')) {
						if ($newEmail != $oldEmail && !empty($oldEmail)) {
							$te = TemplateEngine::get();
							$te->setVar('user', $user);
							[$subject, $message, $htmlmessage] = templateToMail($te, 'emailchanged.tpl');
							HookManager::get()->handle('send_mail', [$oldEmail, $subject, $message, $htmlmessage]);
						}

						if ($newPass != $oldPass && !empty($oldPass)) {
							$te = TemplateEngine::get();
							$te->setVar('user', $user);
							[$subject, $message, $htmlmessage] = templateToMail($te, 'passwordchanged.tpl');
							HookManager::get()->handle('send_mail', [$user->getEmail(), $subject, $message, $htmlmessage]);
						}
					} else if ($isCreate && $sendWelcome) {
						$te = TemplateEngine::get();
						$te->setVar('user', $user);
						[$subject, $message, $htmlmessage] = templateToMail($te, 'register.tpl');
						HookManager::get()->handle('send_mail', [$user->getEmail(), $subject, $message, $htmlmessage]);
					}

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
					$keys['disabledreason'] = 'setDisabledReason';
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
			$key = (new APIKey($this->getContextKey('db')))->setKey(TRUE)->setUserID($user->getID())->setCreated(time());
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

					$te = TemplateEngine::get();
					$te->setVar('user', $user);
					$te->setVar('apikey', $key);
					$template = $isCreate ? 'apikey/create.tpl' : 'apikey/update.tpl';
					[$subject, $message, $htmlmessage] = templateToMail($te, $template);
					HookManager::get()->handle('send_mail', [$user->getEmail(), $subject, $message, $htmlmessage]);
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

			$te = TemplateEngine::get();
			$te->setVar('user', $user);
			$te->setVar('apikey', $key);
			$template = 'apikey/delete.tpl';
			[$subject, $message, $htmlmessage] = templateToMail($te, $template);
			HookManager::get()->handle('send_mail', [$user->getEmail(), $subject, $message, $htmlmessage]);

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
				} else {
					$this->getContextKey('response')->data($k);
				}

				$te = TemplateEngine::get();
				$te->setVar('user', $user);
				$te->setVar('twofactorkey', $key);
				if ($isCreate) {
					$template = '2fakey/create.tpl';
					[$subject, $message, $htmlmessage] = templateToMail($te, $template);
					HookManager::get()->handle('send_mail', [$user->getEmail(), $subject, $message, $htmlmessage]);
				} else {
					// Doesn't make sense to send this mail, as only the
					// description can change, we won't show the 2FA Secret in
					// the mails...
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

			$te = TemplateEngine::get();
			$te->setVar('user', $user);
			$te->setVar('2fakey', $key);
			$template = '2fakey/delete.tpl';
			[$subject, $message, $htmlmessage] = templateToMail($te, $template);
			HookManager::get()->handle('send_mail', [$user->getEmail(), $subject, $message, $htmlmessage]);

			return TRUE;
		}
	}

	class UserIDUserAdmin extends UserAdmin {
		public function check() {
			parent::check();
			list($userid) = func_get_args();

			$user = $this->getContextKey('user');

			if ($userid != 'self' && $userid != $user->getID()) {
				$this->checkPermissions(['manage_users']);
			}
		}
	}

	$router->get('/users', new class extends UserAdmin {
		function run() {
			$this->checkPermissions(['user_read']);

			return $this->listUsers();
		}
	});

	$router->addRoute('(POST)', '/users/([0-9]+)/resendwelcome', new class extends UserAdmin {
		function post($userid) {
			$this->checkPermissions(['manage_users']);
			$user = $this->getUserFromParam($userid);

			if (!$user->isUnVerified()) {
				$this->getContextKey('response')->sendError('User has already completed registration.');
			}

			$te = TemplateEngine::get();
			$te->setVar('user', $user);
			[$subject, $message, $htmlmessage] = templateToMail($te, 'register.tpl');
			HookManager::get()->handle('send_mail', [$user->getEmail(), $subject, $message, $htmlmessage]);

			$this->getContextKey('response')->data(['success' => 'Registration email resent.']);

			return TRUE;
		}
	});

	$router->addRoute('(GET|POST|DELETE)', '/users/(self|[0-9]+)', new class extends UserIDUserAdmin {
		function get($userid) {
			$this->checkPermissions(['user_read']);
			$user = $this->getUserFromParam($userid);

			return $this->getUserData($user);
		}

		function post($userid) {
			$this->checkPermissions(['user_write']);
			$user = $this->getUserFromParam($userid);

			return $this->updateUser($user);
		}

		function delete($userid) {
			$this->checkPermissions(['user_write']);
			$user = $this->getUserFromParam($userid);

			return $this->deleteUser($user);
		}
	});

	$router->addRoute('(GET|POST)', '/users/(self|[0-9]+)/keys', new class extends UserIDUserAdmin {
		function get($userid) {
			$this->checkPermissions(['user_read']);
			$user = $this->getUserFromParam($userid);

			return $this->getAPIKeys($user);
		}

		function post($userid) {
			$this->checkPermissions(['user_write']);
			$user = $this->getUserFromParam($userid);

			return $this->createAPIKey($user);
		}
	});

	$router->addRoute('(GET|POST|DELETE)', '/users/(self|[0-9]+)/keys/([^/]+)', new class extends UserIDUserAdmin {
		function get($userid, $keyid) {
			$this->checkPermissions(['user_read']);
			$user = $this->getUserFromParam($userid);
			$key = $this->getKeyFromParam($userid, $keyid);

			return $this->getAPIKey($user, $key);
		}

		function post($userid, $keyid) {
			$this->checkPermissions(['user_write']);
			$user = $this->getUserFromParam($userid);
			$key = $this->getKeyFromParam($userid, $keyid);

			return $this->updateAPIKey($user, $key);
		}

		function delete($userid, $keyid) {
			$this->checkPermissions(['user_write']);
			$user = $this->getUserFromParam($userid);
			$key = $this->getKeyFromParam($userid, $keyid);

			return $this->deleteAPIKey($user, $key);
		}
	});

	$router->addRoute('(GET|POST)', '/users/(self|[0-9]+)/2fa', new class extends UserIDUserAdmin {
		function get($userid) {
			$this->checkPermissions(['user_read']);
			$user = $this->getUserFromParam($userid);

			return $this->get2FAKeys($user);
		}

		function post($userid) {
			$this->checkPermissions(['user_write']);
			$user = $this->getUserFromParam($userid);

			return $this->create2FAKey($user);
		}
	});

	$router->addRoute('(GET|POST|DELETE)', '/users/(self|[0-9]+)/2fa/([0-9]+)', new class extends UserIDUserAdmin {
		function get($userid, $secretid) {
			$this->checkPermissions(['user_read']);
			$user = $this->getUserFromParam($userid);
			$key = $this->get2FAKeyFromParam($userid, $secretid);

			return $this->get2FAKey($user, $key);
		}

		function post($userid, $secretid) {
			$this->checkPermissions(['user_write']);
			$user = $this->getUserFromParam($userid);
			$key = $this->get2FAKeyFromParam($userid, $secretid);

			return $this->update2FAKey($user, $key);
		}

		function delete($userid, $secretid) {
			$this->checkPermissions(['user_write']);
			$user = $this->getUserFromParam($userid);
			$key = $this->get2FAKeyFromParam($userid, $secretid);

			return $this->delete2FAKey($user, $key);
		}
	});

	$router->post('/users/(self|[0-9]+)/2fa/([0-9]+)/verify', new class extends UserIDUserAdmin {
		function run($userid, $secretid) {
			$this->checkPermissions(['user_write']);
			$user = $this->getUserFromParam($userid);
			$key = $this->get2FAKeyFromParam($userid, $secretid);

			return $this->verify2FAKey($user, $key);
		}
	});

	$router->post('/users/create', new class extends UserAdmin {
		function run() {
			$this->checkPermissions(['manage_users', 'user_write']);

			return $this->createUser();
		}
	});
