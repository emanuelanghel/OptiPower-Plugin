<?php
if (! defined('ABSPATH')) {
	exit;
}

class OptiPower_Health {
	public function calculate_current() {
		$recent = OptiPower_DB::get_recent_summary(24);

		$avg   = (float) ($recent['avg_duration_ms'] ?? 0);
		$max   = (float) ($recent['max_duration_ms'] ?? 0);
		$count = (int) ($recent['total_logs'] ?? 0);

		$avg_penalty   = min(35, $avg / 4);
		$max_penalty   = min(25, $max / 20);
		$count_penalty = min(20, $count / 6);

		$cache_bonus = OptiPower_Settings::get('cache_enabled', 0) ? 8 : 0;
		$monitor_bonus = OptiPower_Settings::get('enabled', 1) ? 6 : 0;

		$score = 100 - ($avg_penalty + $max_penalty + $count_penalty) + $cache_bonus + $monitor_bonus;
		$score = (int) max(0, min(100, round($score)));

		$components = array(
			'db_avg_penalty'      => round($avg_penalty, 2),
			'db_peak_penalty'     => round($max_penalty, 2),
			'volume_penalty'      => round($count_penalty, 2),
			'cache_bonus'         => $cache_bonus,
			'monitor_bonus'       => $monitor_bonus,
		);

		$context = array(
			'recent_total_logs'   => $count,
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

