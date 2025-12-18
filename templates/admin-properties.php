<?php
/**
 * Admin Properties Page Template
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$is_authenticated = ! empty( get_option( 'goldenstay_api_token' ) );

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





