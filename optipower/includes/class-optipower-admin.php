<?php
if (! defined('ABSPATH')) {
	exit;
}

class OptiPower_Admin {
	public function register() {
		add_action('admin_menu', array($this, 'add_menu'));
		add_action('admin_init', array($this, 'register_settings'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
	}

	public function add_menu() {
		add_menu_page(
			'OptiPower',
			'OptiPower',
			'manage_options',
			'optipower',
			array($this, 'render_page'),
			'dashicons-performance',
			58
		);
	}

	public function register_settings() {
		register_setting(
			'optipower_settings_group',
			OptiPower_Settings::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array('OptiPower_Settings', 'sanitize'),
				'default'           => OptiPower_Settings::defaults(),
			)
		);
	}

	public function enqueue_assets($hook) {
		if ($hook !== 'toplevel_page_optipower') {
			return;
		}

		wp_enqueue_style('optipower-admin', OPTIPOWER_URL . 'assets/css/admin.css', array(), OPTIPOWER_VERSION);
		wp_enqueue_script('optipower-admin', OPTIPOWER_URL . 'assets/js/admin.js', array(), OPTIPOWER_VERSION, true);

		wp_localize_script('optipower-admin', 'OptiPowerData', array(
			'logsEndpoint'    => esc_url_raw(rest_url('optipower/v1/logs')),
			'summaryEndpoint' => esc_url_raw(rest_url('optipower/v1/summary')),
			'nonce'           => wp_create_nonce('wp_rest'),
		));
	}

	public function render_page() {
		$settings = OptiPower_Settings::get_all();
		?>
		<div class="wrap optipower-wrap">
			<h1>OptiPower</h1>
			<?php if (! OptiPower_Monitor::instrumentation_available()) : ?>
				<div class="notice notice-warning">
					<p>Set <code>define('SAVEQUERIES', true);</code> in <code>wp-config.php</code> to enable deep query timing.</p>
				</div>
			<?php endif; ?>

			<div class="optipower-grid">
				<div class="optipower-card">
					<h2>Settings</h2>
					<form method="post" action="options.php">
						<?php settings_fields('optipower_settings_group'); ?>
						<table class="form-table">
							<tr>
								<th scope="row">Enable Monitoring</th>
								<td>
									<label>
										<input type="checkbox" name="<?php echo esc_attr(OptiPower_Settings::OPTION_KEY); ?>[enabled]" value="1" <?php checked(! empty($settings['enabled'])); ?> />
										Enabled
									</label>
								</td>
							</tr>
							<tr>
								<th scope="row">Slow Query Threshold (ms)</th>
								<td>
									<input type="number" min="10" name="<?php echo esc_attr(OptiPower_Settings::OPTION_KEY); ?>[slow_query_threshold_ms]" value="<?php echo esc_attr($settings['slow_query_threshold_ms']); ?>" />
								</td>
							</tr>
							<tr>
								<th scope="row">Log Retention (days)</th>
								<td>
									<input type="number" min="1" name="<?php echo esc_attr(OptiPower_Settings::OPTION_KEY); ?>[retention_days]" value="<?php echo esc_attr($settings['retention_days']); ?>" />
								</td>
							</tr>
							<tr>
								<th scope="row">Max Log Rows</th>
								<td>
									<input type="number" min="100" step="100" name="<?php echo esc_attr(OptiPower_Settings::OPTION_KEY); ?>[max_log_rows]" value="<?php echo esc_attr($settings['max_log_rows']); ?>" />
								</td>
							</tr>
							<tr>
								<th scope="row">Cleanup On Uninstall</th>
								<td>
									<label>
										<input type="checkbox" name="<?php echo esc_attr(OptiPower_Settings::OPTION_KEY); ?>[cleanup_on_uninstall]" value="1" <?php checked(! empty($settings['cleanup_on_uninstall'])); ?> />
										Delete logs/settings when plugin is uninstalled.
									</label>
								</td>
							</tr>
						</table>
						<?php submit_button('Save Settings'); ?>
					</form>
				</div>

				<div class="optipower-card">
					<h2>Realtime Slow Query Panel</h2>
					<p class="description">Auto-refreshes every 5 seconds.</p>
					<div id="optipower-summary"></div>
					<label for="optipower-min-duration">Minimum Duration (ms):</label>
					<input id="optipower-min-duration" type="number" min="0" value="0" />
					<button id="optipower-refresh" class="button button-secondary">Refresh Now</button>
					<table class="widefat striped optipower-table">
						<thead>
							<tr>
								<th>Duration</th>
								<th>Source</th>
								<th>Request</th>
								<th>Severity</th>
								<th>Recommendation</th>
								<th>Query</th>
								<th>Time</th>
							</tr>
						</thead>
						<tbody id="optipower-rows">
							<tr><td colspan="7">Loading...</td></tr>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		<?php
	}
}

