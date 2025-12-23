<?php
/**
 * Main Manager class
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GoldenStay_Manager {
    
    private static $instance = null;
    
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }
    
    /**
     * Load required files
     */
    private function load_dependencies() {
        require_once GOLDENSTAY_PLUGIN_DIR . 'includes/class-admin.php';
        require_once GOLDENSTAY_PLUGIN_DIR . 'includes/class-ajax.php';
        require_once GOLDENSTAY_PLUGIN_DIR . 'includes/class-frontend.php';
        require_once GOLDENSTAY_PLUGIN_DIR . 'includes/class-accommodation-mapping.php';
        require_once GOLDENSTAY_PLUGIN_DIR . 'includes/class-hbook-compat.php';
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Initialize admin
        GoldenStay_Admin::get_instance();
        
        // Initialize AJAX
        GoldenStay_Ajax::init();

        // Accommodation ↔ API property mapping
        GoldenStay_Accommodation_Mapping::get_instance();

        // HBook compatibility layer (shortcodes + assets) for Adomus theme
        GoldenStay_HBook_Compat::get_instance();
        
        // Register shortcodes
        add_shortcode( 'goldenstay_properties', array( 'GoldenStay_Frontend', 'properties_list_shortcode' ) );
        add_shortcode( 'goldenstay_property', array( 'GoldenStay_Frontend', 'property_single_shortcode' ) );
    }
    
    /**
     * Get API token
     */
    public static function get_api_token() {
        return get_option( 'goldenstay_api_token' );
    }
    
    /**
     * Get API URL
     */
    public static function get_api_url() {
        return get_option( 'goldenstay_api_url', 'http://localhost:3000' );
    }
}





