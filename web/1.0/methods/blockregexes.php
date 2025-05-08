<?php

	use shanemcc\phpdb\ValidationFailed;

	class BlockRegexAdmin extends RouterMethod {
		public function check() {
			$user = $this->getContextKey('user');
			if ($user == NULL) {
				throw new RouterMethod_NeedsAuthentication();
			}

			$this->checkPermissions(['manage_blocks']);

			if ($this->hasContextKey('key') && !$this->getContextKey('key')->getAdminFeatures()) {
				throw new RouterMethod_AccessDenied();
			}
		}

		protected function getBlockRegexFromParam($id) {
			$blockRegex = BlockRegex::load($this->getContextKey('db'), $id);
			if ($blockRegex === FALSE) {
				$this->getContextKey('response')->sendError('Unknown blockregex id: ' . $id);
			}

			return $blockRegex;
		}

		protected function createBlockRegex() {
			$blockRegex = new BlockRegex($this->getContextKey('db'));
			$blockRegex->setCreated(time());
			return $this->updateBlockRegex($blockRegex, true);
		}

		protected function updateBlockRegex($blockRegex, $isCreate = false) {
			$data = $this->getContextKey('data');
			if (!isset($data['data']) || !is_array($data['data'])) {
				$this->getContextKey('response')->sendError('No data provided for update.');
			}

			if ($blockRegex !== FALSE) {
				$this->doUpdateBlockRegex($blockRegex, $data['data']);

				try {
					$blockRegex->validate();
				} catch (ValidationFailed $ex) {
					if ($isCreate) {
						$this->getContextKey('response')->sendError('Error creating blockRegex.', $ex->getMessage());
					} else {
						$this->getContextKey('response')->sendError('Error updating blockRegex: ' . $blockRegex->getID(), $ex->getMessage());
					}
				}

				$a = $blockRegex->toArray();
				$a['updated'] = $blockRegex->save();
				$a['id'] = $blockRegex->getID();

				if (!$a['updated']) {
					if ($isCreate) {
						$this->getContextKey('response')->sendError('Unknown error creating blockRegex.');
					} else {
						$this->getContextKey('response')->sendError('Error updating blockRegex: ' . $blockRegex->getID());
					}
				} else {
					$this->getContextKey('response')->data($a);
				}

				return true;
			}

			return false;
		}

		private function doUpdateBlockRegex($blockRegex, $data) {
			$keys = array('regex' => 'setRegex',
			              'comment' => 'setComment',
			              'signup_name' => 'setSignupName',
			              'signup_email' => 'setSignupEmail',
			              'domain_name' => 'setDomainName',
			             );

			foreach ($keys as $k => $f) {
				if (array_key_exists($k, $data)) {
					$blockRegex->$f($data[$k]);
				}
			}

			return $blockRegex;
		}

		protected function deleteBlockRegex($blockRegex) {
			if ($blockRegex !== FALSE) {
				$this->getContextKey('response')->data(['deleted' => $blockRegex->delete()]);
				return TRUE;
			}

			return FALSE;
		}
	}

	$router->addRoute('(GET|POST)', '/admin/blockRegexes', new class extends BlockRegexAdmin {
		function get() {
			$blockRegexes = BlockRegex::getSearch($this->getContextKey('db'));
			$blockRegexes = $blockRegexes->find('id');

			$this->getContextKey('response')->data($blockRegexes);

			return true;
		}

		function post() {
			return $this->createBlockRegex();
		}
	});

	$router->addRoute('(GET|POST|DELETE)', '/admin/blockRegexes/([0-9]+)', new class extends BlockRegexAdmin {
		function get($blockRegexid) {
			$blockRegex = $this->getBlockRegexFromParam($blockRegexid);

			$this->getContextKey('response')->data($blockRegex->toArray());

			return true;
		}

		function post($blockRegexid) {
			$blockRegex = $this->getBlockRegexFromParam($blockRegexid);

			return $this->updateBlockRegex($blockRegex);
		}

		function delete($blockRegexid) {
			$blockRegex = $this->getBlockRegexFromParam($blockRegexid);

			return $this->deleteBlockRegex($blockRegex);
		}
	});
