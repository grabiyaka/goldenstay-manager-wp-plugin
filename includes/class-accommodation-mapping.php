<?php
/**
 * Map Adomus/HBook accommodations (post_type=hb_accommodation) to GoldenStay API properties.
 *
 * Stored in post meta: goldenstay_property_id
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GoldenStay_Accommodation_Mapping {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
        add_action( 'save_post', array( $this, 'save_meta' ), 10, 2 );
    }

    public static function get_property_id_for_accom( $accom_id ) {
        $value = get_post_meta( $accom_id, 'goldenstay_property_id', true );
        return $value ? intval( $value ) : 0;
    }

    public function add_meta_boxes() {
        add_meta_box(
            'goldenstay_accom_mapping',
            'GoldenStay',
            array( $this, 'render_meta_box' ),
            'hb_accommodation',
            'side',
            'default'
        );
    }

    public function render_meta_box( $post ) {
        wp_nonce_field( 'goldenstay_accom_mapping', 'goldenstay_accom_mapping_nonce' );

        $property_id = get_post_meta( $post->ID, 'goldenstay_property_id', true );
        ?>
        <p style="margin-top: 0;">
            <strong>API Property ID</strong>
        </p>
        <p>
            <label for="goldenstay_property_id" class="screen-reader-text">API Property ID</label>
            <input
                type="number"
                min="1"
                step="1"
                id="goldenstay_property_id"
                name="goldenstay_property_id"
                value="<?php echo esc_attr( $property_id ); ?>"
                style="width: 100%;"
                placeholder="e.g. 123"
            />
        </p>
        <p class="description" style="margin-bottom: 0;">
            This links the accommodation to your GoldenStay API property.
            You can find the ID in <a href="<?php echo esc_url( admin_url( 'admin.php?page=goldenstay-properties' ) ); ?>">GoldenStay → Properties</a>.
        </p>
        <?php
    }

    public function save_meta( $post_id, $post ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }
        if ( ! isset( $_POST['goldenstay_accom_mapping_nonce'] ) ) {
            return;
        }
        if ( ! wp_verify_nonce( $_POST['goldenstay_accom_mapping_nonce'], 'goldenstay_accom_mapping' ) ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        if ( $post->post_type !== 'hb_accommodation' ) {
            return;
        }

        $property_id = isset( $_POST['goldenstay_property_id'] ) ? intval( $_POST['goldenstay_property_id'] ) : 0;
        if ( $property_id > 0 ) {
            update_post_meta( $post_id, 'goldenstay_property_id', $property_id );
        } else {
            delete_post_meta( $post_id, 'goldenstay_property_id' );
        }
    }
}


