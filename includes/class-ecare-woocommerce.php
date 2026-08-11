<?php
defined('ABSPATH') || exit;

class ECare_WooCommerce {

    public static function init() {
        add_filter('woocommerce_cart_item_name', array(__CLASS__, 'cart_item_name'), 10, 3);
        
        // Secure payment hooks
        add_action('woocommerce_payment_complete', array(__CLASS__, 'handle_payment_complete'));
        add_action('woocommerce_order_status_processing', array(__CLASS__, 'handle_payment_complete'));
        add_action('woocommerce_order_status_completed', array(__CLASS__, 'handle_order_completed'));

        // Auto ingest lab bookings on order checkout creation & fallback hooks
        add_action('woocommerce_checkout_order_processed', array(__CLASS__, 'create_lab_bookings_from_order'), 10, 3);
        add_action('woocommerce_thankyou', array(__CLASS__, 'create_lab_bookings_from_order'), 10, 1);
        add_action('woocommerce_payment_complete', array(__CLASS__, 'create_lab_bookings_from_order'), 10, 1);
        add_action('woocommerce_order_status_processing', array(__CLASS__, 'create_lab_bookings_from_order'), 10, 1);

        // Display custom location metadata on cart and checkout pages
        add_filter('woocommerce_get_item_data', array(__CLASS__, 'display_cart_item_location_metadata'), 10, 2);

        // Add custom location metadata to order items
        add_action('woocommerce_checkout_create_order_line_item', array(__CLASS__, 'save_location_metadata_to_order_item'), 10, 4);
    }

    public static function cart_item_name($name, $cart_item, $cart_item_key) {
        $lab_ref = WC()->session->get('ecare_lab_test_ref_' . $cart_item_key);
        if ($lab_ref) {
            $name .= ' <small>(Lab Test)</small>';
        }
        return $name;
    }

    public static function display_cart_item_location_metadata($item_data, $cart_item) {
        if (isset($cart_item['ecare_location_data'])) {
            $loc = $cart_item['ecare_location_data'];
            if (!empty($loc['division'])) {
                $item_data[] = array('name' => __('Division', 'ecare-health-services'), 'value' => $loc['division']);
            }
            if (!empty($loc['district'])) {
                $item_data[] = array('name' => __('District', 'ecare-health-services'), 'value' => $loc['district']);
            }
            if (!empty($loc['area'])) {
                $item_data[] = array('name' => __('Area', 'ecare-health-services'), 'value' => $loc['area']);
            }
            if (!empty($loc['lab_provider'])) {
                $item_data[] = array('name' => __('Lab Provider', 'ecare-health-services'), 'value' => $loc['lab_provider']);
            }
        }
        return $item_data;
    }

    public static function save_location_metadata_to_order_item($item, $cart_item_key, $values, $order) {
        if (isset($values['ecare_location_data'])) {
            $loc = $values['ecare_location_data'];
            if (!empty($loc['division'])) {
                $item->add_meta_data(__('Division', 'ecare-health-services'), $loc['division'], true);
            }
            if (!empty($loc['district'])) {
                $item->add_meta_data(__('District', 'ecare-health-services'), $loc['district'], true);
            }
            if (!empty($loc['area'])) {
                $item->add_meta_data(__('Area', 'ecare-health-services'), $loc['area'], true);
            }
            if (!empty($loc['lab_provider'])) {
                $item->add_meta_data(__('Lab Provider', 'ecare-health-services'), $loc['lab_provider'], true);
            }
        }
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

    public static function create_lab_bookings_from_order($order_id, $posted_data = array(), $order = null) {
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
                    
                    // Extract division, district, area, and lab provider details from item meta
                    $loc_meta = array();
                    foreach (array('Division', 'District', 'Area', 'Lab Provider') as $loc_key) {
                        $meta_val = $item->get_meta($loc_key);
                        if ($meta_val) {
                            $loc_meta[] = $loc_key . ': ' . $meta_val;
                        }
                    }
                    $locations_info = !empty($loc_meta) ? ' (' . implode(', ', $loc_meta) . ')' : '';
                    
                    // Insert booking record of type 'lab'
                    $wpdb->insert($table, array(
                        'booking_type'   => 'lab',
                        'user_id'        => $order->get_customer_id() ?: 0,
                        'provider_id'    => $test_id, // Store test ID
                        'patient_name'   => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                        'contact_phone'  => $order->get_billing_phone(),
                        'address'        => $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() . $locations_info,
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
