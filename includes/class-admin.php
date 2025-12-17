<?php
/**
 * Admin functionality
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GoldenStay_Admin {
    
    private static $instance = null;
    
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            'GoldenStay Settings',
            'GoldenStay',
            'manage_options',
            'goldenstay-settings',
            array( $this, 'render_settings_page' ),
            'dashicons-building',
            30
        );
        
        // Add Properties submenu
        add_submenu_page(
            'goldenstay-settings',
            'Properties',
            'Properties',
            'manage_options',
            'goldenstay-properties',
            array( $this, 'render_properties_page' )
        );
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts( $hook ) {
        // Load on GoldenStay pages only
        if ( strpos( $hook, 'goldenstay' ) === false ) {
            return;
        }
        
        wp_enqueue_style( 
            'goldenstay-admin-css', 
            GOLDENSTAY_PLUGIN_URL . 'assets/admin-style.css',
            array(),
            GOLDENSTAY_VERSION
        );
        
        wp_enqueue_style( 
            'goldenstay-calendar-buttons-css', 
            GOLDENSTAY_PLUGIN_URL . 'assets/calendar-buttons.css',
            array( 'goldenstay-admin-css' ),
            GOLDENSTAY_VERSION
        );
        
        wp_enqueue_script( 
            'goldenstay-admin-js', 
            GOLDENSTAY_PLUGIN_URL . 'assets/admin-script.js',
            array( 'jquery' ),
            GOLDENSTAY_VERSION,
            true
        );
        
        wp_localize_script( 'goldenstay-admin-js', 'goldenStayAdmin', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'goldenstay_admin_nonce' ),
            'apiUrl' => GoldenStay_Manager::get_api_url(),
            'isAuthenticated' => $this->is_authenticated()
        ));
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        require_once GOLDENSTAY_PLUGIN_DIR . 'templates/admin-settings.php';
    }
    
    /**
     * Render properties page
     */
    public function render_properties_page() {
        require_once GOLDENSTAY_PLUGIN_DIR . 'templates/admin-properties.php';
    }
    
    /**
     * Check if authenticated
     */
    private function is_authenticated() {
        $token = get_option( 'goldenstay_api_token' );
        return ! empty( $token );
    }
}




