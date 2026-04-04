<?php
if (! defined('ABSPATH')) {
	exit;
}

class OptiPower_DB {
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'optipower_query_logs';
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$table           = self::table_name();
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
	}

	public static function insert_log($data) {
		global $wpdb;
		$table = self::table_name();

		$wpdb->insert(
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
	}

	public static function get_logs($limit = 50, $min_duration = 0) {
		global $wpdb;
		$table = self::table_name();
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

	public static function get_summary() {
		global $wpdb;
		$table = self::table_name();

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

	public static function drop_tables() {
		global $wpdb;
		$table = self::table_name();
		$wpdb->query("DROP TABLE IF EXISTS {$table}");
	}
}
