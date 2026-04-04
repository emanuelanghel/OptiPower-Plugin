<?php
if (! defined('ABSPATH')) {
	exit;
}

class OptiPower_Admin {
	public function register() {
		add_action('admin_menu', array($this, 'add_menu'));
		add_action('admin_init', array($this, 'register_settings'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
		add_action('admin_post_optipower_purge_cache', array($this, 'handle_purge_cache'));
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

	public function handle_purge_cache() {
		if (! current_user_can('manage_options')) {
			wp_die('Unauthorized', 403);
		}

		check_admin_referer('optipower_purge_cache');
		OptiPower_Cache::purge_all();

		wp_safe_redirect(admin_url('admin.php?page=optipower&tab=cache&purged=1'));
		exit;
	}

	public function render_page() {
		$settings    = OptiPower_Settings::get_all();
		$current_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'monitor';
		$tabs        = array(
			'monitor' => 'Monitor',
			'assets'  => 'Assets',
			'cache'   => 'Cache',
			'images'  => 'Images',
			'general' => 'General',
		);

		if (! isset($tabs[$current_tab])) {
			$current_tab = 'monitor';
		}
		?>
		<div class="wrap optipower-wrap">
			<header class="optipower-hero">
				<div>
					<p class="optipower-kicker">Performance Intelligence</p>
					<h1>OptiPower</h1>
					<p class="optipower-subtitle">A lightweight optimization suite for WordPress performance tuning.</p>
				</div>
				<div class="optipower-hero-badge">
					<span><?php echo esc_html($tabs[$current_tab]); ?></span>
				</div>
			</header>

			<?php if (! OptiPower_Monitor::instrumentation_available()) : ?>
				<div class="optipower-inline-warning">
					<p>Set <code>define('SAVEQUERIES', true);</code> in <code>wp-config.php</code> to enable deep query timing in Monitor tab.</p>
				</div>
			<?php endif; ?>

			<nav class="nav-tab-wrapper optipower-tabs">
				<?php foreach ($tabs as $slug => $label) : ?>
					<a class="nav-tab <?php echo $current_tab === $slug ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=optipower&tab=' . $slug)); ?>">
						<?php echo esc_html($label); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="optipower-grid optipower-grid-single">
				<section class="optipower-card optipower-card-panel">
					<?php
					switch ($current_tab) {
						case 'assets':
							$this->render_assets_tab($settings);
							break;
						case 'cache':
							$this->render_cache_tab($settings);
							break;
						case 'images':
							$this->render_images_tab($settings);
							break;
						case 'general':
							$this->render_general_tab($settings);
							break;
						case 'monitor':
						default:
							$this->render_monitor_tab();
							break;
					}
					?>
				</section>
			</div>
		</div>
		<?php
	}

	private function render_monitor_tab() {
		?>
		<div class="optipower-card-head">
			<h2>Realtime Slow Query Panel</h2>
			<p>Auto-refreshes every 5 seconds with the latest query events.</p>
		</div>
		<div id="optipower-summary" class="optipower-summary"></div>
		<div class="optipower-controls">
			<label for="optipower-min-duration">Minimum Duration (ms)</label>
			<input id="optipower-min-duration" type="number" min="0" value="0" />
			<button id="optipower-refresh" class="button button-secondary">Refresh Now</button>
		</div>
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
		<?php
	}

	private function render_assets_tab($settings) {
		?>
		<div class="optipower-card-head">
			<h2>Assets Optimization</h2>
			<p>Optimize frontend CSS/JS delivery with minification and defer controls.</p>
		</div>
		<form method="post" action="options.php">
			<?php settings_fields('optipower_settings_group'); ?>
			<div class="optipower-form-grid">
				<?php $this->checkbox_field($settings, 'minify_css', 'Minify CSS', 'Use existing .min.css when available, or generate optimized cached CSS.'); ?>
				<?php $this->checkbox_field($settings, 'minify_js', 'Minify JS', 'Use existing .min.js when available, or generate optimized cached JS.'); ?>
				<?php $this->checkbox_field($settings, 'defer_js', 'Defer JavaScript', 'Adds defer attribute to most scripts except critical WordPress handles.'); ?>
				<?php $this->checkbox_field($settings, 'remove_asset_version', 'Remove Asset Version Query', 'Removes ?ver= from script/style URLs.'); ?>
			</div>
			<div class="optipower-actions">
				<?php submit_button('Save Assets Settings', 'primary', 'submit', false); ?>
			</div>
		</form>
		<?php
	}

	private function render_cache_tab($settings) {
		$purged = isset($_GET['purged']) ? (int) $_GET['purged'] : 0;
		?>
		<div class="optipower-card-head">
			<h2>Caching</h2>
			<p>Enable page caching, browser caching headers, and control cache TTL.</p>
		</div>
		<?php if ($purged === 1) : ?>
			<div class="optipower-inline-warning">
				<p>Cache purged successfully.</p>
			</div>
		<?php endif; ?>
		<form method="post" action="options.php">
			<?php settings_fields('optipower_settings_group'); ?>
			<div class="optipower-form-grid">
				<?php $this->checkbox_field($settings, 'cache_enabled', 'Enable Page Cache', 'Stores full-page HTML output for faster responses.'); ?>
				<label class="optipower-field">
					<span class="optipower-label">Cache TTL (seconds)</span>
					<input type="number" min="60" step="30" name="<?php echo esc_attr(OptiPower_Settings::OPTION_KEY); ?>[cache_ttl]" value="<?php echo esc_attr($settings['cache_ttl']); ?>" />
				</label>
				<?php $this->checkbox_field($settings, 'cache_logged_in_users', 'Cache Logged-in Users', 'Usually keep disabled unless you understand personalized content risks.'); ?>
				<?php $this->checkbox_field($settings, 'browser_cache_headers', 'Send Browser Cache Headers', 'Adds Cache-Control headers for client-side caching.'); ?>
			</div>
			<div class="optipower-actions">
				<?php submit_button('Save Cache Settings', 'primary', 'submit', false); ?>
			</div>
		</form>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
			<?php wp_nonce_field('optipower_purge_cache'); ?>
			<input type="hidden" name="action" value="optipower_purge_cache" />
			<button type="submit" class="button">Purge All Page Cache</button>
		</form>
		<?php
	}

	private function render_images_tab($settings) {
		?>
		<div class="optipower-card-head">
			<h2>Image Optimization</h2>
			<p>Improve rendering and media payload with lazy loading and upload optimization.</p>
		</div>
		<form method="post" action="options.php">
			<?php settings_fields('optipower_settings_group'); ?>
			<div class="optipower-form-grid">
				<?php $this->checkbox_field($settings, 'image_lazy_load', 'Lazy Load Content Images', 'Adds loading="lazy" and decoding="async" to post images.'); ?>
				<?php $this->checkbox_field($settings, 'image_convert_webp', 'Generate WebP On Upload', 'Creates .webp files for JPEG/PNG if server supports it.'); ?>
				<label class="optipower-field">
					<span class="optipower-label">JPEG Quality</span>
					<input type="number" min="40" max="100" name="<?php echo esc_attr(OptiPower_Settings::OPTION_KEY); ?>[image_jpeg_quality]" value="<?php echo esc_attr($settings['image_jpeg_quality']); ?>" />
				</label>
			</div>
			<div class="optipower-actions">
				<?php submit_button('Save Image Settings', 'primary', 'submit', false); ?>
			</div>
		</form>
		<?php
	}

	private function render_general_tab($settings) {
		?>
		<div class="optipower-card-head">
			<h2>General</h2>
			<p>Configure monitoring scope, retention, and uninstall cleanup.</p>
		</div>
		<form method="post" action="options.php">
			<?php settings_fields('optipower_settings_group'); ?>
			<div class="optipower-form-grid">
				<?php $this->checkbox_field($settings, 'enabled', 'Enable Monitoring', 'Capture and analyze slow database queries.'); ?>
				<label class="optipower-field">
					<span class="optipower-label">Slow Query Threshold (ms)</span>
					<input type="number" min="10" name="<?php echo esc_attr(OptiPower_Settings::OPTION_KEY); ?>[slow_query_threshold_ms]" value="<?php echo esc_attr($settings['slow_query_threshold_ms']); ?>" />
				</label>
				<label class="optipower-field">
					<span class="optipower-label">Log Retention (days)</span>
					<input type="number" min="1" name="<?php echo esc_attr(OptiPower_Settings::OPTION_KEY); ?>[retention_days]" value="<?php echo esc_attr($settings['retention_days']); ?>" />
				</label>
				<label class="optipower-field">
					<span class="optipower-label">Max Log Rows</span>
					<input type="number" min="100" step="100" name="<?php echo esc_attr(OptiPower_Settings::OPTION_KEY); ?>[max_log_rows]" value="<?php echo esc_attr($settings['max_log_rows']); ?>" />
				</label>
				<?php $this->checkbox_field($settings, 'cleanup_on_uninstall', 'Cleanup On Uninstall', 'Delete plugin data when the plugin is uninstalled.'); ?>
			</div>
			<div class="optipower-actions">
				<?php submit_button('Save General Settings', 'primary', 'submit', false); ?>
			</div>
		</form>
		<?php
	}

	private function checkbox_field($settings, $key, $label, $help) {
		?>
		<label class="optipower-field optipower-field-checkbox">
			<span class="optipower-label"><?php echo esc_html($label); ?></span>
			<input type="checkbox" name="<?php echo esc_attr(OptiPower_Settings::OPTION_KEY); ?>[<?php echo esc_attr($key); ?>]" value="1" <?php checked(! empty($settings[$key])); ?> />
			<span class="optipower-help"><?php echo esc_html($help); ?></span>
		</label>
		<?php
	}
}

