<?php
if (! defined('ABSPATH')) {
	exit;
}

class OptiPower_DB {
	private static $table_exists_cache = null;
	private static $ai_table_exists_cache = null;
	private static $health_table_exists_cache = null;
	private static $last_error = '';

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'optipower_query_logs';
	}

	public static function ai_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'optipower_ai_insights';
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$table           = self::table_name();
		$ai_table        = self::ai_table_name();
		$health_table    = self::health_table_name();
		$sql             = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			query_hash varchar(64) NOT NULL,
			query_sample longtext NOT NULL,
			duration_ms decimal(10,3) NOT NULL,
			request_uri text NOT NULL,
			source_type varchar(20) NOT NULL DEFAULT 'unknown',
			source_hint text NOT NULL,
			severity varchar(10) NOT NULL DEFAULT 'low',
			recommendation text NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY (id),
			KEY query_hash (query_hash),
			KEY duration_ms (duration_ms),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta($sql);
		$ai_sql = "CREATE TABLE {$ai_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			query_hash varchar(64) NOT NULL,
			provider varchar(30) NOT NULL,
			model varchar(100) NOT NULL,
			analysis_json longtext NOT NULL,
			confidence decimal(4,3) NOT NULL DEFAULT 0.000,
			created_at datetime NOT NULL,
			expires_at datetime NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY query_hash (query_hash),
			KEY expires_at (expires_at)
		) {$charset_collate};";
		dbDelta($ai_sql);
		$health_sql = "CREATE TABLE {$health_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			score smallint(3) unsigned NOT NULL,
			components_json text NOT NULL,
			context_json text NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY (id),
			KEY created_at (created_at)
		) {$charset_collate};";
		dbDelta($health_sql);
		self::$table_exists_cache = true;
		self::$ai_table_exists_cache = true;
		self::$health_table_exists_cache = true;
	}

	public static function table_exists() {
		if (self::$table_exists_cache !== null) {
			return self::$table_exists_cache;
		}

		global $wpdb;
		$table   = self::table_name();
		$exists  = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
		self::$table_exists_cache = ($exists === $table);
		return self::$table_exists_cache;
	}

	public static function ensure_tables() {
		if (! self::table_exists() || ! self::ai_table_exists() || ! self::health_table_exists()) {
			self::create_tables();
		}
	}

	public static function ai_table_exists() {
		if (self::$ai_table_exists_cache !== null) {
			return self::$ai_table_exists_cache;
		}

		global $wpdb;
		$table = self::ai_table_name();
		$exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
		self::$ai_table_exists_cache = ($exists === $table);
		return self::$ai_table_exists_cache;
	}

	public static function health_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'optipower_health_scores';
	}

	public static function health_table_exists() {
		if (self::$health_table_exists_cache !== null) {
			return self::$health_table_exists_cache;
		}

		global $wpdb;
		$table = self::health_table_name();
		$exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
		self::$health_table_exists_cache = ($exists === $table);
		return self::$health_table_exists_cache;
	}

	public static function insert_log($data) {
		global $wpdb;
		$table = self::table_name();
		self::ensure_tables();

		$result = $wpdb->insert(
			$table,
			array(
				'query_hash'      => sanitize_text_field($data['query_hash'] ?? ''),
				'query_sample'    => sanitize_textarea_field($data['query_sample'] ?? ''),
				'duration_ms'     => (float) ($data['duration_ms'] ?? 0),
				'request_uri'     => sanitize_text_field($data['request_uri'] ?? ''),
				'source_type'     => sanitize_text_field($data['source_type'] ?? 'unknown'),
				'source_hint'     => sanitize_text_field($data['source_hint'] ?? ''),
				'severity'        => sanitize_text_field($data['severity'] ?? 'low'),
				'recommendation'  => sanitize_text_field($data['recommendation'] ?? ''),
				'created_at'      => current_time('mysql'),
			),
			array('%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s')
		);
		self::$last_error = (string) $wpdb->last_error;
		return $result !== false;
	}

	public static function get_last_error() {
		return self::$last_error;
	}

	public static function get_logs($limit = 50, $min_duration = 0) {
		global $wpdb;
		$table = self::table_name();
		self::ensure_tables();
		$limit = max(1, min(200, absint($limit)));
		$min   = max(0, (float) $min_duration);

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE duration_ms >= %f ORDER BY id DESC LIMIT %d",
				$min,
				$limit
			),
			ARRAY_A
		);
	}

	public static function get_latest_log_by_hash($query_hash) {
		global $wpdb;
		$table = self::table_name();
		self::ensure_tables();

		$query_hash = sanitize_text_field((string) $query_hash);
		if ($query_hash === '') {
			return null;
		}

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE query_hash = %s ORDER BY id DESC LIMIT 1",
				$query_hash
			),
			ARRAY_A
		);
	}

	public static function get_ai_insight($query_hash) {
		global $wpdb;
		$table      = self::ai_table_name();
		self::ensure_tables();
		$query_hash = sanitize_text_field((string) $query_hash);
		if ($query_hash === '') {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE query_hash = %s AND expires_at > NOW() LIMIT 1",
				$query_hash
			),
			ARRAY_A
		);

		if (! is_array($row)) {
			return null;
		}

		$row['analysis'] = json_decode((string) $row['analysis_json'], true);
		return $row;
	}

	public static function save_ai_insight($query_hash, $provider, $model, $analysis, $confidence, $cache_hours) {
		global $wpdb;
		$table = self::ai_table_name();
		self::ensure_tables();

		$query_hash = sanitize_text_field((string) $query_hash);
		if ($query_hash === '') {
			return false;
		}

		$cache_hours = max(1, min(168, absint($cache_hours)));
		$expires_at  = gmdate('Y-m-d H:i:s', time() + ($cache_hours * HOUR_IN_SECONDS));
		$analysis_json = wp_json_encode($analysis);
		if (! is_string($analysis_json)) {
			$analysis_json = '{}';
		}

		$exists_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE query_hash = %s LIMIT 1",
				$query_hash
			)
		);

		$data = array(
			'query_hash'    => $query_hash,
			'provider'      => sanitize_text_field((string) $provider),
			'model'         => sanitize_text_field((string) $model),
			'analysis_json' => $analysis_json,
			'confidence'    => max(0, min(1, (float) $confidence)),
			'created_at'    => current_time('mysql', true),
			'expires_at'    => $expires_at,
		);

		if ($exists_id > 0) {
			return false !== $wpdb->update($table, $data, array('id' => $exists_id));
		}

		return false !== $wpdb->insert($table, $data);
	}

	public static function get_summary() {
		global $wpdb;
		$table = self::table_name();
		self::ensure_tables();

		$row = $wpdb->get_row(
			"SELECT
				COUNT(*) AS total_logs,
				COALESCE(AVG(duration_ms), 0) AS avg_duration_ms,
				COALESCE(MAX(duration_ms), 0) AS max_duration_ms
			FROM {$table}",
			ARRAY_A
		);

		return is_array($row) ? $row : array(
			'total_logs'       => 0,
			'avg_duration_ms'  => 0,
			'max_duration_ms'  => 0,
		);
	}

	public static function cleanup_old_logs($retention_days, $max_rows) {
		global $wpdb;
		$table = self::table_name();
		self::ensure_tables();
		$days  = max(1, absint($retention_days));
		$rows  = max(100, absint($max_rows));

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
				$days
			)
		);

		$total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
		if ($total > $rows) {
			$to_delete = $total - $rows;
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} ORDER BY id ASC LIMIT %d",
					$to_delete
				)
			);
		}
	}

	public static function get_recent_summary($hours = 24) {
		global $wpdb;
		$table = self::table_name();
		self::ensure_tables();
		$hours = max(1, min(720, absint($hours)));

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS total_logs,
					COALESCE(AVG(duration_ms), 0) AS avg_duration_ms,
					COALESCE(MAX(duration_ms), 0) AS max_duration_ms
				FROM {$table}
				WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d HOUR)",
				$hours
			),
			ARRAY_A
		);

		return is_array($row) ? $row : array(
			'total_logs'      => 0,
			'avg_duration_ms' => 0,
			'max_duration_ms' => 0,
		);
	}

	public static function save_health_snapshot($score, $components, $context) {
		global $wpdb;
		$table = self::health_table_name();
		self::ensure_tables();

		return false !== $wpdb->insert(
			$table,
			array(
				'score'           => max(0, min(100, absint($score))),
				'components_json' => wp_json_encode(is_array($components) ? $components : array()),
				'context_json'    => wp_json_encode(is_array($context) ? $context : array()),
				'created_at'      => current_time('mysql'),
			),
			array('%d', '%s', '%s', '%s')
		);
	}

	public static function get_health_history($limit = 26) {
		global $wpdb;
		$table = self::health_table_name();
		self::ensure_tables();
		$limit = max(1, min(104, absint($limit)));

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, score, components_json, context_json, created_at
				FROM {$table}
				ORDER BY created_at DESC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		if (! is_array($rows)) {
			return array();
		}

		$rows = array_reverse($rows);
		foreach ($rows as &$row) {
			$row['components'] = json_decode((string) ($row['components_json'] ?? '{}'), true);
			$row['context']    = json_decode((string) ($row['context_json'] ?? '{}'), true);
		}
		return $rows;
	}

	public static function get_last_health_snapshot() {
		global $wpdb;
		$table = self::health_table_name();
		self::ensure_tables();

		$row = $wpdb->get_row(
			"SELECT id, score, components_json, context_json, created_at
			FROM {$table}
			ORDER BY created_at DESC
			LIMIT 1",
			ARRAY_A
		);
		if (! is_array($row)) {
			return null;
		}

		$row['components'] = json_decode((string) ($row['components_json'] ?? '{}'), true);
		$row['context']    = json_decode((string) ($row['context_json'] ?? '{}'), true);
		return $row;
	}

	public static function clear_logs() {
		global $wpdb;
		$table = self::table_name();
		self::ensure_tables();
		$wpdb->query("TRUNCATE TABLE {$table}");
	}

	public static function drop_tables() {
		global $wpdb;
		$table = self::table_name();
		$ai_table = self::ai_table_name();
		$health_table = self::health_table_name();
		$wpdb->query("DROP TABLE IF EXISTS {$table}");
		$wpdb->query("DROP TABLE IF EXISTS {$ai_table}");
		$wpdb->query("DROP TABLE IF EXISTS {$health_table}");
		self::$table_exists_cache = false;
		self::$ai_table_exists_cache = false;
		self::$health_table_exists_cache = false;
	}
}
