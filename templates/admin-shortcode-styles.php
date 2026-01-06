<?php
/**
 * Admin Shortcode Styles Page Template
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( 'You do not have permission to access this page.' );
}

$is_updated = isset( $_GET['updated'] ) && $_GET['updated'] === '1';
$is_reset = isset( $_GET['reset'] ) && $_GET['reset'] === '1';

// Current saved overrides (empty/absent = default)
$custom_frontend_css = get_option( GoldenStay_Admin::OPT_FRONTEND_CSS );
$custom_hb_booking_css = get_option( GoldenStay_Admin::OPT_HB_BOOKING_CSS );
$custom_hb_availability_css = get_option( GoldenStay_Admin::OPT_HB_AVAILABILITY_CSS );

// Default CSS (from plugin files)
$default_frontend_css = @file_get_contents( GOLDENSTAY_PLUGIN_DIR . 'assets/frontend-style.css' );
$default_hb_booking_css = @file_get_contents( GOLDENSTAY_PLUGIN_DIR . 'assets/hbook/css/gs-booking.css' );
$default_hb_availability_css = @file_get_contents( GOLDENSTAY_PLUGIN_DIR . 'assets/hbook/css/gs-availability.css' );

if ( $default_frontend_css === false ) {
    $default_frontend_css = '';
}
if ( $default_hb_booking_css === false ) {
    $default_hb_booking_css = '';
}
if ( $default_hb_availability_css === false ) {
    $default_hb_availability_css = '';
}

// Values to show in editors
$frontend_css_value = ( is_string( $custom_frontend_css ) && trim( $custom_frontend_css ) !== '' )
    ? $custom_frontend_css
    : $default_frontend_css;

$hb_booking_css_value = ( is_string( $custom_hb_booking_css ) && trim( $custom_hb_booking_css ) !== '' )
    ? $custom_hb_booking_css
    : $default_hb_booking_css;

$hb_availability_css_value = ( is_string( $custom_hb_availability_css ) && trim( $custom_hb_availability_css ) !== '' )
    ? $custom_hb_availability_css
    : $default_hb_availability_css;

$frontend_state = ( is_string( $custom_frontend_css ) && trim( $custom_frontend_css ) !== '' ) ? 'Custom' : 'Default';
$hb_booking_state = ( is_string( $custom_hb_booking_css ) && trim( $custom_hb_booking_css ) !== '' ) ? 'Custom' : 'Default';
$hb_availability_state = ( is_string( $custom_hb_availability_css ) && trim( $custom_hb_availability_css ) !== '' ) ? 'Custom' : 'Default';

// Read-only HTML previews (static, for reference)
$properties_html_preview = <<<HTML
<!-- Shortcode: [goldenstay_properties] -->
<div class="goldenstay-properties-wrapper">
  <div class="gs-filter-bar">
    <input type="text" id="gs-search" class="gs-search-input" placeholder="🔍 Search properties..." />
    <select id="gs-filter-location" class="gs-filter-select">
      <option value="">All Locations</option>
    </select>
    <button id="gs-clear-filters" class="gs-btn gs-btn-secondary">Clear</button>
  </div>

  <div id="goldenstay-properties-container" class="gs-properties-grid">
    <div class="gs-loading-spinner">
      <div class="gs-spinner"></div>
      <p>Loading properties...</p>
    </div>
  </div>
</div>
HTML;

$property_html_preview = <<<HTML
<!-- Shortcode: [goldenstay_property id="123"] -->
<div class="goldenstay-property-single" data-property-id="123">
  <div id="gs-property-loading" class="gs-loading-spinner">
    <div class="gs-spinner"></div>
    <p>Loading property...</p>
  </div>

  <div id="gs-property-content" style="display:none;">
    <div id="gs-property-info" class="gs-property-info"></div>

    <div class="gs-booking-section">
      <h2>📅 Check Availability & Book</h2>
      <div id="gs-property-calendar" class="gs-booking-calendar"></div>

      <div id="gs-booking-form" class="gs-booking-form" style="display:none;">
        <h3>Complete Your Booking</h3>
        <form id="gs-booking-form-element">
          <div class="gs-form-row">
            <label>Check-in Date</label>
            <input type="date" id="gs-checkin" required readonly />
          </div>
          <div class="gs-form-row">
            <label>Check-out Date</label>
            <input type="date" id="gs-checkout" required readonly />
          </div>
          <div class="gs-form-row">
            <label>Number of Guests</label>
            <input type="number" id="gs-guests" min="1" required />
          </div>
          <div class="gs-form-row">
            <label>Your Name</label>
            <input type="text" id="gs-name" required />
          </div>
          <div class="gs-form-row">
            <label>Email</label>
            <input type="email" id="gs-email" required />
          </div>
          <div class="gs-form-actions">
            <button type="button" id="gs-cancel-booking" class="gs-btn gs-btn-secondary">Cancel</button>
            <button type="submit" id="gs-submit-booking" class="gs-btn gs-btn-primary">Book Now</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
HTML;
?>

<div class="wrap goldenstay-settings-wrap goldenstay-shortcode-styles-page">
    <h1>
        <span class="dashicons dashicons-admin-appearance"></span>
        Shortcode Styles
    </h1>

    <div class="goldenstay-settings-container">
        <?php if ( $is_updated ) : ?>
            <div class="goldenstay-card">
                <div class="goldenstay-card-body">
                    <div class="goldenstay-notice success">
                        <span class="dashicons dashicons-yes-alt"></span>
                        CSS saved. Changes apply immediately on the frontend.
                    </div>
                </div>
            </div>
        <?php elseif ( $is_reset ) : ?>
            <div class="goldenstay-card">
                <div class="goldenstay-card-body">
                    <div class="goldenstay-notice info">
                        <span class="dashicons dashicons-info"></span>
                        Reset complete. Defaults are now active.
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="goldenstay-card">
            <div class="goldenstay-card-header">
                <span class="dashicons dashicons-editor-code"></span>
                <h2>HTML markup (read-only reference)</h2>
            </div>
            <div class="goldenstay-card-body">
                <p class="description">
                    This is the <strong>static wrapper markup</strong> produced by the shortcodes.
                    Most inner content is rendered dynamically by JavaScript / API responses.
                </p>

                <h3>[goldenstay_properties]</h3>
                <textarea id="goldenstay_shortcode_html_properties" class="large-text code" rows="14" readonly><?php echo esc_textarea( $properties_html_preview ); ?></textarea>

                <h3 style="margin-top: 18px;">[goldenstay_property id="123"]</h3>
                <textarea id="goldenstay_shortcode_html_property" class="large-text code" rows="26" readonly><?php echo esc_textarea( $property_html_preview ); ?></textarea>
            </div>
        </div>

        <div class="goldenstay-card">
            <div class="goldenstay-card-header">
                <span class="dashicons dashicons-art"></span>
                <h2>CSS editor</h2>
            </div>
            <div class="goldenstay-card-body">
                <p class="description">
                    Edit the CSS below and click <strong>Save CSS</strong>.
                    If you leave an editor empty and save, it will fall back to the plugin default CSS file.
                </p>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="goldenstay_save_shortcode_styles" />
                    <?php wp_nonce_field( 'goldenstay_shortcode_styles_save', 'goldenstay_nonce' ); ?>

                    <h3>GoldenStay shortcodes CSS <small>(<?php echo esc_html( $frontend_state ); ?>)</small></h3>
                    <textarea id="goldenstay_frontend_css" class="large-text code" rows="20" name="goldenstay_frontend_css"><?php echo esc_textarea( $frontend_css_value ); ?></textarea>

                    <h3 style="margin-top: 18px;">HBook booking form compat CSS <small>(<?php echo esc_html( $hb_booking_state ); ?>)</small></h3>
                    <textarea id="goldenstay_hb_booking_css" class="large-text code" rows="14" name="goldenstay_hb_booking_css"><?php echo esc_textarea( $hb_booking_css_value ); ?></textarea>

                    <h3 style="margin-top: 18px;">HBook availability compat CSS <small>(<?php echo esc_html( $hb_availability_state ); ?>)</small></h3>
                    <textarea id="goldenstay_hb_availability_css" class="large-text code" rows="8" name="goldenstay_hb_availability_css"><?php echo esc_textarea( $hb_availability_css_value ); ?></textarea>

                    <p class="submit">
                        <button type="submit" class="button button-primary">Save CSS</button>
                    </p>
                </form>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 10px;">
                    <input type="hidden" name="action" value="goldenstay_reset_shortcode_styles" />
                    <?php wp_nonce_field( 'goldenstay_shortcode_styles_reset', 'goldenstay_nonce' ); ?>
                    <button
                        type="submit"
                        class="button button-secondary"
                        onclick="return confirm('Reset all GoldenStay shortcode styles to plugin defaults?');"
                    >
                        Reset to defaults
                    </button>
                </form>

                <p class="description" style="margin-top: 12px;">
                    Tip: if you use a caching plugin/CDN, you may need to purge cache after changing styles.
                </p>
            </div>
        </div>
    </div>
</div>


