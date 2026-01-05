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
    private $base_assets_enqueued = false;
    private $availability_assets_enqueued = false;
    private $last_calendar_days_raw = array();
    private $last_calendar_fetch_debug = null;
    private $last_calendar_fetch_response = null;
    private $last_calendar_days_sample = array();

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

        // Make sure datepick assets are available before wp_head runs.
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_hbook_assets' ) );

        // Availability calendar assets are required for reservation page replacement.
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_availability_assets' ) );

        // HBook used to enable shortcodes inside classic Text widgets.
        // When HBook is disabled, Adomus sidebars may display raw [hb_*] text without this.
        add_filter( 'widget_text', 'do_shortcode' );
        add_filter( 'widget_text_content', 'do_shortcode' );

        add_shortcode( 'hb_availability', array( $this, 'shortcode_availability' ) );
        add_shortcode( 'hb_booking_form', array( $this, 'shortcode_booking_form' ) );

        // Minimal stubs to avoid raw shortcodes in content when HBook is removed.
        add_shortcode( 'hb_rates', array( $this, 'shortcode_not_implemented' ) );
        add_shortcode( 'hb_accommodation_list', array( $this, 'shortcode_not_implemented' ) );
        add_shortcode( 'hb_starting_price', array( $this, 'shortcode_not_implemented' ) );
        add_shortcode( 'hb_reservation_summary', array( $this, 'shortcode_not_implemented' ) );
        add_shortcode( 'hb_paypal_confirmation', array( $this, 'shortcode_not_implemented' ) );

        // Public AJAX: calculate availability + price breakdown for booking form (replacement for HBook search results).
        add_action( 'wp_ajax_nopriv_goldenstay_hb_calc_prices', array( $this, 'ajax_hb_calc_prices' ) );
        add_action( 'wp_ajax_goldenstay_hb_calc_prices', array( $this, 'ajax_hb_calc_prices' ) );

        // Public AJAX: recalc prices when user selects additional fees on the website.
        add_action( 'wp_ajax_nopriv_goldenstay_hb_recalc_prices', array( $this, 'ajax_hb_recalc_prices' ) );
        add_action( 'wp_ajax_goldenstay_hb_recalc_prices', array( $this, 'ajax_hb_recalc_prices' ) );
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

    public function enqueue_hbook_assets() {
        if ( $this->base_assets_enqueued ) {
            return;
        }

        // Styles
        // Prefer original HBook CSS if the plugin is installed (even if deactivated),
        // to match Adomus theme styling as closely as possible.
        $hbook_css_path = WP_PLUGIN_DIR . '/hbook/front-end/css/hbook.css';
        $front_css_url = GOLDENSTAY_PLUGIN_URL . 'assets/hbook/css/hb-front-end-style.min.css';
        if ( file_exists( $hbook_css_path ) ) {
            $front_css_url = plugins_url( 'hbook/front-end/css/hbook.css' );
        }
        wp_enqueue_style(
            'gs-hb-front-end',
            $front_css_url,
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

        // Minimal booking form behaviour (AJAX search + price breakdown toggle).
        wp_enqueue_style(
            'gs-hb-booking',
            GOLDENSTAY_PLUGIN_URL . 'assets/hbook/css/gs-booking.css',
            array( 'gs-hb-front-end', 'gs-hb-datepick' ),
            GOLDENSTAY_VERSION
        );
        wp_enqueue_script(
            'gs-hb-booking',
            GOLDENSTAY_PLUGIN_URL . 'assets/hbook/js/gs-booking.js',
            array( 'jquery', 'gs-hb-datepick', 'gs-hb-utils' ),
            GOLDENSTAY_VERSION,
            true
        );
        wp_localize_script(
            'gs-hb-booking',
            'gsHbBooking',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce' => wp_create_nonce( 'goldenstay_frontend_nonce' ),
                'currency' => '€',
            )
        );

        $this->base_assets_enqueued = true;
    }

    public function enqueue_availability_assets() {
        if ( $this->availability_assets_enqueued ) {
            return;
        }

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

        $this->availability_assets_enqueued = true;
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
        // WP_Locale::get_weekday_abbrev expects the FULL translated weekday name.
        $days_min = array();
        for ( $i = 0; $i < 7; $i++ ) {
            if ( $wp_locale ) {
                $weekday = $wp_locale->get_weekday( $i );
                $days_min[] = $wp_locale->get_weekday_abbrev( $weekday );
            } else {
                $days_min[] = date_i18n( 'D', strtotime( 'Sunday +' . $i . ' day' ) );
            }
        }

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

    private function api_get_json( $endpoint, $with_token = false ) {
        $api_url = GoldenStay_Manager::get_api_url();
        $url = trailingslashit( $api_url ) . ltrim( $endpoint, '/' );

        $args = array(
            'headers' => array(
                'Accept' => 'application/json',
            ),
            'timeout' => 30,
        );

        if ( $with_token ) {
            $token = GoldenStay_Manager::get_api_token();
            if ( $token ) {
                $args['headers']['Authorization'] = $token;
            }
        }

        return wp_remote_get( $url, $args );
    }

    public function ajax_hb_calc_prices() {
        check_ajax_referer( 'goldenstay_frontend_nonce', 'nonce' );

        $accom_id = isset( $_POST['accom_id'] ) ? intval( $_POST['accom_id'] ) : 0;
        $check_in_raw = isset( $_POST['check_in'] ) ? sanitize_text_field( $_POST['check_in'] ) : '';
        $check_out_raw = isset( $_POST['check_out'] ) ? sanitize_text_field( $_POST['check_out'] ) : '';
        $adults = isset( $_POST['adults'] ) ? intval( $_POST['adults'] ) : 1;
        $children = isset( $_POST['children'] ) ? intval( $_POST['children'] ) : 0;

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log(
                '[GS HB] ajax_hb_calc_prices request: ' . wp_json_encode(
                    array(
                        'accom_id' => $accom_id,
                        'check_in' => $check_in_raw,
                        'check_out' => $check_out_raw,
                        'adults' => $adults,
                        'children' => $children,
                    )
                )
            );
        }

        $result = $this->build_hb_calc_prices_result(
            $accom_id,
            $check_in_raw,
            $check_out_raw,
            $adults,
            $children
        );

        if ( empty( $result['success'] ) ) {
            wp_send_json_error(
                array(
                    'message' => isset( $result['message'] ) ? $result['message'] : 'Failed to calculate price',
                )
            );
        }

        wp_send_json_success(
            array(
                'available' => ! empty( $result['available'] ),
                'mark_up' => isset( $result['mark_up'] ) ? $result['mark_up'] : '',
            )
        );
    }

    public function ajax_hb_recalc_prices() {
        check_ajax_referer( 'goldenstay_frontend_nonce', 'nonce' );

        $accom_id = isset( $_POST['accom_id'] ) ? intval( $_POST['accom_id'] ) : 0;
        $check_in_raw = isset( $_POST['check_in'] ) ? sanitize_text_field( $_POST['check_in'] ) : '';
        $check_out_raw = isset( $_POST['check_out'] ) ? sanitize_text_field( $_POST['check_out'] ) : '';
        $adults = isset( $_POST['adults'] ) ? intval( $_POST['adults'] ) : 1;
        $children = isset( $_POST['children'] ) ? intval( $_POST['children'] ) : 0;
        $fee_ids_raw = isset( $_POST['fee_ids'] ) ? sanitize_text_field( $_POST['fee_ids'] ) : '';
        $base_fee_ids_raw = isset( $_POST['base_fee_ids'] ) ? sanitize_text_field( $_POST['base_fee_ids'] ) : '';
        $toggle_fee_ids_raw = isset( $_POST['toggle_fee_ids'] ) ? sanitize_text_field( $_POST['toggle_fee_ids'] ) : '';

        if ( ! $accom_id ) {
            wp_send_json_error( array( 'message' => 'Accommodation ID is required' ) );
        }

        $token = GoldenStay_Manager::get_api_token();
        if ( ! $token ) {
            wp_send_json_error( array( 'message' => 'GoldenStay API token is missing. Please login in WP admin.' ) );
        }

        $property_id = GoldenStay_Accommodation_Mapping::get_property_id_for_accom( $accom_id );
        if ( ! $property_id ) {
            wp_send_json_error( array( 'message' => 'Property mapping is missing for this accommodation' ) );
        }

        $date_from = $this->normalize_date_ymd( $check_in_raw );
        $date_to = $this->normalize_date_ymd( $check_out_raw );
        if ( ! $date_from || ! $date_to ) {
            wp_send_json_error( array( 'message' => 'Please select check-in and check-out dates' ) );
        }
        if ( strtotime( $date_to ) <= strtotime( $date_from ) ) {
            wp_send_json_error( array( 'message' => 'Check-out date must be after check-in date' ) );
        }

        $adults = max( 0, intval( $adults ) );
        $children = max( 0, intval( $children ) );
        $guests = max( 1, $adults + $children );

        $parse_ids = function ( $raw ) {
            $ids = array();
            $raw = trim( (string) $raw );
            if ( $raw === '' ) {
                return $ids;
            }
            $parts = preg_split( '/[,\s]+/', $raw );
            if ( is_array( $parts ) ) {
                foreach ( $parts as $p ) {
                    $id = intval( trim( (string) $p ) );
                    if ( $id > 0 ) {
                        $ids[] = $id;
                    }
                }
            }
            return array_values( array_unique( array_filter( $ids ) ) );
        };

        // Posted selected fee IDs (website optional selections)
        $fee_ids = $parse_ids( $fee_ids_raw );
        // Fee IDs that were included in the Step 1 total (base fees)
        $base_fee_ids = $parse_ids( $base_fee_ids_raw );
        // Fee IDs that are toggleable in the website fees UI (checkbox list)
        $toggle_fee_ids = $parse_ids( $toggle_fee_ids_raw );

        $fees_by_id = $this->get_property_additional_fees_by_id( $property_id );

        $fee_keys = array_map( 'intval', array_keys( $fees_by_id ) );
        $fee_key_map = array_fill_keys( $fee_keys, true );

        $filter_existing = function ( $ids ) use ( $fee_key_map ) {
            $out = array();
            foreach ( $ids as $id ) {
                $id = intval( $id );
                if ( $id > 0 && isset( $fee_key_map[ $id ] ) ) {
                    $out[] = $id;
                }
            }
            return array_values( array_unique( $out ) );
        };

        $fee_ids = $filter_existing( $fee_ids );
        $base_fee_ids = $filter_existing( $base_fee_ids );
        $toggle_fee_ids = $filter_existing( $toggle_fee_ids );

        $selected_optional_fee_ids = array();
        $all_fee_ids = array();

        // Preferred mode: base fees are provided by frontend (keeps totals stable).
        if ( count( $base_fee_ids ) ) {
            $toggle_map = array_fill_keys( $toggle_fee_ids, true );
            $fixed_fee_ids = array();
            foreach ( $base_fee_ids as $id ) {
                if ( isset( $toggle_map[ $id ] ) ) {
                    continue;
                }
                $fixed_fee_ids[] = $id;
            }

            $toggle_allowed_map = array_fill_keys( $toggle_fee_ids, true );
            foreach ( $fee_ids as $id ) {
                if ( isset( $toggle_allowed_map[ $id ] ) ) {
                    $selected_optional_fee_ids[] = $id;
                }
            }

            $all_fee_ids = array_values(
                array_unique(
                    array_filter(
                        array_merge( $fixed_fee_ids, $selected_optional_fee_ids )
                    )
                )
            );
        } else {
            // Fallback: keep mandatory fees always included.
            $mandatory_fee_ids = array();
            foreach ( $fees_by_id as $fid => $fee ) {
                if ( ! is_array( $fee ) ) {
                    continue;
                }
                $is_optional = ! empty( $fee['optional'] );
                if ( ! $is_optional ) {
                    $mandatory_fee_ids[] = intval( $fid );
                }
            }

            foreach ( $fee_ids as $fid ) {
                if ( ! isset( $fees_by_id[ $fid ] ) || ! is_array( $fees_by_id[ $fid ] ) ) {
                    continue;
                }
                if ( empty( $fees_by_id[ $fid ]['optional'] ) ) {
                    continue;
                }
                $selected_optional_fee_ids[] = intval( $fid );
            }

            $all_fee_ids = array_values(
                array_unique(
                    array_filter(
                        array_merge( $mandatory_fee_ids, $selected_optional_fee_ids )
                    )
                )
            );
        }

        $response = $this->api_post_json(
            'reservation/calc-prices',
            array(
                'fees' => $all_fee_ids,
                'property_id' => intval( $property_id ),
                'date_from' => $date_from,
                'date_to' => $date_to,
                'number_of_guests' => intval( $guests ),
                'number_of_adults' => intval( $adults ),
                'number_of_children' => intval( $children ),
                'units' => 1,
                'rent' => null,
                'auto_recalculation' => true,
            ),
            true
        );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array( 'message' => 'API connection error: ' . $response->get_error_message() ) );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $calc_prices = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $status_code !== 200 || ! is_array( $calc_prices ) ) {
            wp_send_json_error( array( 'message' => 'Failed to calculate price' ) );
        }

        $variant = $this->pick_calc_prices_variant( $calc_prices, $guests );
        if ( ! is_array( $variant ) ) {
            wp_send_json_error( array( 'message' => 'Failed to calculate price' ) );
        }

        $total = isset( $variant['total'] ) ? floatval( $variant['total'] ) : 0;

        $fee_amounts = array();
        $services = isset( $variant['services'] ) && is_array( $variant['services'] ) ? $variant['services'] : array();
        foreach ( $services as $service ) {
            if ( ! is_array( $service ) ) {
                continue;
            }
            $fee_id = isset( $service['property_additional_fee_id'] ) ? intval( $service['property_additional_fee_id'] ) : 0;
            if ( ! $fee_id ) {
                continue;
            }
            $amount = isset( $service['amount'] ) ? floatval( $service['amount'] ) : 0;
            $fee_amounts[ $fee_id ] = array(
                'amount' => $amount,
                'formatted' => $this->format_price( $amount ),
            );
        }

        wp_send_json_success(
            array(
                'total' => $total,
                'total_formatted' => $this->format_price( $total ),
                'fee_amounts' => $fee_amounts,
                'selected_optional_fee_ids' => array_values( array_unique( $selected_optional_fee_ids ) ),
            )
        );
    }

    private function normalize_date_ymd( $value ) {
        $value = trim( (string) $value );
        if ( ! $value ) {
            return '';
        }

        $formats = array( 'Y-m-d', 'd-m-Y', 'd/m/Y' );
        foreach ( $formats as $format ) {
            $dt = DateTime::createFromFormat( $format, $value );
            $errors = DateTime::getLastErrors();
            if ( $dt && empty( $errors['warning_count'] ) && empty( $errors['error_count'] ) ) {
                return $dt->format( 'Y-m-d' );
            }
        }

        // Fallback: try native parsing (last resort)
        $ts = strtotime( $value );
        if ( $ts ) {
            return date( 'Y-m-d', $ts );
        }

        return '';
    }

    private function diff_nights( $date_from, $date_to ) {
        $from_ts = strtotime( $date_from );
        $to_ts = strtotime( $date_to );
        if ( ! $from_ts || ! $to_ts ) {
            return 0;
        }
        $diff = intval( floor( ( $to_ts - $from_ts ) / DAY_IN_SECONDS ) );
        return max( 0, $diff );
    }

    private function format_price( $amount ) {
        $num = is_numeric( $amount ) ? floatval( $amount ) : 0;
        return '€' . number_format_i18n( $num, 2 );
    }

    private function calculate_fee_amount_preview( $fee, $client_total, $nights, $adults, $children ) {
        if ( ! is_array( $fee ) ) {
            return 0;
        }
        $value = isset( $fee['value'] ) ? floatval( $fee['value'] ) : 0;
        $discriminator = isset( $fee['discriminator_id'] ) ? intval( $fee['discriminator_id'] ) : 0;
        $nights = max( 1, intval( $nights ) );
        $adults = max( 0, intval( $adults ) );
        $children = max( 0, intval( $children ) );
        $guests = max( 1, $adults + $children );
        $client_total = is_numeric( $client_total ) ? floatval( $client_total ) : 0;

        switch ( $discriminator ) {
            case 1: // FlatPerStay
                return $value;
            case 2: // FixedPerDay
                return $value * $nights;
            case 3: // IndependentPercentage (based on current client_total)
                return $value * $client_total;
            case 5: // FixedAmountPerPerson
                return $value * $guests;
            case 6: // FixedAmountPerPersonPerDay
                return $value * $guests * $nights;
            case 7: // FixedAmountPerPersonPerWeek
                $weeks = (int) ceil( $nights / 7 );
                return $value * $guests * max( 1, $weeks );
            case 8: // FixedAmountPerWeek
                $weeks2 = (int) ceil( $nights / 7 );
                return $value * max( 1, $weeks2 );
            default:
                return $value;
        }
    }

    private function get_fee_display_name_nl( $fee ) {
        if ( ! is_array( $fee ) ) {
            return '';
        }

        $name = '';
        if ( isset( $fee['translations'] ) && is_array( $fee['translations'] ) ) {
            foreach ( $fee['translations'] as $tr ) {
                if ( ! is_array( $tr ) ) {
                    continue;
                }
                // 11 = nl
                if ( isset( $tr['language_id'] ) && intval( $tr['language_id'] ) === 11 && ! empty( $tr['name'] ) ) {
                    $name = (string) $tr['name'];
                    break;
                }
            }
        }

        if ( $name === '' ) {
            $name = isset( $fee['name'] ) ? (string) $fee['name'] : '';
        }

        return trim( (string) $name );
    }

    private function get_property_additional_fees_by_id( $property_id ) {
        $property_id = intval( $property_id );
        if ( ! $property_id ) {
            return array();
        }

        $transient_key = 'gs_prop_fees_v1_' . $property_id;
        $cached = get_transient( $transient_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $response = $this->api_get_json( 'property/' . $property_id, true );
        if ( is_wp_error( $response ) ) {
            return array();
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $status_code !== 200 || ! is_array( $data ) ) {
            return array();
        }

        $fees = isset( $data['additional_fees'] ) && is_array( $data['additional_fees'] )
            ? $data['additional_fees']
            : array();

        $map = array();
        foreach ( $fees as $fee ) {
            if ( ! is_array( $fee ) || empty( $fee['id'] ) ) {
                continue;
            }
            $map[ intval( $fee['id'] ) ] = $fee;
        }

        // 6 hours: fees do not change often.
        set_transient( $transient_key, $map, 6 * HOUR_IN_SECONDS );
        return $map;
    }

    private function get_calc_prices_fee_ids( $property_id, $fees_by_id = array() ) {
        $default_fees = get_option( 'goldenstay_calc_prices_fee_ids', array() );
        $fees = is_array( $default_fees ) ? array_values( array_unique( array_map( 'intval', $default_fees ) ) ) : array();
        $fees = array_values( array_filter( $fees ) );
        if ( count( $fees ) ) {
            return $fees;
        }

        // Auto-mode: include non-optional fees and "enabled by default" fees for the property.
        if ( ! is_array( $fees_by_id ) || ! count( $fees_by_id ) ) {
            $fees_by_id = $this->get_property_additional_fees_by_id( $property_id );
        }

        $out = array();
        foreach ( $fees_by_id as $fee_id => $fee ) {
            if ( ! is_array( $fee ) ) {
                continue;
            }
            $is_optional = ! empty( $fee['optional'] );
            $enable_by_default = ! empty( $fee['enable_by_default'] );
            if ( ! $is_optional || $enable_by_default ) {
                $out[] = intval( $fee_id );
            }
        }

        return array_values( array_unique( array_filter( $out ) ) );
    }

    private function is_available_for_period( $property_id, $date_from, $date_to ) {
        $property_id = intval( $property_id );
        if ( ! $property_id || ! $date_from || ! $date_to ) {
            return false;
        }

        $last_night = date( 'Y-m-d', strtotime( $date_to . ' -1 day' ) );
        if ( strtotime( $last_night ) < strtotime( $date_from ) ) {
            return false;
        }

        $endpoint = sprintf(
            'property/calendar_day/period/%d/%s/%s',
            $property_id,
            rawurlencode( $date_from ),
            rawurlencode( $last_night )
        );

        $response = $this->api_get_json( $endpoint, true );
        if ( is_wp_error( $response ) ) {
            return false;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $days = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $status_code !== 200 || ! is_array( $days ) ) {
            return false;
        }

        foreach ( $days as $day ) {
            if ( is_array( $day ) && $this->is_calendar_day_taken( $day ) ) {
                return false;
            }
        }

        return true;
    }

    private function pick_calc_prices_variant( $calc_prices, $guests ) {
        if ( ! is_array( $calc_prices ) ) {
            return null;
        }
        $key = strval( intval( $guests ) > 0 ? intval( $guests ) : 1 );
        if ( isset( $calc_prices[ $key ] ) && is_array( $calc_prices[ $key ] ) ) {
            return $calc_prices[ $key ];
        }
        foreach ( $calc_prices as $variant ) {
            if ( is_array( $variant ) && isset( $variant['total'] ) ) {
                return $variant;
            }
        }
        return null;
    }

    private function build_hb_price_breakdown_markup( $variant, $date_from, $date_to, $nights, $adults, $children, $fees_by_id = array() ) {
        $adults = max( 0, intval( $adults ) );
        $children = max( 0, intval( $children ) );
        $guests = max( 1, $adults + $children );

        $rent = isset( $variant['rent'] ) ? floatval( $variant['rent'] ) : 0;
        $day_rate = isset( $variant['day_rate'] ) ? floatval( $variant['day_rate'] ) : 0;
        $service_amount = isset( $variant['service_amount'] ) ? floatval( $variant['service_amount'] ) : 0;

        $services = isset( $variant['services'] ) && is_array( $variant['services'] ) ? $variant['services'] : array();

        $out = '';

        // Rent (villa price)
        $out .= '<span class="hb-price-breakdown-accom">';
        $out .= '<span class="hb-price-breakdown-title">';
        $out .= 'Prijs villa: <span class="hb-price-breakdown-amount">' . esc_html( $this->format_price( $rent ) ) . '</span>';
        $out .= '</span>';
        $out .= '<span class="hb-price-breakdown-section">';
        $out .= 'Van <span class="hb-format-date">' . esc_html( $date_from ) . '</span> tot <span class="hb-format-date">' . esc_html( $date_to ) . '</span>';
        $out .= ' (' . intval( $nights ) . ' nachten x ' . esc_html( $this->format_price( $day_rate ) ) . ') : ';
        $out .= esc_html( $this->format_price( $rent ) );
        $out .= '</span>';
        $out .= '</span>';

        // Fees / taxes / services
        $out .= '<span class="hb-price-breakdown-fees">';
        $out .= '<span class="hb-price-breakdown-title">';
        $out .= 'Toeslagen: <span class="hb-price-breakdown-amount">' . esc_html( $this->format_price( $service_amount ) ) . '</span>';
        $out .= '</span>';

        foreach ( $services as $service ) {
            if ( ! is_array( $service ) ) {
                continue;
            }
            $desc = isset( $service['description'] ) ? (string) $service['description'] : '';
            if ( $desc === '' ) {
                continue;
            }
            $amount = isset( $service['amount'] ) ? floatval( $service['amount'] ) : 0;

            $detail = '';
            $fee_id = isset( $service['property_additional_fee_id'] ) ? intval( $service['property_additional_fee_id'] ) : 0;
            if ( $fee_id && isset( $fees_by_id[ $fee_id ] ) && is_array( $fees_by_id[ $fee_id ] ) ) {
                $fee = $fees_by_id[ $fee_id ];
                $discriminator = isset( $fee['discriminator_id'] ) ? intval( $fee['discriminator_id'] ) : 0;
                $value = isset( $fee['value'] ) ? floatval( $fee['value'] ) : 0;

                if ( $value > 0 ) {
                    if ( $discriminator === 2 ) { // FixedPerDay
                        $detail = '(' . intval( $nights ) . ' nachten x ' . $this->format_price( $value ) . ')';
                    } else if ( $discriminator === 5 ) { // FixedAmountPerPerson
                        // Prefer adult-based wording when no children are selected (matches original UI).
                        if ( $children <= 0 && $adults > 0 ) {
                            $label = $adults === 1 ? 'volwassene' : 'volwassenen';
                            $detail = '(' . intval( $adults ) . ' ' . $label . ' x ' . $this->format_price( $value ) . ')';
                        } else {
                            $detail = '(' . intval( $guests ) . ' personen x ' . $this->format_price( $value ) . ')';
                        }
                    } else if ( $discriminator === 6 ) { // FixedAmountPerPersonPerDay
                        if ( $children <= 0 && $adults > 0 ) {
                            $label = $adults === 1 ? 'volwassene' : 'volwassenen';
                            $detail = '(' . intval( $adults ) . ' ' . $label . ' x ' . intval( $nights ) . ' nachten x ' . $this->format_price( $value ) . ')';
                        } else {
                            $detail = '(' . intval( $guests ) . ' personen x ' . intval( $nights ) . ' nachten x ' . $this->format_price( $value ) . ')';
                        }
                    } else if ( $discriminator === 3 ) { // IndependentPercentage
                        $detail = '(' . esc_html( rtrim( rtrim( number_format_i18n( $value * 100, 2 ), '0' ), ',' ) ) . '%)';
                    }
                }
            }

            $out .= '<span class="hb-price-breakdown-section">';
            $out .= esc_html( $desc );
            if ( $detail ) {
                $out .= ' ' . $detail;
            }
            $out .= ': ' . esc_html( $this->format_price( $amount ) );
            $out .= '</span>';
        }

        $out .= '</span>';
        return $out;
    }

    private function build_hb_fees_step_markup( $accom_id, $date_from, $date_to, $nights, $adults, $children, $variant, $fees_by_id, $default_fee_ids ) {
        if ( ! is_array( $fees_by_id ) || ! count( $fees_by_id ) ) {
            return '';
        }

        $accom_name = get_the_title( $accom_id );
        if ( ! $accom_name ) {
            $accom_name = 'Accommodation';
        }

        $rent = isset( $variant['rent'] ) ? floatval( $variant['rent'] ) : 0;

        // Website optional fees (checkboxes)
        $options = array();
        foreach ( $fees_by_id as $fee_id => $fee ) {
            if ( ! is_array( $fee ) ) {
                continue;
            }
            $fee_id = intval( $fee_id );
            if ( ! $fee_id ) {
                continue;
            }

            $is_optional = ! empty( $fee['optional'] );
            if ( ! $is_optional ) {
                continue;
            }

            // website_optional is nullable in DB: treat NULL as "not configured" (visible).
            $website_optional_raw = array_key_exists( 'website_optional', $fee ) ? $fee['website_optional'] : null;
            $is_website_optional = ( $website_optional_raw === null ) ? true : ! empty( $website_optional_raw );
            if ( ! $is_website_optional ) {
                continue;
            }

            $options[] = array(
                'id' => $fee_id,
                'fee' => $fee,
            );
        }

        if ( ! count( $options ) ) {
            return '';
        }

        usort(
            $options,
            function ( $a, $b ) {
                $a_order = isset( $a['fee']['order'] ) ? intval( $a['fee']['order'] ) : 999999;
                $b_order = isset( $b['fee']['order'] ) ? intval( $b['fee']['order'] ) : 999999;
                if ( $a_order === $b_order ) {
                    return intval( $a['id'] ) - intval( $b['id'] );
                }
                return $a_order - $b_order;
            }
        );

        // Amounts for fees already included in calc-prices response.
        $amounts_by_fee = array();
        if ( is_array( $variant ) && isset( $variant['services'] ) && is_array( $variant['services'] ) ) {
            foreach ( $variant['services'] as $service ) {
                if ( ! is_array( $service ) ) {
                    continue;
                }
                $fid = isset( $service['property_additional_fee_id'] ) ? intval( $service['property_additional_fee_id'] ) : 0;
                if ( ! $fid ) {
                    continue;
                }
                $amounts_by_fee[ $fid ] = isset( $service['amount'] ) ? floatval( $service['amount'] ) : 0;
            }
        }

        $base_total = isset( $variant['total'] ) ? floatval( $variant['total'] ) : 0;

        $base_fee_ids = is_array( $default_fee_ids ) ? $default_fee_ids : array();
        $base_fee_ids = array_values( array_unique( array_filter( array_map( 'intval', $base_fee_ids ) ) ) );

        $toggle_fee_ids = array();
        foreach ( $options as $opt ) {
            $toggle_fee_ids[] = intval( $opt['id'] );
        }
        $toggle_fee_ids = array_values( array_unique( array_filter( $toggle_fee_ids ) ) );

        // Provide fee meta (name + order) for summary rendering in JS.
        $fee_meta = array();
        foreach ( $fees_by_id as $fid => $fee ) {
            if ( ! is_array( $fee ) ) {
                continue;
            }
            $fid = intval( $fid );
            if ( ! $fid ) {
                continue;
            }
            $name = $this->get_fee_display_name_nl( $fee );
            if ( $name === '' ) {
                $name = 'Fee';
            }
            $order = isset( $fee['order'] ) ? intval( $fee['order'] ) : 999999;
            $fee_meta[ $fid ] = array(
                'name' => $name,
                'order' => $order,
            );
        }
        $fee_meta_json = wp_json_encode( $fee_meta );
        if ( false === $fee_meta_json ) {
            $fee_meta_json = '{}';
        }

        $initial_selected = array();
        foreach ( $options as $opt ) {
            if ( in_array( intval( $opt['id'] ), $default_fee_ids, true ) ) {
                $initial_selected[] = intval( $opt['id'] );
            } else if ( ! empty( $opt['fee']['enable_by_default'] ) ) {
                // Fallback if defaults were not passed explicitly
                $initial_selected[] = intval( $opt['id'] );
            }
        }
        $initial_selected = array_values( array_unique( array_filter( $initial_selected ) ) );

        $out = '';
        $out .= '<div class="hb-intermediate-step-wrapper hb-step-wrapper gs-hb-fees-step" style="display:none;">';

        // Back button
        $out .= '<p class="hb-step-button hb-button-wrapper hb-previous-step hb-previous-step-1">';
        $out .= '<input type="submit" value="← TERUG" />';
        $out .= '</p>';

        // Options form
        $out .= '<form class="hb-options-form gs-hb-fees-form" ' .
            'data-accom-id="' . esc_attr( intval( $accom_id ) ) . '" ' .
            'data-accom-name="' . esc_attr( $accom_name ) . '" ' .
            'data-check-in="' . esc_attr( $date_from ) . '" ' .
            'data-check-out="' . esc_attr( $date_to ) . '" ' .
            'data-adults="' . esc_attr( intval( $adults ) ) . '" ' .
            'data-children="' . esc_attr( intval( $children ) ) . '" ' .
            'data-rent="' . esc_attr( $rent ) . '" ' .
            'data-base-total="' . esc_attr( $base_total ) . '" ' .
            'data-current-total="' . esc_attr( $base_total ) . '" ' .
            'data-base-fee-ids="' . esc_attr( implode( ',', $base_fee_ids ) ) . '" ' .
            'data-toggle-fee-ids="' . esc_attr( implode( ',', $toggle_fee_ids ) ) . '" ' .
            'data-fee-meta="' . esc_attr( $fee_meta_json ) . '"' .
        '>';

        $out .= '<h3 class="hb-title hb-title-extra">' . esc_html( "Extra's" ) . '</h3>';

        $out .= '<div class="hb-options-multi-accoms hb-options-multi-accom-1">';
        foreach ( $options as $opt ) {
            $fee_id = intval( $opt['id'] );
            $fee = $opt['fee'];
            $name = $this->get_fee_display_name_nl( $fee );
            if ( $name === '' ) {
                $name = 'Fee';
            }

            $is_checked = in_array( $fee_id, $initial_selected, true );
            $amount = isset( $amounts_by_fee[ $fee_id ] )
                ? floatval( $amounts_by_fee[ $fee_id ] )
                : $this->calculate_fee_amount_preview( $fee, $base_total, $nights, $adults, $children );

            $amount = is_numeric( $amount ) ? floatval( $amount ) : 0;
            $amount_formatted = $this->format_price( $amount );
            $amount_data = number_format( $amount, 2, '.', '' );

            $input_id = 'gs-hb-fee-' . intval( $fee_id ) . '-' . intval( $accom_id );

            $out .= '<div class="hb-single-option hb-option gs-hb-fee-option">';
            $out .= '<span class="hb-checkbox-wrapper">';
            $out .= '<input type="checkbox" class="gs-hb-fee-checkbox" ' .
                'data-fee-id="' . esc_attr( $fee_id ) . '" ' .
                'data-price="' . esc_attr( $amount_data ) . '" ' .
                'id="' . esc_attr( $input_id ) . '" ' .
                'name="' . esc_attr( 'gs-hb-fee-' . $fee_id ) . '" ' .
                ( $is_checked ? 'checked ' : '' ) .
            '/>';
            $out .= '<label for="' . esc_attr( $input_id ) . '">';
            $out .= '<span class="gs-hb-fee-name">' . esc_html( $name ) . '</span> ';
            $out .= '<span class="gs-hb-fee-price">(' . esc_html( $amount_formatted ) . ')</span>';
            $out .= '</label>';
            $out .= '</span>';
            $out .= '</div>';
        }
        $out .= '</div>';

        // Options subtotal (HBook-compatible placeholders)
        $out .= '<p class="hb-options-total-price" style="display:none;">';
        $out .= esc_html( "Totaal extra's:" ) . ' ';
        $out .= '<span class="hb-price-placeholder-minus">-</span>';
        $out .= '€<span class="hb-price-placeholder"></span>';
        $out .= '</p>';

        // Total price with selected fees
        $out .= '<p class="gs-hb-total-price">';
        $out .= esc_html( 'Totaal prijs:' ) . ' ';
        $out .= '<span class="gs-hb-total-price-value">' . esc_html( $this->format_price( $base_total ) ) . '</span>';
        $out .= '</p>';

        // Hidden value with selected optional fees (CSV)
        $out .= '<input type="hidden" class="gs-hb-selected-fees" name="gs_selected_fees" value="' . esc_attr( implode( ',', $initial_selected ) ) . '" />';
        $out .= '<input name="hb-has-options-form" type="hidden" value="yes" />';
        $out .= '</form>';

        // Next button (step 2)
        $out .= '<p class="hb-step-button hb-button-wrapper hb-next-step hb-next-step-2">';
        $out .= '<input type="submit" value="NEXT →" />';
        $out .= '</p>';

        $out .= '</div>';

        return $out;
    }

    private function build_hb_details_step_markup( $accom_id, $accom_name, $date_from, $date_to, $nights, $adults, $children, $variant, $fees_by_id, $base_fee_ids ) {
        $accom_id = intval( $accom_id );
        if ( ! $accom_id ) {
            return '';
        }

        $accom_name = trim( (string) $accom_name );
        if ( $accom_name === '' ) {
            $accom_name = 'Accommodation';
        }

        $rent = isset( $variant['rent'] ) ? floatval( $variant['rent'] ) : 0;
        $base_total = isset( $variant['total'] ) ? floatval( $variant['total'] ) : 0;

        $base_fee_ids = is_array( $base_fee_ids ) ? $base_fee_ids : array();
        $base_fee_ids = array_values( array_unique( array_filter( array_map( 'intval', $base_fee_ids ) ) ) );

        // Determine which fees are toggleable on the website (same filter as fees-step)
        $toggle_fee_ids = array();
        if ( is_array( $fees_by_id ) ) {
            foreach ( $fees_by_id as $fid => $fee ) {
                if ( ! is_array( $fee ) ) {
                    continue;
                }
                $fid = intval( $fid );
                if ( ! $fid ) {
                    continue;
                }
                $is_optional = ! empty( $fee['optional'] );
                if ( ! $is_optional ) {
                    continue;
                }
                $website_optional_raw = array_key_exists( 'website_optional', $fee ) ? $fee['website_optional'] : null;
                $is_website_optional = ( $website_optional_raw === null ) ? true : ! empty( $website_optional_raw );
                if ( ! $is_website_optional ) {
                    continue;
                }
                $toggle_fee_ids[] = $fid;
            }
        }
        $toggle_fee_ids = array_values( array_unique( array_filter( $toggle_fee_ids ) ) );

        $fee_meta = array();
        if ( is_array( $fees_by_id ) ) {
            foreach ( $fees_by_id as $fid => $fee ) {
                if ( ! is_array( $fee ) ) {
                    continue;
                }
                $fid = intval( $fid );
                if ( ! $fid ) {
                    continue;
                }
                $name = $this->get_fee_display_name_nl( $fee );
                if ( $name === '' ) {
                    $name = 'Fee';
                }
                $order = isset( $fee['order'] ) ? intval( $fee['order'] ) : 999999;
                $fee_meta[ $fid ] = array(
                    'name' => $name,
                    'order' => $order,
                );
            }
        }
        $fee_meta_json = wp_json_encode( $fee_meta );
        if ( false === $fee_meta_json ) {
            $fee_meta_json = '{}';
        }

        $out = '';
        $out .= '<form class="hb-booking-details-form hb-step-wrapper gs-hb-details-step" style="display:none;" novalidate>';

        // Back button to fees-step (or price-step if no fees)
        $out .= '<p class="hb-step-button hb-button-wrapper hb-previous-step hb-previous-step-2">';
        $out .= '<input type="submit" value="← PREVIOUS" />';
        $out .= '</p>';

        // Customer details (minimal clone of original HBook layout)
        $out .= '<div id="hb-resa-customer-details-wrap">';
        $out .= '<h3 class="hb-title">' . esc_html( 'Vul uw gegevens in' ) . '</h3>';
        $out .= '<div class="hb-details-fields">';
        $suffix = '-' . intval( $accom_id );
        $out .= '<p><label for="gs-hb-first-name' . esc_attr( $suffix ) . '">' . esc_html( 'Voornaam' ) . '*</label>';
        $out .= '<input id="gs-hb-first-name' . esc_attr( $suffix ) . '" name="hb_first_name" class="hb-detail-field" data-validation="required" type="text" /></p>';
        $out .= '<p><label for="gs-hb-last-name' . esc_attr( $suffix ) . '">' . esc_html( 'Naam' ) . '*</label>';
        $out .= '<input id="gs-hb-last-name' . esc_attr( $suffix ) . '" name="hb_last_name" class="hb-detail-field" data-validation="required" type="text" /></p>';
        $out .= '<p><label for="gs-hb-email' . esc_attr( $suffix ) . '">' . esc_html( 'e-mail' ) . '*</label>';
        $out .= '<input id="gs-hb-email' . esc_attr( $suffix ) . '" name="hb_email" class="hb-detail-field" data-validation="required email" type="email" /></p>';
        $out .= '<p><label for="gs-hb-phone' . esc_attr( $suffix ) . '">' . esc_html( 'Telefoon' ) . '</label>';
        $out .= '<input id="gs-hb-phone' . esc_attr( $suffix ) . '" name="hb_phone" class="hb-detail-field" data-validation="" type="text" /></p>';
        $out .= '<p><label for="gs-hb-address' . esc_attr( $suffix ) . '">' . esc_html( 'Adres' ) . '</label>';
        $out .= '<input id="gs-hb-address' . esc_attr( $suffix ) . '" name="hb_address_1" class="hb-detail-field" data-validation="" type="text" /></p>';
        $out .= '<p><label for="gs-hb-address-2' . esc_attr( $suffix ) . '">' . esc_html( 'Extra adresregel' ) . '</label>';
        $out .= '<input id="gs-hb-address-2' . esc_attr( $suffix ) . '" name="hb_address_2" class="hb-detail-field" data-validation="" type="text" /></p>';
        $out .= '<p><label for="gs-hb-city' . esc_attr( $suffix ) . '">' . esc_html( 'Plaats' ) . '</label>';
        $out .= '<input id="gs-hb-city' . esc_attr( $suffix ) . '" name="hb_city" class="hb-detail-field" data-validation="" type="text" /></p>';
        $out .= '<p><label for="gs-hb-state' . esc_attr( $suffix ) . '">' . esc_html( 'Provincie' ) . '</label>';
        $out .= '<input id="gs-hb-state' . esc_attr( $suffix ) . '" name="hb_state_province" class="hb-detail-field" data-validation="" type="text" /></p>';
        $out .= '<p><label for="gs-hb-country' . esc_attr( $suffix ) . '">' . esc_html( 'Land' ) . '*</label>';
        $out .= '<select id="gs-hb-country' . esc_attr( $suffix ) . '" name="hb_country_iso" class="hb-detail-field hb-country-iso-select" data-validation="required">';
        $out .= '<option value="">' . esc_html( '' ) . '</option>';
        $out .= '<option value="NL">Netherlands</option>';
        $out .= '<option value="BE">Belgium</option>';
        $out .= '<option value="DE">Germany</option>';
        $out .= '<option value="FR">France</option>';
        $out .= '<option value="GB">United Kingdom</option>';
        $out .= '</select></p>';
        $out .= '<p><label for="gs-hb-zip' . esc_attr( $suffix ) . '">' . esc_html( 'Postcode' ) . '</label>';
        $out .= '<input id="gs-hb-zip' . esc_attr( $suffix ) . '" name="hb_zip_code" class="hb-detail-field" data-validation="" type="text" /></p>';
        $out .= '</div>';
        $out .= '</div><!-- end #hb-resa-customer-details-wrap -->';

        // Summary (filled/updated by gs-booking.js)
        $out .= '<h3 class="hb-title hb-title-summary">' . esc_html( 'Samenvatting van uw reservatie' ) . '</h3>';
        $out .= '<div class="hb-summary-wrapper gs-hb-summary-wrapper" ' .
            'data-accom-id="' . esc_attr( $accom_id ) . '" ' .
            'data-accom-name="' . esc_attr( $accom_name ) . '" ' .
            'data-check-in="' . esc_attr( $date_from ) . '" ' .
            'data-check-out="' . esc_attr( $date_to ) . '" ' .
            'data-nights="' . esc_attr( intval( $nights ) ) . '" ' .
            'data-adults="' . esc_attr( intval( $adults ) ) . '" ' .
            'data-children="' . esc_attr( intval( $children ) ) . '" ' .
            'data-rent="' . esc_attr( $rent ) . '" ' .
            'data-base-total="' . esc_attr( $base_total ) . '" ' .
            'data-current-total="' . esc_attr( $base_total ) . '" ' .
            'data-base-fee-ids="' . esc_attr( implode( ',', $base_fee_ids ) ) . '" ' .
            'data-toggle-fee-ids="' . esc_attr( implode( ',', $toggle_fee_ids ) ) . '" ' .
            'data-fee-meta="' . esc_attr( $fee_meta_json ) . '"' .
        '>';
        $out .= '<div class="gs-hb-summary-lines">';
        $out .= '<div class="gs-hb-summary-line">Chosen check-in date <span class="gs-hb-summary-check-in">' . esc_html( $date_from ) . '</span></div>';
        $out .= '<div class="gs-hb-summary-line">Chosen check-out date <span class="gs-hb-summary-check-out">' . esc_html( $date_to ) . '</span></div>';
        $out .= '<div class="gs-hb-summary-line">Number of nights <span class="gs-hb-summary-nights">' . esc_html( intval( $nights ) ) . '</span></div>';
        $out .= '<div class="gs-hb-summary-line">Volwassenen: <span class="gs-hb-summary-adults">' . esc_html( intval( $adults ) ) . '</span></div>';
        $out .= '<br/>';
        $out .= '<div class="gs-hb-summary-line">Villa: <span class="gs-hb-summary-accom">' . esc_html( $accom_name ) . '</span></div>';
        $out .= '<div class="gs-hb-summary-line">Prijs villa: <span class="gs-hb-summary-rent">' . esc_html( $this->format_price( $rent ) ) . '</span></div>';
        $out .= '<div class="gs-hb-summary-fees"></div>';
        $out .= '<div class="gs-hb-summary-line gs-hb-summary-total"><strong>Totaal:</strong> <span class="gs-hb-summary-total-value">' . esc_html( $this->format_price( $base_total ) ) . '</span></div>';
        $out .= '</div>';
        $out .= '</div><!-- end .hb-summary-wrapper -->';

        // Policies (static copy of original wording seen in theme)
        $out .= '<div class="hb-policies-area">';
        $out .= '<h3 class="hb-title hb-title-terms">' . esc_html( 'Annuleringsvoorwaarden' ) . '</h3>';
        $out .= '<p><input type="checkbox" id="gs-hb-terms' . esc_attr( $suffix ) . '" name="hb_terms_and_cond" />';
        $out .= '<label for="gs-hb-terms' . esc_attr( $suffix ) . '" class="hb-terms-and-cond"> ' . esc_html( 'Ik heb de annuleringsvoorwaarden gelezen en accepteer deze voorwaarden voor deze boeking.' ) . '</label></p>';
        $out .= '<p><input type="checkbox" id="gs-hb-privacy' . esc_attr( $suffix ) . '" name="hb_privacy_policy" />';
        $out .= '<label for="gs-hb-privacy' . esc_attr( $suffix ) . '" class="hb-privacy-policy"> ' . esc_html( 'Ik heb de privacy voorwaarden gelezen en accepteer deze voorwaarden.' ) . '</label></p>';
        $out .= '<p class="hb-policies-error"></p>';
        $out .= '</div>';

        // Book now (no-op for now)
        $out .= '<div class="hb-confirm-area gs-hb-confirm-area">';
        $out .= '<p class="hb-confirm-error"></p>';
        $out .= '<p class="hb-confirm-button hb-button-wrapper"><input type="submit" class="gs-hb-book-now" value="BOOK NOW" /></p>';
        $out .= '</div>';

        // Hidden fields (keep same classnames as HBook for easier future integration)
        $out .= '<input type="hidden" class="hb-details-check-in" name="hb-details-check-in" value="' . esc_attr( $date_from ) . '" />';
        $out .= '<input type="hidden" class="hb-details-check-out" name="hb-details-check-out" value="' . esc_attr( $date_to ) . '" />';
        $out .= '<input type="hidden" class="hb-details-adults" name="hb-details-adults" value="' . esc_attr( intval( $adults ) ) . '" />';
        $out .= '<input type="hidden" class="hb-details-children" name="hb-details-children" value="' . esc_attr( intval( $children ) ) . '" />';
        $out .= '<input type="hidden" class="hb-details-accom-ids" name="hb-details-accom-ids" value="' . esc_attr( intval( $accom_id ) ) . '" />';
        $out .= '<input type="hidden" class="gs-hb-selected-fees" name="gs_selected_fees" value="" />';

        $out .= '</form>';

        return $out;
    }

    private function build_hb_calc_prices_result( $accom_id, $check_in_raw, $check_out_raw, $adults, $children ) {
        $accom_id = intval( $accom_id );
        if ( ! $accom_id ) {
            return array( 'success' => false, 'message' => 'Accommodation ID is required' );
        }

        $token = GoldenStay_Manager::get_api_token();
        if ( ! $token ) {
            return array( 'success' => false, 'message' => 'GoldenStay API token is missing. Please login in WP admin.' );
        }

        $property_id = GoldenStay_Accommodation_Mapping::get_property_id_for_accom( $accom_id );
        if ( ! $property_id ) {
            return array( 'success' => false, 'message' => 'Property mapping is missing for this accommodation' );
        }

        $date_from = $this->normalize_date_ymd( $check_in_raw );
        $date_to = $this->normalize_date_ymd( $check_out_raw );
        if ( ! $date_from || ! $date_to ) {
            return array( 'success' => false, 'message' => 'Please select check-in and check-out dates' );
        }
        if ( strtotime( $date_to ) <= strtotime( $date_from ) ) {
            return array( 'success' => false, 'message' => 'Check-out date must be after check-in date' );
        }

        $adults = max( 0, intval( $adults ) );
        $children = max( 0, intval( $children ) );
        $guests = max( 1, $adults + $children );
        $nights = $this->diff_nights( $date_from, $date_to );

        $accom_name = get_the_title( $accom_id );
        if ( ! $accom_name ) {
            $accom_name = 'Accommodation';
        }

        $available = $this->is_available_for_period( $property_id, $date_from, $date_to );
        if ( ! $available ) {
            $msg = esc_html( $accom_name ) . ' is niet beschikbaar op de door jou gekozen data';
            $mark_up = '<div class="hb-accom-step-wrapper hb-step-wrapper">' .
                '<div class="hb-accom-list">' .
                    '<div class="hb-accom hb-clearfix">' .
                        '<div class="hb-accom-desc">' . $msg . '</div>' .
                    '</div>' .
                '</div>' .
            '</div>';
            return array(
                'success' => true,
                'available' => false,
                'mark_up' => $mark_up,
            );
        }

        $fees_by_id = $this->get_property_additional_fees_by_id( $property_id );
        $fee_ids = $this->get_calc_prices_fee_ids( $property_id, $fees_by_id );

        $response = $this->api_post_json(
            'reservation/calc-prices',
            array(
                'fees' => $fee_ids,
                'property_id' => intval( $property_id ),
                'date_from' => $date_from,
                'date_to' => $date_to,
                'number_of_guests' => intval( $guests ),
                'number_of_adults' => intval( $adults ),
                'number_of_children' => intval( $children ),
                'units' => 1,
                'rent' => null,
                'auto_recalculation' => true,
            ),
            true
        );

        if ( is_wp_error( $response ) ) {
            return array( 'success' => false, 'message' => 'API connection error: ' . $response->get_error_message() );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $calc_prices = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $status_code !== 200 || ! is_array( $calc_prices ) ) {
            return array( 'success' => false, 'message' => 'Failed to calculate price' );
        }

        $variant = $this->pick_calc_prices_variant( $calc_prices, $guests );
        if ( ! is_array( $variant ) ) {
            return array( 'success' => false, 'message' => 'Failed to calculate price' );
        }

        $total = isset( $variant['total'] ) ? floatval( $variant['total'] ) : 0;
        $caption = 'Prijs voor ' . intval( $nights ) . ' nachten';
        $show_text = 'Toon de prijs in detail';
        $hide_text = 'Verberg prijs detail';

        $msg_available = esc_html( $accom_name ) . ' is beschikbaar op de door jou gekozen data';
        $price_breakdown = $this->build_hb_price_breakdown_markup( $variant, $date_from, $date_to, $nights, $adults, $children, $fees_by_id );

        $mark_up = '' .
            '<div class="hb-accom-step-wrapper hb-step-wrapper">' .
                '<div class="hb-accom-list">' .
                    '<div class="hb-accom hb-clearfix">' .
                        '<div class="hb-accom-desc">' . $msg_available . '</div>' .
                        '<div class="hb-accom-price-total hb-clearfix">' .
                            '<div class="hb-accom-price">' . esc_html( $this->format_price( $total ) ) . '</div>' .
                            '<div class="hb-accom-price-caption">' . esc_html( $caption ) .
                                '<br/>' .
                                '<span class="hb-accom-price-caption-dash">&nbsp;-&nbsp;</span>' .
                                '<a class="hb-view-price-breakdown" href="#">' .
                                    '<span class="hb-price-bd-show-text">' . esc_html( $show_text ) . '</span>' .
                                    '<span class="hb-price-bd-hide-text">' . esc_html( $hide_text ) . '</span>' .
                                '</a>' .
                            '</div>' .
                        '</div>' .
                        '<p class="hb-price-breakdown">' . $price_breakdown . '</p>' .
                    '</div>' .
                '</div>' .
                '<p class="hb-step-button hb-button-wrapper hb-next-step hb-next-step-1">' .
                    '<input type="submit" value="NEXT →" />' .
                '</p>' .
            '</div>';

        // Optional website fees step (shown after clicking NEXT)
        $mark_up .= $this->build_hb_fees_step_markup(
            $accom_id,
            $date_from,
            $date_to,
            $nights,
            $adults,
            $children,
            $variant,
            $fees_by_id,
            $fee_ids
        );

        // Booking details step (shown after fees NEXT, or directly if no fees step)
        $mark_up .= $this->build_hb_details_step_markup(
            $accom_id,
            $accom_name,
            $date_from,
            $date_to,
            $nights,
            $adults,
            $children,
            $variant,
            $fees_by_id,
            $fee_ids
        );

        return array(
            'success' => true,
            'available' => true,
            'mark_up' => $mark_up,
        );
    }

    private function fetch_reservations_for_property( $property_id, $date_from = null, $date_to = null ) {
        $debug_enabled = false;
        if ( isset( $_GET['gs_debug'] ) && current_user_can( 'manage_options' ) ) {
            $debug_enabled = true;
        }

        static $cache = array();
        $cache_key = $property_id . '|' . ( $date_from ?: 'legacy' ) . '|' . ( $date_to ?: 'legacy' );
        if ( ! $debug_enabled && isset( $cache[ $cache_key ] ) ) {
            $this->last_calendar_fetch_response = array(
                'cacheType' => 'static',
                'cacheKey' => $cache_key,
            );
            $this->last_calendar_fetch_debug = array(
                'cacheHit' => true,
                'propertyId' => intval( $property_id ),
                'rangeFrom' => $date_from,
                'rangeTo' => $date_to,
                'source' => $date_from && $date_to ? 'calendar_day_period' : 'legacy',
                'rawDayCount' => isset( $this->last_calendar_days_raw ) && is_array( $this->last_calendar_days_raw ) ? count( $this->last_calendar_days_raw ) : 0,
                'reservationsCount' => isset( $cache[ $cache_key ] ) ? count( $cache[ $cache_key ] ) : 0,
                'responseMeta' => $this->last_calendar_fetch_response,
            );
            return $cache[ $cache_key ];
        }

        // Cache across requests to avoid hammering PMS on every page view.
        $transient_key = 'gs_hb_res_' . md5( 'v3|' . $cache_key );
        $cached = $debug_enabled ? null : get_transient( $transient_key );
        if ( ! $debug_enabled && is_array( $cached ) ) {
            $cache[ $cache_key ] = $cached;
            $this->last_calendar_fetch_response = array(
                'cacheType' => 'transient',
                'transientKey' => $transient_key,
            );
            $this->last_calendar_fetch_debug = array(
                'cacheHit' => true,
                'propertyId' => intval( $property_id ),
                'rangeFrom' => $date_from,
                'rangeTo' => $date_to,
                'source' => $date_from && $date_to ? 'calendar_day_period' : 'legacy',
                'rawDayCount' => isset( $this->last_calendar_days_raw ) && is_array( $this->last_calendar_days_raw ) ? count( $this->last_calendar_days_raw ) : 0,
                'reservationsCount' => count( $cached ),
                'responseMeta' => $this->last_calendar_fetch_response,
            );
            return $cache[ $cache_key ];
        }

        $normalized = null;
        $this->last_calendar_fetch_response = null;

        $raw_days_snapshot = array();
        $source_used = $date_from && $date_to ? 'calendar_day_period' : 'legacy';

        if ( $date_from && $date_to ) {
            $normalized = $this->fetch_calendar_days_period( $property_id, $date_from, $date_to );
            $raw_days_snapshot = $this->last_calendar_days_raw;
        }

        if ( null === $normalized ) {
            $normalized = $this->fetch_reservations_via_legacy_endpoint( $property_id );
            $raw_days_snapshot = array();
            $source_used = 'legacy';
        }

        if ( ! is_array( $normalized ) ) {
            $normalized = array();
        }

        // 5 minutes is enough: avoids spam while still updating reasonably fast.
        if ( ! $debug_enabled ) {
            set_transient( $transient_key, $normalized, 5 * MINUTE_IN_SECONDS );
        }

        $cache[ $cache_key ] = $normalized;

        $this->last_calendar_fetch_debug = array(
            'cacheHit' => false,
            'debugBypassCache' => $debug_enabled,
            'propertyId' => intval( $property_id ),
            'rangeFrom' => $date_from,
            'rangeTo' => $date_to,
            'source' => $source_used,
            'rawDayCount' => is_array( $raw_days_snapshot ) ? count( $raw_days_snapshot ) : 0,
            'rawSample' => is_array( $raw_days_snapshot ) ? array_slice( $raw_days_snapshot, 0, 20 ) : array(),
            'reservationsCount' => is_array( $normalized ) ? count( $normalized ) : 0,
            'responseMeta' => $this->last_calendar_fetch_response,
            'calendarDaysSample' => $this->last_calendar_days_sample,
        );

        return $cache[ $cache_key ];
    }

    private function fetch_calendar_days_period( $property_id, $date_from, $date_to ) {
        $api_url = GoldenStay_Manager::get_api_url();
        $endpoint = sprintf(
            'property/calendar_day/period/%d/%s/%s',
            intval( $property_id ),
            rawurlencode( $date_from ),
            rawurlencode( $date_to )
        );
        $full_url = trailingslashit( $api_url ) . ltrim( $endpoint, '/' );

        $response = $this->api_get_json( $endpoint, true );
        if ( is_wp_error( $response ) ) {
            $this->last_calendar_fetch_response = array(
                'endpoint' => $endpoint,
                'url' => $full_url,
                'error' => $response->get_error_message(),
            );
            return null;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        $this->last_calendar_fetch_response = array(
            'endpoint' => $endpoint,
            'url' => $full_url,
            'statusCode' => $status_code,
            'bodySample' => substr( $body, 0, 400 ),
        );
        if ( $status_code !== 200 || ! is_array( $data ) ) {
            $this->last_calendar_days_raw = array();
            return null;
        }

        $this->last_calendar_days_raw = $data;
        $this->last_calendar_days_sample = array_slice( $data, 0, 50 );

        return $this->calendar_days_to_reservations( $data );
    }

    private function fetch_reservations_via_legacy_endpoint( $property_id ) {
        $api_url = GoldenStay_Manager::get_api_url();
        $token = GoldenStay_Manager::get_api_token();
        if ( ! $token ) {
            $this->last_calendar_days_raw = array();
            $this->last_calendar_fetch_response = array(
                'endpoint' => 'reservation/property',
                'url' => trailingslashit( $api_url ) . 'reservation/property',
                'error' => 'missing_token',
            );
            return array();
        }

        $response = $this->api_post_json(
            'reservation/property',
            array( 'ids' => array( intval( $property_id ) ) ),
            true
        );

        if ( is_wp_error( $response ) ) {
            $this->last_calendar_days_raw = array();
            $this->last_calendar_fetch_response = array(
                'endpoint' => 'reservation/property',
                'url' => trailingslashit( $api_url ) . 'reservation/property',
                'error' => $response->get_error_message(),
            );
            return array();
        }

        $body = wp_remote_retrieve_body( $response );
        $status_code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( $body, true );
        $this->last_calendar_fetch_response = array(
            'endpoint' => 'reservation/property',
            'url' => trailingslashit( $api_url ) . 'reservation/property',
            'statusCode' => $status_code,
            'bodySample' => substr( $body, 0, 400 ),
        );
        if ( $status_code !== 200 || ! is_array( $data ) ) {
            $this->last_calendar_days_raw = array();
            return array();
        }

        $normalized = array();
        foreach ( $data as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $normalized[] = array(
                'id' => isset( $row['id'] ) ? intval( $row['id'] ) : 0,
                'status_id' => isset( $row['status_id'] ) ? intval( $row['status_id'] ) : 0,
                'date_from' => isset( $row['date_from'] ) ? substr( $row['date_from'], 0, 10 ) : null,
                'date_to' => isset( $row['date_to'] ) ? substr( $row['date_to'], 0, 10 ) : null,
                'is_quote' => isset( $row['is_quote'] ) ? (bool) $row['is_quote'] : null,
            );
        }

        $this->last_calendar_days_raw = array();

        return $normalized;
    }

    private function calendar_days_to_reservations( $days ) {
        if ( ! is_array( $days ) || empty( $days ) ) {
            return array();
        }

        $filtered = array();
        foreach ( $days as $day ) {
            if ( empty( $day['date'] ) ) {
                continue;
            }
            $filtered[] = $day;
        }

        if ( empty( $filtered ) ) {
            return array();
        }

        usort(
            $filtered,
            function( $a, $b ) {
                return strcmp( substr( $a['date'], 0, 10 ), substr( $b['date'], 0, 10 ) );
            }
        );

        $reservations = array();
        $current_start = null;
        $previous_date = null;

        foreach ( $filtered as $day ) {
            $date = substr( $day['date'], 0, 10 );
            $is_taken = $this->is_calendar_day_taken( $day );

            if ( $is_taken ) {
                if ( null === $current_start ) {
                    $current_start = $date;
                }
                $previous_date = $date;
                continue;
            }

            if ( null !== $current_start && $previous_date ) {
                $reservations[] = array(
                    'id' => 0,
                    'date_from' => $current_start,
                    'date_to' => date( 'Y-m-d', strtotime( $previous_date . ' +1 day' ) ),
                );
            }

            $current_start = null;
            $previous_date = null;
        }

        if ( null !== $current_start && $previous_date ) {
            $reservations[] = array(
                'id' => 0,
                'date_from' => $current_start,
                'date_to' => date( 'Y-m-d', strtotime( $previous_date . ' +1 day' ) ),
            );
        }

        return $reservations;
    }

    private function is_calendar_day_taken( $day ) {
        $reservations = isset( $day['reservations'] ) ? intval( $day['reservations'] ) : 0;
        $units = isset( $day['units'] ) ? intval( $day['units'] ) : null;
        $is_blocked = ! empty( $day['is_blocked'] );

        if ( $is_blocked ) {
            return true;
        }

        if ( $reservations > 0 ) {
            return true;
        }

        if ( $units !== null && $units <= 0 ) {
            return true;
        }

        return false;
    }

    private function fetch_price_days_for_period( $property_id, $date_from, $date_to, $nop = 1 ) {
        static $cache = array();
        $cache_key = $property_id . '|' . $date_from . '|' . $date_to . '|' . intval( $nop );
        if ( isset( $cache[ $cache_key ] ) ) {
            return $cache[ $cache_key ];
        }

        // Cache across requests (prices don't change every second, but calc-prices can be heavy).
        $transient_key = 'gs_hb_price_' . md5( 'v2|' . $cache_key . '|' . wp_json_encode( get_option( 'goldenstay_calc_prices_fee_ids', array() ) ) );
        $cached = get_transient( $transient_key );
        if ( is_array( $cached ) ) {
            $cache[ $cache_key ] = $cached;
            return $cache[ $cache_key ];
        }

        // Pricing comes from PMS: POST /reservation/calc-prices
        // Response: object keyed by number of guests ("1", "2", ...)
        // Each entry contains `day_prices` [{ date, base_price, amount, extra }, ...]
        $default_fees = get_option( 'goldenstay_calc_prices_fee_ids', array() );
        $fees = is_array( $default_fees ) ? array_values( array_unique( array_map( 'intval', $default_fees ) ) ) : array();

        $response = $this->api_post_json(
            'reservation/calc-prices',
            array(
                'fees' => $fees,
                'property_id' => intval( $property_id ),
                'date_from' => $date_from,
                'date_to' => $date_to,
                'number_of_guests' => intval( $nop ) > 0 ? intval( $nop ) : 1,
                'units' => 1,
                'rent' => null,
                'auto_recalculation' => true,
            ),
            // PMS routes are protected by guestAccess which still requires an Authorization token.
            true
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

        // Choose pricing block by number of guests.
        $key = strval( intval( $nop ) > 0 ? intval( $nop ) : 1 );
        $chosen = null;
        if ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
            $chosen = $data[ $key ];
        } else {
            // Fallback: first available variant
            foreach ( $data as $variant_key => $variant_val ) {
                if ( is_array( $variant_val ) && isset( $variant_val['day_prices'] ) ) {
                    $chosen = $variant_val;
                    break;
                }
            }
        }

        $day_prices = $chosen && isset( $chosen['day_prices'] ) ? $chosen['day_prices'] : array();
        if ( is_array( $day_prices ) ) {
            foreach ( $day_prices as $dp ) {
                if ( ! is_array( $dp ) || empty( $dp['date'] ) ) {
                    continue;
                }
                $date = substr( $dp['date'], 0, 10 );
                // Prefer `amount` (already includes extra guests logic).
                $price = isset( $dp['amount'] ) ? floatval( $dp['amount'] ) : null;
                if ( $price !== null ) {
                    $price_days[ $date ] = $price;
                }
            }
        }

        $cache[ $cache_key ] = $price_days;
        // 30 minutes: good balance between freshness and performance.
        set_transient( $transient_key, $price_days, 30 * MINUTE_IN_SECONDS );
        return $cache[ $cache_key ];
    }

    private function compute_prefetch_range( $calendar_sizes ) {
        // calendar_sizes is array of { cols, rows }. We prefetch months for the largest layout.
        $months_to_show = 3;
        if ( is_array( $calendar_sizes ) ) {
            foreach ( $calendar_sizes as $s ) {
                $cols = isset( $s['cols'] ) ? intval( $s['cols'] ) : 0;
                $rows = isset( $s['rows'] ) ? intval( $s['rows'] ) : 0;
                if ( $cols > 0 && $rows > 0 ) {
                    $months_to_show = max( $months_to_show, $cols * $rows );
                }
            }
        }

        // Don't overdo: HBook often limits search horizon; we keep it small and cache.
        $months_prefetch = min( max( $months_to_show + 1, 3 ), 6 );

        $now_ts = current_time( 'timestamp' );
        $today = date( 'Y-m-d', $now_ts );
        $month_start = date( 'Y-m-01', $now_ts );
        $to_exclusive = date( 'Y-m-d', strtotime( '+' . $months_prefetch . ' months', strtotime( $month_start ) ) );

        // Safety
        if ( strtotime( $to_exclusive ) <= strtotime( $today ) ) {
            $to_exclusive = date( 'Y-m-d', strtotime( '+3 months', strtotime( $month_start ) ) );
        }

        return array( $today, $to_exclusive );
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
        // Follow HBook approach:
        // - Build a list of "taken days" (check-in included, check-out excluded)
        // - Derive status days:
        //   - first taken day => hb-day-taken-start
        //   - intermediate => hb-day-fully-taken
        //   - day after last taken day (check-out day) => hb-day-taken-end

        $taken = array();
        $status_days = array();

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

            $check_in = substr( $reservation['date_from'], 0, 10 );
            $check_out = substr( $reservation['date_to'], 0, 10 );

            // Basic sanity
            if ( ! $check_in || ! $check_out || strtotime( $check_out ) <= strtotime( $check_in ) ) {
                continue;
            }

            // Add taken days, clipped to range (while current < check_out)
            $current = $check_in;
            while ( strtotime( $current ) < strtotime( $check_out ) ) {
                if ( strtotime( $current ) >= strtotime( $range_from ) && strtotime( $current ) <= strtotime( $range_to ) ) {
                    $taken[ $current ] = true;
                }
                $current = date( 'Y-m-d', strtotime( $current . ' +1 day' ) );
                if ( strtotime( $current ) > strtotime( $range_to . ' +2 day' ) ) {
                    break;
                }
            }
        }

        if ( ! count( $taken ) ) {
            return $status_days;
        }

        $dates = array_keys( $taken );
        sort( $dates );

        foreach ( $dates as $d ) {
            $prev = date( 'Y-m-d', strtotime( $d . ' -1 day' ) );
            if ( isset( $taken[ $prev ] ) ) {
                $status_days[ $d ] = 'hb-day-fully-taken';
            } else {
                $status_days[ $d ] = 'hb-day-taken-start';
            }

            $candidate = date( 'Y-m-d', strtotime( $d . ' +1 day' ) );
            if ( ! isset( $taken[ $candidate ] ) && strtotime( $candidate ) <= strtotime( $range_to ) ) {
                if ( isset( $status_days[ $candidate ] ) ) {
                    $status_days[ $candidate ] .= ' hb-day-taken-end';
                } else {
                    $status_days[ $candidate ] = 'hb-day-taken-end';
                }
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

        $calendar_sizes = $this->parse_calendar_sizes( $atts['calendar_sizes'] );
        list( $range_from, $range_to ) = $this->compute_prefetch_range( $calendar_sizes );

        $hidden_ids = $this->get_hidden_reservation_ids_for_property( $property_id );
        $reservations = $this->fetch_reservations_for_property( $property_id, $range_from, $range_to );
        $status_days = $this->build_status_days( $reservations, $range_from, $range_to, $hidden_ids );
        $price_days = $this->fetch_price_days_for_period( $property_id, $range_from, $range_to, $nop );

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

        $output .= $this->render_calendar_debug_script(
            array(
                'propertyId' => $property_id,
                'rangeFrom' => $range_from,
                'rangeTo' => $range_to,
                'reservations' => array_values( $reservations ),
                'statusDays' => $status_days,
                'priceDaysCount' => count( $price_days ),
                'fetchDebug' => $this->last_calendar_fetch_debug,
                'fetchResponse' => $this->last_calendar_fetch_response,
                'calendarDaysSample' => $this->last_calendar_days_sample,
            )
        );

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
                // HBook compatibility: allow forcing accommodation context.
                'accom_id' => '',
            ),
            $atts,
            'hb_booking_form'
        );

        $this->enqueue_hbook_assets();

        // Match original HBook behavior:
        // - on hb_accommodation pages, the booking form is scoped to that accommodation
        // - otherwise it defaults to "all"
        $page_accom_id = 0;
        if ( ! empty( $atts['accom_id'] ) && $atts['accom_id'] !== 'all' ) {
            $page_accom_id = intval( $atts['accom_id'] );
        } else {
            $current_post_id = get_the_ID();
            if ( $current_post_id && get_post_type( $current_post_id ) === 'hb_accommodation' ) {
                $page_accom_id = intval( $current_post_id );
            }
        }

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
        // Fallback: if booking JS isn't present, read visible date inputs.
        if ( ! $check_in && isset( $_POST['hb-check-in-date'] ) ) {
            $check_in = sanitize_text_field( $_POST['hb-check-in-date'] );
        }
        if ( ! $check_out && isset( $_POST['hb-check-out-date'] ) ) {
            $check_out = sanitize_text_field( $_POST['hb-check-out-date'] );
        }
        $adults = isset( $_POST['hb-adults'] ) ? sanitize_text_field( $_POST['hb-adults'] ) : '';
        $children = isset( $_POST['hb-children'] ) ? sanitize_text_field( $_POST['hb-children'] ) : '';

        // NOTE: HBook shows a title on accommodation pages.
        $form_title = '';
        if ( $page_accom_id ) {
            $accom_title = get_the_title( $page_accom_id );
            if ( ! $accom_title ) {
                $accom_title = 'Accommodation';
            }
            $form_title = '<h3 class="hb-title hb-title-search-form">' .
                esc_html( 'Check prijs en beschikbaarheid voor ' . $accom_title ) .
            '</h3>';
        }
        $form_class = 'hb-booking-search-form';

        $markup = $this->get_default_search_form_markup();
        $markup = apply_filters( 'hb_search_form_markup', $markup, $form_id );

        // Labels / placeholders
        if ( $search_placeholder === 'yes' ) {
            $markup = str_replace( '[check_in_placeholder]', esc_html( 'Aankomst' ), $markup );
            $markup = str_replace( '[check_out_placeholder]', esc_html( 'Vertrek' ), $markup );
            $markup = str_replace( '[check_in_label]', '', $markup );
            $markup = str_replace( '[check_out_label]', '', $markup );
            $markup = str_replace( '[adults_label]', '', $markup );
            $markup = str_replace( '[children_label]', '', $markup );
            $markup = str_replace( '[search_label]', '', $markup );
        } else {
            $markup = str_replace( '[check_in_placeholder]', '', $markup );
            $markup = str_replace( '[check_out_placeholder]', '', $markup );
            $markup = str_replace( '[check_in_label]', '<label for="check-in-date">' . esc_html( 'Aankomst' ) . '</label>', $markup );
            $markup = str_replace( '[check_out_label]', '<label for="check-out-date">' . esc_html( 'Vertrek' ) . '</label>', $markup );
            $markup = str_replace( '[adults_label]', '<label for="adults">' . esc_html( 'Volwassenen' ) . '</label>', $markup );
            $markup = str_replace( '[children_label]', '<label for="children">' . esc_html( 'Kinderen' ) . '</label>', $markup );
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
            'chosen_check_in' => esc_html( 'Aankomst:' ),
            'chosen_check_out' => esc_html( 'Vertrek:' ),
            'chosen_adults' => esc_html( 'Volwassenen:' ),
            'chosen_children' => esc_html( 'Kinderen:' ),
            'change_search_button' => esc_html( 'WIJZIG ZOEKOPDRACHT' ),
            'search_button' => esc_html( 'ZOEK' ),
        );
        foreach ( $strings as $k => $v ) {
            $markup = str_replace( '[string_' . $k . ']', $v, $markup );
        }

        $status_days_for_datepick = array();
        $datepick_window = '0';
        if ( $page_accom_id ) {
            $mapped_property_id = GoldenStay_Accommodation_Mapping::get_property_id_for_accom( $page_accom_id );
            if ( $mapped_property_id ) {
                // Datepick window (min/max selectable dates).
                // Previously we used compute_prefetch_range() which capped at ~4-6 months for performance.
                // For real booking flows we need a larger horizon (the PMS calendar is typically initialized for years ahead).
                $now_ts = current_time( 'timestamp' );
                $range_from = date( 'Y-m-d', $now_ts );
                $month_start = date( 'Y-m-01', $now_ts );
                $months_prefetch = intval( get_option( 'goldenstay_calendar_horizon_months', 24 ) );
                if ( $months_prefetch < 3 ) {
                    $months_prefetch = 3;
                } else if ( $months_prefetch > 60 ) {
                    $months_prefetch = 60;
                }
                $range_to = date( 'Y-m-d', strtotime( '+' . $months_prefetch . ' months', strtotime( $month_start ) ) );

                $hidden_ids = $this->get_hidden_reservation_ids_for_property( $mapped_property_id );
                $reservations = $this->fetch_reservations_for_property( $mapped_property_id, $range_from, $range_to );
                $status_days_for_datepick = $this->build_status_days( $reservations, $range_from, $range_to, $hidden_ids );
                $datepick_window = array(
                    'min_date' => $range_from,
                    'max_date' => $range_to,
                );
            }
        }

        // Wrap to provide booking rules for hb-datepick.js
        static $gs_booking_form_num = 0;
        $gs_booking_form_num++;
        $wrapper_classes = 'hbook-wrapper hbook-wrapper-booking-form';
        if ( $page_accom_id ) {
            $wrapper_classes .= ' hb-accom-page';
        }
        $out = '<div id="' . esc_attr( 'hbook-booking-form-' . $gs_booking_form_num ) . '" class="' . esc_attr( $wrapper_classes ) . '" data-gs-hb-compat="1" ' .
            'data-booking-rules=\'' . esc_attr( wp_json_encode( $wrapper_rules ) ) . '\' ' .
            ( $page_accom_id ? 'data-page-accom-id="' . esc_attr( $page_accom_id ) . '" ' : '' ) .
            '>';

        // Provide globals used by hb-datepick.js for this page scope.
        if ( $page_accom_id ) {
            $out .= '<script>(function(){' .
                'window.hb_status_days_' . intval( $page_accom_id ) . ' = ' . wp_json_encode( $status_days_for_datepick ) . ';' .
                'window.hb_accom_data_' . intval( $page_accom_id ) . ' = ' . wp_json_encode( $datepick_window ) . ';' .
            '})();</script>';

            // Extra debug in console for the datepick status-days mapping
            $out .= $this->render_calendar_debug_script(
                array(
                    'context' => 'datepick',
                    'accomId' => $page_accom_id,
                    'statusDays' => $status_days_for_datepick,
                    'bookingWindow' => $datepick_window,
                    'fetchDebug' => $this->last_calendar_fetch_debug,
                    'fetchResponse' => $this->last_calendar_fetch_response,
                    'calendarDaysSample' => $this->last_calendar_days_sample,
                )
            );
        }

        $out .= $markup;

        // Placeholder for AJAX-rendered results.
        $out .= '<div class="gs-hb-search-results" aria-live="polite"></div>';

        if ( $search_only === 'no' && ! $page_accom_id ) {
            // Show calendars (prices + blocked days) for all accommodations as a simple replacement for HBook results.
            // On accommodation pages we do not render the whole list to avoid duplication/clutter.
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
            $label = $key === 'adults' ? esc_html( 'Volwassenen' ) : esc_html( 'Kinderen' );
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
 
    private function render_calendar_debug_script( $data ) {
        if ( empty( $data ) ) {
            return '';
        }

        $json = wp_json_encode( $data );
        if ( false === $json ) {
            return '';
        }

        return '<script>(function(){window.goldenStayCalendarDebug = window.goldenStayCalendarDebug || []; window.goldenStayCalendarDebug.push(' . $json . '); if ( window.console && console.info ) { console.info("GoldenStay calendar data", ' . $json . ' ); }} )();</script>';
    }
}


