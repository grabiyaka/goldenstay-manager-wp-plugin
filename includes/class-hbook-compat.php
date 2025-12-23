<?php
/**
 * HBook compatibility layer for Adomus theme.
 *
 * Goal: keep the same shortcodes/classes/styles (as much as possible),
 * but source availability + prices from GoldenStay API.
 *
 * IMPORTANT: This class intentionally does NOT try to implement the full HBook booking pipeline.
 * It focuses on:
 * - rendering availability calendars with blocked days from API reservations
 * - rendering per-day prices from API (reservation/get-price-avb)
 * - keeping Adomus theme integration working (hb_search_form_markup filter, hero form, etc.)
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GoldenStay_HBook_Compat {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // If original HBook plugin is active, do not override its shortcodes.
        if ( class_exists( 'HBook' ) ) {
            return;
        }

        add_shortcode( 'hb_availability', array( $this, 'shortcode_availability' ) );
        add_shortcode( 'hb_booking_form', array( $this, 'shortcode_booking_form' ) );

        // Minimal stubs to avoid raw shortcodes in content when HBook is removed.
        add_shortcode( 'hb_rates', array( $this, 'shortcode_not_implemented' ) );
        add_shortcode( 'hb_accommodation_list', array( $this, 'shortcode_not_implemented' ) );
        add_shortcode( 'hb_starting_price', array( $this, 'shortcode_not_implemented' ) );
        add_shortcode( 'hb_reservation_summary', array( $this, 'shortcode_not_implemented' ) );
        add_shortcode( 'hb_paypal_confirmation', array( $this, 'shortcode_not_implemented' ) );
    }

    public function shortcode_not_implemented() {
        return '';
    }

    private function get_hidden_reservations_map() {
        $hidden = get_option( 'goldenstay_hidden_reservations', array() );
        return is_array( $hidden ) ? $hidden : array();
    }

    private function get_hidden_reservation_ids_for_property( $property_id ) {
        $hidden = $this->get_hidden_reservations_map();
        $ids = isset( $hidden[ $property_id ] ) ? $hidden[ $property_id ] : array();
        if ( ! is_array( $ids ) ) {
            return array();
        }
        return array_values( array_unique( array_map( 'intval', $ids ) ) );
    }

    private function enqueue_hbook_assets() {
        // Styles
        wp_enqueue_style(
            'gs-hb-front-end',
            GOLDENSTAY_PLUGIN_URL . 'assets/hbook/css/hb-front-end-style.min.css',
            array(),
            GOLDENSTAY_VERSION
        );
        wp_enqueue_style(
            'gs-hb-datepick',
            GOLDENSTAY_PLUGIN_URL . 'assets/hbook/css/hb-datepick.css',
            array( 'gs-hb-front-end' ),
            GOLDENSTAY_VERSION
        );

        // Datepick scripts (vendor-copied from HBook)
        wp_enqueue_script(
            'gs-hb-jquery-plugin',
            GOLDENSTAY_PLUGIN_URL . 'assets/hbook/js/jquery.plugin.min.js',
            array( 'jquery' ),
            GOLDENSTAY_VERSION,
            true
        );
        wp_enqueue_script(
            'gs-hb-jquery-datepick',
            GOLDENSTAY_PLUGIN_URL . 'assets/hbook/js/jquery.datepick.min.js',
            array( 'jquery', 'gs-hb-jquery-plugin' ),
            GOLDENSTAY_VERSION,
            true
        );
        wp_enqueue_script(
            'gs-hb-utils',
            GOLDENSTAY_PLUGIN_URL . 'assets/hbook/js/hb-utils.js',
            array( 'jquery', 'gs-hb-jquery-datepick' ),
            GOLDENSTAY_VERSION,
            true
        );

        // Provide globals required by copied hb-datepick.js
        $inline = $this->build_datepick_globals();
        wp_register_script(
            'gs-hb-datepick',
            GOLDENSTAY_PLUGIN_URL . 'assets/hbook/js/hb-datepick.js',
            array( 'jquery', 'gs-hb-jquery-datepick', 'gs-hb-utils' ),
            GOLDENSTAY_VERSION,
            true
        );
        wp_add_inline_script( 'gs-hb-datepick', $inline, 'before' );
        wp_enqueue_script( 'gs-hb-datepick' );
    }

    private function enqueue_availability_assets() {
        wp_enqueue_style(
            'gs-hb-availability',
            GOLDENSTAY_PLUGIN_URL . 'assets/hbook/css/gs-availability.css',
            array( 'gs-hb-front-end', 'gs-hb-datepick' ),
            GOLDENSTAY_VERSION
        );
        wp_enqueue_script(
            'gs-hb-availability',
            GOLDENSTAY_PLUGIN_URL . 'assets/hbook/js/gs-availability.js',
            array( 'jquery', 'gs-hb-jquery-datepick', 'gs-hb-utils', 'gs-hb-datepick' ),
            GOLDENSTAY_VERSION,
            true
        );
        wp_localize_script(
            'gs-hb-availability',
            'gsHbAvailability',
            array(
                'currency' => '€',
            )
        );
    }

    private function build_datepick_globals() {
        $start_of_week = get_option( 'start_of_week', 0 );
        $first_day = is_numeric( $start_of_week ) ? intval( $start_of_week ) : 0;

        // HBook uses datepick format tokens (yyyy-mm-dd)
        $hb_date_format = 'yyyy-mm-dd';

        // Month and day names
        global $wp_locale;
        $months = array();
        for ( $i = 1; $i <= 12; $i++ ) {
            $months[] = $wp_locale ? $wp_locale->get_month( $i ) : date_i18n( 'F', mktime( 0, 0, 0, $i, 1 ) );
        }

        // jQuery datepick expects Sunday-first array.
        $days_min = array();
        $days_min[] = $wp_locale ? $wp_locale->get_weekday_abbrev( 'Sunday' ) : 'Su';
        $days_min[] = $wp_locale ? $wp_locale->get_weekday_abbrev( 'Monday' ) : 'Mo';
        $days_min[] = $wp_locale ? $wp_locale->get_weekday_abbrev( 'Tuesday' ) : 'Tu';
        $days_min[] = $wp_locale ? $wp_locale->get_weekday_abbrev( 'Wednesday' ) : 'We';
        $days_min[] = $wp_locale ? $wp_locale->get_weekday_abbrev( 'Thursday' ) : 'Th';
        $days_min[] = $wp_locale ? $wp_locale->get_weekday_abbrev( 'Friday' ) : 'Fr';
        $days_min[] = $wp_locale ? $wp_locale->get_weekday_abbrev( 'Saturday' ) : 'Sa';

        $is_rtl = is_rtl() ? 'true' : 'false';

        $hb_text = array(
            'legend_select_check_in' => '',
            'legend_select_check_out' => '',
            'legend_past' => 'Past',
            'legend_closed' => 'Closed',
            'legend_occupied' => 'Occupied',
            'legend_check_out_only' => 'Check-out only',
            'legend_check_in_only' => 'Check-in only',
            'legend_available' => 'Available',
            'legend_check_in' => 'Check-in',
            'legend_check_out' => 'Check-out',
            'legend_no_check_in' => 'No check-in',
            'legend_no_check_out' => 'No check-out',
            'legend_before_check_in' => 'Before check-in',
            'legend_no_check_out_min_stay' => 'No check-out (min %nb_nights nights)',
            'legend_no_check_out_max_stay' => 'No check-out (max %nb_nights nights)',
        );

        $booking_rules = array(
            'allowed_check_in_days' => 'all',
            'allowed_check_out_days' => 'all',
            'minimum_stay' => 1,
            'maximum_stay' => 9999,
            'conditional_booking_rules' => array(),
            'seasonal_allowed_check_in_days' => array(),
            'seasonal_allowed_check_out_days' => array(),
            'seasonal_minimum_stay' => array(),
            'seasonal_maximum_stay' => array(),
        );

        // hb-datepick.js reads:
        // - hb_text
        // - hb_booking_form_data.is_admin + seasons
        // - window.hb_accom_data_0 (booking window)
        // - window.hb_status_days_all (status days for search form)
        return '' .
            'var hb_date_format = ' . wp_json_encode( $hb_date_format ) . ";\n" .
            'var hb_day_names_min = ' . wp_json_encode( $days_min ) . ";\n" .
            'var hb_months_name = ' . wp_json_encode( $months ) . ";\n" .
            'var hb_first_day = ' . wp_json_encode( strval( $first_day ) ) . ";\n" .
            'var hb_is_rtl = ' . wp_json_encode( $is_rtl ) . ";\n" .
            'var hb_text = ' . wp_json_encode( $hb_text ) . ";\n" .
            'var hb_booking_form_data = ' . wp_json_encode(
                array(
                    'is_admin' => 'no',
                    'seasons' => array(),
                )
            ) . ";\n" .
            'window.hb_accom_data_0 = "0";' . "\n" .
            'window.hb_status_days_all = {};' . "\n" .
            // We attach booking rules on wrapper via data-booking-rules, but keep a safe fallback too.
            'window.gs_hb_default_booking_rules = ' . wp_json_encode( $booking_rules ) . ";\n";
    }

    private function api_post_json( $endpoint, $body, $with_token = false ) {
        $api_url = GoldenStay_Manager::get_api_url();
        $url = trailingslashit( $api_url ) . ltrim( $endpoint, '/' );

        $args = array(
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode( $body ),
            'timeout' => 30,
        );
        if ( $with_token ) {
            $token = GoldenStay_Manager::get_api_token();
            if ( $token ) {
                $args['headers']['Authorization'] = $token;
            }
        }

        return wp_remote_post( $url, $args );
    }

    private function fetch_reservations_for_property( $property_id ) {
        static $cache = array();
        if ( isset( $cache[ $property_id ] ) ) {
            return $cache[ $property_id ];
        }

        $token = GoldenStay_Manager::get_api_token();
        if ( ! $token ) {
            $cache[ $property_id ] = array();
            return $cache[ $property_id ];
        }

        $response = $this->api_post_json(
            'reservation/property',
            array( 'ids' => array( intval( $property_id ) ) ),
            true
        );

        if ( is_wp_error( $response ) ) {
            $cache[ $property_id ] = array();
            return $cache[ $property_id ];
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $status_code !== 200 || ! is_array( $data ) ) {
            $cache[ $property_id ] = array();
            return $cache[ $property_id ];
        }

        $cache[ $property_id ] = $data;
        return $cache[ $property_id ];
    }

    private function fetch_price_days_for_period( $property_id, $date_from, $date_to, $nop = 1 ) {
        static $cache = array();
        $cache_key = $property_id . '|' . $date_from . '|' . $date_to . '|' . intval( $nop );
        if ( isset( $cache[ $cache_key ] ) ) {
            return $cache[ $cache_key ];
        }

        $response = $this->api_post_json(
            'reservation/get-price-avb',
            array(
                'propertyId' => intval( $property_id ),
                'dateFrom' => $date_from,
                'dateTo' => $date_to,
            ),
            false
        );

        if ( is_wp_error( $response ) ) {
            $cache[ $cache_key ] = array();
            return $cache[ $cache_key ];
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $status_code !== 200 || ! is_array( $data ) ) {
            $cache[ $cache_key ] = array();
            return $cache[ $cache_key ];
        }

        $price_days = array();

        $breakdowns = isset( $data['ReservationsBreakdowns']['ReservationBreakdown'] )
            ? $data['ReservationsBreakdowns']['ReservationBreakdown']
            : array();
        if ( ! is_array( $breakdowns ) ) {
            $breakdowns = array();
        }
        if ( isset( $breakdowns['NOP'] ) ) {
            // Single object case
            $breakdowns = array( $breakdowns );
        }

        $chosen = null;
        foreach ( $breakdowns as $b ) {
            if ( isset( $b['NOP'] ) && intval( $b['NOP'] ) === intval( $nop ) ) {
                $chosen = $b;
                break;
            }
        }
        if ( ! $chosen && count( $breakdowns ) ) {
            $chosen = $breakdowns[0];
        }

        $day_prices = $chosen && isset( $chosen['RUBreakdown']['DayPrices'] ) ? $chosen['RUBreakdown']['DayPrices'] : array();
        if ( isset( $day_prices['Date'] ) ) {
            $day_prices = array( $day_prices );
        }
        if ( is_array( $day_prices ) ) {
            foreach ( $day_prices as $dp ) {
                if ( ! is_array( $dp ) || empty( $dp['Date'] ) ) {
                    continue;
                }
                $date = substr( $dp['Date'], 0, 10 );
                $price = isset( $dp['Price'] ) ? floatval( $dp['Price'] ) : null;
                if ( $price !== null ) {
                    $price_days[ $date ] = $price;
                }
            }
        }

        $cache[ $cache_key ] = $price_days;
        return $cache[ $cache_key ];
    }

    private function set_status_day( &$map, $date, $status ) {
        $priority = array(
            'hb-day-taken-start' => 1,
            'hb-day-taken-end' => 1,
            'hb-day-fully-taken' => 2,
        );
        $new_p = isset( $priority[ $status ] ) ? $priority[ $status ] : 0;
        $old = isset( $map[ $date ] ) ? $map[ $date ] : null;
        $old_p = $old && isset( $priority[ $old ] ) ? $priority[ $old ] : 0;
        if ( ! $old || $new_p >= $old_p ) {
            $map[ $date ] = $status;
        }
    }

    private function build_status_days( $reservations, $range_from, $range_to, $hidden_ids = array() ) {
        $status_days = array();

        try {
            $from = new DateTime( $range_from );
            $to = new DateTime( $range_to );
        } catch ( Exception $e ) {
            return $status_days;
        }

        foreach ( $reservations as $reservation ) {
            if ( ! is_array( $reservation ) ) {
                continue;
            }
            $reservation_id = isset( $reservation['id'] ) ? intval( $reservation['id'] ) : 0;
            if ( $reservation_id && in_array( $reservation_id, $hidden_ids, true ) ) {
                continue;
            }
            if ( empty( $reservation['date_from'] ) || empty( $reservation['date_to'] ) ) {
                continue;
            }

            try {
                $start = new DateTime( substr( $reservation['date_from'], 0, 10 ) );
                $end = new DateTime( substr( $reservation['date_to'], 0, 10 ) );
            } catch ( Exception $e ) {
                continue;
            }

            // Normalize: ignore empty / inverted ranges
            if ( $end <= $start ) {
                continue;
            }

            // Clip to requested range (we still want to mark check-out boundary inside range)
            $clip_start = $start < $from ? clone $from : clone $start;
            $clip_end = $end > $to ? clone $to : clone $end;

            // Mark check-in and check-out boundaries (if inside range)
            $start_str = $start->format( 'Y-m-d' );
            $end_str = $end->format( 'Y-m-d' );
            if ( $start >= $from && $start <= $to ) {
                $this->set_status_day( $status_days, $start_str, 'hb-day-taken-end' );
            }
            if ( $end >= $from && $end <= $to ) {
                $this->set_status_day( $status_days, $end_str, 'hb-day-taken-start' );
            }

            // Fully taken: from (start+1) to (end-1)
            $iter = clone $start;
            $iter->modify( '+1 day' );
            $last = clone $end;
            $last->modify( '-1 day' );

            // Clip again
            if ( $iter < $from ) {
                $iter = clone $from;
            }
            if ( $last > $to ) {
                $last = clone $to;
            }

            while ( $iter <= $last ) {
                $this->set_status_day( $status_days, $iter->format( 'Y-m-d' ), 'hb-day-fully-taken' );
                $iter->modify( '+1 day' );
            }
        }

        return $status_days;
    }

    public function shortcode_availability( $atts ) {
        $atts = shortcode_atts(
            array(
                'accom_id' => '',
                'calendar_sizes' => '1x3',
                'property_id' => '',
                'nop' => 1,
            ),
            $atts,
            'hb_availability'
        );

        $accom_id = $atts['accom_id'];
        $property_id = $atts['property_id'] ? intval( $atts['property_id'] ) : 0;
        $nop = intval( $atts['nop'] ) > 0 ? intval( $atts['nop'] ) : 1;

        if ( ! $property_id ) {
            if ( $accom_id === 'all' ) {
                return '';
            }
            if ( ! $accom_id ) {
                $accom_id = get_the_ID();
            }
            $property_id = GoldenStay_Accommodation_Mapping::get_property_id_for_accom( intval( $accom_id ) );
        }

        if ( ! $property_id ) {
            return '';
        }

        $this->enqueue_hbook_assets();
        $this->enqueue_availability_assets();

        // Prefetch window: next 365 days
        $range_from = gmdate( 'Y-m-d' );
        $range_to = gmdate( 'Y-m-d', strtotime( '+365 days' ) );

        $hidden_ids = $this->get_hidden_reservation_ids_for_property( $property_id );
        $reservations = $this->fetch_reservations_for_property( $property_id );
        $status_days = $this->build_status_days( $reservations, $range_from, $range_to, $hidden_ids );
        $price_days = $this->fetch_price_days_for_period( $property_id, $range_from, $range_to, $nop );

        $calendar_sizes = $this->parse_calendar_sizes( $atts['calendar_sizes'] );

        $booking_window = array(
            'min_date' => $range_from,
            'max_date' => $range_to,
        );

        $output = '' .
            '<div class="hb-availability-calendar-wrapper">' .
                '<div class="hb-availability-calendar-centered">' .
                    '<div ' .
                        'class="hb-availability-calendar" ' .
                        'data-calendar-sizes=\'' . esc_attr( wp_json_encode( $calendar_sizes ) ) . '\' ' .
                        'data-status-days=\'' . esc_attr( wp_json_encode( $status_days ) ) . '\' ' .
                        'data-price-days=\'' . esc_attr( wp_json_encode( $price_days ) ) . '\' ' .
                        'data-booking-window=\'' . esc_attr( wp_json_encode( $booking_window ) ) . '\' ' .
                    '></div>' .
                '</div>' .
            '</div>';

        return $output;
    }

    private function parse_calendar_sizes( $calendar_sizes_str ) {
        $calendar_sizes_cols = array();
        $calendar_sizes_rows = array();
        $calendar_sizes = explode( ',', $calendar_sizes_str );
        foreach ( $calendar_sizes as $size ) {
            $size = trim( $size );
            if ( ! $size ) {
                continue;
            }
            $cols_rows = explode( 'x', $size );
            if ( count( $cols_rows ) !== 2 ) {
                continue;
            }
            $cols = intval( $cols_rows[0] );
            $rows = intval( $cols_rows[1] );
            if ( ! $cols || ! $rows ) {
                continue;
            }
            $calendar_sizes_cols[] = $cols;
            $calendar_sizes_rows[ $cols ] = $rows;
        }
        rsort( $calendar_sizes_cols );
        $out = array();
        foreach ( $calendar_sizes_cols as $col ) {
            $out[] = array(
                'cols' => $col,
                'rows' => $calendar_sizes_rows[ $col ],
            );
        }
        if ( ! count( $out ) ) {
            $out[] = array( 'cols' => 1, 'rows' => 3 );
        }
        return $out;
    }

    public function shortcode_booking_form( $atts ) {
        $atts = shortcode_atts(
            array(
                'form_id' => '',
                'search_form_placeholder' => 'no',
                'search_only' => 'no',
                'redirection_url' => '#',
            ),
            $atts,
            'hb_booking_form'
        );

        $this->enqueue_hbook_assets();

        // Minimal wrapper required by hb-datepick.js
        $wrapper_rules = array(
            'allowed_check_in_days' => 'all',
            'allowed_check_out_days' => 'all',
            'minimum_stay' => 1,
            'maximum_stay' => 9999,
            'conditional_booking_rules' => array(),
            'seasonal_allowed_check_in_days' => array(),
            'seasonal_allowed_check_out_days' => array(),
            'seasonal_minimum_stay' => array(),
            'seasonal_maximum_stay' => array(),
        );

        $form_id = sanitize_text_field( $atts['form_id'] );
        $search_only = ( $atts['search_only'] === 'yes' ) ? 'yes' : 'no';
        $search_placeholder = ( $atts['search_form_placeholder'] === 'yes' ) ? 'yes' : 'no';

        $form_action = ( $search_only === 'yes' && ! empty( $atts['redirection_url'] ) && $atts['redirection_url'] !== '#' )
            ? esc_url( $atts['redirection_url'] )
            : esc_url( get_permalink( get_the_ID() ) );

        $check_in = isset( $_POST['hb-check-in-hidden'] ) ? sanitize_text_field( $_POST['hb-check-in-hidden'] ) : '';
        $check_out = isset( $_POST['hb-check-out-hidden'] ) ? sanitize_text_field( $_POST['hb-check-out-hidden'] ) : '';
        $adults = isset( $_POST['hb-adults'] ) ? sanitize_text_field( $_POST['hb-adults'] ) : '';
        $children = isset( $_POST['hb-children'] ) ? sanitize_text_field( $_POST['hb-children'] ) : '';

        $form_title = '';
        $form_class = 'hb-booking-search-form';

        $markup = $this->get_default_search_form_markup();
        $markup = apply_filters( 'hb_search_form_markup', $markup, $form_id );

        // Labels / placeholders
        if ( $search_placeholder === 'yes' ) {
            $markup = str_replace( '[check_in_placeholder]', esc_html__( 'Check-in', 'goldenstay-manager' ), $markup );
            $markup = str_replace( '[check_out_placeholder]', esc_html__( 'Check-out', 'goldenstay-manager' ), $markup );
            $markup = str_replace( '[check_in_label]', '', $markup );
            $markup = str_replace( '[check_out_label]', '', $markup );
            $markup = str_replace( '[adults_label]', '', $markup );
            $markup = str_replace( '[children_label]', '', $markup );
            $markup = str_replace( '[search_label]', '', $markup );
        } else {
            $markup = str_replace( '[check_in_placeholder]', '', $markup );
            $markup = str_replace( '[check_out_placeholder]', '', $markup );
            $markup = str_replace( '[check_in_label]', '<label for="check-in-date">' . esc_html__( 'Check-in', 'goldenstay-manager' ) . '</label>', $markup );
            $markup = str_replace( '[check_out_label]', '<label for="check-out-date">' . esc_html__( 'Check-out', 'goldenstay-manager' ) . '</label>', $markup );
            $markup = str_replace( '[adults_label]', '<label for="adults">' . esc_html__( 'Adults', 'goldenstay-manager' ) . '</label>', $markup );
            $markup = str_replace( '[children_label]', '<label for="children">' . esc_html__( 'Children', 'goldenstay-manager' ) . '</label>', $markup );
            $markup = str_replace( '[search_label]', '<label for="hb-search-form-submit">&nbsp;</label>', $markup );
        }

        $markup = str_replace( '[people_selects_adults]', $this->build_people_select( 'adults', 1, 20, $search_placeholder ), $markup );
        $markup = str_replace( '[people_selects_children]', $this->build_people_select( 'children', 0, 20, $search_placeholder ), $markup );

        // Replace generic placeholders
        $form_id_attr = $form_id ? 'id="' . esc_attr( $form_id ) . '"' : '';
        $vars = array(
            'form_id' => $form_id_attr,
            'form_class' => $form_class,
            'search_only_data' => $search_only,
            'form_action' => $form_action,
            'form_title' => $form_title,
            'check_in' => $check_in,
            'check_out' => $check_out,
            'adults' => $adults,
            'children' => $children,
            'options' => '',
            'accom_num' => '',
            'accom_people' => '',
        );
        foreach ( $vars as $k => $v ) {
            $markup = str_replace( '[' . $k . ']', $v, $markup );
        }

        // Strings
        $strings = array(
            'chosen_check_in' => esc_html__( 'Check-in:', 'goldenstay-manager' ),
            'chosen_check_out' => esc_html__( 'Check-out:', 'goldenstay-manager' ),
            'chosen_adults' => esc_html__( 'Adults:', 'goldenstay-manager' ),
            'chosen_children' => esc_html__( 'Children:', 'goldenstay-manager' ),
            'change_search_button' => esc_html__( 'Change search', 'goldenstay-manager' ),
            'search_button' => esc_html__( 'Search', 'goldenstay-manager' ),
        );
        foreach ( $strings as $k => $v ) {
            $markup = str_replace( '[string_' . $k . ']', $v, $markup );
        }

        // Wrap to provide booking rules for hb-datepick.js
        $out = '<div class="hbook-wrapper" data-booking-rules=\'' . esc_attr( wp_json_encode( $wrapper_rules ) ) . '\'>';
        $out .= $markup;

        if ( $search_only === 'no' ) {
            // Show calendars (prices + blocked days) for all accommodations as a simple replacement for HBook results.
            $out .= $this->render_accom_calendars();
        }

        $out .= '</div>';
        return $out;
    }

    private function build_people_select( $key, $min, $max, $placeholder_mode ) {
        $min = intval( $min );
        $max = intval( $max );
        $options = '';
        if ( $placeholder_mode === 'yes' ) {
            $label = $key === 'adults' ? esc_html__( 'Adults', 'goldenstay-manager' ) : esc_html__( 'Children', 'goldenstay-manager' );
            $options .= '<option selected disabled>' . esc_html( $label ) . '</option>';
        }
        for ( $i = $min; $i <= $max; $i++ ) {
            $options .= '<option value="' . esc_attr( $i ) . '">' . esc_html( $i ) . '</option>';
        }
        return '<select id="' . esc_attr( $key ) . '" name="hb-' . esc_attr( $key ) . '" class="hb-' . esc_attr( $key ) . '">' . $options . '</select>';
    }

    private function get_default_search_form_markup() {
        // Based on HBook search form (simplified; Adomus may override via hb_search_form_markup filter).
        return '
            <form [form_id] class="[form_class]" method="POST" data-search-only="[search_only_data]" action="[form_action]">
                [form_title]
                <div class="hb-searched-summary hb-clearfix">
                    <p class="hb-check-dates-wrapper hb-chosen-check-in-date">[string_chosen_check_in] <span></span></p>
                    <p class="hb-check-dates-wrapper hb-chosen-check-out-date">[string_chosen_check_out] <span></span></p>
                    <p class="hb-people-wrapper hb-chosen-adults">[string_chosen_adults] <span></span></p>
                    <p class="hb-people-wrapper hb-chosen-children">[string_chosen_children] <span></span></p>
                    <p class="hb-change-search-wrapper hb-search-button-wrapper hb-button-wrapper">
                        <input type="submit" value="[string_change_search_button]" />
                    </p>
                </div>
                <div class="hb-search-fields-and-submit">
                    <div class="hb-search-fields hb-clearfix">
                        <p class="hb-check-dates-wrapper">
                            [check_in_label]
                            <input id="check-in-date" name="hb-check-in-date" class="hb-input-datepicker hb-check-in-date" type="text" placeholder="[check_in_placeholder]" autocomplete="off" />
                            <input class="hb-check-in-hidden" name="hb-check-in-hidden" type="hidden" value="[check_in]" />
                            <span class="hb-datepick-check-in-out-mobile-trigger hb-datepick-check-in-mobile-trigger"></span>
                            <span class="hb-datepick-check-in-out-trigger hb-datepick-check-in-trigger"></span>
                        </p>
                        <p class="hb-check-dates-wrapper">
                            [check_out_label]
                            <input id="check-out-date" name="hb-check-out-date" class="hb-input-datepicker hb-check-out-date" type="text" placeholder="[check_out_placeholder]" autocomplete="off" />
                            <input class="hb-check-out-hidden" name="hb-check-out-hidden" type="hidden" value="[check_out]" />
                            <span class="hb-datepick-check-in-out-mobile-trigger hb-datepick-check-out-mobile-trigger"></span>
                            <span class="hb-datepick-check-in-out-trigger hb-datepick-check-out-trigger"></span>
                        </p>
                        <p class="hb-people-wrapper hb-people-wrapper-adults">
                            [adults_label]
                            [people_selects_adults]
                            <input class="hb-adults-hidden" type="hidden" value="[adults]" />
                        </p>
                        <p class="hb-people-wrapper hb-people-wrapper-children hb-people-wrapper-last">
                            [children_label]
                            [people_selects_children]
                            <input class="hb-children-hidden" type="hidden" value="[children]" />
                        </p>
                        <p class="hb-search-submit-wrapper hb-search-button-wrapper hb-button-wrapper">
                            [search_label]
                            <input type="submit" id="hb-search-form-submit" value="[string_search_button]" />
                        </p>
                    </div>
                    <p class="hb-search-error">&nbsp;</p>
                    <p class="hb-search-no-result">&nbsp;</p>
                </div>
                <input type="hidden" class="hb-results-show-only-accom-id" name="hb-results-show-only-accom-id" />
            </form>
        ';
    }

    private function render_accom_calendars() {
        $posts = get_posts(
            array(
                'post_type' => 'hb_accommodation',
                'post_status' => 'publish',
                'numberposts' => -1,
            )
        );
        if ( ! $posts ) {
            return '';
        }

        $out = '<div class="hb-accom-list">';
        foreach ( $posts as $post ) {
            $property_id = GoldenStay_Accommodation_Mapping::get_property_id_for_accom( $post->ID );
            if ( ! $property_id ) {
                continue;
            }
            $out .= '<div class="hb-accom">';
            $out .= '<p class="hb-accom-title">' . esc_html( get_the_title( $post ) ) . '</p>';
            $out .= do_shortcode( '[hb_availability accom_id="' . intval( $post->ID ) . '" calendar_sizes="1x3"]' );
            $out .= '</div>';
        }
        $out .= '</div>';
        return $out;
    }
}


