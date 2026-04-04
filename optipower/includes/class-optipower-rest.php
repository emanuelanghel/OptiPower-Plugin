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
}

