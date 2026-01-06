<?php
/**
 * Frontend functionality and shortcodes
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GoldenStay_Frontend {

    const OPT_FRONTEND_CSS = 'goldenstay_shortcode_css_frontend';
    
    /**
     * Properties list shortcode
     * Usage: [goldenstay_properties]
     */
    public static function properties_list_shortcode( $atts ) {
        self::enqueue_frontend_assets();
        
        $atts = shortcode_atts( array(
            'limit' => -1,
            'show_filter' => 'yes',
        ), $atts );
        
        ob_start();
        require GOLDENSTAY_PLUGIN_DIR . 'templates/frontend-properties-list.php';
        return ob_get_clean();
    }
    
    /**
     * Single property shortcode with booking calendar
     * Usage: [goldenstay_property id="123"]
     */
    public static function property_single_shortcode( $atts ) {
        self::enqueue_frontend_assets();
        
        $atts = shortcode_atts( array(
            'id' => 0,
        ), $atts );
        
        if ( empty( $atts['id'] ) ) {
            return '<p class="gs-error">Please provide property ID: [goldenstay_property id="123"]</p>';
        }
        
        ob_start();
        require GOLDENSTAY_PLUGIN_DIR . 'templates/frontend-property-single.php';
        return ob_get_clean();
    }
    
    /**
     * Enqueue frontend assets
     */
    private static function enqueue_frontend_assets() {
        static $assets_enqueued = false;
        
        if ( $assets_enqueued ) {
            return;
        }

        // Allow overriding shortcode CSS via WP Admin (GoldenStay → Shortcode Styles)
        $custom_css = get_option( self::OPT_FRONTEND_CSS );
        if ( is_string( $custom_css ) && trim( $custom_css ) !== '' ) {
            wp_register_style( 'goldenstay-frontend-css', false, array(), GOLDENSTAY_VERSION );
            wp_enqueue_style( 'goldenstay-frontend-css' );
            wp_add_inline_style( 'goldenstay-frontend-css', $custom_css );
        } else {
            wp_enqueue_style(
                'goldenstay-frontend-css',
                GOLDENSTAY_PLUGIN_URL . 'assets/frontend-style.css',
                array(),
                GOLDENSTAY_VERSION
            );
        }
        
        wp_enqueue_script( 
            'goldenstay-frontend-js', 
            GOLDENSTAY_PLUGIN_URL . 'assets/frontend-script.js',
            array( 'jquery' ),
            GOLDENSTAY_VERSION,
            true
        );
        
        wp_localize_script( 'goldenstay-frontend-js', 'goldenStayFrontend', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'apiUrl' => GoldenStay_Manager::get_api_url(),
            'nonce' => wp_create_nonce( 'goldenstay_frontend_nonce' )
        ));
        
        $assets_enqueued = true;
    }
}





