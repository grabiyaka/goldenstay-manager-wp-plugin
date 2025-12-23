<?php
/**
 * AJAX handlers
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GoldenStay_Ajax {
    
    public static function init() {
        // Admin AJAX
        add_action( 'wp_ajax_goldenstay_login', array( __CLASS__, 'ajax_login' ) );
        add_action( 'wp_ajax_goldenstay_logout', array( __CLASS__, 'ajax_logout' ) );
        add_action( 'wp_ajax_goldenstay_check_auth', array( __CLASS__, 'ajax_check_auth' ) );
        add_action( 'wp_ajax_goldenstay_get_properties', array( __CLASS__, 'ajax_get_properties' ) );
        add_action( 'wp_ajax_goldenstay_get_reservations', array( __CLASS__, 'ajax_get_reservations' ) );
        add_action( 'wp_ajax_goldenstay_toggle_reservation_visibility', array( __CLASS__, 'ajax_toggle_reservation_visibility' ) );
        
        // Public AJAX (no auth required)
        add_action( 'wp_ajax_nopriv_goldenstay_get_properties_public', array( __CLASS__, 'ajax_get_properties' ) );
        add_action( 'wp_ajax_nopriv_goldenstay_get_property_public', array( __CLASS__, 'ajax_get_property_public' ) );
        add_action( 'wp_ajax_nopriv_goldenstay_check_availability', array( __CLASS__, 'ajax_check_availability' ) );
        add_action( 'wp_ajax_nopriv_goldenstay_create_booking', array( __CLASS__, 'ajax_create_booking' ) );
    }

    private static function get_hidden_reservations_map() {
        $hidden = get_option( 'goldenstay_hidden_reservations', array() );
        return is_array( $hidden ) ? $hidden : array();
    }

    private static function get_hidden_reservation_ids_for_property( $property_id ) {
        $hidden = self::get_hidden_reservations_map();
        $ids = isset( $hidden[ $property_id ] ) ? $hidden[ $property_id ] : array();
        if ( ! is_array( $ids ) ) {
            return array();
        }
        return array_values( array_unique( array_map( 'intval', $ids ) ) );
    }
    
    /**
     * AJAX: Login via API
     */
    public static function ajax_login() {
        check_ajax_referer( 'goldenstay_admin_nonce', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'You do not have permission to perform this action' ) );
        }
        
        $email = sanitize_email( $_POST['email'] ?? '' );
        $password = $_POST['password'] ?? '';
        $api_url = esc_url_raw( $_POST['api_url'] ?? '' );
        
        if ( empty( $email ) || empty( $password ) || empty( $api_url ) ) {
            wp_send_json_error( array( 'message' => 'All fields are required' ) );
        }
        
        update_option( 'goldenstay_api_url', $api_url );
        
        $response = wp_remote_post( trailingslashit( $api_url ) . 'user/login', array(
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body' => json_encode( array( 'email' => $email, 'password' => $password ) ),
            'timeout' => 30,
        ));
        
        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array( 'message' => 'API connection error: ' . $response->get_error_message() ) );
        }
        
        $status_code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        
        if ( $status_code === 200 && isset( $body['token'] ) ) {
            update_option( 'goldenstay_api_token', $body['token'] );
            update_option( 'goldenstay_user_data', array(
                'email' => $email,
                'name' => $body['user']['name'] ?? $body['name'] ?? 'User',
                'id' => $body['user']['id'] ?? $body['id'] ?? null,
            ));
            
            wp_send_json_success( array( 
                'message' => 'Authentication successful! Reloading page...',
                'user' => $body['user'] ?? array(),
            ));
        } else {
            $error_message = $body['message'] ?? $body['error'] ?? 'Invalid email or password';
            wp_send_json_error( array( 'message' => $error_message ) );
        }
    }
    
    /**
     * AJAX: Logout
     */
    public static function ajax_logout() {
        check_ajax_referer( 'goldenstay_admin_nonce', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'You do not have permission to perform this action' ) );
        }
        
        delete_option( 'goldenstay_api_token' );
        delete_option( 'goldenstay_user_data' );
        
        wp_send_json_success( array( 'message' => 'You have successfully logged out' ) );
    }
    
    /**
     * AJAX: Check authentication
     */
    public static function ajax_check_auth() {
        check_ajax_referer( 'goldenstay_admin_nonce', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'You do not have permission to perform this action' ) );
        }
        
        $token = GoldenStay_Manager::get_api_token();
        $is_authenticated = ! empty( $token );
        
        wp_send_json_success( array( 
            'authenticated' => $is_authenticated,
            'user_data' => $is_authenticated ? get_option( 'goldenstay_user_data' ) : null,
        ));
    }
    
    /**
     * AJAX: Get properties from API
     */
    public static function ajax_get_properties() {
        $nonce_name = is_admin() ? 'goldenstay_admin_nonce' : 'goldenstay_frontend_nonce';
        check_ajax_referer( $nonce_name, 'nonce' );
        
        $token = GoldenStay_Manager::get_api_token();
        if ( is_admin() && empty( $token ) ) {
            wp_send_json_error( array( 'message' => 'Not authenticated. Please login first.' ) );
        }
        
        $api_url = GoldenStay_Manager::get_api_url();
        
        $args = array( 'timeout' => 30 );
        if ( ! empty( $token ) ) {
            $args['headers'] = array(
                'Content-Type' => 'application/json',
                'Authorization' => $token,
            );
        }
        
        $response = wp_remote_get( trailingslashit( $api_url ) . 'property', $args );
        
        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array( 'message' => 'API connection error: ' . $response->get_error_message() ) );
        }
        
        $status_code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        
        if ( $status_code === 200 ) {
            wp_send_json_success( array( 
                'properties' => $body,
                'count' => is_array( $body ) ? count( $body ) : 0,
            ));
        } else if ( $status_code === 401 ) {
            wp_send_json_error( array( 
                'message' => 'Authentication expired. Please login again.',
                'code' => 'auth_expired'
            ));
        } else {
            $error_message = $body['message'] ?? $body['error'] ?? 'Failed to fetch properties';
            wp_send_json_error( array( 'message' => $error_message ) );
        }
    }
    
    /**
     * AJAX: Get reservations for property
     */
    public static function ajax_get_reservations() {
        check_ajax_referer( 'goldenstay_admin_nonce', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'You do not have permission to perform this action' ) );
        }
        
        $token = GoldenStay_Manager::get_api_token();
        if ( empty( $token ) ) {
            wp_send_json_error( array( 'message' => 'Not authenticated. Please login first.' ) );
        }
        
        $property_id = isset( $_POST['property_id'] ) ? intval( $_POST['property_id'] ) : 0;
        if ( empty( $property_id ) ) {
            wp_send_json_error( array( 'message' => 'Property ID is required' ) );
        }
        
        $api_url = GoldenStay_Manager::get_api_url();
        
        $response = wp_remote_post( trailingslashit( $api_url ) . 'reservation/property', array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => $token,
            ),
            'body' => json_encode( array( 'ids' => array( $property_id ) ) ),
            'timeout' => 30,
        ));
        
        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array( 'message' => 'API connection error: ' . $response->get_error_message() ) );
        }
        
        $status_code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        
        if ( $status_code === 200 ) {
            $hidden_ids = self::get_hidden_reservation_ids_for_property( $property_id );
            if ( is_array( $body ) ) {
                foreach ( $body as &$reservation ) {
                    if ( is_array( $reservation ) && isset( $reservation['id'] ) ) {
                        $reservation['is_hidden'] = in_array( intval( $reservation['id'] ), $hidden_ids, true );
                    }
                }
                unset( $reservation );
            }
            wp_send_json_success( array( 
                'reservations' => $body,
                'count' => is_array( $body ) ? count( $body ) : 0,
            ));
        } else if ( $status_code === 401 ) {
            wp_send_json_error( array( 
                'message' => 'Authentication expired. Please login again.',
                'code' => 'auth_expired'
            ));
        } else {
            $error_message = $body['message'] ?? $body['error'] ?? 'Failed to fetch reservations';
            wp_send_json_error( array( 'message' => $error_message ) );
        }
    }

    /**
     * AJAX: Toggle reservation visibility on the website (WordPress-side override)
     */
    public static function ajax_toggle_reservation_visibility() {
        check_ajax_referer( 'goldenstay_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'You do not have permission to perform this action' ) );
        }

        $property_id = isset( $_POST['property_id'] ) ? intval( $_POST['property_id'] ) : 0;
        $reservation_id = isset( $_POST['reservation_id'] ) ? intval( $_POST['reservation_id'] ) : 0;
        $is_hidden = isset( $_POST['is_hidden'] ) ? (bool) intval( $_POST['is_hidden'] ) : false;

        if ( ! $property_id || ! $reservation_id ) {
            wp_send_json_error( array( 'message' => 'Property ID and Reservation ID are required' ) );
        }

        $hidden = self::get_hidden_reservations_map();
        $property_hidden = isset( $hidden[ $property_id ] ) && is_array( $hidden[ $property_id ] )
            ? array_values( array_unique( array_map( 'intval', $hidden[ $property_id ] ) ) )
            : array();

        if ( $is_hidden ) {
            if ( ! in_array( $reservation_id, $property_hidden, true ) ) {
                $property_hidden[] = $reservation_id;
            }
        } else {
            $property_hidden = array_values(
                array_filter(
                    $property_hidden,
                    function( $id ) use ( $reservation_id ) {
                        return intval( $id ) !== intval( $reservation_id );
                    }
                )
            );
        }

        $hidden[ $property_id ] = $property_hidden;
        update_option( 'goldenstay_hidden_reservations', $hidden );

        wp_send_json_success( array(
            'property_id' => $property_id,
            'reservation_id' => $reservation_id,
            'is_hidden' => $is_hidden,
            'hidden_ids' => $property_hidden,
        ) );
    }
    
    /**
     * AJAX: Get single property for public
     */
    public static function ajax_get_property_public() {
        check_ajax_referer( 'goldenstay_frontend_nonce', 'nonce' );
        
        $property_id = isset( $_POST['property_id'] ) ? intval( $_POST['property_id'] ) : 0;
        
        if ( empty( $property_id ) ) {
            wp_send_json_error( array( 'message' => 'Property ID is required' ) );
        }
        
        $api_url = GoldenStay_Manager::get_api_url();
        
        $response = wp_remote_get( trailingslashit( $api_url ) . 'property/' . $property_id, array(
            'timeout' => 30,
        ));
        
        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array( 'message' => 'API connection error: ' . $response->get_error_message() ) );
        }
        
        $status_code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        
        if ( $status_code === 200 ) {
            wp_send_json_success( array( 'property' => $body ) );
        } else {
            wp_send_json_error( array( 'message' => 'Property not found' ) );
        }
    }
    
    /**
     * AJAX: Check availability
     */
    public static function ajax_check_availability() {
        check_ajax_referer( 'goldenstay_frontend_nonce', 'nonce' );
        
        $property_id = isset( $_POST['property_id'] ) ? intval( $_POST['property_id'] ) : 0;
        $date_from = isset( $_POST['date_from'] ) ? sanitize_text_field( $_POST['date_from'] ) : '';
        $date_to = isset( $_POST['date_to'] ) ? sanitize_text_field( $_POST['date_to'] ) : '';
        
        if ( empty( $property_id ) || empty( $date_from ) || empty( $date_to ) ) {
            wp_send_json_error( array( 'message' => 'All fields are required' ) );
        }
        
        $api_url = GoldenStay_Manager::get_api_url();
        
        $response = wp_remote_get( 
            trailingslashit( $api_url ) . 'reservation/overlaps/' . $property_id . '/' . $date_from . '/' . $date_to,
            array( 'timeout' => 30 )
        );
        
        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array( 'message' => 'API connection error' ) );
        }
        
        $status_code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        
        $available = ( $status_code === 200 && ( empty( $body ) || count( $body ) === 0 ) );
        
        wp_send_json_success( array( 
            'available' => $available,
            'message' => $available ? 'Available' : 'Not available for selected dates'
        ));
    }
    
    /**
     * AJAX: Create booking
     */
    public static function ajax_create_booking() {
        check_ajax_referer( 'goldenstay_frontend_nonce', 'nonce' );
        
        $data = array(
            'property_id' => isset( $_POST['property_id'] ) ? intval( $_POST['property_id'] ) : 0,
            'date_from' => isset( $_POST['date_from'] ) ? sanitize_text_field( $_POST['date_from'] ) : '',
            'date_to' => isset( $_POST['date_to'] ) ? sanitize_text_field( $_POST['date_to'] ) : '',
            'customer_name' => isset( $_POST['name'] ) ? sanitize_text_field( $_POST['name'] ) : '',
            'customer_email' => isset( $_POST['email'] ) ? sanitize_email( $_POST['email'] ) : '',
            'number_of_guests' => isset( $_POST['guests'] ) ? intval( $_POST['guests'] ) : 1,
        );
        
        foreach ( $data as $key => $value ) {
            if ( empty( $value ) ) {
                wp_send_json_error( array( 'message' => 'All fields are required' ) );
            }
        }
        
        $api_url = GoldenStay_Manager::get_api_url();
        
        $response = wp_remote_post( trailingslashit( $api_url ) . 'reservation', array(
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body' => json_encode( $data ),
            'timeout' => 30,
        ));
        
        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array( 'message' => 'Booking failed. Please try again.' ) );
        }
        
        $status_code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        
        if ( $status_code === 200 || $status_code === 201 ) {
            wp_send_json_success( array( 
                'message' => 'Booking request sent successfully!',
                'reservation' => $body
            ));
        } else {
            $error_message = $body['message'] ?? 'Booking failed';
            wp_send_json_error( array( 'message' => $error_message ) );
        }
    }
}





