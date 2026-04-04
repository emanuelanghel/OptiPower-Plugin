<?php
if (! defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

$settings = get_option('optipower_settings', array());
if (empty($settings['cleanup_on_uninstall'])) {
	return;
}

global $wpdb;
$table = $wpdb->prefix . 'optipower_query_logs';

$wpdb->query("DROP TABLE IF EXISTS {$table}");
delete_option('optipower_settings');

