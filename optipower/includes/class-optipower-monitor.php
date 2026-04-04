<?php
if (! defined('ABSPATH')) {
	exit;
}

class OptiPower_Monitor {
	private $ai_service;

	public function __construct($ai_service = null) {
		$this->ai_service = $ai_service instanceof OptiPower_AI_Service ? $ai_service : new OptiPower_Rules_AI_Service();
	}

	public function register() {
		add_action('shutdown', array($this, 'capture_slow_queries'), 9999);
	}

	public static function instrumentation_available() {
		return defined('SAVEQUERIES') && SAVEQUERIES;
	}

	public function capture_slow_queries() {
		if (! OptiPower_Settings::get('enabled', 1)) {
			return;
		}

		if (! self::instrumentation_available()) {
			return;
		}

		global $wpdb;
		if (! isset($wpdb->queries) || ! is_array($wpdb->queries)) {
			return;
		}

		$threshold_ms = (float) OptiPower_Settings::get('slow_query_threshold_ms', 100);
		$request_uri  = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';

		foreach ($wpdb->queries as $query_data) {
			if (! is_array($query_data) || ! isset($query_data[0], $query_data[1])) {
				continue;
			}

			$query       = (string) $query_data[0];
			$duration_ms = ((float) $query_data[1]) * 1000;

			if ($duration_ms < $threshold_ms) {
				continue;
			}

			$source_info = $this->infer_source($query_data[2] ?? '');
			$insight     = $this->ai_service->analyze($query, $duration_ms);

			OptiPower_DB::insert_log(array(
				'query_hash'     => hash('sha256', preg_replace('/\s+/', ' ', trim($query))),
				'query_sample'   => substr($query, 0, 1500),
				'duration_ms'    => round($duration_ms, 3),
				'request_uri'    => $request_uri,
				'source_type'    => $source_info['type'],
				'source_hint'    => $source_info['hint'],
				'severity'       => $insight['severity'],
				'recommendation' => $insight['recommendation'],
			));
		}
	}

	private function infer_source($caller) {
		$caller = is_string($caller) ? $caller : '';
		$type   = 'unknown';
		$hint   = 'N/A';

		if (preg_match('#wp-content/plugins/([^/]+)/#', $caller, $m)) {
			$type = 'plugin';
			$hint = $m[1];
		} elseif (preg_match('#wp-content/themes/([^/]+)/#', $caller, $m)) {
			$type = 'theme';
			$hint = $m[1];
		} elseif (strpos($caller, 'wp-includes') !== false || strpos($caller, 'wp-admin') !== false) {
			$type = 'core';
			$hint = 'WordPress Core';
		}

		return array(
			'type' => $type,
			'hint' => $hint,
		);
	}
}
