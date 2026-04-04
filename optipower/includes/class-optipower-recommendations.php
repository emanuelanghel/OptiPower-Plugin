<?php
if (! defined('ABSPATH')) {
	exit;
}

class OptiPower_Recommendations {
	public static function build($query, $duration_ms) {
		$q = strtolower((string) $query);

		if ($duration_ms >= 1000) {
			$severity = 'high';
		} elseif ($duration_ms >= 300) {
			$severity = 'medium';
		} else {
			$severity = 'low';
		}

		$recommendation = 'Consider object caching and reviewing plugin/theme data access patterns.';

		if (strpos($q, 'select') === 0 && strpos($q, 'order by') !== false && strpos($q, 'limit') === false) {
			$recommendation = 'Add LIMIT where possible and review indexes for ORDER BY columns.';
		} elseif (strpos($q, 'like \'%') !== false) {
			$recommendation = 'Leading wildcard LIKE can skip indexes; consider indexed search fields or full-text search.';
		} elseif (strpos($q, 'join') !== false) {
			$recommendation = 'Review JOIN columns and ensure both sides are indexed.';
		} elseif (strpos($q, 'wp_options') !== false) {
			$recommendation = 'Audit autoloaded options and reduce large option payloads.';
		} elseif (strpos($q, 'meta_key') !== false || strpos($q, 'postmeta') !== false) {
			$recommendation = 'Meta queries can be expensive; cache frequent lookups and tighten query conditions.';
		}

		return array(
			'severity'       => $severity,
			'recommendation' => $recommendation,
		);
	}
}

