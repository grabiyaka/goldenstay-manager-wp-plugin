<?php
/**
 * Frontend Properties List Template
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<div class="goldenstay-properties-wrapper">
    <?php if ( $atts['show_filter'] === 'yes' ) : ?>
    <div class="gs-filter-bar">
        <input 
            type="text" 
            id="gs-search" 
            class="gs-search-input"
            placeholder="🔍 Search properties..." 
        />
        <select id="gs-filter-location" class="gs-filter-select">
            <option value="">All Locations</option>
        </select>
        <button id="gs-clear-filters" class="gs-btn gs-btn-secondary">Clear</button>
    </div>
    <?php endif; ?>
    
    <div id="goldenstay-properties-container" class="gs-properties-grid">
        <div class="gs-loading-spinner">
            <div class="gs-spinner"></div>
            <p>Loading properties...</p>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    goldenStayLoadProperties(<?php echo (int)$atts['limit']; ?>);
});
</script>





