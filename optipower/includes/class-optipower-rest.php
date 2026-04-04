<?php
if (! defined('ABSPATH')) {
	exit;
}

class OptiPower_REST {
	public function register() {
		add_action('rest_api_init', array($this, 'register_routes'));
	}

	public function register_routes() {
		register_rest_route(
			'optipower/v1',
			'/logs',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => array($this, 'can_access'),
				'callback'            => array($this, 'get_logs'),
				'args'                => array(
					'limit'        => array(
						'type'              => 'integer',
						'default'           => 25,
						'sanitize_callback' => 'absint',
					),
					'min_duration' => array(
						'type'              => 'number',
						'default'           => 0,
						'sanitize_callback' => 'floatval',
					),
				),
			)
		);

		register_rest_route(
			'optipower/v1',
			'/summary',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => array($this, 'can_access'),
				'callback'            => array($this, 'get_summary'),
			)
		);

		register_rest_route(
			'optipower/v1',
			'/analyze',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array($this, 'can_access'),
				'callback'            => array($this, 'analyze_query'),
				'args'                => array(
					'query_hash' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			'optipower/v1',
			'/health',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => array($this, 'can_access'),
				'callback'            => array($this, 'get_health'),
			)
		);
	}

	public function can_access() {
		return current_user_can('manage_options');
	}

	public function get_logs(WP_REST_Request $request) {
		$limit        = $request->get_param('limit');
		$min_duration = $request->get_param('min_duration');
		return rest_ensure_response(OptiPower_DB::get_logs($limit, $min_duration));
	}

	public function get_summary() {
		return rest_ensure_response(array(
			'summary'                   => OptiPower_DB::get_summary(),
			'instrumentation_available' => OptiPower_Monitor::instrumentation_available(),
			'monitor_debug'             => OptiPower_Monitor::get_debug_state(),
		));
	}

	public function analyze_query(WP_REST_Request $request) {
		$query_hash = (string) $request->get_param('query_hash');
		if ($query_hash === '') {
			return new WP_REST_Response(array('error' => 'Missing query_hash'), 400);
		}

		$cached = OptiPower_DB::get_ai_insight($query_hash);
		if (is_array($cached) && isset($cached['analysis']) && is_array($cached['analysis'])) {
			return rest_ensure_response(array(
				'cached'   => true,
				'analysis' => $cached['analysis'],
			));
		}

		$settings = OptiPower_Settings::get_all();
		if (empty($settings['ai_enabled'])) {
			return new WP_REST_Response(array('error' => 'AI is disabled in settings.'), 400);
		}

		if (! $this->consume_daily_ai_quota((int) $settings['ai_max_daily_requests'])) {
			return new WP_REST_Response(array('error' => 'Daily AI request limit reached.'), 429);
		}

		$log = OptiPower_DB::get_latest_log_by_hash($query_hash);
		if (! is_array($log)) {
			return new WP_REST_Response(array('error' => 'No log found for query hash.'), 404);
		}

		$query_for_model = $this->maybe_redact_query((string) ($log['query_sample'] ?? ''), ! empty($settings['ai_redact_literals']));

		$service = new OptiPower_OpenAI_Service(
			(string) ($settings['ai_api_key'] ?? ''),
			(string) ($settings['ai_model'] ?? 'gpt-4.1-mini')
		);

		$analysis = $service->analyze(
			$query_for_model,
			(float) ($log['duration_ms'] ?? 0),
			array(
				'source_type' => (string) ($log['source_type'] ?? 'unknown'),
				'source_hint' => (string) ($log['source_hint'] ?? 'N/A'),
				'request_uri' => (string) ($log['request_uri'] ?? ''),
			)
		);

		OptiPower_DB::save_ai_insight(
			$query_hash,
			(string) ($analysis['provider'] ?? 'openai'),
			(string) ($analysis['model'] ?? ($settings['ai_model'] ?? 'unknown')),
			$analysis,
			(float) ($analysis['confidence'] ?? 0),
			(int) ($settings['ai_cache_hours'] ?? 24)
		);

		return rest_ensure_response(array(
			'cached'   => false,
			'analysis' => $analysis,
		));
	}

	public function get_health() {
		$health = new OptiPower_Health();
		return rest_ensure_response($health->get_dashboard_payload());
	}

	private function consume_daily_ai_quota($limit) {
		$limit = max(1, min(5000, absint($limit)));
		$key   = 'optipower_ai_daily_usage';
		$today = gmdate('Y-m-d');

		$usage = get_option($key, array());
		if (! is_array($usage) || ($usage['date'] ?? '') !== $today) {
			$usage = array('date' => $today, 'count' => 0);
		}

		if ((int) $usage['count'] >= $limit) {
			return false;
		}

		$usage['count'] = (int) $usage['count'] + 1;
		update_option($key, $usage, false);
		return true;
	}

	private function maybe_redact_query($query, $enabled) {
		$query = (string) $query;
		if (! $enabled) {
			return $query;
		}

		$query = preg_replace("/'[^']*'/", "'?'", $query);
		$query = preg_replace('/"[^"]*"/', '"?"', $query);
		$query = preg_replace('/\b\d+\b/', '?', $query);
		return is_string($query) ? $query : '';
	}
}

