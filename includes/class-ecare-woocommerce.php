<?php
defined('ABSPATH') || exit;

class ECare_WooCommerce {

    public static function init() {
        add_filter('woocommerce_cart_item_name', array(__CLASS__, 'cart_item_name'), 10, 3);
        add_action('woocommerce_thankyou', array(__CLASS__, 'handle_order_complete'));
        add_action('woocommerce_order_status_completed', array(__CLASS__, 'handle_order_completed'));
    }

    public static function cart_item_name($name, $cart_item, $cart_item_key) {
        $lab_ref = WC()->session->get('ecare_lab_test_ref_' . $cart_item_key);
        if ($lab_ref) {
            $name .= ' <small>(Lab Test)</small>';
        }
        return $name;
    }

    public static function handle_order_complete($order_id) {
        $booking_id = get_post_meta($order_id, '_ecare_booking_id', true);
        if ($booking_id) {
            global $wpdb;
            $table = $wpdb->prefix . 'ecare_bookings';
            $wpdb->update($table, array('status' => 'approved'), array('id' => $booking_id, 'order_id' => $order_id));
        }
    }

    public static function handle_order_completed($order_id) {
        $booking_id = get_post_meta($order_id, '_ecare_booking_id', true);
        if ($booking_id) {
            global $wpdb;
            $table = $wpdb->prefix . 'ecare_bookings';
            $wpdb->update($table, array('status' => 'completed'), array('id' => $booking_id, 'order_id' => $order_id));
        }
    }
}
