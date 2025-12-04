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

// Константы плагина
define( 'GOLDENSTAY_VERSION', '0.0.1' );
define( 'GOLDENSTAY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GOLDENSTAY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Класс основного функционала плагина
class GoldenStay_Manager {
    
    private static $instance = null;
    
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Хуки инициализации
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
        add_action( 'wp_ajax_goldenstay_login', array( $this, 'ajax_login' ) );
        add_action( 'wp_ajax_goldenstay_logout', array( $this, 'ajax_logout' ) );
        add_action( 'wp_ajax_goldenstay_check_auth', array( $this, 'ajax_check_auth' ) );
        add_action( 'wp_ajax_goldenstay_get_properties', array( $this, 'ajax_get_properties' ) );
        add_action( 'wp_ajax_goldenstay_get_reservations', array( $this, 'ajax_get_reservations' ) );
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
            'apiUrl' => self::get_api_url(),
            'isAuthenticated' => $this->is_authenticated()
        ));
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        $is_authenticated = $this->is_authenticated();
        $user_data = $is_authenticated ? get_option( 'goldenstay_user_data' ) : null;
        
        ?>
        <div class="wrap goldenstay-settings-wrap">
            <h1>
                <span class="dashicons dashicons-building"></span>
                GoldenStay Settings
            </h1>
            
            <div class="goldenstay-settings-container">
                <?php if ( $is_authenticated && $user_data ) : ?>
                    <!-- Authenticated -->
                    <div class="goldenstay-auth-success">
                        <div class="goldenstay-card">
                            <div class="goldenstay-card-header success">
                                <span class="dashicons dashicons-yes-alt"></span>
                                <h2>You are authenticated</h2>
                            </div>
                            <div class="goldenstay-card-body">
                                <div class="user-info">
                                    <p><strong>Email:</strong> <?php echo esc_html( $user_data['email'] ?? 'N/A' ); ?></p>
                                    <p><strong>Name:</strong> <?php echo esc_html( $user_data['name'] ?? 'N/A' ); ?></p>
                                    <p><strong>Token:</strong> <code><?php echo esc_html( substr( get_option( 'goldenstay_api_token' ), 0, 20 ) . '...' ); ?></code></p>
                                </div>
                                <button type="button" class="button button-secondary" id="goldenstay-logout-btn">
                                    <span class="dashicons dashicons-exit"></span>
                                    Logout
                                </button>
                            </div>
                        </div>
                        
                        <div class="goldenstay-card">
                            <div class="goldenstay-card-header">
                                <span class="dashicons dashicons-admin-generic"></span>
                                <h2>API Settings</h2>
                            </div>
                            <div class="goldenstay-card-body">
                                <form id="goldenstay-api-settings-form">
                                    <table class="form-table">
                                        <tr>
                                            <th scope="row">
                                                <label for="api_url">API URL</label>
                                            </th>
                                            <td>
                                                <input 
                                                    type="url" 
                                                    id="api_url" 
                                                    name="api_url" 
                                                    class="regular-text"
                                                    value="<?php echo esc_attr( get_option( 'goldenstay_api_url', 'http://localhost:3000' ) ); ?>"
                                                />
                                                <p class="description">Your API service URL</p>
                                            </td>
                                        </tr>
                                    </table>
                                    <button type="submit" class="button button-primary">Save Settings</button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php else : ?>
                    <!-- Login Form -->
                    <div class="goldenstay-auth-form">
                        <div class="goldenstay-card">
                            <div class="goldenstay-card-header">
                                <span class="dashicons dashicons-lock"></span>
                                <h2>GoldenStay API Authentication</h2>
                            </div>
                            <div class="goldenstay-card-body">
                                <p class="description">Login to your GoldenStay account to start using the plugin</p>
                                
                                <form id="goldenstay-login-form">
                                    <table class="form-table">
                                        <tr>
                                            <th scope="row">
                                                <label for="api_url">API URL</label>
                                            </th>
                                            <td>
                                                <input 
                                                    type="url" 
                                                    id="api_url" 
                                                    name="api_url" 
                                                    class="regular-text"
                                                    value="<?php echo esc_attr( get_option( 'goldenstay_api_url', 'http://localhost:3000' ) ); ?>"
                                                    required
                                                />
                                                <p class="description">Your API service URL</p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row">
                                                <label for="email">Email</label>
                                            </th>
                                            <td>
                                                <input 
                                                    type="email" 
                                                    id="email" 
                                                    name="email" 
                                                    class="regular-text"
                                                    placeholder="your@email.com"
                                                    required
                                                />
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row">
                                                <label for="password">Password</label>
                                            </th>
                                            <td>
                                                <input 
                                                    type="password" 
                                                    id="password" 
                                                    name="password" 
                                                    class="regular-text"
                                                    placeholder="••••••••"
                                                    required
                                                />
                                            </td>
                                        </tr>
                                    </table>
                                    
                                    <div id="goldenstay-login-message"></div>
                                    
                                    <p class="submit">
                                        <button type="submit" class="button button-primary button-large" id="goldenstay-login-btn">
                                            <span class="dashicons dashicons-unlock"></span>
                                            Login to Account
                                        </button>
                                    </p>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * AJAX: Login via API
     */
    public function ajax_login() {
        check_ajax_referer( 'goldenstay_admin_nonce', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'You do not have permission to perform this action' ) );
        }
        
        $email = sanitize_email( $_POST['email'] ?? '' );
        $password = $_POST['password'] ?? '';
        $api_url = esc_url_raw( $_POST['api_url'] ?? '' );
        
        if ( empty( $email ) || empty( $password ) || empty( $api_url ) ) {
            wp_send_json_error( array( 'message' => 'All fields are required' ) );
        }
        
        // Save API URL
        update_option( 'goldenstay_api_url', $api_url );
        
        // Send request to API
        $response = wp_remote_post( trailingslashit( $api_url ) . 'user/login', array(
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode( array(
                'email' => $email,
                'password' => $password,
            )),
            'timeout' => 30,
        ));
        
        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array( 
                'message' => 'API connection error: ' . $response->get_error_message() 
            ));
        }
        
        $status_code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        
        if ( $status_code === 200 && isset( $body['token'] ) ) {
            // Successful authentication
            update_option( 'goldenstay_api_token', $body['token'] );
            update_option( 'goldenstay_user_data', array(
                'email' => $email,
                'name' => $body['user']['name'] ?? $body['name'] ?? 'User',
                'id' => $body['user']['id'] ?? $body['id'] ?? null,
            ));
            
            wp_send_json_success( array( 
                'message' => 'Authentication successful! Reloading page...',
                'user' => $body['user'] ?? array(),
            ));
        } else {
            // Authentication error
            $error_message = $body['message'] ?? $body['error'] ?? 'Invalid email or password';
            wp_send_json_error( array( 'message' => $error_message ) );
        }
    }
    
    /**
     * AJAX: Logout
     */
    public function ajax_logout() {
        check_ajax_referer( 'goldenstay_admin_nonce', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'You do not have permission to perform this action' ) );
        }
        
        delete_option( 'goldenstay_api_token' );
        delete_option( 'goldenstay_user_data' );
        
        wp_send_json_success( array( 'message' => 'You have successfully logged out' ) );
    }
    
    /**
     * AJAX: Check authentication
     */
    public function ajax_check_auth() {
        check_ajax_referer( 'goldenstay_admin_nonce', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'You do not have permission to perform this action' ) );
        }
        
        $is_authenticated = $this->is_authenticated();
        
        wp_send_json_success( array( 
            'authenticated' => $is_authenticated,
            'user_data' => $is_authenticated ? get_option( 'goldenstay_user_data' ) : null,
        ));
    }
    
    /**
     * AJAX: Get properties from API
     */
    public function ajax_get_properties() {
        check_ajax_referer( 'goldenstay_admin_nonce', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'You do not have permission to perform this action' ) );
        }
        
        $token = self::get_api_token();
        if ( empty( $token ) ) {
            wp_send_json_error( array( 'message' => 'Not authenticated. Please login first.' ) );
        }
        
        $api_url = self::get_api_url();
        
        // Send request to API
        $response = wp_remote_get( trailingslashit( $api_url ) . 'property', array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => $token,
            ),
            'timeout' => 30,
        ));
        
        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array( 
                'message' => 'API connection error: ' . $response->get_error_message() 
            ));
        }
        
        $status_code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        
        if ( $status_code === 200 ) {
            wp_send_json_success( array( 
                'properties' => $body,
                'count' => is_array( $body ) ? count( $body ) : 0,
            ));
        } else if ( $status_code === 401 ) {
            wp_send_json_error( array( 
                'message' => 'Authentication expired. Please login again.',
                'code' => 'auth_expired'
            ));
        } else {
            $error_message = $body['message'] ?? $body['error'] ?? 'Failed to fetch properties';
            wp_send_json_error( array( 'message' => $error_message ) );
        }
    }
    
    /**
     * Render properties page
     */
    public function render_properties_page() {
        $is_authenticated = $this->is_authenticated();
        
        if ( ! $is_authenticated ) {
            ?>
            <div class="wrap goldenstay-settings-wrap">
                <h1>
                    <span class="dashicons dashicons-building"></span>
                    Properties
                </h1>
                <div class="goldenstay-settings-container">
                    <div class="goldenstay-card">
                        <div class="goldenstay-card-body">
                            <div class="goldenstay-notice error">
                                <span class="dashicons dashicons-warning"></span>
                                Please <a href="<?php echo admin_url( 'admin.php?page=goldenstay-settings' ); ?>">login</a> first to view properties.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php
            return;
        }
        
        ?>
        <div class="wrap goldenstay-settings-wrap">
            <h1>
                <span class="dashicons dashicons-building"></span>
                Properties
            </h1>
            
            <div class="goldenstay-settings-container">
                <div class="goldenstay-card">
                    <div class="goldenstay-card-header">
                        <span class="dashicons dashicons-admin-multisite"></span>
                        <h2>Your Properties</h2>
                        <button type="button" class="button button-secondary" id="goldenstay-refresh-properties">
                            <span class="dashicons dashicons-update"></span>
                            Refresh
                        </button>
                    </div>
                    <div class="goldenstay-card-body">
                        <div id="goldenstay-properties-loading" class="goldenstay-loading-state">
                            <span class="goldenstay-loader-large"></span>
                            <p>Loading properties...</p>
                        </div>
                        
                        <div id="goldenstay-properties-error" class="goldenstay-error-state" style="display: none;">
                            <div class="goldenstay-notice error">
                                <span class="dashicons dashicons-warning"></span>
                                <span id="goldenstay-properties-error-message"></span>
                            </div>
                        </div>
                        
                        <div id="goldenstay-properties-empty" class="goldenstay-empty-state" style="display: none;">
                            <span class="dashicons dashicons-building"></span>
                            <p>No properties found</p>
                        </div>
                        
                        <div id="goldenstay-properties-list" class="goldenstay-properties-grid" style="display: none;">
                            <!-- Properties will be loaded here via JS -->
                        </div>
                    </div>
                </div>
                
                <!-- Property Details Modal -->
                <div id="goldenstay-property-modal" class="goldenstay-modal" style="display: none;">
                    <div class="goldenstay-modal-overlay"></div>
                    <div class="goldenstay-modal-container">
                        <div class="goldenstay-modal-header">
                            <h2 id="goldenstay-property-modal-title">Property Details</h2>
                            <button type="button" class="goldenstay-modal-close" id="goldenstay-close-modal">
                                <span class="dashicons dashicons-no-alt"></span>
                            </button>
                        </div>
                        <div class="goldenstay-modal-body">
                            <div id="goldenstay-property-details-loading" class="goldenstay-loading-state">
                                <span class="goldenstay-loader-large"></span>
                                <p>Loading reservations...</p>
                            </div>
                            <div id="goldenstay-property-details-content" style="display: none;">
                                <div id="goldenstay-property-info" class="property-info-section"></div>
                                <div id="goldenstay-reservations-calendar" class="reservations-calendar"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * AJAX: Get reservations for property
     */
    public function ajax_get_reservations() {
        check_ajax_referer( 'goldenstay_admin_nonce', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'You do not have permission to perform this action' ) );
        }
        
        $token = self::get_api_token();
        if ( empty( $token ) ) {
            wp_send_json_error( array( 'message' => 'Not authenticated. Please login first.' ) );
        }
        
        $property_id = isset( $_POST['property_id'] ) ? intval( $_POST['property_id'] ) : 0;
        if ( empty( $property_id ) ) {
            wp_send_json_error( array( 'message' => 'Property ID is required' ) );
        }
        
        $api_url = self::get_api_url();
        
        // Send request to API
        $response = wp_remote_post( trailingslashit( $api_url ) . 'reservation/property', array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => $token,
            ),
            'body' => json_encode( array(
                'ids' => array( $property_id ),
            )),
            'timeout' => 30,
        ));
        
        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array( 
                'message' => 'API connection error: ' . $response->get_error_message() 
            ));
        }
        
        $status_code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        
        if ( $status_code === 200 ) {
            wp_send_json_success( array( 
                'reservations' => $body,
                'count' => is_array( $body ) ? count( $body ) : 0,
            ));
        } else if ( $status_code === 401 ) {
            wp_send_json_error( array( 
                'message' => 'Authentication expired. Please login again.',
                'code' => 'auth_expired'
            ));
        } else {
            $error_message = $body['message'] ?? $body['error'] ?? 'Failed to fetch reservations';
            wp_send_json_error( array( 'message' => $error_message ) );
        }
    }
    
    /**
     * Check if authenticated
     */
    private function is_authenticated() {
        $token = get_option( 'goldenstay_api_token' );
        return ! empty( $token );
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

// Initialize plugin
function goldenstay_manager_init() {
    return GoldenStay_Manager::get_instance();
}
add_action( 'plugins_loaded', 'goldenstay_manager_init' );

// Simple test shortcode: [gs_hello]
add_shortcode( 'gs_hello', function() {
    return '<h2>Hello from GoldenStay Plugin! 🏡</h2>';
} );
