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
		);
	}
}

