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
        add_action('woocommerce_order_status_completed', array(__CLASS__, 'create_lab_bookings_from_order'), 10, 1);

        // Cash on Delivery makes no sense for a lab test - the sample is taken
        // and the result issued long before anyone would turn up to collect.
        add_filter('woocommerce_available_payment_gateways', array(__CLASS__, 'restrict_lab_test_gateways'));

        // There is no shop to return to on an empty cart.
        add_filter('woocommerce_return_to_shop_text', array(__CLASS__, 'return_to_shop_text'));
        add_filter('woocommerce_return_to_shop_redirect', array(__CLASS__, 'return_to_shop_redirect'));

        // Nothing here is shipped.
        add_filter('woocommerce_new_order_note_data', array(__CLASS__, 'rewrite_shipping_language'));

        // Display custom location metadata on cart and checkout pages
        add_filter('woocommerce_get_item_data', array(__CLASS__, 'display_cart_item_location_metadata'), 10, 2);

        // Add custom location metadata to order items
        add_action('woocommerce_checkout_create_order_line_item', array(__CLASS__, 'save_location_metadata_to_order_item'), 10, 4);

        // Name the submit button after what actually happens next, and clear
        // the boilerplate off the order-pay page.
        add_filter('woocommerce_pay_order_button_text', array(__CLASS__, 'pay_order_button_text'));
        add_action('woocommerce_pay_order_before_payment', array(__CLASS__, 'tidy_pay_page'));
        add_action('wp_head', array(__CLASS__, 'pay_page_styles'));

        // Everything this shop sells is an E-Care booking, and the booking
        // flow has already asked where the patient is. Cut the checkout down
        // to what still has to be typed, then fill the rest in behind it.
        add_filter('woocommerce_checkout_fields', array(__CLASS__, 'slim_checkout_fields'));
        add_filter('default_checkout_billing_country', array(__CLASS__, 'default_billing_country'));
        add_filter('woocommerce_checkout_posted_data', array(__CLASS__, 'fill_hidden_checkout_data'));
        add_filter('get_post_metadata', array(__CLASS__, 'hide_checkout_page_title'), 10, 4);
    }

    /**
     * Label for the submit button on the order-pay page.
     *
     * WooCommerce ships "Pay for order". One button now serves two very
     * different outcomes - SSLCommerz takes the customer off to pay, Cash on
     * Delivery does not take a payment at all - so it is named after the thing
     * both have in common: the booking is confirmed. Filter name verified
     * against WooCommerce 11 (class-wc-shortcode-checkout.php:208).
     *
     * The standard checkout button is a different filter,
     * woocommerce_order_button_text, and is deliberately left alone.
     */
    public static function pay_order_button_text($text) {
        return __('Confirm Booking', 'ecare-health-services');
    }

    /**
     * Strip the boilerplate WooCommerce prints on the order-pay page.
     *
     * The privacy notice ("Your personal data will be used to process your
     * order...") is written for a page that collects personal data. This one
     * collects none: the order already exists, the customer is confirming it.
     *
     * Fires from woocommerce_pay_order_before_payment, which runs before
     * checkout/terms.php renders, so the removal lands in time.
     */
    public static function tidy_pay_page() {
        remove_action('woocommerce_checkout_terms_and_conditions', 'wc_checkout_privacy_policy_text', 20);
    }

    /**
     * Hide the payment-method list on the order-pay page when there is nothing
     * to choose between.
     *
     * With Cash on Delivery as the only gateway, the radio, its "Cash on
     * delivery" label and the "Pay with cash upon delivery." box repeat what
     * the button already says. The radio stays in the DOM - display:none still
     * submits - so $_POST['payment_method'] is unaffected.
     *
     * The single-gateway guard matters: add a second gateway later and this
     * turns itself off rather than leaving customers unable to pick one.
     */
    public static function pay_page_styles() {
        if (!function_exists('is_wc_endpoint_url') || !is_wc_endpoint_url('order-pay')) {
            return;
        }

        if (!function_exists('WC') || !WC()->payment_gateways) {
            return;
        }

        $gateways = (array) WC()->payment_gateways->get_available_payment_gateways();
        if (count($gateways) !== 1) {
            return;
        }

        echo '<style id="ecare-pay-page">#order_review .payment_methods{display:none;}</style>' . "\n";
    }

    /**
     * Take Cash on Delivery off the table when a lab test is being bought.
     *
     * There is one guard that matters more than the rule itself: if removing
     * COD would leave no way to pay at all, the rule stands down. Until an
     * online gateway is installed COD is the only one available, and a
     * checkout with zero payment methods is worse than the wrong one. Enable
     * SSLCommerz and this starts enforcing itself, with nothing to switch on.
     *
     * @param array $gateways Available gateways, keyed by id.
     * @return array
     */
    public static function restrict_lab_test_gateways($gateways) {
        if (is_admin() && !wp_doing_ajax()) {
            return $gateways;   // order screens in wp-admin are not a customer checkout
        }

        if (!self::buying_a_lab_test()) {
            return $gateways;
        }

        $disallowed = (array) apply_filters('ecare_lab_test_disallowed_gateways', array('cod'));

        $remaining = $gateways;
        foreach ($disallowed as $id) {
            unset($remaining[$id]);
        }

        return empty($remaining) ? $gateways : $remaining;
    }

    /**
     * Is a lab test part of what is being paid for right now?
     *
     * Checks the order on the order-pay page - where the cart is empty and the
     * order is the only record of what was bought - and the cart everywhere
     * else.
     */
    private static function buying_a_lab_test() {
        if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-pay')) {
            global $wp;
            $order_id = isset($wp->query_vars['order-pay']) ? absint($wp->query_vars['order-pay']) : 0;
            $order    = $order_id ? wc_get_order($order_id) : null;

            if ($order) {
                foreach ($order->get_items() as $item) {
                    if (self::is_lab_test_product($item->get_product())) {
                        return true;
                    }
                }
                return false;
            }
        }

        if (!function_exists('WC') || !WC()->cart) {
            return false;
        }

        foreach (WC()->cart->get_cart() as $cart_item) {
            $product = isset($cart_item['data']) ? $cart_item['data'] : null;
            if (self::is_lab_test_product($product)) {
                return true;
            }
        }

        return false;
    }

    /** Lab tests are the products whose SKU starts ecare-lab_test-. */
    private static function is_lab_test_product($product) {
        return $product && strpos((string) $product->get_sku(), 'ecare-lab_test-') === 0;
    }

    /**
     * Label on the empty-cart button.
     *
     * WooCommerce says "Return to shop", but this site has no shop page anyone
     * is meant to browse - services are booked from Find a Caregiver, Lab Tests
     * and Ambulance. Sending someone to a bare product archive is a dead end.
     * Filters verified against WooCommerce 11 (templates/cart/cart-empty.php).
     */
    public static function return_to_shop_text($text) {
        return __('Return to Home', 'ecare-health-services');
    }

    /** ...and it should land on the home page, not the shop archive. */
    public static function return_to_shop_redirect($url) {
        return home_url('/');
    }

    /**
     * Take shipping language out of the order notes.
     *
     * The SSLCommerz gateway writes "...We will be shipping your order to you
     * soon." on every successful payment (sslcommerz-class.php:513). Nothing on
     * this site is shipped - a nurse arrives, a sample is collected, an
     * ambulance is dispatched - and the note is what an operator reads when
     * they open the order.
     *
     * The string is hard-coded there with no filter of its own, so it is
     * matched and replaced as the note is written. Matching on a distinctive
     * fragment rather than the whole sentence, so a reworded upstream release
     * is still caught; if it ever stops matching, the worst case is the
     * original wording comes back.
     */
    public static function rewrite_shipping_language($commentdata) {
        if (empty($commentdata['comment_content'])) {
            return $commentdata;
        }

        if (stripos($commentdata['comment_content'], 'shipping your order') === false) {
            return $commentdata;
        }

        $commentdata['comment_content'] = __(
            'Payment received. The booking is confirmed and the service will be arranged as scheduled.',
            'ecare-health-services'
        );

        return $commentdata;
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

        // Nothing is booked until the order is paid for.
        //
        // Five hooks lead here and two of them - woocommerce_checkout_order_processed
        // and woocommerce_thankyou - fire while the order is still unpaid. With
        // Cash on Delivery that was harmless. With a gateway it is not: every
        // customer who reaches the payment page and closes the tab would leave a
        // lab booking behind, and the dashboard would fill with work nobody has
        // paid for. is_paid() covers processing and completed; on-hold and
        // pending do not qualify.
        if (!$order->is_paid()) return;

        // Check if we already created lab bookings for this order to prevent duplicate insertions.
        // Under HPOS order meta lives in wp_wc_orders_meta, which get_post_meta() cannot read.
        $already_created = $order->get_meta('_ecare_lab_bookings_created');
        if ($already_created) return;

        // The row used to be written as 'pending' and promoted a moment later by
        // advance_booking_status(). That no longer works: both run on
        // woocommerce_payment_complete, and the sweep goes first, so it would
        // look for a row that does not exist yet. Since we only get here on a
        // paid order, the status is known outright.
        $booking_status = $order->has_status('completed') ? 'completed' : 'approved';

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
                        'status'         => $booking_status,
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

    /* ---------------------------------------------------------------------
     * Checkout form
     *
     * The form a patient fills in and the data the payment gateway needs are
     * two different lists, and they had been treated as one. SSLCommerz's v4
     * hosted API takes cus_name, cus_email, cus_city, cus_postcode,
     * cus_country and cus_phone as mandatory, and the gateway plugin reads
     * every one of them straight off the billing fields
     * (wc-sslcommerz-easycheckout/lib/sslcommerz-class.php). Deleting a field
     * from the form therefore does not just tidy the page - it empties a
     * parameter the payment session will not open without.
     *
     * So: slim_checkout_fields() decides what the patient sees, and
     * fill_hidden_checkout_data() supplies what the gateway reads. Country,
     * city and postcode never appear on screen again but are never empty in
     * the order either.
     * ------------------------------------------------------------------- */

    /**
     * The billing form, reduced to what a patient still has to answer.
     *
     * @param array $fields Checkout fields.
     * @return array
     */
    public static function slim_checkout_fields($fields) {
        if (empty($fields['billing']) || !is_array($fields['billing'])) {
            return $fields;
        }

        // Off the form. The first four are dead weight for a service booked
        // online and delivered in person; the last two are supplied by
        // fill_hidden_checkout_data() instead of being asked for twice.
        $drop = array(
            'billing_company',
            'billing_address_2',
            'billing_last_name',
            'billing_country',
            'billing_city',
            'billing_postcode',
        );

        foreach ($drop as $key) {
            unset($fields['billing'][$key]);
        }

        // One name box. The two halves are put back in the posted data, so the
        // order list, the invoice and cus_name all still read normally.
        if (isset($fields['billing']['billing_first_name'])) {
            $fields['billing']['billing_first_name']['label']        = __('Full Name', 'ecare-health-services');
            $fields['billing']['billing_first_name']['placeholder']  = __('e.g. Sumaiya Akter', 'ecare-health-services');
            $fields['billing']['billing_first_name']['autocomplete'] = 'name';
            $fields['billing']['billing_first_name']['class']        = array('form-row-wide');
            $fields['billing']['billing_first_name']['priority']     = 10;
        }

        // "Street address" reads oddly for a flat in Dhaka, and the second
        // line is gone, so this is simply the address.
        if (isset($fields['billing']['billing_address_1'])) {
            $fields['billing']['billing_address_1']['label']       = __('Address', 'ecare-health-services');
            $fields['billing']['billing_address_1']['placeholder'] = __('House, road, area', 'ecare-health-services');
            $fields['billing']['billing_address_1']['class']       = array('form-row-wide');
            $fields['billing']['billing_address_1']['priority']    = 20;
        }

        // WooCommerce labels billing_state "District" under the Bangladesh
        // locale - it is not a custom field. cus_state is optional at the
        // gateway, so this can be optional here too.
        if (isset($fields['billing']['billing_state'])) {
            $fields['billing']['billing_state']['required'] = false;
            $fields['billing']['billing_state']['priority'] = 30;
        }

        if (isset($fields['billing']['billing_phone'])) {
            $fields['billing']['billing_phone']['required'] = true;
            $fields['billing']['billing_phone']['priority'] = 40;
        }

        // Optional on the form; fill_hidden_checkout_data() still hands the
        // gateway an address, because cus_email is mandatory there.
        if (isset($fields['billing']['billing_email'])) {
            $fields['billing']['billing_email']['required'] = false;
            $fields['billing']['billing_email']['priority'] = 50;
        }

        return $fields;
    }

    /**
     * The country the checkout starts on, with no country field to choose it.
     *
     * @return string
     */
    public static function default_billing_country() {
        return apply_filters('ecare_checkout_country', 'BD');
    }

    /**
     * Supply the values the form no longer asks for.
     *
     * Runs on woocommerce_checkout_posted_data, which WC_Checkout applies
     * before it validates and before it builds the order, so everything set
     * here reaches the customer session, the order and the gateway.
     *
     * @param array $data Posted checkout data.
     * @return array
     */
    public static function fill_hidden_checkout_data($data) {
        // The gateway does wc()->countries->countries[ $country ] with this
        // value. Empty is not merely missing data, it is an undefined index.
        $data['billing_country'] = self::default_billing_country();

        if (!empty($data['billing_first_name']) && empty($data['billing_last_name'])) {
            list($first, $last) = self::split_full_name($data['billing_first_name']);

            $data['billing_first_name'] = $first;
            $data['billing_last_name']  = $last;
        }

        if (empty($data['billing_city'])) {
            $data['billing_city'] = self::checkout_city($data);
        }

        if (empty($data['billing_postcode'])) {
            $data['billing_postcode'] = apply_filters('ecare_checkout_fallback_postcode', '1000');
        }

        if (empty($data['billing_email'])) {
            $data['billing_email'] = self::checkout_email($data);
        }

        return $data;
    }

    /**
     * Split one name box into the two halves WooCommerce stores.
     *
     * The last word is the surname; everything before it is the given name, so
     * "Md. Kamrul Hasan" keeps "Md. Kamrul" together. A single word is left as
     * a first name rather than having a surname invented for it. mb_* because
     * the name may well be in Bengali.
     *
     * @param string $name Whatever was typed in the one box.
     * @return array{0:string,1:string} First name, last name.
     */
    private static function split_full_name($name) {
        $name = (string) $name;
        $name = preg_replace('/[\s\x{00A0}]+/u', ' ', $name);
        $name = trim($name);

        if ('' === $name) {
            return array('', '');
        }

        $break = function_exists('mb_strrpos') ? mb_strrpos($name, ' ') : strrpos($name, ' ');

        if (false === $break) {
            return array($name, '');
        }

        if (function_exists('mb_substr')) {
            return array(mb_substr($name, 0, $break), mb_substr($name, $break + 1));
        }

        return array(substr($name, 0, $break), substr($name, $break + 1));
    }

    /**
     * A city for the gateway, taken from what the patient already told us.
     *
     * The booking flow stores division, district and area on the cart item
     * (see save_location_metadata_to_order_item), so the area the patient
     * picked is a truer answer than any box on this page would have been.
     *
     * @param array $data Posted checkout data.
     * @return string
     */
    private static function checkout_city($data) {
        foreach (array('area', 'district') as $key) {
            $value = self::cart_location_value($key);

            if ('' !== $value) {
                return $value;
            }
        }

        // Nothing in the cart said where this is - fall back to the District
        // select, if the patient happened to fill it in.
        if (!empty($data['billing_state']) && function_exists('WC') && WC()->countries) {
            $states = WC()->countries->get_states(self::default_billing_country());

            if (!empty($states[$data['billing_state']])) {
                return $states[$data['billing_state']];
            }

            return (string) $data['billing_state'];
        }

        return apply_filters('ecare_checkout_fallback_city', 'Dhaka');
    }

    /**
     * Read one piece of E-Care location data off the cart.
     *
     * @param string $key division|district|area|lab_provider.
     * @return string Empty string when no cart item carries it.
     */
    private static function cart_location_value($key) {
        if (!function_exists('WC') || !WC()->cart) {
            return '';
        }

        foreach (WC()->cart->get_cart() as $cart_item) {
            if (!empty($cart_item['ecare_location_data'][$key])) {
                return (string) $cart_item['ecare_location_data'][$key];
            }
        }

        return '';
    }

    /**
     * An email address for the gateway when the patient left the box empty.
     *
     * A signed-in patient has one on their account already. For anyone else it
     * is built from the phone number, on a subdomain that holds no mailbox -
     * so an operator can still tell whose order it is, and nothing is ever
     * sent into the void.
     *
     * @param array $data Posted checkout data.
     * @return string
     */
    private static function checkout_email($data) {
        $user = wp_get_current_user();

        if ($user && $user->exists() && is_email($user->user_email)) {
            return $user->user_email;
        }

        $digits = preg_replace('/\D+/', '', isset($data['billing_phone']) ? (string) $data['billing_phone'] : '');
        $local  = '' !== $digits ? $digits : 'guest-' . wp_generate_password(8, false, false);

        $host = wp_parse_url(home_url(), PHP_URL_HOST);
        $host = $host ? 'no-email.' . preg_replace('/^www\./', '', $host) : 'no-email.invalid';

        return apply_filters('ecare_checkout_fallback_email', $local . '@' . $host, $data);
    }

    /**
     * Take the "Checkout" heading off the checkout page.
     *
     * The heading is the theme's page title, not ours. Astra decides whether to
     * print it from a per-page setting stored as the post meta
     * `site-post-title`, which its "Disable Title" checkbox writes as
     * "disabled". Answering that one meta read for the checkout page does the
     * same thing the checkbox would, without anybody having to find it and
     * without this plugin knowing a single one of the theme's class names - so
     * there is no selector here to go stale when the theme is updated.
     *
     * On a theme that does not read that meta this is simply never consulted.
     * It cannot leave the page looking broken; the heading would just stay.
     *
     * The thank-you page keeps its heading: "Order received" is the whole
     * message there, and losing it would leave a customer staring at a receipt
     * with no confirmation on it.
     *
     * @param mixed  $value     Short-circuit value; null lets the database answer.
     * @param int    $object_id Post being asked about.
     * @param string $meta_key  Meta key being read.
     * @param bool   $single    Whether one value was asked for.
     * @return mixed
     */
    public static function hide_checkout_page_title($value, $object_id, $meta_key, $single = false) {
        if ('site-post-title' !== $meta_key) {
            return $value;
        }

        // Conditional tags are meaningless until the main query has run, and
        // calling them earlier earns a _doing_it_wrong notice.
        if (is_admin() || !did_action('wp') || !function_exists('is_checkout')) {
            return $value;
        }

        if (!is_checkout()) {
            return $value;
        }

        if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-received')) {
            return $value;
        }

        // Only the page being displayed. Another post's meta read during this
        // request is none of our business.
        if ((int) $object_id !== (int) get_queried_object_id()) {
            return $value;
        }

        if (!apply_filters('ecare_hide_checkout_page_title', true)) {
            return $value;
        }

        // get_metadata_raw() hands a non-null answer straight back, so the
        // shape has to match what the caller asked for.
        return $single ? 'disabled' : array('disabled');
    }
}
