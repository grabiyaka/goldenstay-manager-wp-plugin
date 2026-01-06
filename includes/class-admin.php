<?php
/**
 * Admin functionality
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GoldenStay_Admin {
    
    private static $instance = null;

    // Option names for shortcode CSS overrides
    const OPT_FRONTEND_CSS = 'goldenstay_shortcode_css_frontend';
    const OPT_HB_BOOKING_CSS = 'goldenstay_shortcode_css_hb_booking';
    const OPT_HB_AVAILABILITY_CSS = 'goldenstay_shortcode_css_hb_availability';
    
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

        // Admin-post handlers for saving / resetting shortcode styles
        add_action( 'admin_post_goldenstay_save_shortcode_styles', array( $this, 'handle_save_shortcode_styles' ) );
        add_action( 'admin_post_goldenstay_reset_shortcode_styles', array( $this, 'handle_reset_shortcode_styles' ) );
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

        // Shortcode styles editor
        add_submenu_page(
            'goldenstay-settings',
            'Shortcode Styles',
            'Shortcode Styles',
            'manage_options',
            'goldenstay-shortcode-styles',
            array( $this, 'render_shortcode_styles_page' )
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
            'adminUrl' => admin_url(),
            'nonce' => wp_create_nonce( 'goldenstay_admin_nonce' ),
            'apiUrl' => GoldenStay_Manager::get_api_url(),
            'isAuthenticated' => $this->is_authenticated()
        ));

        // Code editor (CodeMirror) for Shortcode Styles page
        if ( strpos( $hook, 'goldenstay-shortcode-styles' ) !== false ) {
            wp_enqueue_style(
                'goldenstay-codemirror-dark',
                GOLDENSTAY_PLUGIN_URL . 'assets/codemirror-goldenstay-dark.css',
                array(),
                GOLDENSTAY_VERSION
            );

            $css_editor_settings = wp_enqueue_code_editor( array(
                'type' => 'text/css',
                'codemirror' => array(
                    'indentUnit' => 2,
                    'tabSize' => 2,
                    'lineNumbers' => true,
                    'autoRefresh' => true,
                    // Highlighting only (avoid noisy lint warnings for modern CSS vars, etc.)
                    'lint' => false,
                ),
            ) );

            $html_editor_settings = wp_enqueue_code_editor( array(
                'type' => 'text/html',
                'codemirror' => array(
                    'indentUnit' => 2,
                    'tabSize' => 2,
                    'lineNumbers' => true,
                    'autoRefresh' => true,
                    'readOnly' => true,
                    'lint' => false,
                ),
            ) );

            // Apply our dark theme for CodeMirror (styles are in assets/codemirror-goldenstay-dark.css)
            if ( $css_editor_settings !== false ) {
                $css_editor_settings['codemirror']['theme'] = 'goldenstay-dark';
            }
            if ( $html_editor_settings !== false ) {
                $html_editor_settings['codemirror']['theme'] = 'goldenstay-dark';
            }

            // Initialize editors if syntax highlighting is enabled in the user profile.
            if ( $css_editor_settings !== false || $html_editor_settings !== false ) {
                $inline = 'jQuery(function(){';

                if ( $css_editor_settings !== false ) {
                    $inline .= 'if (window.wp && wp.codeEditor) {'
                        . 'wp.codeEditor.initialize("goldenstay_frontend_css", ' . wp_json_encode( $css_editor_settings, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ) . ');'
                        . 'wp.codeEditor.initialize("goldenstay_hb_booking_css", ' . wp_json_encode( $css_editor_settings, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ) . ');'
                        . 'wp.codeEditor.initialize("goldenstay_hb_availability_css", ' . wp_json_encode( $css_editor_settings, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ) . ');'
                        . '}';
                }

                if ( $html_editor_settings !== false ) {
                    $inline .= 'if (window.wp && wp.codeEditor) {'
                        . 'wp.codeEditor.initialize("goldenstay_shortcode_html_properties", ' . wp_json_encode( $html_editor_settings, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ) . ');'
                        . 'wp.codeEditor.initialize("goldenstay_shortcode_html_property", ' . wp_json_encode( $html_editor_settings, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ) . ');'
                        . '}';
                }

                $inline .= '});';

                // Attach to the code-editor handle, which wp_enqueue_code_editor() enqueues.
                wp_add_inline_script( 'code-editor', $inline );
            }
        }
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
     * Render shortcode styles editor page
     */
    public function render_shortcode_styles_page() {
        require_once GOLDENSTAY_PLUGIN_DIR . 'templates/admin-shortcode-styles.php';
    }

    /**
     * Save shortcode styles (admin-post)
     */
    public function handle_save_shortcode_styles() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'You do not have permission to perform this action.' );
        }

        check_admin_referer( 'goldenstay_shortcode_styles_save', 'goldenstay_nonce' );

        $frontend_css = $this->sanitize_custom_css( $_POST['goldenstay_frontend_css'] ?? '' );
        $hb_booking_css = $this->sanitize_custom_css( $_POST['goldenstay_hb_booking_css'] ?? '' );
        $hb_availability_css = $this->sanitize_custom_css( $_POST['goldenstay_hb_availability_css'] ?? '' );

        $this->update_css_option( self::OPT_FRONTEND_CSS, $frontend_css );
        $this->update_css_option( self::OPT_HB_BOOKING_CSS, $hb_booking_css );
        $this->update_css_option( self::OPT_HB_AVAILABILITY_CSS, $hb_availability_css );

        wp_safe_redirect( add_query_arg(
            array( 'page' => 'goldenstay-shortcode-styles', 'updated' => '1' ),
            admin_url( 'admin.php' )
        ) );
        exit;
    }

    /**
     * Reset shortcode styles to defaults (admin-post)
     */
    public function handle_reset_shortcode_styles() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'You do not have permission to perform this action.' );
        }

        check_admin_referer( 'goldenstay_shortcode_styles_reset', 'goldenstay_nonce' );

        delete_option( self::OPT_FRONTEND_CSS );
        delete_option( self::OPT_HB_BOOKING_CSS );
        delete_option( self::OPT_HB_AVAILABILITY_CSS );

        wp_safe_redirect( add_query_arg(
            array( 'page' => 'goldenstay-shortcode-styles', 'reset' => '1' ),
            admin_url( 'admin.php' )
        ) );
        exit;
    }
    
    /**
     * Check if authenticated
     */
    private function is_authenticated() {
        $token = get_option( 'goldenstay_api_token' );
        return ! empty( $token );
    }

    /**
     * Sanitize CSS that will be printed inside a <style> tag.
     * We remove any HTML tags to prevent breaking out of <style> context.
     */
    private function sanitize_custom_css( $css ) {
        $css = is_string( $css ) ? $css : '';
        $css = wp_unslash( $css );
        $css = str_replace( "\0", '', $css );
        // Strip any tags like </style><script>...
        $css = wp_kses( $css, array() );
        return $css;
    }

    /**
     * Update an option with CSS. Empty string means "use default" (delete option).
     */
    private function update_css_option( $option_name, $css ) {
        $css = is_string( $css ) ? $css : '';
        if ( trim( $css ) === '' ) {
            delete_option( $option_name );
            return;
        }
        update_option( $option_name, $css );
    }
}





