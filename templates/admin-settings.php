<?php
/**
 * Admin Settings Page Template
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$is_authenticated = ! empty( get_option( 'goldenstay_api_token' ) );
$user_data = $is_authenticated ? get_option( 'goldenstay_user_data' ) : null;
$fee_ids_opt = get_option( 'goldenstay_calc_prices_fee_ids', array() );
$fee_ids_str = is_array( $fee_ids_opt ) ? implode( ',', array_map( 'intval', $fee_ids_opt ) ) : '';
$excluded_ids_opt = get_option( 'goldenstay_excluded_property_ids', array() );
$excluded_ids_str = is_array( $excluded_ids_opt ) ? implode( ',', array_map( 'intval', $excluded_ids_opt ) ) : '';
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
                                <tr>
                                    <th scope="row">
                                        <label for="calendar_horizon_months">Calendar horizon (months)</label>
                                    </th>
                                    <td>
                                        <input
                                            type="number"
                                            id="calendar_horizon_months"
                                            name="calendar_horizon_months"
                                            class="small-text"
                                            min="3"
                                            max="60"
                                            step="1"
                                            value="<?php echo esc_attr( (int) get_option( 'goldenstay_calendar_horizon_months', 24 ) ); ?>"
                                        />
                                        <p class="description">
                                            How many months ahead to allow date selection (e.g. 24 = 2 years, 36 = 3 years).
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="excluded_property_ids">Hide property IDs on website</label>
                                    </th>
                                    <td>
                                        <input
                                            type="text"
                                            id="excluded_property_ids"
                                            name="excluded_property_ids"
                                            class="regular-text"
                                            value="<?php echo esc_attr( $excluded_ids_str ); ?>"
                                            placeholder="e.g. 123,456"
                                        />
                                        <p class="description">
                                            Optional. Comma-separated GoldenStay <strong>property IDs</strong> to exclude from the frontend
                                            shortcode <code>[goldenstay_properties]</code>. This does not delete properties in your PMS/API.
                                        </p>
                                    </td>
                                </tr>
                                <!-- <tr>
                                    <th scope="row">
                                        <label for="calc_prices_fee_ids">Calc-prices fee IDs</label>
                                    </th>
                                    <td>
                                        <input
                                            type="text"
                                            id="calc_prices_fee_ids"
                                            name="calc_prices_fee_ids"
                                            class="regular-text"
                                            value="<?php echo esc_attr( $fee_ids_str ); ?>"
                                            placeholder="e.g. 3372,3373,3382"
                                        />
                                        <p class="description">
                                            Optional. Comma-separated Additional Fee IDs to include in price calculation (<code>/reservation/calc-prices</code>).
                                            Leave empty to auto-include non-optional fees.
                                        </p>
                                    </td>
                                </tr> -->
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





