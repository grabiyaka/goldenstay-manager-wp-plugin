/**
 * GoldenStay Frontend Scripts
 */

(function($) {
    'use strict';

    // Global state
    let selectedDates = { checkin: null, checkout: null };
    let currentProperty = null;

    /**
     * Load properties list
     */
    window.goldenStayLoadProperties = function(limit) {
        const $container = $('#goldenstay-properties-container');
        
        $.ajax({
            url: goldenStayFrontend.ajaxUrl,
            type: 'POST',
            data: {
                action: 'goldenstay_get_properties_public',
                nonce: goldenStayFrontend.nonce
            },
            dataType: 'json',
            success: function(response) {
                $container.empty();
                
                if (response.success && response.data.properties) {
                    let properties = response.data.properties;
                    
                    if (limit > 0) {
                        properties = properties.slice(0, limit);
                    }
                    
                    if (properties.length === 0) {
                        $container.append('<p class="gs-no-results">No properties found</p>');
                        return;
                    }
                    
                    properties.forEach(function(property) {
                        const card = createPropertyCard(property);
                        $container.append(card);
                    });
                } else {
                    $container.append('<p class="gs-error">Failed to load properties</p>');
                }
            },
            error: function() {
                $container.empty();
                $container.append('<p class="gs-error">Connection error. Please try again.</p>');
            }
        });
    };

    /**
     * Create property card
     */
    function createPropertyCard(property) {
        const card = $('<div>', { class: 'gs-property-card' });
        
        // Icon
        const icon = $('<div>', { class: 'gs-property-icon' })
            .html('<svg viewBox="0 0 24 24"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>');
        
        // Details
        const details = $('<div>', { class: 'gs-property-details' });
        
        const title = $('<h3>', { class: 'gs-property-title' })
            .text(property.name || property.internal_name || 'Property');
        
        const meta = $('<div>', { class: 'gs-property-meta' });
        
        if (property.address) {
            meta.append('<p>📍 ' + property.address + '</p>');
        }
        
        if (property.city || property.country) {
            const location = [property.city, property.country].filter(Boolean).join(', ');
            meta.append('<p>🌍 ' + location + '</p>');
        }
        
        if (property.max_guests) {
            meta.append('<p>👥 Up to ' + property.max_guests + ' guests</p>');
        }
        
        if (property.bedrooms) {
            meta.append('<p>🛏️ ' + property.bedrooms + ' bedroom' + (property.bedrooms > 1 ? 's' : '') + '</p>');
        }
        
        const viewBtn = $('<button>', { 
            class: 'gs-btn gs-btn-primary gs-btn-block',
            'data-property-id': property.id
        })
            .text('View & Book')
            .on('click', function() {
                window.location.href = '?property_id=' + property.id;
            });
        
        details.append(title, meta, viewBtn);
        card.append(icon, details);
        
        return card;
    }

    /**
     * Load single property
     */
    window.goldenStayLoadProperty = function(propertyId) {
        const $loading = $('#gs-property-loading');
        const $content = $('#gs-property-content');
        
        $.ajax({
            url: goldenStayFrontend.ajaxUrl,
            type: 'POST',
            data: {
                action: 'goldenstay_get_property_public',
                nonce: goldenStayFrontend.nonce,
                property_id: propertyId
            },
            dataType: 'json',
            success: function(response) {
                $loading.hide();
                
                if (response.success && response.data.property) {
                    currentProperty = response.data.property;
                    renderPropertyInfo(currentProperty);
                    renderBookingCalendar(currentProperty);
                    $content.show();
                } else {
                    $content.html('<p class="gs-error">Property not found</p>').show();
                }
            },
            error: function() {
                $loading.hide();
                $content.html('<p class="gs-error">Failed to load property</p>').show();
            }
        });
    };

    /**
     * Render property info
     */
    function renderPropertyInfo(property) {
        const $info = $('#gs-property-info');
        $info.empty();
        
        const title = $('<h1>').text(property.name || property.internal_name || 'Property');
        $info.append(title);
        
        const grid = $('<div>', { class: 'gs-property-info-grid' });
        
        const infoItems = [
            { icon: '📍', label: 'Address', value: property.address },
            { icon: '🌍', label: 'Location', value: [property.city, property.country].filter(Boolean).join(', ') },
            { icon: '👥', label: 'Max Guests', value: property.max_guests },
            { icon: '🛏️', label: 'Bedrooms', value: property.bedrooms },
        ];
        
        infoItems.forEach(item => {
            if (item.value) {
                const infoItem = $('<div>', { class: 'gs-info-item' });
                infoItem.append('<span>' + item.icon + '</span>');
                infoItem.append('<div><strong>' + item.label + ':</strong> ' + item.value + '</div>');
                grid.append(infoItem);
            }
        });
        
        $info.append(grid);
    }

    /**
     * Render booking calendar
     */
    function renderBookingCalendar(property) {
        const $calendar = $('#gs-property-calendar');
        $calendar.empty();
        
        // Simple calendar for now (placeholder)
        const calendarHTML = `
            <div class="gs-date-picker">
                <div class="gs-date-field">
                    <label>Check-in Date</label>
                    <input type="date" id="gs-checkin-picker" class="gs-date-input" />
                </div>
                <div class="gs-date-field">
                    <label>Check-out Date</label>
                    <input type="date" id="gs-checkout-picker" class="gs-date-input" />
                </div>
                <button type="button" id="gs-check-availability" class="gs-btn gs-btn-primary">
                    Check Availability
                </button>
            </div>
            <div id="gs-availability-result"></div>
        `;
        
        $calendar.html(calendarHTML);
        
        // Check availability button handler
        $('#gs-check-availability').on('click', function() {
            const checkin = $('#gs-checkin-picker').val();
            const checkout = $('#gs-checkout-picker').val();
            
            if (!checkin || !checkout) {
                alert('Please select check-in and check-out dates');
                return;
            }
            
            checkAvailability(property.id, checkin, checkout);
        });
    }

    /**
     * Check availability
     */
    function checkAvailability(propertyId, dateFrom, dateTo) {
        const $result = $('#gs-availability-result');
        const $btn = $('#gs-check-availability');
        
        $btn.prop('disabled', true).text('Checking...');
        $result.html('<p>Checking availability...</p>');
        
        $.ajax({
            url: goldenStayFrontend.ajaxUrl,
            type: 'POST',
            data: {
                action: 'goldenstay_check_availability',
                nonce: goldenStayFrontend.nonce,
                property_id: propertyId,
                date_from: dateFrom,
                date_to: dateTo
            },
            dataType: 'json',
            success: function(response) {
                $btn.prop('disabled', false).text('Check Availability');
                
                if (response.success && response.data.available) {
                    $result.html('<div class="gs-success">✅ Available! Ready to book.</div>');
                    showBookingForm(dateFrom, dateTo);
                } else {
                    $result.html('<div class="gs-error">❌ Not available for selected dates</div>');
                }
            },
            error: function() {
                $btn.prop('disabled', false).text('Check Availability');
                $result.html('<div class="gs-error">Error checking availability</div>');
            }
        });
    }

    /**
     * Show booking form
     */
    function showBookingForm(dateFrom, dateTo) {
        const $form = $('#gs-booking-form');
        $('#gs-checkin').val(dateFrom);
        $('#gs-checkout').val(dateTo);
        $form.slideDown();
    }

    /**
     * Submit booking
     */
    $(document).on('submit', '#gs-booking-form-element', function(e) {
        e.preventDefault();
        
        const $btn = $('#gs-submit-booking');
        const originalText = $btn.text();
        
        $btn.prop('disabled', true).text('Booking...');
        
        const data = {
            action: 'goldenstay_create_booking',
            nonce: goldenStayFrontend.nonce,
            property_id: currentProperty.id,
            date_from: $('#gs-checkin').val(),
            date_to: $('#gs-checkout').val(),
            guests: $('#gs-guests').val(),
            name: $('#gs-name').val(),
            email: $('#gs-email').val()
        };
        
        $.ajax({
            url: goldenStayFrontend.ajaxUrl,
            type: 'POST',
            data: data,
            dataType: 'json',
            success: function(response) {
                $btn.prop('disabled', false).text(originalText);
                
                if (response.success) {
                    alert('✅ ' + response.data.message);
                    $('#gs-booking-form-element')[0].reset();
                    $('#gs-booking-form').slideUp();
                } else {
                    alert('❌ ' + response.data.message);
                }
            },
            error: function() {
                $btn.prop('disabled', false).text(originalText);
                alert('❌ Booking failed. Please try again.');
            }
        });
    });

    // Cancel booking button
    $(document).on('click', '#gs-cancel-booking', function() {
        $('#gs-booking-form').slideUp();
    });

})(jQuery);





