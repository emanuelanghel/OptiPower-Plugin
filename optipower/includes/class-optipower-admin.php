<?php
if (! defined('ABSPATH')) {
	exit;
}

class OptiPower_Admin {
	public function register() {
		add_action('admin_menu', array($this, 'add_menu'));
		add_action('admin_init', array($this, 'register_settings'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
		add_action('update_option_' . OptiPower_Settings::OPTION_KEY, array($this, 'maybe_purge_cache_on_settings_update'), 10, 2);
		add_action('admin_post_optipower_monitor_self_test', array($this, 'handle_monitor_self_test'));
		add_action('wp_ajax_optipower_get_logs', array($this, 'ajax_get_logs'));
		add_action('wp_ajax_optipower_get_summary', array($this, 'ajax_get_summary'));
		add_action('wp_ajax_optipower_get_health', array($this, 'ajax_get_health'));
		add_action('wp_ajax_optipower_ai_analyze', array($this, 'ajax_ai_analyze'));
		add_action('admin_post_optipower_ai_test', array($this, 'handle_ai_test'));
		add_action('admin_post_optipower_ai_refresh_models', array($this, 'handle_ai_refresh_models'));
		add_action('admin_post_optipower_clear_logs', array($this, 'handle_clear_logs'));
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
			'healthEndpoint'  => esc_url_raw(rest_url('optipower/v1/health')),
			'analyzeEndpoint' => esc_url_raw(rest_url('optipower/v1/analyze')),
			'ajaxUrl'         => esc_url_raw(admin_url('admin-ajax.php')),
			'nonce'           => wp_create_nonce('wp_rest'),
		));
	}

	public function maybe_purge_cache_on_settings_update($old_value, $new_value) {
		if (! is_admin()) {
			return;
		}

		if (! isset($_POST[OptiPower_Settings::OPTION_KEY]) || ! is_array($_POST[OptiPower_Settings::OPTION_KEY])) {
			return;
		}

		$submitted = wp_unslash($_POST[OptiPower_Settings::OPTION_KEY]);
		$cache_keys = array(
			'cache_enabled',
			'cache_ttl',
			'cache_logged_in_users',
			'browser_cache_headers',
		);

		foreach ($cache_keys as $key) {
			if (array_key_exists($key, $submitted)) {
				OptiPower_Cache::purge_all();
				break;
			}
		}
	}

	public function handle_monitor_self_test() {
		if (! current_user_can('manage_options')) {
			wp_die('Unauthorized', 403);
		}
		check_admin_referer('optipower_monitor_self_test');

		global $wpdb;
		$wpdb->query('SELECT SLEEP(0.25)');

		$insight = OptiPower_Recommendations::build('SELECT SLEEP(0.25)', 250);
		OptiPower_DB::insert_log(array(
			'query_hash'     => hash('sha256', 'optipower_monitor_self_test'),
			'query_sample'   => 'SELECT SLEEP(0.25) /* OptiPower self-test */',
			'duration_ms'    => 250,
			'request_uri'    => '/wp-admin/admin-post.php?action=optipower_monitor_self_test',
			'source_type'    => 'plugin',
			'source_hint'    => 'optipower',
			'severity'       => $insight['severity'],
			'recommendation' => $insight['recommendation'],
		));

		wp_safe_redirect(admin_url('admin.php?page=optipower&tab=monitor&self_test=1'));
		exit;
	}

	public function ajax_get_logs() {
		if (! current_user_can('manage_options')) {
			wp_send_json_error(array('error' => 'Unauthorized'), 403);
		}

		$limit = isset($_REQUEST['limit']) ? absint($_REQUEST['limit']) : 25;
		$min   = isset($_REQUEST['min_duration']) ? (float) $_REQUEST['min_duration'] : 0;
		wp_send_json_success(OptiPower_DB::get_logs($limit, $min));
	}

	public function ajax_get_summary() {
		if (! current_user_can('manage_options')) {
			wp_send_json_error(array('error' => 'Unauthorized'), 403);
		}

		wp_send_json_success(array(
			'summary'                   => OptiPower_DB::get_summary(),
			'instrumentation_available' => OptiPower_Monitor::instrumentation_available(),
			'monitor_debug'             => OptiPower_Monitor::get_debug_state(),
		));
	}

	public function ajax_get_health() {
		if (! current_user_can('manage_options')) {
			wp_send_json_error(array('error' => 'Unauthorized'), 403);
		}

		$health = new OptiPower_Health();
		wp_send_json_success($health->get_dashboard_payload());
	}

	public function ajax_ai_analyze() {
		if (! current_user_can('manage_options')) {
			wp_send_json_error(array('error' => 'Unauthorized'), 403);
		}

		$query_hash = isset($_REQUEST['query_hash']) ? sanitize_text_field(wp_unslash($_REQUEST['query_hash'])) : '';
		if ($query_hash === '') {
			wp_send_json_error(array('error' => 'Missing query_hash'), 400);
		}

		$rest = new OptiPower_REST();
		$request = new WP_REST_Request('POST', '/optipower/v1/analyze');
		$request->set_param('query_hash', $query_hash);
		$response = $rest->analyze_query($request);

		if ($response instanceof WP_REST_Response) {
			$status = $response->get_status();
			$data   = $response->get_data();
			if ($status >= 200 && $status < 300) {
				wp_send_json_success($data);
			}
			wp_send_json_error($data, $status);
		}

		wp_send_json_error(array('error' => 'Unknown AI analyze response'), 500);
	}

	public function handle_ai_test() {
		if (! current_user_can('manage_options')) {
			wp_die('Unauthorized', 403);
		}

		check_admin_referer('optipower_ai_test');

		$settings = OptiPower_Settings::get_all();
		$status   = 'error';
		$message  = 'Unknown AI validation error.';

		if (empty($settings['ai_enabled'])) {
			$message = 'AI is disabled. Enable AI Analysis first.';
		} elseif (($settings['ai_provider'] ?? '') !== 'openai') {
			$message = 'Unsupported AI provider. Use "openai".';
		} elseif (empty($settings['ai_model'])) {
			$message = 'Missing AI model.';
		} elseif (empty($settings['ai_api_key'])) {
			$message = 'Missing OpenAI API key.';
		} else {
			$service = new OptiPower_OpenAI_Service((string) $settings['ai_api_key'], (string) $settings['ai_model']);
			$result  = $service->test_connection();
			if (! empty($result['ok'])) {
				$status  = 'success';
				$message = 'AI connection test succeeded.';
			} else {
				$message = 'AI connection test failed: ' . sanitize_text_field((string) ($result['error'] ?? 'Unknown error'));
			}
		}

		$url = add_query_arg(
			array(
				'page'      => 'optipower',
				'tab'       => 'ai',
				'ai_test'   => $status,
				'ai_notice' => rawurlencode($message),
			),
			admin_url('admin.php')
		);
		wp_safe_redirect($url);
		exit;
	}

	public function handle_ai_refresh_models() {
		if (! current_user_can('manage_options')) {
			wp_die('Unauthorized', 403);
		}

		check_admin_referer('optipower_ai_refresh_models');

		$settings = OptiPower_Settings::get_all();
		$status   = 'error';
		$message  = 'Failed to load model list.';

		if (($settings['ai_provider'] ?? '') !== 'openai') {
			$message = 'Unsupported provider. Set provider to "openai".';
		} elseif (empty($settings['ai_api_key'])) {
			$message = 'Missing OpenAI API key.';
		} else {
			$service = new OptiPower_OpenAI_Service((string) $settings['ai_api_key'], (string) ($settings['ai_model'] ?? ''));
			$result  = $service->list_models();
			if (! empty($result['ok']) && ! empty($result['data']) && is_array($result['data'])) {
				set_transient($this->get_models_cache_key((string) $settings['ai_api_key']), $result['data'], 6 * HOUR_IN_SECONDS);
				$status  = 'success';
				$message = 'OpenAI model list refreshed (' . count($result['data']) . ' models).';
			} else {
				$message = 'Model list refresh failed: ' . sanitize_text_field((string) ($result['error'] ?? 'Unknown error'));
			}
		}

		$url = add_query_arg(
			array(
				'page'      => 'optipower',
				'tab'       => 'ai',
				'ai_test'   => $status,
				'ai_notice' => rawurlencode($message),
			),
			admin_url('admin.php')
		);
		wp_safe_redirect($url);
		exit;
	}

	public function handle_clear_logs() {
		if (! current_user_can('manage_options')) {
			wp_die('Unauthorized', 403);
		}

		check_admin_referer('optipower_clear_logs');
		OptiPower_DB::clear_logs();

		$url = add_query_arg(
			array(
				'page'        => 'optipower',
				'tab'         => 'monitor',
				'logs_cleared'=> '1',
			),
			admin_url('admin.php')
		);
		wp_safe_redirect($url);
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
			'ai'      => 'AI',
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
						case 'ai':
							$this->render_ai_tab($settings);
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
		$self_test = isset($_GET['self_test']) && $_GET['self_test'] === '1';
		$logs_cleared = isset($_GET['logs_cleared']) && $_GET['logs_cleared'] === '1';
		$monitor_enabled = (bool) OptiPower_Settings::get('enabled', 1);
		?>
		<div class="optipower-card-head">
			<h2>Realtime Slow Query Panel</h2>
			<p>Manual refresh mode. Click Refresh Now whenever you want the latest query events.</p>
		</div>
		<?php if (! $monitor_enabled) : ?>
			<div class="optipower-inline-warning">
				<p>Monitoring is disabled. Showing historical logs only; no new queries will be captured.</p>
			</div>
		<?php endif; ?>
		<?php if ($logs_cleared) : ?>
			<div class="optipower-inline-warning optipower-inline-success">
				<p>Query logs cleared successfully.</p>
			</div>
		<?php endif; ?>
		<?php if ($self_test) : ?>
			<div class="optipower-inline-warning">
				<p>Self-test query executed and logged.</p>
			</div>
		<?php endif; ?>
		<div id="optipower-summary" class="optipower-summary"></div>
		<div class="optipower-controls">
			<label for="optipower-min-duration">Minimum Duration (ms)</label>
			<input id="optipower-min-duration" type="number" min="0" value="0" />
			<button id="optipower-refresh" class="button button-secondary">Refresh Now</button>
		</div>
		<div class="optipower-health-wrap">
			<div class="optipower-health-head">
				<h3>Website Health Status</h3>
				<p>Real-time score and weekly 7-day snapshot trend.</p>
			</div>
			<div id="optipower-health-kpis" class="optipower-health-kpis"></div>
			<canvas id="optipower-health-chart" width="900" height="220"></canvas>
		</div>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="optipower-self-test-form">
			<?php wp_nonce_field('optipower_monitor_self_test'); ?>
			<input type="hidden" name="action" value="optipower_monitor_self_test" />
			<button type="submit" class="button">Run Monitor Self-Test</button>
		</form>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="optipower-self-test-form">
			<?php wp_nonce_field('optipower_clear_logs'); ?>
			<input type="hidden" name="action" value="optipower_clear_logs" />
			<button type="submit" class="button">Clear Logs Now</button>
		</form>
		<table class="widefat striped optipower-table">
			<thead>
				<tr>
					<th>Duration</th>
					<th>Source</th>
					<th>Request</th>
					<th>Severity</th>
					<th>Recommendation</th>
					<th>Query</th>
					<th>AI Insight</th>
					<th>Action</th>
					<th>Time</th>
				</tr>
			</thead>
			<tbody id="optipower-rows">
				<tr><td colspan="9">Loading...</td></tr>
			</tbody>
		</table>
		<?php
	}

	private function render_assets_tab($settings) {
		$profile = (string) ($settings['compatibility_profile'] ?? 'none');
		?>
		<div class="optipower-card-head">
			<h2>Assets Optimization</h2>
			<p>Optimize frontend CSS/JS delivery with minification and defer controls.</p>
		</div>
		<form id="optipower-ai-settings-form" method="post" action="options.php">
			<?php settings_fields('optipower_settings_group'); ?>
			<div class="optipower-form-grid">
				<label class="optipower-field">
					<span class="optipower-label">Compatibility Profile</span>
					<select name="<?php echo esc_attr(OptiPower_Settings::OPTION_KEY); ?>[compatibility_profile]">
						<option value="none" <?php selected($profile, 'none'); ?>>None</option>
						<option value="translatepress" <?php selected($profile, 'translatepress'); ?>>TranslatePress</option>
						<option value="woocommerce" <?php selected($profile, 'woocommerce'); ?>>WooCommerce</option>
					</select>
					<span class="optipower-help">Loads safe defaults for exclusions and cache behavior.</span>
				</label>
				<?php $this->checkbox_field($settings, 'minify_css', 'Minify CSS', 'Use existing .min.css when available, or generate optimized cached CSS.'); ?>
				<?php $this->checkbox_field($settings, 'minify_js', 'Minify JS', 'Use existing .min.js when available, or generate optimized cached JS.'); ?>
				<?php $this->checkbox_field($settings, 'defer_js', 'Defer JavaScript', 'Adds defer attribute to most scripts except critical WordPress handles.'); ?>
				<label class="optipower-field">
					<span class="optipower-label">CSS Exclusions (handles or URL fragments)</span>
					<textarea rows="3" name="<?php echo esc_attr(OptiPower_Settings::OPTION_KEY); ?>[css_exclusions]"><?php echo esc_textarea((string) ($settings['css_exclusions'] ?? '')); ?></textarea>
					<span class="optipower-help">Comma/newline-separated list. Matching CSS files are excluded from minification rewrite.</span>
				</label>
				<label class="optipower-field">
					<span class="optipower-label">JS Exclusions (handles or URL fragments)</span>
					<textarea rows="3" name="<?php echo esc_attr(OptiPower_Settings::OPTION_KEY); ?>[js_exclusions]"><?php echo esc_textarea((string) ($settings['js_exclusions'] ?? '')); ?></textarea>
					<span class="optipower-help">Comma/newline-separated list. Default includes TranslatePress entries to keep its floating switcher working.</span>
				</label>
				<?php $this->checkbox_field($settings, 'remove_asset_version', 'Remove Asset Version Query', 'Removes ?ver= from script/style URLs.'); ?>
			</div>
			<div class="optipower-actions">
				<?php submit_button('Save Assets Settings', 'primary', 'submit', false); ?>
			</div>
		</form>
		<?php
	}

	private function render_cache_tab($settings) {
		$settings_updated = isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true';
		?>
		<div class="optipower-card-head">
			<h2>Caching</h2>
			<p>Enable page caching, browser caching headers, and control cache TTL.</p>
		</div>
		<?php if ($settings_updated) : ?>
			<div class="optipower-inline-warning">
				<p>Cache settings saved. Page cache has been purged automatically.</p>
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
				<label class="optipower-field">
					<span class="optipower-label">Cache URL Exclusions</span>
					<textarea rows="3" name="<?php echo esc_attr(OptiPower_Settings::OPTION_KEY); ?>[cache_uri_exclusions]"><?php echo esc_textarea((string) ($settings['cache_uri_exclusions'] ?? '')); ?></textarea>
					<span class="optipower-help">Comma/newline-separated URL fragments. If matched, page cache is skipped.</span>
				</label>
				<label class="optipower-field">
					<span class="optipower-label">Cache Cookie Exclusions</span>
					<textarea rows="3" name="<?php echo esc_attr(OptiPower_Settings::OPTION_KEY); ?>[cache_cookie_exclusions]"><?php echo esc_textarea((string) ($settings['cache_cookie_exclusions'] ?? '')); ?></textarea>
					<span class="optipower-help">Comma/newline-separated cookie names/fragments that should bypass cache.</span>
				</label>
				<label class="optipower-field">
					<span class="optipower-label">Cache Query Param Exclusions</span>
					<textarea rows="3" name="<?php echo esc_attr(OptiPower_Settings::OPTION_KEY); ?>[cache_query_exclusions]"><?php echo esc_textarea((string) ($settings['cache_query_exclusions'] ?? '')); ?></textarea>
					<span class="optipower-help">Comma/newline-separated query params that should bypass cache.</span>
				</label>
			</div>
			<div class="optipower-actions">
				<?php submit_button('Save Cache Settings', 'primary', 'submit', false); ?>
			</div>
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

	private function render_ai_tab($settings) {
		$models       = $this->get_ai_model_options($settings);
		$currentModel = (string) ($settings['ai_model'] ?? '');
		$notices = $this->get_ai_notices($settings);
		if (isset($_GET['ai_test'])) {
			$test_status = sanitize_key(wp_unslash($_GET['ai_test']));
			$test_msg    = isset($_GET['ai_notice']) ? rawurldecode(sanitize_text_field(wp_unslash($_GET['ai_notice']))) : '';
			if ($test_msg !== '') {
				$notices[] = array(
					'type'    => $test_status === 'success' ? 'success' : 'error',
					'message' => $test_msg,
				);
			}
		}
		?>
		<div class="optipower-card-head">
			<h2>AI Analysis</h2>
			<p>Use OpenAI to generate structured recommendations for slow queries.</p>
		</div>
		<?php foreach ($notices as $notice) : ?>
			<div class="optipower-inline-warning optipower-inline-<?php echo esc_attr($notice['type']); ?>">
				<p><?php echo esc_html($notice['message']); ?></p>
			</div>
		<?php endforeach; ?>
		<form method="post" action="options.php">
			<?php settings_fields('optipower_settings_group'); ?>
			<div class="optipower-form-grid">
				<?php $this->checkbox_field($settings, 'ai_enabled', 'Enable AI Analysis', 'Turns on AI-powered query diagnostics.'); ?>
				<label class="optipower-field">
					<span class="optipower-label">Provider</span>
					<input type="text" name="<?php echo esc_attr(OptiPower_Settings::OPTION_KEY); ?>[ai_provider]" value="<?php echo esc_attr($settings['ai_provider']); ?>" />
				</label>
				<label class="optipower-field">
					<span class="optipower-label">Model</span>
					<select name="<?php echo esc_attr(OptiPower_Settings::OPTION_KEY); ?>[ai_model]">
						<?php foreach ($models as $model) : ?>
							<option value="<?php echo esc_attr($model); ?>" <?php selected($currentModel, $model); ?>><?php echo esc_html($model); ?></option>
						<?php endforeach; ?>
						<?php if ($currentModel !== '' && ! in_array($currentModel, $models, true)) : ?>
							<option value="<?php echo esc_attr($currentModel); ?>" selected><?php echo esc_html($currentModel); ?> (current)</option>
						<?php endif; ?>
					</select>
				</label>
				<label class="optipower-field">
					<span class="optipower-label">OpenAI API Key</span>
					<input type="password" autocomplete="off" placeholder="<?php echo ! empty($settings['ai_api_key']) ? 'Saved (leave empty to keep current key)' : 'sk-...'; ?>" name="<?php echo esc_attr(OptiPower_Settings::OPTION_KEY); ?>[ai_api_key]" value="" />
				</label>
				<label class="optipower-field">
					<span class="optipower-label">AI Cache (hours)</span>
					<input type="number" min="1" max="168" name="<?php echo esc_attr(OptiPower_Settings::OPTION_KEY); ?>[ai_cache_hours]" value="<?php echo esc_attr($settings['ai_cache_hours']); ?>" />
				</label>
				<label class="optipower-field">
					<span class="optipower-label">Max AI Requests / Day</span>
					<input type="number" min="1" max="5000" name="<?php echo esc_attr(OptiPower_Settings::OPTION_KEY); ?>[ai_max_daily_requests]" value="<?php echo esc_attr($settings['ai_max_daily_requests']); ?>" />
				</label>
				<?php $this->checkbox_field($settings, 'ai_redact_literals', 'Redact Query Literals', 'Redacts string and numeric literals before AI requests.'); ?>
			</div>
		</form>
		<div class="optipower-actions optipower-ai-actions">
			<button type="submit" form="optipower-ai-settings-form" class="button button-primary">Save AI Settings</button>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="optipower-self-test-form">
				<?php wp_nonce_field('optipower_ai_test'); ?>
				<input type="hidden" name="action" value="optipower_ai_test" />
				<button type="submit" class="button button-secondary">Test AI Connection</button>
			</form>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="optipower-self-test-form">
				<?php wp_nonce_field('optipower_ai_refresh_models'); ?>
				<input type="hidden" name="action" value="optipower_ai_refresh_models" />
				<button type="submit" class="button button-secondary">Refresh OpenAI Models</button>
			</form>
		</div>
		<?php
	}

	private function get_ai_model_options($settings) {
		$fallback = array(
			'gpt-4.1-mini',
			'gpt-4.1',
			'gpt-4o-mini',
			'gpt-4o',
			'gpt-5-mini',
			'gpt-5',
		);

		$provider = (string) ($settings['ai_provider'] ?? '');
		$api_key  = (string) ($settings['ai_api_key'] ?? '');
		if ($provider !== 'openai' || $api_key === '') {
			return $fallback;
		}

		$cache_key = $this->get_models_cache_key($api_key);
		$cached    = get_transient($cache_key);
		if (is_array($cached) && ! empty($cached)) {
			return $cached;
		}

		$service = new OptiPower_OpenAI_Service($api_key, (string) ($settings['ai_model'] ?? ''));
		$result  = $service->list_models();
		if (! empty($result['ok']) && ! empty($result['data']) && is_array($result['data'])) {
			set_transient($cache_key, $result['data'], 6 * HOUR_IN_SECONDS);
			return $result['data'];
		}

		return $fallback;
	}

	private function get_models_cache_key($api_key) {
		return 'optipower_ai_models_' . md5((string) $api_key);
	}

	private function get_ai_notices($settings) {
		$notices = array();

		if (empty($settings['ai_enabled'])) {
			$notices[] = array(
				'type'    => 'warning',
				'message' => 'AI Analysis is currently disabled.',
			);
			return $notices;
		}

		if (($settings['ai_provider'] ?? '') !== 'openai') {
			$notices[] = array(
				'type'    => 'error',
				'message' => 'Unsupported provider. Set provider to "openai".',
			);
		}

		if (empty($settings['ai_model'])) {
			$notices[] = array(
				'type'    => 'error',
				'message' => 'AI model is missing.',
			);
		}

		if (empty($settings['ai_api_key'])) {
			$notices[] = array(
				'type'    => 'error',
				'message' => 'OpenAI API key is missing.',
			);
		}

		if (! wp_http_supports(array('ssl' => true))) {
			$notices[] = array(
				'type'    => 'error',
				'message' => 'Your server cannot perform secure outbound HTTPS requests required for AI.',
			);
		}

		if (empty($notices)) {
			$notices[] = array(
				'type'    => 'success',
				'message' => 'AI configuration looks valid. Use "Test AI Connection" to verify runtime access.',
			);
		}

		return $notices;
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
			<input type="hidden" name="<?php echo esc_attr(OptiPower_Settings::OPTION_KEY); ?>[<?php echo esc_attr($key); ?>]" value="0" />
			<input type="checkbox" name="<?php echo esc_attr(OptiPower_Settings::OPTION_KEY); ?>[<?php echo esc_attr($key); ?>]" value="1" <?php checked(! empty($settings[$key])); ?> />
			<span class="optipower-help"><?php echo esc_html($help); ?></span>
		</label>
		<?php
	}
}

