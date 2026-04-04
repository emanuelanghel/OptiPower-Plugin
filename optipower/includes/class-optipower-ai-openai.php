<?php
if (! defined('ABSPATH')) {
	exit;
}

class OptiPower_OpenAI_Service implements OptiPower_AI_Service {
	private $api_key;
	private $model;

	public function __construct($api_key, $model) {
		$this->api_key = trim((string) $api_key);
		$this->model   = trim((string) $model);
	}

	public function analyze($query, $duration_ms, $context = array()) {
		$rules = OptiPower_Recommendations::build($query, $duration_ms);

		if ($this->api_key === '' || $this->model === '') {
			return array(
				'summary'     => $rules['recommendation'],
				'root_cause'  => 'AI unavailable: missing API key or model.',
				'confidence'  => 0.25,
				'severity'    => $rules['severity'],
				'fixes'       => array(
					array(
						'title'  => 'Apply rules-based recommendation',
						'action' => $rules['recommendation'],
						'impact' => 'medium',
						'risk'   => 'low',
					),
				),
				'provider'    => 'rules_fallback',
				'model'       => 'rules',
			);
		}

		$response = $this->call_openai($query, $duration_ms, $context, $rules);
		if ($response['ok']) {
			return $response['analysis'];
		}

		return array(
			'summary'     => $rules['recommendation'],
			'root_cause'  => 'AI request failed: ' . $response['error'],
			'confidence'  => 0.30,
			'severity'    => $rules['severity'],
			'fixes'       => array(
				array(
					'title'  => 'Apply rules-based recommendation',
					'action' => $rules['recommendation'],
					'impact' => 'medium',
					'risk'   => 'low',
				),
			),
			'provider'    => 'rules_fallback',
			'model'       => 'rules',
		);
	}

	public function test_connection() {
		if ($this->api_key === '' || $this->model === '') {
			return array(
				'ok'    => false,
				'error' => 'Missing API key or model.',
			);
		}

		$payload = array(
			'model' => $this->model,
			'input' => 'Reply with OK',
			'max_output_tokens' => 20,
		);

		$http_response = wp_remote_post(
			'https://api.openai.com/v1/responses',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode($payload),
			)
		);

		if (is_wp_error($http_response)) {
			return array(
				'ok'    => false,
				'error' => $http_response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code($http_response);
		if ($code < 200 || $code >= 300) {
			return array(
				'ok'    => false,
				'error' => 'HTTP ' . $code,
			);
		}

		return array('ok' => true);
	}

	public function list_models() {
		if ($this->api_key === '') {
			return array(
				'ok'    => false,
				'error' => 'Missing API key.',
				'data'  => array(),
			);
		}

		$http_response = wp_remote_get(
			'https://api.openai.com/v1/models',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->api_key,
				),
			)
		);

		if (is_wp_error($http_response)) {
			return array(
				'ok'    => false,
				'error' => $http_response->get_error_message(),
				'data'  => array(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code($http_response);
		$body = (string) wp_remote_retrieve_body($http_response);

		if ($code < 200 || $code >= 300) {
			return array(
				'ok'    => false,
				'error' => 'HTTP ' . $code,
				'data'  => array(),
			);
		}

		$parsed = json_decode($body, true);
		if (! is_array($parsed) || ! isset($parsed['data']) || ! is_array($parsed['data'])) {
			return array(
				'ok'    => false,
				'error' => 'Invalid model list response.',
				'data'  => array(),
			);
		}

		$model_ids = array();
		foreach ($parsed['data'] as $model) {
			if (! is_array($model) || empty($model['id'])) {
				continue;
			}
			$model_ids[] = sanitize_text_field((string) $model['id']);
		}

		$model_ids = array_values(array_unique(array_filter($model_ids)));
		sort($model_ids, SORT_NATURAL | SORT_FLAG_CASE);

		return array(
			'ok'    => true,
			'error' => '',
			'data'  => $model_ids,
		);
	}

	private function call_openai($query, $duration_ms, $context, $rules) {
		$context = is_array($context) ? $context : array();

		$payload = array(
			'model' => $this->model,
			'input' => array(
				array(
					'role'    => 'system',
					'content' => array(
						array(
							'type' => 'input_text',
							'text' => 'You are a senior WordPress performance engineer. Return only strict JSON with keys: summary, root_cause, confidence, severity, fixes. fixes must be an array of objects with keys: title, action, impact, risk. confidence should be between 0 and 1. severity one of low, medium, high.',
						),
					),
				),
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type' => 'input_text',
							'text' => wp_json_encode(array(
								'query'        => (string) $query,
								'duration_ms'  => (float) $duration_ms,
								'source_type'  => (string) ($context['source_type'] ?? 'unknown'),
								'source_hint'  => (string) ($context['source_hint'] ?? 'N/A'),
								'request_uri'  => (string) ($context['request_uri'] ?? ''),
								'fallback'     => $rules,
							)),
						),
					),
				),
			),
			'max_output_tokens' => 500,
		);

		$http_response = wp_remote_post(
			'https://api.openai.com/v1/responses',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode($payload),
			)
		);

		if (is_wp_error($http_response)) {
			return array('ok' => false, 'error' => $http_response->get_error_message());
		}

		$code = (int) wp_remote_retrieve_response_code($http_response);
		$body = (string) wp_remote_retrieve_body($http_response);

		if ($code < 200 || $code >= 300) {
			return array('ok' => false, 'error' => 'HTTP ' . $code);
		}

		$parsed = json_decode($body, true);
		$text   = '';

		if (is_array($parsed) && isset($parsed['output_text']) && is_string($parsed['output_text'])) {
			$text = $parsed['output_text'];
		}

		if ($text === '' && is_array($parsed) && isset($parsed['output']) && is_array($parsed['output'])) {
			foreach ($parsed['output'] as $item) {
				if (! is_array($item) || ! isset($item['content']) || ! is_array($item['content'])) {
					continue;
				}
				foreach ($item['content'] as $content_item) {
					if (is_array($content_item) && isset($content_item['text']) && is_string($content_item['text'])) {
						$text .= $content_item['text'];
					}
				}
			}
		}

		if ($text === '') {
			return array('ok' => false, 'error' => 'No model text output');
		}

		$analysis = $this->extract_json($text);
		if (! is_array($analysis)) {
			return array('ok' => false, 'error' => 'Invalid model JSON output');
		}

		$severity   = in_array(($analysis['severity'] ?? ''), array('low', 'medium', 'high'), true) ? $analysis['severity'] : $rules['severity'];
		$confidence = isset($analysis['confidence']) ? (float) $analysis['confidence'] : 0.5;
		$confidence = max(0.0, min(1.0, $confidence));
		$fixes      = isset($analysis['fixes']) && is_array($analysis['fixes']) ? $analysis['fixes'] : array();

		return array(
			'ok'       => true,
			'analysis' => array(
				'summary'    => sanitize_textarea_field((string) ($analysis['summary'] ?? $rules['recommendation'])),
				'root_cause' => sanitize_textarea_field((string) ($analysis['root_cause'] ?? 'Not provided by model.')),
				'confidence' => $confidence,
				'severity'   => $severity,
				'fixes'      => array_values(array_map(array($this, 'normalize_fix'), $fixes)),
				'provider'   => 'openai',
				'model'      => $this->model,
			),
		);
	}

	private function extract_json($text) {
		$text    = trim((string) $text);
		$decoded = json_decode($text, true);
		if (is_array($decoded)) {
			return $decoded;
		}

		if (preg_match('/\{.*\}/s', $text, $m)) {
			$decoded = json_decode($m[0], true);
			if (is_array($decoded)) {
				return $decoded;
			}
		}

		return null;
	}

	private function normalize_fix($item) {
		$item = is_array($item) ? $item : array();
		return array(
			'title'  => sanitize_text_field((string) ($item['title'] ?? 'Suggested fix')),
			'action' => sanitize_textarea_field((string) ($item['action'] ?? 'Review query and optimize indexing/caching.')),
			'impact' => sanitize_text_field((string) ($item['impact'] ?? 'medium')),
			'risk'   => sanitize_text_field((string) ($item['risk'] ?? 'medium')),
		);
	}
}
