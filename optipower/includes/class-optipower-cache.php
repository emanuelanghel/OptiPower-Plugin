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

		if ($this->request_matches_uri_exclusions()) {
			return false;
		}

		if ($this->request_matches_cookie_exclusions()) {
			return false;
		}

		if ($this->request_matches_query_exclusions()) {
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

	private function request_matches_uri_exclusions() {
		$request_uri = isset($_SERVER['REQUEST_URI']) ? strtolower((string) wp_unslash($_SERVER['REQUEST_URI'])) : '/';
		foreach ($this->get_uri_exclusions() as $token) {
			if ($token !== '' && strpos($request_uri, $token) !== false) {
				return true;
			}
		}
		return false;
	}

	private function request_matches_cookie_exclusions() {
		if (empty($_COOKIE) || ! is_array($_COOKIE)) {
			return false;
		}

		$cookie_names = array_map(static function ($name) {
			return strtolower((string) $name);
		}, array_keys($_COOKIE));

		foreach ($this->get_cookie_exclusions() as $token) {
			foreach ($cookie_names as $cookie_name) {
				if ($token !== '' && strpos($cookie_name, $token) !== false) {
					return true;
				}
			}
		}
		return false;
	}

	private function request_matches_query_exclusions() {
		if (empty($_GET) || ! is_array($_GET)) {
			return false;
		}

		$query_keys = array_map(static function ($key) {
			return strtolower((string) $key);
		}, array_keys($_GET));

		foreach ($this->get_query_exclusions() as $token) {
			foreach ($query_keys as $query_key) {
				if ($token !== '' && $query_key === $token) {
					return true;
				}
			}
		}
		return false;
	}

	private function get_uri_exclusions() {
		$defaults = $this->get_profile_uri_exclusions();
		$raw = (string) OptiPower_Settings::get('cache_uri_exclusions', '');
		$user = $this->parse_csv_tokens($raw);
		return array_values(array_unique(array_merge($defaults, $user)));
	}

	private function get_cookie_exclusions() {
		$defaults = $this->get_profile_cookie_exclusions();
		$raw = (string) OptiPower_Settings::get('cache_cookie_exclusions', '');
		$user = $this->parse_csv_tokens($raw);
		return array_values(array_unique(array_merge($defaults, $user)));
	}

	private function get_query_exclusions() {
		$defaults = $this->get_profile_query_exclusions();
		$raw = (string) OptiPower_Settings::get('cache_query_exclusions', '');
		$user = $this->parse_csv_tokens($raw);
		return array_values(array_unique(array_merge($defaults, $user)));
	}

	private function get_profile_uri_exclusions() {
		$profile = (string) OptiPower_Settings::get('compatibility_profile', 'none');
		if ($profile === 'woocommerce') {
			return array('/cart', '/checkout', '/my-account');
		}
		return array();
	}

	private function get_profile_cookie_exclusions() {
		$profile = (string) OptiPower_Settings::get('compatibility_profile', 'none');
		if ($profile === 'translatepress') {
			return array('trp_language');
		}
		if ($profile === 'woocommerce') {
			return array('woocommerce_items_in_cart', 'woocommerce_cart_hash', 'wp_woocommerce_session_');
		}
		return array();
	}

	private function get_profile_query_exclusions() {
		$profile = (string) OptiPower_Settings::get('compatibility_profile', 'none');
		if ($profile === 'translatepress') {
			return array('trp-edit-translation', 'lang');
		}
		if ($profile === 'woocommerce') {
			return array('add-to-cart', 'wc-ajax');
		}
		return array();
	}

	private function parse_csv_tokens($value) {
		$parts = preg_split('/[\r\n,]+/', (string) $value);
		$parts = is_array($parts) ? $parts : array();
		$tokens = array();
		foreach ($parts as $part) {
			$item = strtolower(trim((string) $part));
			$item = preg_replace('/[^a-z0-9_\-\.\/\?=]+/', '', $item);
			if ($item !== '') {
				$tokens[] = $item;
			}
		}
		return array_values(array_unique($tokens));
	}
}

