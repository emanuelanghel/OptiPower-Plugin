<?php
if (! defined('ABSPATH')) {
	exit;
}

class OptiPower_Health {
	public function calculate_current() {
		$recent = OptiPower_DB::get_recent_metrics(24);

		$avg        = (float) ($recent['avg_duration_ms'] ?? 0);
		$max        = (float) ($recent['max_duration_ms'] ?? 0);
		$count      = (int) ($recent['total_logs'] ?? 0);
		$total_ms   = (float) ($recent['total_duration_ms'] ?? 0);
		$p1_count   = (int) ($recent['p1_count'] ?? 0); // 120-300ms (notice)
		$p2_count   = (int) ($recent['p2_count'] ?? 0); // 300-700ms (medium)
		$p3_count   = (int) ($recent['p3_count'] ?? 0); // 700-1500ms (high)
		$p4_count   = (int) ($recent['p4_count'] ?? 0); // >=1500ms (critical)

		// Priority-weighted impact based on actual query duration ranges.
		$impact_units    = ($p1_count * 0.4) + ($p2_count * 1.2) + ($p3_count * 2.8) + ($p4_count * 4.8);
		$priority_penalty = min(46, $impact_units);

		// Sustained load and spikes are separate risk dimensions.
		$avg_penalty     = $avg > 120 ? min(16, ($avg - 120) / 10) : 0;
		$peak_penalty    = $max > 400 ? min(14, ($max - 400) / 30) : 0;
		$volume_penalty  = max(0, min(8, ($count - 50) / 18));
		$load_penalty    = max(0, min(12, ($total_ms - 2500) / 450));

		$cache_bonus   = OptiPower_Settings::get('cache_enabled', 0) ? 8 : 0;
		$assets_bonus  = (OptiPower_Settings::get('minify_css', 1) ? 2 : 0) + (OptiPower_Settings::get('minify_js', 1) ? 2 : 0);
		$images_bonus  = OptiPower_Settings::get('image_lazy_load', 1) ? 2 : 0;
		$monitor_bonus = OptiPower_Settings::get('enabled', 1) ? 4 : 0;

		$total_penalty = $priority_penalty + $avg_penalty + $peak_penalty + $volume_penalty + $load_penalty;
		$total_bonus   = $cache_bonus + $assets_bonus + $images_bonus + $monitor_bonus;
		$score         = 100 - $total_penalty + $total_bonus;
		$score = (int) max(0, min(100, round($score)));

		$components = array(
			'priority_penalty'    => round($priority_penalty, 2),
			'db_avg_penalty'      => round($avg_penalty, 2),
			'db_peak_penalty'     => round($peak_penalty, 2),
			'volume_penalty'      => round($volume_penalty, 2),
			'load_penalty'        => round($load_penalty, 2),
			'cache_bonus'         => $cache_bonus,
			'assets_bonus'        => $assets_bonus,
			'images_bonus'        => $images_bonus,
			'monitor_bonus'       => $monitor_bonus,
		);

		$context = array(
			'recent_total_logs'   => $count,
			'recent_total_ms'     => round($total_ms, 2),
			'priority_p1'         => $p1_count,
			'priority_p2'         => $p2_count,
			'priority_p3'         => $p3_count,
			'priority_p4'         => $p4_count,
			'recent_avg_ms'       => round($avg, 2),
			'recent_max_ms'       => round($max, 2),
			'cache_enabled'       => OptiPower_Settings::get('cache_enabled', 0) ? 1 : 0,
			'monitor_enabled'     => OptiPower_Settings::get('enabled', 1) ? 1 : 0,
		);

		return array(
			'score'      => $score,
			'components' => $components,
			'context'    => $context,
		);
	}

	public function maybe_capture_weekly_snapshot() {
		$last = OptiPower_DB::get_last_health_snapshot();
		if (is_array($last) && ! empty($last['created_at'])) {
			$last_ts = strtotime((string) $last['created_at']);
			if ($last_ts && (time() - $last_ts) < (7 * DAY_IN_SECONDS)) {
				return false;
			}
		}

		$current = $this->calculate_current();
		return OptiPower_DB::save_health_snapshot($current['score'], $current['components'], $current['context']);
	}

	public function get_dashboard_payload() {
		$current = $this->calculate_current();
		$history = OptiPower_DB::get_health_history(26);

		$trend = 'stable';
		if (count($history) >= 2) {
			$last_score = (int) ($history[count($history) - 1]['score'] ?? 0);
			$prev_score = (int) ($history[count($history) - 2]['score'] ?? 0);
			$diff       = $last_score - $prev_score;
			if ($diff >= 3) {
				$trend = 'up';
			} elseif ($diff <= -3) {
				$trend = 'down';
			}
		}

		return array(
			'current' => $current,
			'history' => $history,
			'trend'   => $trend,
		);
	}
}

