<?php
/**
 * Add GoldenStay controls to the HBook Accommodation list screen (post_type=hb_accommodation).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GoldenStay_Accommodation_Admin_List {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter( 'manage_hb_accommodation_posts_columns', array( $this, 'add_columns' ) );
        add_action( 'manage_hb_accommodation_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
        add_filter( 'post_row_actions', array( $this, 'add_row_actions' ), 10, 2 );

        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'admin_head-edit.php', array( $this, 'render_add_from_goldenstay_button' ) );
    }

    public function add_columns( $columns ) {
        // Insert after title
        $out = array();
        foreach ( $columns as $key => $label ) {
            $out[ $key ] = $label;
            if ( $key === 'title' ) {
                $out['goldenstay_property'] = 'GoldenStay';
            }
        }
        if ( ! isset( $out['goldenstay_property'] ) ) {
            $out['goldenstay_property'] = 'GoldenStay';
        }
        return $out;
    }

    public function render_column( $column, $post_id ) {
        if ( $column !== 'goldenstay_property' ) {
            return;
        }

        $property_id = GoldenStay_Accommodation_Mapping::get_property_id_for_accom( $post_id );
        $enabled = GoldenStay_Accommodation_Mapping::is_goldenstay_booking_enabled_for_accom( $post_id );

        echo '<div class="gs-accom-cell" data-accom-id="' . esc_attr( $post_id ) . '">';
        if ( $property_id > 0 ) {
            echo '<div><strong>Property ID:</strong> <code>' . esc_html( $property_id ) . '</code></div>';
            echo '<div style="margin-top:6px;"><strong>Override booking:</strong> ' . ( $enabled ? '<span style="color:#1e8e3e;">ON</span>' : '<span style="color:#777;">off</span>' ) . '</div>';
            echo '<div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;">'
                . '<a href="#" class="button button-small gs-accom-set">Set</a>'
                . '<a href="#" class="button button-small gs-accom-unlink">Unlink</a>'
                . '</div>';
        } else {
            echo '<div><span style="color:#777;">Not linked</span></div>';
            echo '<div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;">'
                . '<a href="#" class="button button-small gs-accom-set">Link</a>'
                . '</div>';
        }
        echo '</div>';
    }

    public function add_row_actions( $actions, $post ) {
        if ( ! $post || $post->post_type !== 'hb_accommodation' ) {
            return $actions;
        }
        $property_id = GoldenStay_Accommodation_Mapping::get_property_id_for_accom( $post->ID );
        if ( $property_id > 0 ) {
            $actions['goldenstay_unlink'] = '<a href="#" class="gs-accom-unlink" data-accom-id="' . esc_attr( $post->ID ) . '">GoldenStay: Unlink</a>';
        } else {
            $actions['goldenstay_link'] = '<a href="#" class="gs-accom-set" data-accom-id="' . esc_attr( $post->ID ) . '">GoldenStay: Link</a>';
        }
        return $actions;
    }

    public function enqueue_assets( $hook ) {
        if ( $hook !== 'edit.php' ) {
            return;
        }
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || $screen->post_type !== 'hb_accommodation' ) {
            return;
        }

        wp_enqueue_script(
            'goldenstay-accommodation-admin-js',
            GOLDENSTAY_PLUGIN_URL . 'assets/accommodation-admin.js',
            array( 'jquery' ),
            GOLDENSTAY_VERSION,
            true
        );

        wp_localize_script( 'goldenstay-accommodation-admin-js', 'goldenStayAccomAdmin', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'goldenstay_admin_nonce' ),
            'createLabel' => 'Create accommodation from property',
            'propertiesAction' => 'goldenstay_get_properties',
            'propertiesPageUrl' => admin_url( 'admin.php?page=goldenstay-properties' ),
            'initialRenderLimit' => 200,
        ) );
    }

    public function render_add_from_goldenstay_button() {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || $screen->post_type !== 'hb_accommodation' ) {
            return;
        }

        if ( ! current_user_can( 'edit_posts' ) ) {
            return;
        }

        // Adds a button next to "Add New" on the list screen.
        // NOTE: This must run after DOM is available (admin_head runs before body).
        echo '<script>
        (function(){
            function addBtn(){
                var heading = document.querySelector(".wrap h1.wp-heading-inline");
                if(!heading) return;

                // Don\'t duplicate on soft navigations / reloads
                if (document.querySelector("a.gs-add-from-goldenstay")) return;

                var btn = document.createElement("a");
                btn.href = "#";
                btn.className = "page-title-action gs-add-from-goldenstay";
                btn.textContent = "Add from GoldenStay";

                // Prefer placing after the existing "Add New" button if present
                var addNew = document.querySelector(".wrap a.page-title-action:not(.gs-add-from-goldenstay)");
                if (addNew && addNew.parentNode) {
                    addNew.parentNode.insertBefore(btn, addNew.nextSibling);
                } else if (heading.parentNode) {
                    heading.parentNode.insertBefore(btn, heading.nextSibling);
                }
            }
            if (document.readyState === "loading") {
                document.addEventListener("DOMContentLoaded", addBtn);
            } else {
                addBtn();
            }
        })();
        </script>';
    }
}

