<?php

	use shanemcc\phpdb\ValidationFailed;

	$router->get('/articles', new class extends RouterMethod {
		function run() {
			$articles = Article::getSearch($this->getContextKey('db'));
			$articles->where('visiblefrom', time(), '<');
			$articles->whereOr([['visibleuntil', time(), '>'], ['visibleuntil', '0', '<']]);
			$articles->order('visiblefrom', 'DESC');
			$articles->limit(3);

			$articles = $articles->find('id');

			$this->getContextKey('response')->data($articles);

			return true;
		}
	});


	class ArticleAdmin extends RouterMethod {
		public function check() {
			$user = $this->getContextKey('user');
			if ($user == NULL) {
				throw new RouterMethod_NeedsAuthentication();
			}

			$this->checkPermissions(['manage_articles']);

			if ($this->hasContextKey('key') && !$this->hasContextKey('key')->getAdminFeatures()) {
				throw new RouterMethod_AccessDenied();
			}
		}

		protected function getArticleFromParam($articleid) {
			$article = Article::load($this->getContextKey('db'), $articleid);
			if ($article === FALSE) {
				$this->getContextKey('response')->sendError('Unknown articleid: ' . $articleid);
			}

			return $article;
		}

		protected function createArticle() {
			$article = new Article($this->getContextKey('db'));
			$article->setCreated(time());
			return $this->updateArticle($article, true);
		}

		protected function updateArticle($article, $isCreate = false) {
			$data = $this->getContextKey('data');
			if (!isset($data['data']) || !is_array($data['data'])) {
				$this->getContextKey('response')->sendError('No data provided for update.');
			}

			if ($article !== FALSE) {
				$this->doUpdateArticle($article, $data['data']);

				try {
					$article->validate();
				} catch (ValidationFailed $ex) {
					if ($isCreate) {
						$this->getContextKey('response')->sendError('Error creating article.', $ex->getMessage());
					} else {
						$this->getContextKey('response')->sendError('Error updating article: ' . $article->getID(), $ex->getMessage());
					}
				}

				$a = $article->toArray();
				$a['updated'] = $article->save();
				$a['id'] = $article->getID();

				if (!$a['updated']) {
					if ($isCreate) {
						$this->getContextKey('response')->sendError('Unknown error creating article.');
					} else {
						$this->getContextKey('response')->sendError('Error updating article: ' . $article->getID());
					}
				} else {
					$this->getContextKey('response')->data($a);
				}

				return true;
			}

			return false;
		}

		private function doUpdateArticle($article, $data) {
			$keys = array('title' => 'setTitle',
			              'content' => 'setContent',
			              'contentfull' => 'setContentFull',
			              'visiblefrom' => 'setVisibleFrom',
			              'visibleuntil' => 'setVisibleUntil',
			             );

			foreach ($keys as $k => $f) {
				if (array_key_exists($k, $data)) {
					$article->$f($data[$k]);
				}
			}

			return $article;
		}

		protected function deleteArticle($article) {
			if ($article !== FALSE) {
				$this->getContextKey('response')->data(['deleted' => $article->delete()]);
				return TRUE;
			}

			return FALSE;
		}
	}

	$router->addRoute('(GET|POST)', '/admin/articles', new class extends ArticleAdmin {
		function get() {
			$articles = Article::getSearch($this->getContextKey('db'));
			$articles = $articles->find('id');

			$this->getContextKey('response')->data($articles);

			return true;
		}

		function post() {
			return $this->createArticle();
		}
	});

	$router->addRoute('(GET|POST|DELETE)', '/admin/articles/([0-9]+)', new class extends ArticleAdmin {
		function get($articleid) {
			$article = $this->getArticleFromParam($articleid);

			$this->getContextKey('response')->data($article->toArray());

			return true;
		}

		function post($articleid) {
			$article = $this->getArticleFromParam($articleid);

			return $this->updateArticle($article);
		}

		function delete($articleid) {
			$article = $this->getArticleFromParam($articleid);

			return $this->deleteArticle($article);
		}
	});
