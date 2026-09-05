<?php
defined('ABSPATH') || exit;

class ECare_WooCommerce {

    public static function init() {
        add_filter('woocommerce_cart_item_name', array(__CLASS__, 'cart_item_name'), 10, 3);
        
        // Secure payment hooks
        add_action('woocommerce_payment_complete', array(__CLASS__, 'handle_payment_complete'));
        add_action('woocommerce_order_status_processing', array(__CLASS__, 'handle_payment_complete'));
        add_action('woocommerce_order_status_completed', array(__CLASS__, 'handle_order_completed'));

        // Auto ingest lab bookings on order checkout creation & fallback hooks.
        //
        // The classic checkout fires woocommerce_checkout_order_processed; the
        // block checkout does not, it fires its own Store API action and passes
        // the order object rather than an id. Verified against WooCommerce 11:
        // of the three hooks this class relies on, that is the only one the
        // Store API skips. woocommerce_get_item_data is applied by the cart
        // schema, and line item meta still works because the Store API hands
        // line item creation back to WC_Checkout::create_order_line_items().
        add_action('woocommerce_checkout_order_processed', array(__CLASS__, 'create_lab_bookings_from_order'), 10, 3);
        add_action('woocommerce_store_api_checkout_order_processed', array(__CLASS__, 'create_lab_bookings_from_order'), 10, 1);
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
        // Money received: a booking that is still waiting becomes approved.
        self::advance_booking_status($order_id, 'approved', array('pending'));
    }

    public static function handle_order_completed($order_id) {
        // Order fulfilled: anything still in flight is done.
        self::advance_booking_status($order_id, 'completed', array('pending', 'approved', 'assigned', 'dispatched'));
    }

    /**
     * Move the bookings behind an order to a new status, but only from a status
     * this transition is allowed to replace.
     *
     * Two things make the whitelist matter more than it looks.
     *
     * First, until the meta lookup below was corrected these handlers silently
     * did nothing on a High-Performance Order Storage site, so this path has
     * never actually run against real data. Switching it on unguarded is a
     * change in behaviour, not a no-op.
     *
     * Second, WooCommerce re-fires the order status hooks whenever an order is
     * saved again. Without the whitelist an ambulance an operator had already
     * marked 'assigned' would quietly fall back to 'approved' the next time
     * somebody opened that order and pressed Update. 'cancelled' appears in no
     * list at all, so a cancelled booking is never revived.
     *
     * @param int      $order_id
     * @param string   $new_status Status to write.
     * @param string[] $from       Statuses this transition may replace.
     */
    private static function advance_booking_status($order_id, $new_status, array $from) {
        $order = wc_get_order($order_id);
        if (!$order || empty($from)) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ecare_bookings';
        $slots = implode(', ', array_fill(0, count($from), '%s'));
        $id    = $order->get_id();

        // Caregiver and ambulance bookings carry their id on the order's own
        // meta. Read it through the order object rather than get_post_meta():
        // under HPOS the value lives in wp_wc_orders_meta, which post meta
        // cannot see. That mismatch is what left paid bookings stuck at
        // 'pending'.
        $booking_id = (int) $order->get_meta('_ecare_booking_id');

        if ($booking_id) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET status = %s WHERE id = %d AND status IN ({$slots})",
                array_merge(array($new_status, $booking_id), $from)
            ));
        }

        // Lab bookings are matched by the order they were created from.
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = %s WHERE order_id = %d AND booking_type = 'lab' AND status IN ({$slots})",
            array_merge(array($new_status, $id), $from)
        ));
    }

    public static function create_lab_bookings_from_order($order_id, $posted_data = array(), $order = null) {
        if (!$order) {
            $order = wc_get_order($order_id);
        }
        if (!$order) return;

        // The Store API passes the order object where the classic checkout
        // passes an id, so take the id from the order and let either shape in.
        // Without this the object would end up in the order_id column.
        $order_id = $order->get_id();

        global $wpdb;
        $table = $wpdb->prefix . 'ecare_bookings';

        // Check if we already created lab bookings for this order to prevent duplicate insertions.
        // Under HPOS order meta lives in wp_wc_orders_meta, which get_post_meta() cannot read.
        $already_created = $order->get_meta('_ecare_lab_bookings_created');
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
            $order->update_meta_data('_ecare_lab_bookings_created', '1');
            $order->save_meta_data();
        }
    }
}
