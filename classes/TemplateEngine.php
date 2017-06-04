<?php

	class TemplateEngine {
		private $twig;
		private $directories = [];
		private $vars = [];

		public function __construct() { }

		public function setConfig($config) {
			$loader = new Twig_Loader_Filesystem();
			$themes = [];
			if (isset($config['theme'])) {
				$themes = is_array($config['theme']) ? $config['theme'] : [$config['theme']];
			}
			foreach (array_unique(array_merge($themes, ['default'])) as $theme) {
				$path = $config['dir'] . '/' . $theme;
				if (file_exists($path)) {
					$loader->addPath($path, $theme);
					$loader->addPath($path, '__main__');
					$this->directories[] = $path;
				}
			}

			$twig = new Twig_Environment($loader, array(
				'cache' => $config['cache'],
				'auto_reload' => true,
				'debug' => true,
				'autoescape' => 'html',
			));

			$twig->addExtension(new Twig_Extension_Debug());

			$twig->addFunction(new Twig_Function('url', function ($path) { return $this->getURL($path); }));
			$twig->addFunction(new Twig_Function('getVar', function ($var) { return $this->getVar($var); }));

			$twig->addFilter(new Twig_Filter('yesno', function($input) {
				return parseBool($input) ? "Yes" : "No";
			}));

			$twig->addFilter(new Twig_Filter('date', function($input) {
				return date('r', $input);
			}));

			$this->twig = $twig;

			return $this;
		}

		public function getTwig() {
			return $this->twig;
		}

		public function setVar($var, $value) {
			$this->vars[$var] = $value;
			return $this;
		}

		public function getVar($var) {
			return array_key_exists($var, $this->vars) ? $this->vars[$var] : '';
		}

		public function render($template) {
			return $this->twig->render($template, $this->vars);
		}

		public function renderBlock($template, $block) {
			$template = $this->twig->load($template);
			return $template->hasBlock($block) ? $template->renderBlock($block, $this->vars) : NULL;
		}

		public function getFile($file) {
			$file = str_replace('../', '', $file);

			foreach ($this->directories as $dir) {
				$path = $dir . '/' . $file;
				if (file_exists($path)) {
					return $path;
				}
			}

			return FALSE;
		}

		private static $instance = null;

		public static function get() {
			if (self::$instance == null) {
				self::$instance = new TemplateEngine();
			}

			return self::$instance;
		}

		public static function getClone() {
			$source = self::get();
			$new = new TemplateEngine();

			$new->twig = $source->twig;
			$new->directories = $source->directories;
			$new->vars = $source->vars;

			return $new;
		}
	}
