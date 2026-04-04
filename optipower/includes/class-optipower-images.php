<?php
if (! defined('ABSPATH')) {
	exit;
}

class OptiPower_Images {
	public function register() {
		add_filter('jpeg_quality', array($this, 'jpeg_quality'));
		add_filter('wp_editor_set_quality', array($this, 'jpeg_quality'));
		add_filter('the_content', array($this, 'add_lazy_loading'), 20);
		add_action('add_attachment', array($this, 'maybe_generate_webp'));
	}

	public function jpeg_quality($quality) {
		return (int) OptiPower_Settings::get('image_jpeg_quality', 82);
	}

	public function add_lazy_loading($content) {
		if (! OptiPower_Settings::get('image_lazy_load', 1)) {
			return $content;
		}

		if (stripos($content, '<img') === false) {
			return $content;
		}

		$content = preg_replace('/<img(?![^>]*\bloading=)([^>]*)>/i', '<img loading="lazy" decoding="async"$1>', $content);
		return $content;
	}

	public function maybe_generate_webp($attachment_id) {
		if (! OptiPower_Settings::get('image_convert_webp', 0) || ! function_exists('imagewebp')) {
			return;
		}

		$mime = get_post_mime_type($attachment_id);
		if (! in_array($mime, array('image/jpeg', 'image/png'), true)) {
			return;
		}

		$path = get_attached_file($attachment_id);
		if (! $path || ! file_exists($path)) {
			return;
		}

		$editor = wp_get_image_editor($path);
		if (is_wp_error($editor)) {
			return;
		}

		$editor->set_quality((int) OptiPower_Settings::get('image_jpeg_quality', 82));

		$webp_path = preg_replace('/\.(jpe?g|png)$/i', '.webp', $path);
		if (! $webp_path) {
			return;
		}

		$result = $editor->save($webp_path, 'image/webp');
		if (! is_wp_error($result) && isset($result['path'])) {
			update_post_meta($attachment_id, '_optipower_webp_path', $result['path']);
		}
	}
}

