<?php
if (! defined('ABSPATH')) {
	exit;
}

class OptiPower_Assets {
	public function register() {
		if (is_admin()) {
			return;
		}

		add_filter('style_loader_src', array($this, 'optimize_style_src'), 20, 2);
		add_filter('script_loader_src', array($this, 'optimize_script_src'), 20, 2);
		add_filter('script_loader_tag', array($this, 'maybe_add_defer'), 20, 3);
	}

	public function optimize_style_src($src, $handle) {
		if (! OptiPower_Settings::get('minify_css', 1)) {
			return $this->maybe_strip_ver($src);
		}

		$optimized = $this->build_optimized_asset($src, 'css');
		return $this->maybe_strip_ver($optimized ?: $src);
	}

	public function optimize_script_src($src, $handle) {
		if ($this->is_excluded_script($handle, $src)) {
			return $this->maybe_strip_ver($src);
		}

		if (! OptiPower_Settings::get('minify_js', 1)) {
			return $this->maybe_strip_ver($src);
		}

		$optimized = $this->build_optimized_asset($src, 'js');
		return $this->maybe_strip_ver($optimized ?: $src);
	}

	public function maybe_add_defer($tag, $handle, $src) {
		if (! OptiPower_Settings::get('defer_js', 1)) {
			return $tag;
		}

		$excluded = array('jquery', 'jquery-core', 'wp-hooks', 'wp-i18n');
		if (in_array($handle, $excluded, true) || strpos($tag, ' defer') !== false) {
			return $tag;
		}

		if ($this->is_excluded_script($handle, $src)) {
			return $tag;
		}

		return str_replace('<script ', '<script defer ', $tag);
	}

	private function is_excluded_script($handle, $src) {
		$tokens = $this->get_js_exclusions();
		if (empty($tokens)) {
			return false;
		}

		$haystacks = array(
			strtolower((string) $handle),
			strtolower((string) $src),
		);

		foreach ($tokens as $token) {
			foreach ($haystacks as $haystack) {
				if ($haystack !== '' && strpos($haystack, $token) !== false) {
					return true;
				}
			}
		}

		return false;
	}

	private function get_js_exclusions() {
		$defaults = array(
			'translatepress',
			'trp-language-switcher',
			'trp-frontend',
		);

		$raw = (string) OptiPower_Settings::get('js_exclusions', '');
		$parts = preg_split('/[\r\n,]+/', $raw);
		$parts = is_array($parts) ? $parts : array();
		$parts = array_filter(array_map(static function ($value) {
			return sanitize_key(trim((string) $value));
		}, $parts));

		$merged = array_merge($defaults, $parts);
		$merged = array_values(array_unique(array_filter($merged)));
		return $merged;
	}

	private function maybe_strip_ver($src) {
		if (! OptiPower_Settings::get('remove_asset_version', 0)) {
			return $src;
		}

		return remove_query_arg('ver', $src);
	}

	private function build_optimized_asset($src, $type) {
		$parsed = wp_parse_url($src);
		if (! is_array($parsed) || empty($parsed['path'])) {
			return '';
		}

		$file_path = $this->map_url_path_to_file($parsed['path']);
		if (! $file_path || ! file_exists($file_path) || ! is_readable($file_path)) {
			return '';
		}

		if (preg_match('/\.min\.' . preg_quote($type, '/') . '$/i', $file_path)) {
			return $src;
		}

		$existing_min = preg_replace('/\.' . preg_quote($type, '/') . '$/i', '.min.' . $type, $file_path);
		if ($existing_min && file_exists($existing_min)) {
			return $this->map_file_to_url($existing_min);
		}

		$content = file_get_contents($file_path);
		if (! is_string($content) || $content === '') {
			return '';
		}

		$minified = $type === 'css' ? $this->minify_css($content) : $this->minify_js($content);
		if ($minified === '') {
			return '';
		}

		$cache_dir = WP_CONTENT_DIR . '/cache/optipower/assets';
		wp_mkdir_p($cache_dir);
		if (! is_dir($cache_dir) || ! is_writable($cache_dir)) {
			return '';
		}

		$cache_file = $cache_dir . '/' . md5($file_path . '|' . filemtime($file_path)) . '.min.' . $type;
		if (! file_exists($cache_file)) {
			file_put_contents($cache_file, $minified);
		}

		return content_url('cache/optipower/assets/' . basename($cache_file));
	}

	private function map_url_path_to_file($url_path) {
		$url_path = wp_normalize_path((string) $url_path);
		$abspath  = wp_normalize_path(ABSPATH);

		if (strpos($url_path, '/wp-content/') !== false) {
			$relative = substr($url_path, strpos($url_path, '/wp-content/'));
			return wp_normalize_path($abspath . ltrim($relative, '/'));
		}

		if (strpos($url_path, '/wp-includes/') !== false) {
			$relative = substr($url_path, strpos($url_path, '/wp-includes/'));
			return wp_normalize_path($abspath . ltrim($relative, '/'));
		}

		return '';
	}

	private function map_file_to_url($file_path) {
		$file_path = wp_normalize_path($file_path);
		$abspath   = wp_normalize_path(ABSPATH);
		$relative  = ltrim(str_replace($abspath, '', $file_path), '/');
		return home_url('/' . $relative);
	}

	private function minify_css($css) {
		$css = preg_replace('#/\*.*?\*/#s', '', $css);
		$css = preg_replace('/\s+/', ' ', $css);
		$css = preg_replace('/\s*([{}|:;,])\s+/', '$1', $css);
		$css = str_replace(';}', '}', $css);
		return trim($css);
	}

	private function minify_js($js) {
		// Lightweight minification. Prefer existing .min.js when available.
		$js = preg_replace('#/\*.*?\*/#s', '', $js);
		$js = preg_replace('/^\s*\/\/.*$/m', '', $js);
		$js = preg_replace('/\s+/', ' ', $js);
		return trim($js);
	}
}

