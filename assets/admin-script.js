/**
 * GoldenStay Admin Scripts
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        
        // Форма авторизации
        $('#goldenstay-login-form').on('submit', function(e) {
            e.preventDefault();
            
            const $form = $(this);
            const $btn = $('#goldenstay-login-btn');
            const $message = $('#goldenstay-login-message');
            
            // Получаем данные формы
            const formData = {
                action: 'goldenstay_login',
                nonce: goldenStayAdmin.nonce,
                email: $('#email').val(),
                password: $('#password').val(),
                api_url: $('#api_url').val()
            };
            
            // Disable button and add loader
            $btn.prop('disabled', true);
            $btn.html('<span class="dashicons dashicons-unlock"></span> Authenticating... <span class="goldenstay-loader"></span>');
            
            // Clear previous messages
            $message.empty();
            
            // Send AJAX request
            $.ajax({
                url: goldenStayAdmin.ajaxUrl,
                type: 'POST',
                data: formData,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Successful authentication
                        $message.html(
                            '<div class="goldenstay-notice success">' +
                            '<span class="dashicons dashicons-yes-alt"></span>' +
                            response.data.message +
                            '</div>'
                        );
                        
                        // Reload page after 1 second
                        setTimeout(function() {
                            location.reload();
                        }, 1000);
                    } else {
                        // Authentication error
                        showError(response.data.message);
                        resetButton();
                    }
                },
                error: function(xhr, status, error) {
                    showError('An error occurred while connecting to the server. Please try again later.');
                    resetButton();
                    console.error('AJAX Error:', error);
                }
            });
            
            function showError(message) {
                $message.html(
                    '<div class="goldenstay-notice error">' +
                    '<span class="dashicons dashicons-warning"></span>' +
                    message +
                    '</div>'
                );
            }
            
            function resetButton() {
                $btn.prop('disabled', false);
                $btn.html('<span class="dashicons dashicons-unlock"></span> Login to Account');
            }
        });
        
        // Logout
        $('#goldenstay-logout-btn').on('click', function(e) {
            e.preventDefault();
            
            if (!confirm('Are you sure you want to logout?')) {
                return;
            }
            
            const $btn = $(this);
            
            // Disable button
            $btn.prop('disabled', true);
            $btn.html('<span class="dashicons dashicons-exit"></span> Logging out... <span class="goldenstay-loader"></span>');
            
            // Send AJAX request
            $.ajax({
                url: goldenStayAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'goldenstay_logout',
                    nonce: goldenStayAdmin.nonce
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Reload page
                        location.reload();
                    } else {
                        alert('Error: ' + response.data.message);
                        $btn.prop('disabled', false);
                        $btn.html('<span class="dashicons dashicons-exit"></span> Logout');
                    }
                },
                error: function(xhr, status, error) {
                    alert('An error occurred while connecting to the server.');
                    $btn.prop('disabled', false);
                    $btn.html('<span class="dashicons dashicons-exit"></span> Logout');
                    console.error('AJAX Error:', error);
                }
            });
        });
        
        // Save API settings (for authenticated users)
        $('#goldenstay-api-settings-form').on('submit', function(e) {
            e.preventDefault();
            
            const $form = $(this);
            const apiUrl = $('#api_url').val();
            
            // TODO: Add AJAX save functionality
            alert('Settings will be saved. (Under development)');
        });
        
        // Load properties on properties page
        if ($('#goldenstay-properties-list').length) {
            loadProperties();
        }
        
        // Refresh properties button
        $('#goldenstay-refresh-properties').on('click', function(e) {
            e.preventDefault();
            loadProperties();
        });
        
    });
    
    /**
     * Load properties from API
     */
    function loadProperties() {
        const $loading = $('#goldenstay-properties-loading');
        const $error = $('#goldenstay-properties-error');
        const $empty = $('#goldenstay-properties-empty');
        const $list = $('#goldenstay-properties-list');
        
        // Show loading state
        $loading.show();
        $error.hide();
        $empty.hide();
        $list.hide();
        
        // Send AJAX request
        $.ajax({
            url: goldenStayAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'goldenstay_get_properties',
                nonce: goldenStayAdmin.nonce
            },
            dataType: 'json',
            success: function(response) {
                $loading.hide();
                
                if (response.success) {
                    const properties = response.data.properties;
                    
                    if (properties && properties.length > 0) {
                        renderProperties(properties);
                        $list.show();
                    } else {
                        $empty.show();
                    }
                } else {
                    showPropertiesError(response.data.message);
                    
                    // If auth expired, show login link
                    if (response.data.code === 'auth_expired') {
                        $('#goldenstay-properties-error-message').append(
                            ' <a href="' + goldenStayAdmin.adminUrl + 'admin.php?page=goldenstay-settings">Login again</a>'
                        );
                    }
                }
            },
            error: function(xhr, status, error) {
                $loading.hide();
                showPropertiesError('An error occurred while loading properties. Please try again.');
                console.error('AJAX Error:', error);
            }
        });
    }
    
    /**
     * Render properties list
     */
    function renderProperties(properties) {
        const $list = $('#goldenstay-properties-list');
        $list.empty();
        
        properties.forEach(function(property) {
            const card = $('<div>', { class: 'goldenstay-property-card' });
            
            // Property icon (no images)
            const icon = $('<div>', { class: 'property-icon' })
                .html('<span class="dashicons dashicons-building"></span>');
            
            // Property details
            const details = $('<div>', { class: 'property-details' });
            
            const title = $('<h3>', { class: 'property-title' })
                .text(property.name || property.internal_name || 'Untitled Property');
            
            const metaItems = [];
            
            if (property.id) {
                metaItems.push('<strong>ID:</strong> ' + property.id);
            }
            
            if (property.address) {
                metaItems.push('<strong>Address:</strong> ' + property.address);
            }
            
            if (property.city || property.country) {
                const location = [property.city, property.country].filter(Boolean).join(', ');
                metaItems.push('<strong>Location:</strong> ' + location);
            }
            
            if (property.max_guests) {
                metaItems.push('<strong>Max Guests:</strong> ' + property.max_guests);
            }
            
            if (property.bedrooms) {
                metaItems.push('<strong>Bedrooms:</strong> ' + property.bedrooms);
            }
            
            const meta = $('<div>', { class: 'property-meta' })
                .html(metaItems.join('<br>'));
            
            // Property status
            const status = $('<div>', { class: 'property-status' });
            
            if (property.is_active) {
                status.append($('<span>', { class: 'status-badge status-active' }).text('Active'));
            } else {
                status.append($('<span>', { class: 'status-badge status-inactive' }).text('Inactive'));
            }
            
            if (property.is_archived) {
                status.append($('<span>', { class: 'status-badge status-archived' }).text('Archived'));
            }
            
            // Property actions
            const actions = $('<div>', { class: 'property-actions' });
            
            const viewBtn = $('<button>', { 
                class: 'button button-primary',
                'data-property-id': property.id
            })
                .html('<span class="dashicons dashicons-calendar-alt"></span> View Reservations')
                .on('click', function() {
                    showPropertyDetails(property);
                });
            
            actions.append(viewBtn);
            
            // Assemble card
            details.append(title, meta, status, actions);
            card.append(icon, details);
            $list.append(card);
        });
    }
    
    /**
     * Show properties error
     */
    function showPropertiesError(message) {
        $('#goldenstay-properties-error-message').text(message);
        $('#goldenstay-properties-error').show();
    }
    
    /**
     * Show property details with reservations
     */
    function showPropertyDetails(property) {
        const $modal = $('#goldenstay-property-modal');
        const $title = $('#goldenstay-property-modal-title');
        const $loading = $('#goldenstay-property-details-loading');
        const $content = $('#goldenstay-property-details-content');
        
        // Set title
        $title.text((property.name || property.internal_name || 'Property') + ' - Reservations');
        
        // Show modal
        $modal.fadeIn(200);
        $('body').addClass('goldenstay-modal-open');
        
        // Show loading
        $loading.show();
        $content.hide();
        
        // Load reservations
        loadReservations(property);
    }
    
    /**
     * Load reservations for property
     */
    function loadReservations(property) {
        $.ajax({
            url: goldenStayAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'goldenstay_get_reservations',
                nonce: goldenStayAdmin.nonce,
                property_id: property.id
            },
            dataType: 'json',
            success: function(response) {
                $('#goldenstay-property-details-loading').hide();
                
                if (response.success) {
                    renderPropertyDetails(property, response.data.reservations);
                    $('#goldenstay-property-details-content').show();
                } else {
                    alert('Error loading reservations: ' + response.data.message);
                    closePropertyModal();
                }
            },
            error: function(xhr, status, error) {
                $('#goldenstay-property-details-loading').hide();
                alert('An error occurred while loading reservations.');
                closePropertyModal();
                console.error('AJAX Error:', error);
            }
        });
    }
    
    /**
     * Render property details and calendar
     */
    let currentPropertyId = null;
    function renderPropertyDetails(property, reservations) {
        currentPropertyId = property && property.id ? property.id : null;
        // Render property info
        const $info = $('#goldenstay-property-info');
        $info.empty();
        
        const infoCard = $('<div>', { class: 'property-info-card' });
        infoCard.append($('<h3>').text(property.name || property.internal_name || 'Property'));
        
        const infoDetails = $('<div>', { class: 'property-info-details' });
        if (property.address) {
            infoDetails.append($('<p>').html('<strong>Address:</strong> ' + property.address));
        }
        if (property.city || property.country) {
            const location = [property.city, property.country].filter(Boolean).join(', ');
            infoDetails.append($('<p>').html('<strong>Location:</strong> ' + location));
        }
        if (property.max_guests) {
            infoDetails.append($('<p>').html('<strong>Max Guests:</strong> ' + property.max_guests));
        }
        
        infoCard.append(infoDetails);
        $info.append(infoCard);
        
        // Render calendar
        renderReservationsCalendar(reservations);
    }
    
    /**
     * Render reservations calendar
     */
    let currentCalendarDate = new Date();
    let currentView = 'calendar'; // 'calendar' or 'list'
    let calendarReservations = [];
    
    function renderReservationsCalendar(reservations) {
        const $calendar = $('#goldenstay-reservations-calendar');
        $calendar.empty();
        
        calendarReservations = reservations || [];
        
        if (!reservations || reservations.length === 0) {
            $calendar.append($('<p>', { class: 'no-reservations' }).text('No reservations found'));
            return;
        }
        
        // Create calendar header with view toggle
        const header = $('<div>', { class: 'calendar-header' });
        const titleSection = $('<div>', { class: 'calendar-title' });
        titleSection.append($('<h3>').html('<span class="dashicons dashicons-calendar-alt"></span> Reservations (' + reservations.length + ')'));
        
        const viewToggle = $('<div>', { class: 'calendar-view-toggle' });
        const calendarBtn = $('<button>', { 
            class: 'view-btn ' + (currentView === 'calendar' ? 'active' : ''),
            'data-view': 'calendar'
        }).html('<span class="dashicons dashicons-calendar"></span> Calendar');
        
        const listBtn = $('<button>', { 
            class: 'view-btn ' + (currentView === 'list' ? 'active' : ''),
            'data-view': 'list'
        }).html('<span class="dashicons dashicons-list-view"></span> List');
        
        viewToggle.append(calendarBtn, listBtn);
        header.append(titleSection, viewToggle);
        $calendar.append(header);
        
        // View toggle handlers
        calendarBtn.on('click', function() {
            currentView = 'calendar';
            renderReservationsCalendar(calendarReservations);
        });
        
        listBtn.on('click', function() {
            currentView = 'list';
            renderReservationsCalendar(calendarReservations);
        });
        
        // Render appropriate view
        if (currentView === 'calendar') {
            renderCalendarView($calendar, reservations);
        } else {
            renderListView($calendar, reservations);
        }
    }
    
    /**
     * Render calendar grid view
     */
    function renderCalendarView($calendar, reservations) {
        // Calendar navigation
        const nav = $('<div>', { class: 'calendar-nav' });
        const prevBtn = $('<button>', { class: 'calendar-nav-btn' })
            .html('<span class="dashicons dashicons-arrow-left-alt2"></span>')
            .on('click', function() {
                currentCalendarDate.setMonth(currentCalendarDate.getMonth() - 1);
                renderReservationsCalendar(calendarReservations);
            });
        
        const monthYear = $('<div>', { class: 'calendar-month-year' })
            .text(currentCalendarDate.toLocaleDateString('en-US', { month: 'long', year: 'numeric' }));
        
        const nextBtn = $('<button>', { class: 'calendar-nav-btn' })
            .html('<span class="dashicons dashicons-arrow-right-alt2"></span>')
            .on('click', function() {
                currentCalendarDate.setMonth(currentCalendarDate.getMonth() + 1);
                renderReservationsCalendar(calendarReservations);
            });
        
        const todayBtn = $('<button>', { class: 'calendar-today-btn' })
            .text('Today')
            .on('click', function() {
                currentCalendarDate = new Date();
                renderReservationsCalendar(calendarReservations);
            });
        
        nav.append(prevBtn, monthYear, nextBtn, todayBtn);
        $calendar.append(nav);
        
        // Calendar grid
        const grid = $('<div>', { class: 'calendar-grid' });
        
        // Days of week header
        const daysOfWeek = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        daysOfWeek.forEach(day => {
            grid.append($('<div>', { class: 'calendar-day-header' }).text(day));
        });
        
        // Get calendar data
        const year = currentCalendarDate.getFullYear();
        const month = currentCalendarDate.getMonth();
        const firstDay = new Date(year, month, 1);
        const lastDay = new Date(year, month + 1, 0);
        const prevLastDay = new Date(year, month, 0);
        
        const firstDayWeek = firstDay.getDay();
        const daysInMonth = lastDay.getDate();
        const prevDaysInMonth = prevLastDay.getDate();
        
        // Previous month days
        for (let i = firstDayWeek - 1; i >= 0; i--) {
            const day = prevDaysInMonth - i;
            const cell = $('<div>', { class: 'calendar-day other-month' });
            cell.append($('<div>', { class: 'day-number' }).text(day));
            grid.append(cell);
        }
        
        // Current month days
        const today = new Date();
        for (let day = 1; day <= daysInMonth; day++) {
            const date = new Date(year, month, day);
            const dateStr = date.toISOString().split('T')[0];
            const isToday = date.toDateString() === today.toDateString();
            
            const cell = $('<div>', { 
                class: 'calendar-day' + (isToday ? ' today' : ''),
                'data-date': dateStr
            });
            
            cell.append($('<div>', { class: 'day-number' }).text(day));
            
            // Find reservations for this date
            const dayReservations = reservations.filter(r => {
                const from = new Date(r.date_from);
                const to = new Date(r.date_to);
                return date >= from && date < to;
            });
            
            if (dayReservations.length > 0) {
                const resContainer = $('<div>', { class: 'day-reservations' });
                
                // Priority: confirmed (status_id = 1) or first reservation
                let mainReservation = dayReservations.find(r => r.status_id === 1) || dayReservations[0];
                const otherCount = dayReservations.length - 1;
                
                const statusClass = getReservationStatusClass(mainReservation.status_id);
                const fromDate = new Date(mainReservation.date_from).toISOString().split('T')[0];
                const toDate = new Date(mainReservation.date_to);
                toDate.setDate(toDate.getDate() - 1);
                const lastDate = toDate.toISOString().split('T')[0];
                
                // Determine position in reservation
                const isFirst = dateStr === fromDate;
                const isLast = dateStr === lastDate;
                const isSingle = isFirst && isLast;
                
                let positionClass = '';
                if (isSingle) {
                    positionClass = 'res-single';
                } else if (isFirst) {
                    positionClass = 'res-start';
                } else if (isLast) {
                    positionClass = 'res-end';
                } else {
                    positionClass = 'res-middle';
                }
                
                const isHidden = !!mainReservation.is_hidden;
                const resBlock = $('<div>', { 
                    class: 'reservation-block ' + statusClass + ' ' + positionClass + (isHidden ? ' res-hidden' : ''),
                    title: (isHidden ? '[Hidden on site] ' : '') + (mainReservation.customer_name || 'Guest') + ' (' + getReservationStatusText(mainReservation.status_id) + ')\n' + 
                           formatDate(mainReservation.date_from) + ' - ' + formatDate(mainReservation.date_to) +
                           (otherCount > 0 ? '\n+' + otherCount + ' more reservation' + (otherCount > 1 ? 's' : '') : ''),
                    'data-reservation-id': mainReservation.id
                });
                
                // Show name and count on check-in day
                if (isFirst) {
                    const nameText = (mainReservation.customer_name || 'Guest') + (otherCount > 0 ? ' +' + otherCount : '');
                    resBlock.text(nameText);
                } else if (otherCount > 0 && !isSingle) {
                    // Show counter badge on middle/end days
                    const badge = $('<span>', { class: 'res-count-badge' }).text('+' + otherCount);
                    resBlock.append(badge);
                }
                
                resContainer.append(resBlock);
                cell.append(resContainer);
            }
            
            grid.append(cell);
        }
        
        // Next month days
        const totalCells = firstDayWeek + daysInMonth;
        const remainingCells = totalCells % 7 === 0 ? 0 : 7 - (totalCells % 7);
        for (let day = 1; day <= remainingCells; day++) {
            const cell = $('<div>', { class: 'calendar-day other-month' });
            cell.append($('<div>', { class: 'day-number' }).text(day));
            grid.append(cell);
        }
        
        $calendar.append(grid);
    }
    
    /**
     * Render list view
     */
    function renderListView($calendar, reservations) {
        
        // Create reservations list
        const list = $('<div>', { class: 'reservations-list' });
        
        // Sort reservations by date
        reservations.sort((a, b) => new Date(a.date_from) - new Date(b.date_from));
        
        reservations.forEach(function(reservation) {
            const card = $('<div>', { class: 'reservation-card' });
            if (reservation.is_hidden) {
                card.addClass('is-hidden');
            }
            
            // Status badge
            const statusClass = getReservationStatusClass(reservation.status_id);
            const statusText = getReservationStatusText(reservation.status_id);
            const statusBadge = $('<span>', { class: 'reservation-status ' + statusClass }).text(statusText);
            
            // Date range
            const dateFrom = formatDate(reservation.date_from);
            const dateTo = formatDate(reservation.date_to);
            const nights = calculateNights(reservation.date_from, reservation.date_to);
            
            const dateInfo = $('<div>', { class: 'reservation-dates' });
            dateInfo.append($('<div>', { class: 'date-range' }).html(
                '<strong>' + dateFrom + '</strong> → <strong>' + dateTo + '</strong>'
            ));
            dateInfo.append($('<div>', { class: 'nights-count' }).text(nights + ' night' + (nights !== 1 ? 's' : '')));
            
            // Guest info
            const guestInfo = $('<div>', { class: 'reservation-guest' });
            const guestName = reservation.customer_name || 'Guest';
            guestInfo.append($('<div>', { class: 'guest-name' }).html('<span class="dashicons dashicons-admin-users"></span> ' + guestName));
            
            if (reservation.number_of_guests) {
                guestInfo.append($('<div>', { class: 'guest-count' }).text(reservation.number_of_guests + ' guest' + (reservation.number_of_guests !== 1 ? 's' : '')));
            }
            
            // Assemble card
            card.append(statusBadge);
            card.append(dateInfo);
            card.append(guestInfo);

            // Visibility toggle (WP-side override)
            const toggleWrap = $('<div>', { class: 'reservation-visibility-toggle' });
            const toggleBtn = $('<button>', {
                type: 'button',
                class: 'button button-secondary gs-toggle-reservation-visibility' + (reservation.is_hidden ? ' is-hidden' : ''),
                'data-reservation-id': reservation.id,
            }).text(reservation.is_hidden ? 'Hidden on site' : 'Visible on site');

            toggleBtn.on('click', function() {
                if (!currentPropertyId) {
                    alert('Property ID is missing in current context.');
                    return;
                }

                const $btn = $(this);
                const nextHidden = reservation.is_hidden ? 0 : 1;
                $btn.prop('disabled', true).text('Saving...');

                $.ajax({
                    url: goldenStayAdmin.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'goldenstay_toggle_reservation_visibility',
                        nonce: goldenStayAdmin.nonce,
                        property_id: currentPropertyId,
                        reservation_id: reservation.id,
                        is_hidden: nextHidden
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (!response.success) {
                            alert('Error: ' + (response.data && response.data.message ? response.data.message : 'Failed to toggle'));
                            $btn.prop('disabled', false).text(reservation.is_hidden ? 'Hidden on site' : 'Visible on site');
                            return;
                        }

                        reservation.is_hidden = !!response.data.is_hidden;

                        // Update button + card state
                        $btn
                            .toggleClass('is-hidden', reservation.is_hidden)
                            .prop('disabled', false)
                            .text(reservation.is_hidden ? 'Hidden on site' : 'Visible on site');
                        card.toggleClass('is-hidden', reservation.is_hidden);

                        // Re-render calendar view blocks with updated hidden flag
                        renderReservationsCalendar(calendarReservations);
                    },
                    error: function() {
                        alert('An error occurred while saving visibility.');
                        $btn.prop('disabled', false).text(reservation.is_hidden ? 'Hidden on site' : 'Visible on site');
                    }
                });
            });

            toggleWrap.append(toggleBtn);
            card.append(toggleWrap);
            
            if (reservation.comments) {
                card.append($('<div>', { class: 'reservation-comments' }).html('<strong>Comments:</strong> ' + reservation.comments));
            }
            
            list.append(card);
        });
        
        $calendar.append(list);
    }
    
    /**
     * Get reservation status class
     */
    function getReservationStatusClass(statusId) {
        const statusMap = {
            1: 'status-confirmed',
            2: 'status-cancelled',
            3: 'status-pending',
            4: 'status-quote',
            5: 'status-lead'
        };
        return statusMap[statusId] || 'status-unknown';
    }
    
    /**
     * Get reservation status text
     */
    function getReservationStatusText(statusId) {
        const statusMap = {
            1: 'Confirmed',
            2: 'Cancelled',
            3: 'Pending',
            4: 'Quote',
            5: 'Lead'
        };
        return statusMap[statusId] || 'Unknown';
    }
    
    /**
     * Format date
     */
    function formatDate(dateString) {
        const date = new Date(dateString);
        const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        return months[date.getMonth()] + ' ' + date.getDate() + ', ' + date.getFullYear();
    }
    
    /**
     * Calculate nights between dates
     */
    function calculateNights(dateFrom, dateTo) {
        const from = new Date(dateFrom);
        const to = new Date(dateTo);
        const diff = Math.abs(to - from);
        return Math.ceil(diff / (1000 * 60 * 60 * 24));
    }
    
    /**
     * Close property modal
     */
    function closePropertyModal() {
        $('#goldenstay-property-modal').fadeOut(200);
        $('body').removeClass('goldenstay-modal-open');
    }
    
    // Close modal on button click
    $(document).on('click', '#goldenstay-close-modal', closePropertyModal);
    
    // Close modal on overlay click
    $(document).on('click', '.goldenstay-modal-overlay', closePropertyModal);
    
    // Close modal on ESC key
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape' && $('#goldenstay-property-modal').is(':visible')) {
            closePropertyModal();
        }
    });

})(jQuery);

