<?php
if (! defined('ABSPATH')) {
	exit;
}

class OptiPower {
	private static $instance = null;

	public static function instance() {
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->bootstrap();
	}

	private function bootstrap() {
		$admin   = new OptiPower_Admin();
		$monitor = new OptiPower_Monitor();
		$assets  = new OptiPower_Assets();
		$cache   = new OptiPower_Cache();
		$images  = new OptiPower_Images();
		$health  = new OptiPower_Health();
		$rest    = new OptiPower_REST();

		$admin->register();
		$monitor->register();
		$assets->register();
		$cache->register();
		$images->register();
		$rest->register();
		OptiPower_Cache::register_purge_hooks();
		add_filter('cron_schedules', array($this, 'add_cron_schedules'));

		add_action('optipower_daily_cleanup', array($this, 'run_cleanup'));
		add_action('optipower_weekly_health_snapshot', array($this, 'run_weekly_health_snapshot'));
		$health->maybe_capture_weekly_snapshot();
	}

	public static function activate() {
		if (! get_option(OptiPower_Settings::OPTION_KEY)) {
			update_option(OptiPower_Settings::OPTION_KEY, OptiPower_Settings::defaults());
		}

		OptiPower_DB::create_tables();

		if (! wp_next_scheduled('optipower_daily_cleanup')) {
			wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'optipower_daily_cleanup');
		}

		if (! wp_next_scheduled('optipower_weekly_health_snapshot')) {
			wp_schedule_event(time() + HOUR_IN_SECONDS, 'optipower_weekly', 'optipower_weekly_health_snapshot');
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook('optipower_daily_cleanup');
		wp_clear_scheduled_hook('optipower_weekly_health_snapshot');
	}

	public function run_cleanup() {
		$settings = OptiPower_Settings::get_all();
		OptiPower_DB::cleanup_old_logs(
			$settings['retention_days'],
			$settings['max_log_rows']
		);
	}

	public function run_weekly_health_snapshot() {
		$health = new OptiPower_Health();
		$health->maybe_capture_weekly_snapshot();
	}

	public function add_cron_schedules($schedules) {
		if (! isset($schedules['optipower_weekly'])) {
			$schedules['optipower_weekly'] = array(
				'interval' => 7 * DAY_IN_SECONDS,
				'display'  => 'Once Every 7 Days (OptiPower)',
			);
		}
		return $schedules;
	}
}

