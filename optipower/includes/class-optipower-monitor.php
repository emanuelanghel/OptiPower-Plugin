<?php
if (! defined('ABSPATH')) {
	exit;
}

class OptiPower_Monitor {
	const DEBUG_TRANSIENT_KEY = 'optipower_monitor_debug';

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
			$this->update_debug(array(
				'reason' => 'monitor_disabled',
			));
			return;
		}

		if (! self::instrumentation_available()) {
			$this->update_debug(array(
				'reason' => 'savequeries_disabled',
			));
			return;
		}

		global $wpdb;
		if (! isset($wpdb->queries) || ! is_array($wpdb->queries)) {
			$this->update_debug(array(
				'reason'        => 'wpdb_queries_missing',
				'queries_seen'  => 0,
				'captured_logs' => 0,
			));
			return;
		}

		$threshold_ms = (float) OptiPower_Settings::get('slow_query_threshold_ms', 100);
		$request_uri  = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
		$captured     = 0;
		$failed       = 0;
		$queries_seen = count($wpdb->queries);
		$queries      = $wpdb->queries;

		foreach ($queries as $query_data) {
			if (! is_array($query_data) || ! isset($query_data[0], $query_data[1])) {
				continue;
			}

			$query       = (string) $query_data[0];
			$duration_ms = ((float) $query_data[1]) * 1000;
			$table_name  = OptiPower_DB::table_name();

			if (stripos($query, "insert into {$table_name}") !== false) {
				continue;
			}

			if ($duration_ms < $threshold_ms) {
				continue;
			}

			$source_info = $this->infer_source($query_data[2] ?? '');
			$insight     = $this->ai_service->analyze($query, $duration_ms);

			$ok = OptiPower_DB::insert_log(array(
				'query_hash'     => hash('sha256', preg_replace('/\s+/', ' ', trim($query))),
				'query_sample'   => substr($query, 0, 1500),
				'duration_ms'    => round($duration_ms, 3),
				'request_uri'    => $request_uri,
				'source_type'    => $source_info['type'],
				'source_hint'    => $source_info['hint'],
				'severity'       => $insight['severity'],
				'recommendation' => $insight['recommendation'],
			));
			if ($ok) {
				$captured++;
			} else {
				$failed++;
			}
		}

		$this->update_debug(array(
			'reason'         => 'ok',
			'queries_seen'   => $queries_seen,
			'captured_logs'  => $captured,
			'insert_failures'=> $failed,
			'db_last_error'  => OptiPower_DB::get_last_error(),
			'threshold_ms'   => $threshold_ms,
			'table_exists'   => OptiPower_DB::table_exists() ? 1 : 0,
		));
	}

	public static function get_debug_state() {
		$debug = get_transient(self::DEBUG_TRANSIENT_KEY);
		return is_array($debug) ? $debug : array(
			'reason'         => 'no_data_yet',
			'queries_seen'   => 0,
			'captured_logs'  => 0,
			'insert_failures'=> 0,
			'db_last_error'  => '',
			'threshold_ms'   => (float) OptiPower_Settings::get('slow_query_threshold_ms', 100),
			'table_exists'   => OptiPower_DB::table_exists() ? 1 : 0,
			'last_run'       => '',
			'savequeries'    => self::instrumentation_available() ? 1 : 0,
			'monitor_enabled'=> OptiPower_Settings::get('enabled', 1) ? 1 : 0,
		);
	}

	private function update_debug($extra) {
		$base = array(
			'last_run'        => current_time('mysql'),
			'savequeries'     => self::instrumentation_available() ? 1 : 0,
			'monitor_enabled' => OptiPower_Settings::get('enabled', 1) ? 1 : 0,
			'table_exists'    => OptiPower_DB::table_exists() ? 1 : 0,
			'threshold_ms'    => (float) OptiPower_Settings::get('slow_query_threshold_ms', 100),
		);
		set_transient(self::DEBUG_TRANSIENT_KEY, array_merge($base, is_array($extra) ? $extra : array()), DAY_IN_SECONDS);
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
