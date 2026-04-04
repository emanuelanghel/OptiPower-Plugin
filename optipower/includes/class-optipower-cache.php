<?php
if (! defined('ABSPATH')) {
	exit;
}

class OptiPower_Cache {
	public function register() {
		if (is_admin()) {
			return;
		}

		add_action('template_redirect', array($this, 'maybe_serve_cache'), 0);
		add_action('template_redirect', array($this, 'start_buffer'), 1);
		add_action('send_headers', array($this, 'send_cache_headers'));
	}

	public static function register_purge_hooks() {
		add_action('save_post', array(__CLASS__, 'purge_all'));
		add_action('deleted_post', array(__CLASS__, 'purge_all'));
		add_action('comment_post', array(__CLASS__, 'purge_all'));
		add_action('wp_set_comment_status', array(__CLASS__, 'purge_all'));
	}

	public function maybe_serve_cache() {
		if (! $this->is_page_cache_enabled() || ! $this->is_cacheable_request()) {
			return;
		}

		$cache_file = $this->get_cache_file();
		$ttl        = (int) OptiPower_Settings::get('cache_ttl', 300);
		if (file_exists($cache_file) && (time() - filemtime($cache_file) <= $ttl)) {
			header('X-OptiPower-Cache: HIT');
			readfile($cache_file);
			exit;
		}
	}

	public function start_buffer() {
		if (! $this->is_page_cache_enabled() || ! $this->is_cacheable_request()) {
			return;
		}

		ob_start(array($this, 'store_buffer'));
	}

	public function store_buffer($html) {
		if (! is_string($html) || $html === '') {
			return $html;
		}

		$cache_file = $this->get_cache_file();
		$cache_dir  = dirname($cache_file);
		wp_mkdir_p($cache_dir);

		if (is_dir($cache_dir) && is_writable($cache_dir)) {
			file_put_contents($cache_file, $html);
			header('X-OptiPower-Cache: MISS');
		}

		return $html;
	}

	public function send_cache_headers() {
		if (is_admin() || ! OptiPower_Settings::get('browser_cache_headers', 1)) {
			return;
		}

		$ttl = (int) OptiPower_Settings::get('cache_ttl', 300);
		header('Cache-Control: public, max-age=' . max(60, $ttl));
	}

	private function is_page_cache_enabled() {
		return (bool) OptiPower_Settings::get('cache_enabled', 0);
	}

	private function is_cacheable_request() {
		if (defined('DONOTCACHEPAGE') && DONOTCACHEPAGE) {
			return false;
		}

		if (! isset($_SERVER['REQUEST_METHOD']) || strtoupper((string) $_SERVER['REQUEST_METHOD']) !== 'GET') {
			return false;
		}

		if (is_feed() || is_preview() || is_404()) {
			return false;
		}

		if (is_user_logged_in() && ! OptiPower_Settings::get('cache_logged_in_users', 0)) {
			return false;
		}

		return true;
	}

	private function get_cache_file() {
		$request_uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '/';
		$key         = md5(home_url($request_uri));
		return WP_CONTENT_DIR . '/cache/optipower/pages/' . $key . '.html';
	}

	public static function purge_all() {
		$base = WP_CONTENT_DIR . '/cache/optipower/pages';
		if (! is_dir($base)) {
			return;
		}

		$files = glob($base . '/*.html');
		if (! is_array($files)) {
			return;
		}

		foreach ($files as $file) {
			if (is_file($file)) {
				unlink($file);
			}
		}
	}
}

