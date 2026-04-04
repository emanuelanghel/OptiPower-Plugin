<?php
/**
 * Plugin Name: OptiPower
 * Plugin URI: https://example.com/optipower
 * Description: Lightweight WordPress performance plugin with slow query monitoring and optimization insights.
 * Version: 0.1.0
 * Author: OptiPower
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: optipower
 */

if (! defined('ABSPATH')) {
	exit;
}

define('OPTIPOWER_VERSION', '0.1.0');
define('OPTIPOWER_FILE', __FILE__);
define('OPTIPOWER_PATH', plugin_dir_path(__FILE__));
define('OPTIPOWER_URL', plugin_dir_url(__FILE__));

require_once OPTIPOWER_PATH . 'includes/class-optipower-settings.php';
require_once OPTIPOWER_PATH . 'includes/class-optipower-db.php';
require_once OPTIPOWER_PATH . 'includes/class-optipower-ai-service.php';
require_once OPTIPOWER_PATH . 'includes/class-optipower-ai-openai.php';
require_once OPTIPOWER_PATH . 'includes/class-optipower-recommendations.php';
require_once OPTIPOWER_PATH . 'includes/class-optipower-monitor.php';
require_once OPTIPOWER_PATH . 'includes/class-optipower-assets.php';
require_once OPTIPOWER_PATH . 'includes/class-optipower-cache.php';
require_once OPTIPOWER_PATH . 'includes/class-optipower-images.php';
require_once OPTIPOWER_PATH . 'includes/class-optipower-rest.php';
require_once OPTIPOWER_PATH . 'includes/class-optipower-admin.php';
require_once OPTIPOWER_PATH . 'includes/class-optipower.php';

register_activation_hook(OPTIPOWER_FILE, array('OptiPower', 'activate'));
register_deactivation_hook(OPTIPOWER_FILE, array('OptiPower', 'deactivate'));

OptiPower::instance();
