<?php
/**
 * Plugin Name: Deal Notification Engine
 * Description: Advanced notification system for deal alerts with multi-platform delivery
 * Version: 1.1.8
 * Author: borkk
 * Text Domain: deal-notification-engine
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('DNE_VERSION', '1.1.8');
define('DNE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DNE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('DNE_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Load autoloader
require_once DNE_PLUGIN_DIR . 'includes/Core/Autoloader.php';
$autoloader = new DNE\Core\Autoloader();
$autoloader->register();

// Initialize plugin
function dne_init() {
    $plugin = DNE\Core\Plugin::get_instance();
    $plugin->init();
}
add_action('plugins_loaded', 'dne_init');

// Activation hook
register_activation_hook(__FILE__, function() {
    require_once DNE_PLUGIN_DIR . 'includes/Core/Installer.php';
    DNE\Core\Installer::activate();
});

// Deactivation hook
register_deactivation_hook(__FILE__, function() {
    require_once DNE_PLUGIN_DIR . 'includes/Core/Installer.php';
    DNE\Core\Installer::deactivate();
});