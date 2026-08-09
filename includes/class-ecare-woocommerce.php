<?php
defined('ABSPATH') || exit;

class ECare_WooCommerce {

    public static function init() {
        add_filter('woocommerce_cart_item_name', array(__CLASS__, 'cart_item_name'), 10, 3);
        
        // Secure payment hooks
        add_action('woocommerce_payment_complete', array(__CLASS__, 'handle_payment_complete'));
        add_action('woocommerce_order_status_processing', array(__CLASS__, 'handle_payment_complete'));
        add_action('woocommerce_order_status_completed', array(__CLASS__, 'handle_order_completed'));

        // Auto ingest lab bookings on order checkout creation
        add_action('woocommerce_checkout_order_processed', array(__CLASS__, 'create_lab_bookings_from_order'), 10, 3);
    }

    public static function cart_item_name($name, $cart_item, $cart_item_key) {
        $lab_ref = WC()->session->get('ecare_lab_test_ref_' . $cart_item_key);
        if ($lab_ref) {
            $name .= ' <small>(Lab Test)</small>';
        }
        return $name;
    }

    public static function handle_payment_complete($order_id) {
        $booking_id = get_post_meta($order_id, '_ecare_booking_id', true);
        global $wpdb;
        $table = $wpdb->prefix . 'ecare_bookings';

        if ($booking_id) {
            $wpdb->update($table, array('status' => 'approved'), array('id' => $booking_id));
        }

        // Also update any lab bookings associated with this order
        $wpdb->update($table, array('status' => 'approved'), array('order_id' => $order_id, 'booking_type' => 'lab'));
    }

    public static function handle_order_completed($order_id) {
        $booking_id = get_post_meta($order_id, '_ecare_booking_id', true);
        global $wpdb;
        $table = $wpdb->prefix . 'ecare_bookings';

        if ($booking_id) {
            $wpdb->update($table, array('status' => 'completed'), array('id' => $booking_id));
        }

        // Also update any lab bookings associated with this order
        $wpdb->update($table, array('status' => 'completed'), array('order_id' => $order_id, 'booking_type' => 'lab'));
    }

    public static function create_lab_bookings_from_order($order_id, $posted_data, $order) {
        if (!$order) {
            $order = wc_get_order($order_id);
        }
        if (!$order) return;

        global $wpdb;
        $table = $wpdb->prefix . 'ecare_bookings';

        // Check if we already created lab bookings for this order to prevent duplicate insertions
        $already_created = get_post_meta($order_id, '_ecare_lab_bookings_created', true);
        if ($already_created) return;

        $has_lab_tests = false;
        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            if ($product) {
                $sku = $product->get_sku();
                if (strpos($sku, 'ecare-lab_test-') === 0) {
                    $has_lab_tests = true;
                    // Extract test ID from SKU: 'ecare-lab_test-123'
                    $test_id = intval(substr($sku, 15));
                    
                    // Insert booking record of type 'lab'
                    $wpdb->insert($table, array(
                        'booking_type'   => 'lab',
                        'user_id'        => $order->get_customer_id() ?: 0,
                        'provider_id'    => $test_id, // Store test ID
                        'patient_name'   => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                        'contact_phone'  => $order->get_billing_phone(),
                        'address'        => $order->get_billing_address_1() . ' ' . $order->get_billing_address_2(),
                        'total_amount'   => $item->get_total(),
                        'status'         => 'pending',
                        'order_id'       => $order_id,
                        'lab_test_ids'   => $test_id,
                    ));
                }
            }
        }

        if ($has_lab_tests) {
            update_post_meta($order_id, '_ecare_lab_bookings_created', '1');
        }
    }
}
