<?php
/**
 * Admin Settings Page Template
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$is_authenticated = ! empty( get_option( 'goldenstay_api_token' ) );
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




