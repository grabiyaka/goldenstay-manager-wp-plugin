'use strict';

jQuery( document ).ready( function( $ ) {
	'use strict';

	$( '.hb-availability-calendar' ).datepick( hb_datepicker_calendar_options );

	var today = new Date();
	today.setHours( 0, 0, 0, 0 );

	$( '.hb-availability-calendar' ).each( function() {
		var $calendar = $( this );
		var hb_status_days = $calendar.data( 'status-days' ) || {};
		var hb_price_days = $calendar.data( 'price-days' ) || {};
		var hb_booking_window = $calendar.data( 'booking-window' ) || { min_date: '0', max_date: '0' };
		var calendar_sizes = $calendar.data( 'calendar-sizes' ) || [];

		var accom_min_date = hb_booking_window['min_date'];
		var accom_max_date = hb_booking_window['max_date'];

		var hb_dp_min_date = hb_booking_window['min_date'] != '0' ? hb_date_str_2_obj( accom_min_date ) : 0;
		var hb_dp_max_date = hb_booking_window['max_date'] != '0' ? hb_date_str_2_obj( accom_max_date ) : 0;

		$calendar.datepick( 'option', {
			minDate: hb_dp_min_date,
			maxDate: hb_dp_max_date,

			onDate: function ( date_noon, date_is_in_current_month ) {
				var date = new Date( date_noon.getTime() );
				date.setHours( 0, 0, 0, 0 );
				var day = date.getDate();
				var str_date = hb_date_obj_2_str( date );
				var on_date_returned = {};

				on_date_returned['selectable'] = false;
				on_date_returned['dateClass'] = 'hb-dp-date-' + str_date;

				if ( ! date_is_in_current_month ) {
					on_date_returned['dateClass'] += ' hb-dp-day-not-current-month';
				} else if ( date < today ) {
					on_date_returned['title'] = hb_text.legend_past;
					on_date_returned['dateClass'] += ' hb-dp-day-past';
				} else if ( hb_dp_min_date && date < hb_dp_min_date ) {
					on_date_returned['title'] = hb_text.legend_closed;
					on_date_returned['dateClass'] += ' hb-dp-day-closed';
				} else if ( hb_dp_max_date && date > hb_dp_max_date ) {
					on_date_returned['title'] = hb_text.legend_closed;
					on_date_returned['dateClass'] += ' hb-dp-day-closed';
				} else if ( hb_status_days[ str_date ] ) {
					switch ( hb_status_days[ str_date ] ) {
						case 'hb-day-fully-taken':
							on_date_returned['title'] = hb_text.legend_occupied;
							break;
						case 'hb-day-taken-start':
							on_date_returned['title'] = hb_text.legend_check_out_only;
							break;
						case 'hb-day-taken-end':
							on_date_returned['title'] = hb_text.legend_check_in_only;
							break;
					}
					on_date_returned['dateClass'] += ' ' + hb_status_days[ str_date ];
					on_date_returned['content'] = '<span class="hb-day-taken-content">' + day + '</span>';
				} else {
					var price = hb_price_days[ str_date ];
					on_date_returned['title'] = hb_text.legend_available;
					on_date_returned['dateClass'] += ' hb-day-available';

					if ( price !== undefined && price !== null && price !== '' ) {
						var currency = ( window.gsHbAvailability && window.gsHbAvailability.currency ) ? window.gsHbAvailability.currency : '';
						var rounded = Math.round( +price );
						on_date_returned['title'] = hb_text.legend_available + ' - ' + currency + rounded;
						on_date_returned['content'] = '' +
							'<span class="gs-hb-day">' +
								'<span class="gs-hb-day-number">' + day + '</span>' +
								'<span class="gs-hb-day-price">' + currency + rounded + '</span>' +
							'</span>';
					}
				}

				return on_date_returned;
			},

			onChangeMonthYear: function( year, month ) {
				$calendar.data( 'current-shown-month', month );
				$calendar.data( 'current-shown-year', year );
				if ( calendar_resize_timer ) {
					clearInterval( calendar_resize_timer );
					calendar_resize_timer = setInterval( calendar_resize, 2000 );
				}
			}
		} );

		// Resize to match HBook behaviour (based on available width)
		function calendar_resize() {
			var calendar_widths = [];
			var current_shown_month = $calendar.data( 'current-shown-month' );
			var current_shown_year = $calendar.data( 'current-shown-year' );
			var wrapper_saved_width = $calendar.data( 'wrapper-width' );
			var wrapper_width = $calendar.parents( '.hb-availability-calendar-wrapper' ).width();

			if ( wrapper_width != wrapper_saved_width ) {
				$calendar.data( 'wrapper-width', wrapper_width );
				$calendar.parents( '.hb-availability-calendar-centered' ).width( 'auto' );

				for ( var i = 0; i < calendar_sizes.length; i++ ) {
					$calendar.datepick( 'option', 'monthsToShow', parseInt( calendar_sizes[i].cols ) );
					calendar_widths[ calendar_sizes[i].cols ] = $calendar.find( '.hb-datepick-wrapper' ).width();
				}

				for ( var j = 0; j < calendar_sizes.length; j++ ) {
					var available_width = $calendar.width();
					if ( calendar_widths[ calendar_sizes[j].cols ] <= available_width ) {
						$calendar.datepick(
							'option',
							'monthsToShow',
							[ parseInt( calendar_sizes[j].rows ), parseInt( calendar_sizes[j].cols ) ]
						);

						if ( calendar_sizes[j].rows > 1 ) {
							$calendar.datepick( 'option', 'monthsToStep', parseInt( calendar_sizes[j].cols ) );
						} else {
							$calendar.datepick( 'option', 'monthsToStep', 1 );
						}

						if ( current_shown_year && current_shown_month ) {
							$calendar.datepick( 'showMonth', current_shown_year, current_shown_month );
						}
						$calendar.parents( '.hb-availability-calendar-centered' ).width( $calendar.find( '.hb-datepick-wrapper' ).width() );
						return;
					}
				}

				$calendar.datepick( 'option', 'monthsToShow', 1 );
				$calendar.datepick( 'option', 'monthsToStep', 1 );
				if ( current_shown_year && current_shown_month ) {
					$calendar.datepick( 'showMonth', current_shown_year, current_shown_month );
				}
				$calendar.parents( '.hb-availability-calendar-centered' ).width( $calendar.find( '.hb-datepick-wrapper' ).width() );
			}
		}

		calendar_resize();
		var calendar_resize_timer = setInterval( calendar_resize, 2000 );
	} );
} );


