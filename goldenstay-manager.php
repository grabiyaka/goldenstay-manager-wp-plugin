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
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts( $hook ) {
        if ( 'toplevel_page_goldenstay-settings' !== $hook ) {
            return;
        }
        
        wp_enqueue_style( 
            'goldenstay-admin-css', 
            GOLDENSTAY_PLUGIN_URL . 'assets/admin-style.css',
            array(),
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
            'nonce' => wp_create_nonce( 'goldenstay_admin_nonce' )
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
