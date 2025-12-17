<?php
/**
 * Plugin Name: GoldenStay Properties
 * Description: GoldenStay Plugin - Booking system with API integration.
 * Version: 0.0.1
 * Author: GoldenStay
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants
define( 'GOLDENSTAY_VERSION', '0.0.1' );
define( 'GOLDENSTAY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GOLDENSTAY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Load main manager class
require_once GOLDENSTAY_PLUGIN_DIR . 'includes/class-manager.php';

// Initialize plugin
function goldenstay_manager_init() {
    return GoldenStay_Manager::get_instance();
}
add_action( 'plugins_loaded', 'goldenstay_manager_init' );
