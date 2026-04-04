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
			'remove_asset_version'    => 0,
			'cache_enabled'           => 0,
			'cache_ttl'               => 300,
			'cache_logged_in_users'   => 0,
			'browser_cache_headers'   => 1,
			'image_lazy_load'         => 1,
			'image_convert_webp'      => 0,
			'image_jpeg_quality'      => 82,
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
		$defaults = self::defaults();
		$input    = is_array($input) ? $input : array();

		return array(
			'enabled'                 => empty($input['enabled']) ? 0 : 1,
			'slow_query_threshold_ms' => max(10, absint($input['slow_query_threshold_ms'] ?? $defaults['slow_query_threshold_ms'])),
			'retention_days'          => max(1, absint($input['retention_days'] ?? $defaults['retention_days'])),
			'max_log_rows'            => max(100, absint($input['max_log_rows'] ?? $defaults['max_log_rows'])),
			'cleanup_on_uninstall'    => empty($input['cleanup_on_uninstall']) ? 0 : 1,
			'minify_css'              => empty($input['minify_css']) ? 0 : 1,
			'minify_js'               => empty($input['minify_js']) ? 0 : 1,
			'defer_js'                => empty($input['defer_js']) ? 0 : 1,
			'remove_asset_version'    => empty($input['remove_asset_version']) ? 0 : 1,
			'cache_enabled'           => empty($input['cache_enabled']) ? 0 : 1,
			'cache_ttl'               => max(60, absint($input['cache_ttl'] ?? $defaults['cache_ttl'])),
			'cache_logged_in_users'   => empty($input['cache_logged_in_users']) ? 0 : 1,
			'browser_cache_headers'   => empty($input['browser_cache_headers']) ? 0 : 1,
			'image_lazy_load'         => empty($input['image_lazy_load']) ? 0 : 1,
			'image_convert_webp'      => empty($input['image_convert_webp']) ? 0 : 1,
			'image_jpeg_quality'      => min(100, max(40, absint($input['image_jpeg_quality'] ?? $defaults['image_jpeg_quality']))),
		);
	}
}

