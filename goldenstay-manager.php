<?php
/**
 * Plugin Name: GoldenStay Properties
 * Description: GoldenStay Plugin - Booking system with API integration.
 * Version: 0.0.13
 * Author: GoldenStay
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Adomus theme bug-guard:
 * The theme registers `save_post_hb_accommodation` callback `adomus_save_accommodation_meta`,
 * but in this codebase the function is missing. That crashes ANY creation of hb_accommodation posts.
 * Provide a no-op shim to prevent fatal errors.
 */
if ( ! function_exists( 'adomus_save_accommodation_meta' ) ) {
    function adomus_save_accommodation_meta() {
        // Intentionally empty.
    }
}

// Plugin constants
define( 'GOLDENSTAY_VERSION', '0.0.17' );
define( 'GOLDENSTAY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GOLDENSTAY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Load main manager class
require_once GOLDENSTAY_PLUGIN_DIR . 'includes/class-manager.php';

// Initialize plugin
function goldenstay_manager_init() {
    return GoldenStay_Manager::get_instance();
}
add_action( 'plugins_loaded', 'goldenstay_manager_init' );
