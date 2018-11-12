<?php

	use shanemcc\phpdb\ValidationFailed;

	$router->get('/articles', new class extends RouterMethod {
		function run() {
			$articles = Article::getSearch($this->getContextKey('db'));
			$articles->where('visiblefrom', time(), '<');
			$articles->whereOr([['visibleuntil', time(), '>'], ['visibleuntil', '0', '<']]);
			$articles->order('visiblefrom', 'DESC');
			$articles->limit(5);

			$articles = $articles->find('id');

			$this->getContextKey('response')->data($articles);

			return true;
		}
	});
