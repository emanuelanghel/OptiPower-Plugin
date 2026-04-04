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
		$rest    = new OptiPower_REST();

		$admin->register();
		$monitor->register();
		$assets->register();
		$cache->register();
		$images->register();
		$rest->register();
		OptiPower_Cache::register_purge_hooks();

		add_action('optipower_daily_cleanup', array($this, 'run_cleanup'));
	}

	public static function activate() {
		if (! get_option(OptiPower_Settings::OPTION_KEY)) {
			update_option(OptiPower_Settings::OPTION_KEY, OptiPower_Settings::defaults());
		}

		OptiPower_DB::create_tables();

		if (! wp_next_scheduled('optipower_daily_cleanup')) {
			wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'optipower_daily_cleanup');
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook('optipower_daily_cleanup');
	}

	public function run_cleanup() {
		$settings = OptiPower_Settings::get_all();
		OptiPower_DB::cleanup_old_logs(
			$settings['retention_days'],
			$settings['max_log_rows']
		);
	}
}

