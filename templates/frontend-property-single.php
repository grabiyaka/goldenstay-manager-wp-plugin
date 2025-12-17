<?php
/**
 * Frontend Single Property Template
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<div class="goldenstay-property-single" data-property-id="<?php echo esc_attr( $atts['id'] ); ?>">
    <div id="gs-property-loading" class="gs-loading-spinner">
        <div class="gs-spinner"></div>
        <p>Loading property...</p>
    </div>
    
    <div id="gs-property-content" style="display:none;">
        <!-- Property Info -->
        <div id="gs-property-info" class="gs-property-info"></div>
        
        <!-- Booking Calendar -->
        <div class="gs-booking-section">
            <h2>📅 Check Availability & Book</h2>
            <div id="gs-property-calendar" class="gs-booking-calendar"></div>
            
            <!-- Booking Form -->
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

<script>
jQuery(document).ready(function($) {
    goldenStayLoadProperty(<?php echo (int)$atts['id']; ?>);
});
</script>




