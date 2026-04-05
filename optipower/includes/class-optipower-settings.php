<?php
if (! defined('ABSPATH')) {
	exit;
}

class OptiPower_Settings {
	const OPTION_KEY = 'optipower_settings';

	public static function defaults() {
		return array(
			'enabled'                 => 1,
			'slow_query_threshold_ms' => 100,
			'retention_days'          => 14,
			'max_log_rows'            => 5000,
			'cleanup_on_uninstall'    => 0,
			'minify_css'              => 1,
			'minify_js'               => 1,
			'defer_js'                => 1,
			'compatibility_profile'   => 'none',
			'css_exclusions'          => '',
			'js_exclusions'           => '',
			'remove_asset_version'    => 0,
			'cache_enabled'           => 0,
			'cache_ttl'               => 300,
			'cache_logged_in_users'   => 0,
			'browser_cache_headers'   => 1,
			'cache_uri_exclusions'    => '/wp-admin,/wp-login.php,/cart,/checkout,/my-account',
			'cache_cookie_exclusions' => 'wordpress_logged_in_,comment_author_,woocommerce_items_in_cart,woocommerce_cart_hash,wp_woocommerce_session_,trp_language',
			'cache_query_exclusions'  => 'preview,customize_changeset_uuid,trp-edit-translation,lang,add-to-cart,wc-ajax',
			'image_lazy_load'         => 1,
			'image_convert_webp'      => 0,
			'image_jpeg_quality'      => 82,
			'ai_enabled'              => 0,
			'ai_provider'             => 'openai',
			'ai_model'                => 'gpt-4.1-mini',
			'ai_api_key'              => '',
			'ai_cache_hours'          => 24,
			'ai_max_daily_requests'   => 100,
			'ai_redact_literals'      => 1,
		);
	}

	public static function get_all() {
		$values = get_option(self::OPTION_KEY, array());
		return wp_parse_args(is_array($values) ? $values : array(), self::defaults());
	}

	public static function get($key, $fallback = null) {
		$settings = self::get_all();
		return array_key_exists($key, $settings) ? $settings[$key] : $fallback;
	}

	public static function sanitize($input) {
		$current  = self::get_all();
		$input    = is_array($input) ? $input : array();

		$int_or_current = static function ($key, $min, $max = null) use ($input, $current) {
			$value = array_key_exists($key, $input) ? absint($input[$key]) : absint($current[$key] ?? 0);
			$value = max($min, $value);
			if ($max !== null) {
				$value = min($max, $value);
			}
			return $value;
		};

		$bool_or_current = static function ($key) use ($input, $current) {
			if (array_key_exists($key, $input)) {
				return empty($input[$key]) ? 0 : 1;
			}
			return empty($current[$key]) ? 0 : 1;
		};

		$string_or_current = static function ($key) use ($input, $current) {
			if (array_key_exists($key, $input)) {
				return sanitize_text_field((string) $input[$key]);
			}
			return sanitize_text_field((string) ($current[$key] ?? ''));
		};

		$csv_or_current = static function ($key) use ($input, $current) {
			$value = array_key_exists($key, $input) ? (string) $input[$key] : (string) ($current[$key] ?? '');
			$parts = preg_split('/[\r\n,]+/', $value);
			$parts = is_array($parts) ? $parts : array();
			$clean = array();
			foreach ($parts as $part) {
				$item = trim((string) $part);
				$item = strtolower($item);
				$item = preg_replace('/[^a-z0-9_\-\.\/\?=]+/', '', $item);
				if ($item !== '') {
					$clean[] = $item;
				}
			}
			$clean = array_values(array_unique($clean));
			return implode(',', $clean);
		};

		$secret_or_current = static function ($key) use ($input, $current) {
			if (array_key_exists($key, $input)) {
				$raw = trim((string) $input[$key]);
				if ($raw !== '') {
					return sanitize_text_field($raw);
				}
			}
			return sanitize_text_field((string) ($current[$key] ?? ''));
		};

		return array(
			'enabled'                 => $bool_or_current('enabled'),
			'slow_query_threshold_ms' => $int_or_current('slow_query_threshold_ms', 10),
			'retention_days'          => $int_or_current('retention_days', 1),
			'max_log_rows'            => $int_or_current('max_log_rows', 100),
			'cleanup_on_uninstall'    => $bool_or_current('cleanup_on_uninstall'),
			'minify_css'              => $bool_or_current('minify_css'),
			'minify_js'               => $bool_or_current('minify_js'),
			'defer_js'                => $bool_or_current('defer_js'),
			'compatibility_profile'   => in_array($string_or_current('compatibility_profile'), array('none', 'translatepress', 'woocommerce'), true) ? $string_or_current('compatibility_profile') : 'none',
			'css_exclusions'          => $csv_or_current('css_exclusions'),
			'js_exclusions'           => $csv_or_current('js_exclusions'),
			'remove_asset_version'    => $bool_or_current('remove_asset_version'),
			'cache_enabled'           => $bool_or_current('cache_enabled'),
			'cache_ttl'               => $int_or_current('cache_ttl', 60),
			'cache_logged_in_users'   => $bool_or_current('cache_logged_in_users'),
			'browser_cache_headers'   => $bool_or_current('browser_cache_headers'),
			'cache_uri_exclusions'    => $csv_or_current('cache_uri_exclusions'),
			'cache_cookie_exclusions' => $csv_or_current('cache_cookie_exclusions'),
			'cache_query_exclusions'  => $csv_or_current('cache_query_exclusions'),
			'image_lazy_load'         => $bool_or_current('image_lazy_load'),
			'image_convert_webp'      => $bool_or_current('image_convert_webp'),
			'image_jpeg_quality'      => $int_or_current('image_jpeg_quality', 40, 100),
			'ai_enabled'              => $bool_or_current('ai_enabled'),
			'ai_provider'             => $string_or_current('ai_provider'),
			'ai_model'                => $string_or_current('ai_model'),
			'ai_api_key'              => $secret_or_current('ai_api_key'),
			'ai_cache_hours'          => $int_or_current('ai_cache_hours', 1, 168),
			'ai_max_daily_requests'   => $int_or_current('ai_max_daily_requests', 1, 5000),
			'ai_redact_literals'      => $bool_or_current('ai_redact_literals'),
		);
	}
}

