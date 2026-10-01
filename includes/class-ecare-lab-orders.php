<?php
defined('ABSPATH') || exit;

/**
 * Lab orders: one row per order in wp_ecare_bookings (booking_type 'lab'),
 * with a WooCommerce order that carries only the advance.
 *
 *   Place Order -> booking row 'pending' + WooCommerce order for the advance
 *   paid        -> 'approved' (shown as Confirmed), the lab cart is emptied
 *   then the operator moves it on: sample_collected -> processing (at the lab)
 *   -> report_ready (report uploaded) -> completed. Or cancelled.
 *
 * New orders are told apart from the one-row-per-test bookings of the old lab
 * pages by lab_provider_id, one of three columns this class adds to the
 * bookings table. The full order (items, prices, address, slot, the quote and
 * a status history) is kept in lab_details as JSON, so it survives even if
 * the WooCommerce order is deleted.
 */
class ECare_Lab_Orders {

    const DB_VERSION = '1';
    const DB_OPTION  = 'ecare_lab_orders_db_version';
    const PENDING_META = '_ecare_lab_pending_order';   // user meta: the unpaid order from the last Place Order
    const ORDER_META   = '_ecare_lab_booking_id';      // WooCommerce order meta: our row

    /** @var ?bool */
    private static $columns = null;

    public static function init() {
        add_action('init', array(__CLASS__, 'maybe_upgrade'), 5);

        foreach (array('woocommerce_payment_complete', 'woocommerce_order_status_processing', 'woocommerce_order_status_completed') as $hook) {
            add_action($hook, array(__CLASS__, 'on_paid'), 20);
        }
        foreach (array('woocommerce_order_status_cancelled', 'woocommerce_order_status_refunded') as $hook) {
            add_action($hook, array(__CLASS__, 'on_cancelled'), 20);
        }
        add_filter('ecare_lab_booked_slots', array(__CLASS__, 'booked_slots'));
        add_action('woocommerce_thankyou', array(__CLASS__, 'thankyou_note'), 5);
        add_filter('woocommerce_get_order_item_totals', array(__CLASS__, 'order_totals'), 10, 2);
        add_filter('woocommerce_account_menu_items', array(__CLASS__, 'account_menu'));
        add_filter('woocommerce_get_endpoint_url', array(__CLASS__, 'account_menu_url'), 10, 2);
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'ecare_bookings';
    }

    // =======================================================================
    // Schema: three additive columns, nothing else touched
    // =======================================================================

    public static function maybe_upgrade() {
        if (get_option(self::DB_OPTION) === self::DB_VERSION) {
            return;
        }
        global $wpdb;
        $t = self::table();
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) !== $t) {
            return;   // the bookings table is made on activation; try again next time
        }
        $add = array(
            'lab_provider_id' => 'BIGINT UNSIGNED NULL DEFAULT NULL',
            'collection_slot' => 'VARCHAR(20) NULL DEFAULT NULL',
            'lab_details'     => 'LONGTEXT NULL',
        );
        foreach ($add as $col => $def) {
            if (!$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$t} LIKE %s", $col))) {
                $wpdb->query("ALTER TABLE {$t} ADD COLUMN {$col} {$def}"); // phpcs:ignore -- fixed names above
            }
        }
        if (!$wpdb->get_var("SHOW INDEX FROM {$t} WHERE Key_name = 'idx_lab_provider_id'")) {
            $wpdb->query("ALTER TABLE {$t} ADD INDEX idx_lab_provider_id (lab_provider_id)");
        }
        self::$columns = null;
        if (self::has_columns()) {
            update_option(self::DB_OPTION, self::DB_VERSION, false);
        }
    }

    /** Are the new columns there? (A failed ALTER must not break the old lab's queries.) */
    public static function has_columns() {
        if (self::$columns === null) {
            global $wpdb;
            self::$columns = (bool) $wpdb->get_var($wpdb->prepare('SHOW COLUMNS FROM ' . self::table() . ' LIKE %s', 'lab_provider_id'));
        }
        return self::$columns;
    }

    /** SQL the old lab's status sweep adds so it leaves new orders alone. */
    public static function legacy_only_sql() {
        return self::has_columns() ? ' AND lab_provider_id IS NULL' : '';
    }

    // =======================================================================
    // Statuses
    // =======================================================================

    public static function statuses() {
        $d = 'ecare-health-services';
        return array(
            'pending'          => __('Pending payment', $d),
            'approved'         => __('Confirmed', $d),
            'sample_collected' => __('Sample collected', $d),
            'processing'       => __('At the lab', $d),
            'report_ready'     => __('Report ready', $d),
            'completed'        => __('Completed', $d),
            'cancelled'        => __('Cancelled', $d),
        );
    }

    public static function status_label($status) {
        return self::statuses()[$status] ?? ucfirst(str_replace('_', ' ', (string) $status));
    }

    /** Orders someone still has to act on. */
    public static function open_statuses() {
        return array('approved', 'sample_collected', 'processing', 'report_ready');
    }

    // =======================================================================
    // Reading
    // =======================================================================

    /** A row with its details decoded, or null. */
    public static function get($id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . " WHERE id = %d AND booking_type = 'lab'", (int) $id));
        return $row ? self::decode($row) : null;
    }

    public static function decode($row) {
        $d            = json_decode((string) ($row->lab_details ?? ''), true);
        $row->details = is_array($d) ? $d : array();
        $row->is_new  = !empty($row->lab_provider_id);
        return $row;
    }

    /**
     * When the order was placed, on the site's clock. created_at is stamped by
     * the database, whose clock may be set to a different timezone.
     */
    public static function placed_at($row) {
        $t = (int) ($row->details['log'][0]['t'] ?? 0);
        return $t ?: (int) strtotime((string) $row->created_at);
    }

    /** A patient's new-style lab orders, newest first. */
    public static function for_user($user_id, $limit = 50) {
        if (!self::has_columns()) {
            return array();
        }
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table() . " WHERE booking_type = 'lab' AND user_id = %d AND lab_provider_id IS NOT NULL ORDER BY id DESC LIMIT %d",
            (int) $user_id, (int) $limit
        ));
        return array_map(array(__CLASS__, 'decode'), (array) $rows);
    }

    /**
     * "Y-m-d|HH:MM-HH:MM" => orders in that slot, for slot capacity. Unpaid and
     * cancelled orders do not hold a place.
     */
    public static function booked_slots($booked = array()) {
        if (!self::has_columns()) {
            return (array) $booked;
        }
        global $wpdb;
        $rows = $wpdb->get_results(
            'SELECT required_date AS d, collection_slot AS s, COUNT(*) AS n FROM ' . self::table()
            . " WHERE booking_type = 'lab' AND lab_provider_id IS NOT NULL AND status NOT IN ('pending','cancelled')"
            . ' AND required_date >= CURDATE() GROUP BY required_date, collection_slot'
        );
        $out = (array) $booked;
        foreach ((array) $rows as $r) {
            $key       = $r->d . '|' . $r->s;
            $out[$key] = ($out[$key] ?? 0) + (int) $r->n;
        }
        return $out;
    }

    // =======================================================================
    // Placing an order
    // =======================================================================

    /** A fingerprint of what is in the cart, so payment only empties the cart it paid for. */
    public static function cart_hash($cart) {
        $items = $cart['items'];
        ksort($items);
        return md5(wp_json_encode(array((int) $cart['provider_id'], $items)));
    }

    /** "Md. Kamrul Hasan" -> ["Md. Kamrul", "Hasan"]; one word stays a first name. */
    public static function split_name($name) {
        $name  = trim((string) preg_replace('/\s+/u', ' ', (string) $name));
        $break = function_exists('mb_strrpos') ? mb_strrpos($name, ' ') : strrpos($name, ' ');
        if ($break === false) {
            return array($name, '');
        }
        return function_exists('mb_substr')
            ? array(mb_substr($name, 0, $break), mb_substr($name, $break + 1))
            : array(substr($name, 0, $break), substr($name, $break + 1));
    }

    /**
     * Turn a checkout that passed ECare_Lab_Checkout::validate() into a lab
     * order and a WooCommerce order for the advance.
     *
     * @param array $state the checkout state (phone already normalized)
     * @param array $v     validate() result: quote, address, coupon
     * @return array{ok:bool, code?:string, booking_id?:int, order?:object, pay_url?:string}
     */
    public static function create_from_checkout($user_id, $state, $v) {
        if (!function_exists('wc_create_order')) {
            return array('ok' => false, 'code' => 'no_wc');
        }
        if (!self::has_columns()) {
            return array('ok' => false, 'code' => 'no_db');
        }
        $cart  = ECare_Lab_Cart::get($user_id);
        $p     = ECare_Lab_Cart::priced($user_id);
        $q     = $v['quote'];
        $addr  = $v['address'];
        $lab   = (int) $p['provider_id'];
        if (!$cart['items'] || !$lab || !$addr) {
            return array('ok' => false, 'code' => 'empty');
        }

        self::cancel_stale($user_id);

        $items = array();
        foreach ($p['lines'] as $l) {
            $row     = ECare_Lab_Cart::offering($l['test_id'], $lab);
            $items[] = array(
                'test_id'    => (int) $l['test_id'],
                'title'      => (string) $l['title'],
                'patients'   => (int) $l['patients'],
                'price'      => (float) $l['price'],
                'mrp'        => (float) $l['mrp'],
                'material'   => $row ? (float) $row->material_cost : 0.0,
                'line_total' => (float) $l['line_total'],
            );
        }
        $details = array(
            'v'         => 1,
            'lab'       => array('id' => $lab, 'name' => get_the_title($lab)),
            'items'     => $items,
            'address'   => $addr + array('text' => ECare_Lab_Checkout::address_text($addr)),
            'delivery'  => $state['delivery'],
            'date'      => $state['date'],
            'slot'      => $state['slot'],
            'name'      => $state['name'],
            'phone'     => $state['phone'],
            'note'      => $state['note'],
            'quote'     => $q,
            'cart_hash' => self::cart_hash($cart),
            'log'       => array(array('t' => time(), 'by' => (int) $user_id, 'status' => 'pending', 'note' => 'Order placed')),
        );

        global $wpdb;
        list($h, $m) = array_map('intval', explode(':', explode('-', $state['slot'])[0]));
        $ok = $wpdb->insert(self::table(), array(
            'booking_type'    => 'lab',
            'user_id'         => (int) $user_id,
            'lab_provider_id' => $lab,
            'patient_name'    => $state['name'],
            'contact_phone'   => $state['phone'],
            'address'         => $details['address']['text'],
            'required_date'   => $state['date'],
            'schedule_time'   => sprintf('%s %02d:%02d:00', $state['date'], $h, $m),
            'collection_slot' => $state['slot'],
            'lab_test_ids'    => implode(',', wp_list_pluck($items, 'test_id')),
            'notes'           => $state['note'],
            'total_amount'    => $q['total'],
            'status'          => 'pending',
            'lab_details'     => wp_json_encode($details),
        ));
        if (!$ok) {
            return array('ok' => false, 'code' => 'db');
        }
        $booking_id = (int) $wpdb->insert_id;

        $order = self::create_wc_order($user_id, $booking_id, $details, $v);
        if (is_wp_error($order) || !$order) {
            $wpdb->update(self::table(), array('status' => 'cancelled'), array('id' => $booking_id));
            return array('ok' => false, 'code' => 'order');
        }
        $wpdb->update(self::table(), array('order_id' => $order->get_id()), array('id' => $booking_id));
        update_user_meta((int) $user_id, self::PENDING_META, $order->get_id());

        if ($q['advance'] <= 0) {
            // Nothing to pay online (advance set to 0%): confirmed straight away.
            $order->payment_complete();
            return array('ok' => true, 'booking_id' => $booking_id, 'order' => $order, 'pay_url' => ECare_Lab_Front::url('cart', array('step' => 'orders', 'placed' => $booking_id)));
        }
        return array('ok' => true, 'booking_id' => $booking_id, 'order' => $order, 'pay_url' => $order->get_checkout_payment_url());
    }

    /**
     * The WooCommerce order: one fee line for the advance, the patient's
     * billing details (SSLCommerz will not open a session without name, email,
     * phone, city, postcode and country) and the coupon, so WooCommerce counts
     * its use once the order is paid.
     */
    private static function create_wc_order($user_id, $booking_id, $details, $v) {
        $q     = $details['quote'];
        $order = wc_create_order(array('customer_id' => (int) $user_id, 'created_via' => 'ecare_lab'));
        if (is_wp_error($order)) {
            return $order;
        }

        $fee = new WC_Order_Item_Fee();
        $fee->set_name(sprintf(
            /* translators: 1: percent, 2: order number, 3: lab name */
            __('Advance payment (%1$s%%) for lab order #%2$d at %3$s', 'ecare-health-services'),
            rtrim(rtrim(number_format((float) $q['percent'], 2, '.', ''), '0'), '.'),
            $booking_id,
            $details['lab']['name']
        ));
        $fee->set_amount((string) $q['advance']);
        $fee->set_total((string) $q['advance']);
        $fee->set_tax_status('none');
        $order->add_item($fee);

        if ($q['coupon_code'] !== '' && class_exists('WC_Order_Item_Coupon')) {
            $c = new WC_Order_Item_Coupon();
            $c->set_code($q['coupon_code']);
            $c->set_discount((string) $q['coupon']);
            $order->add_item($c);
        }

        $user  = get_userdata((int) $user_id);
        $email = ($user && is_email($user->user_email)) ? $user->user_email : '';
        if ($email === '') {
            $host  = wp_parse_url(home_url(), PHP_URL_HOST);
            $email = preg_replace('/\D+/', '', $details['phone']) . '@' . ($host ? 'no-email.' . preg_replace('/^www\./', '', $host) : 'no-email.invalid');
        }
        list($first, $last) = self::split_name($details['name']);
        $area     = get_term((int) $details['address']['area_id'], ECare_Locations::TAXONOMY);
        $district = ($area && !is_wp_error($area)) ? get_term((int) $area->parent, ECare_Locations::TAXONOMY) : null;
        $order->set_address(array(
            'first_name' => $first,
            'last_name'  => $last,
            'phone'      => $details['phone'],
            'email'      => $email,
            'address_1'  => $details['address']['line'],
            'address_2'  => ($area && !is_wp_error($area)) ? $area->name : '',
            'city'       => ($district && !is_wp_error($district)) ? $district->name : 'Dhaka',
            'postcode'   => apply_filters('ecare_checkout_fallback_postcode', '1000'),
            'country'    => 'BD',
        ), 'billing');

        $order->update_meta_data(self::ORDER_META, $booking_id);
        $order->update_meta_data('_ecare_lab_cart_hash', $details['cart_hash']);
        $order->calculate_totals(false);
        $order->set_status('pending');
        $order->save();
        $order->add_order_note(sprintf(
            'Lab order #%d: %s. Total %s, advance %s, pay at collection %s. Collection %s %s.',
            $booking_id,
            implode(', ', array_map(function ($i) { return $i['title'] . ($i['patients'] > 1 ? ' x' . $i['patients'] : ''); }, $details['items'])),
            $q['total'], $q['advance'], $q['later'], $details['date'], $details['slot']
        ));
        return $order;
    }

    /** An earlier unpaid order from this patient is replaced, not left lying about. */
    public static function cancel_stale($user_id) {
        $old_id = (int) get_user_meta((int) $user_id, self::PENDING_META, true);
        if (!$old_id) {
            return;
        }
        delete_user_meta((int) $user_id, self::PENDING_META);
        $old = wc_get_order($old_id);
        if ($old && $old->has_status(array('pending', 'failed')) && $old->get_meta(self::ORDER_META)) {
            $old->update_status('cancelled', __('Replaced by a newer lab order from the same patient.', 'ecare-health-services'));
        }
    }

    // =======================================================================
    // WooCommerce says: paid / cancelled
    // =======================================================================

    public static function on_paid($order_id) {
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        if (!$order || !$order->is_paid()) {
            return;
        }
        $id = (int) $order->get_meta(self::ORDER_META);
        if (!$id) {
            return;
        }
        $row = self::get($id);
        if (!$row || $row->status !== 'pending') {
            return;   // already confirmed (these hooks fire more than once)
        }
        self::set_status($id, 'approved', __('Advance paid online.', 'ecare-health-services'), 0);

        $uid = (int) $row->user_id;
        if ((int) get_user_meta($uid, self::PENDING_META, true) === (int) $order->get_id()) {
            delete_user_meta($uid, self::PENDING_META);
        }
        // Empty the cart only if it is still the cart that was paid for.
        if ($uid && ($row->details['cart_hash'] ?? '') === self::cart_hash(ECare_Lab_Cart::get($uid))) {
            ECare_Lab_Cart::clear($uid);
            $state           = ECare_Lab_Checkout::get_state($uid);
            $state['coupon'] = '';
            $state['date']   = '';
            $state['slot']   = '';
            $state['note']   = '';
            ECare_Lab_Checkout::save_state($uid, $state);
        }
    }

    public static function on_cancelled($order_id) {
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        $id    = $order ? (int) $order->get_meta(self::ORDER_META) : 0;
        $row   = $id ? self::get($id) : null;
        if ($row && in_array($row->status, array('pending', 'approved'), true)) {
            self::set_status($id, 'cancelled', __('The WooCommerce order was cancelled or refunded.', 'ecare-health-services'), 0);
        }
    }

    // =======================================================================
    // Changing an order
    // =======================================================================

    /** Move an order to a status, with a line in its history. */
    public static function set_status($id, $status, $note = '', $by = 0) {
        if (!isset(self::statuses()[$status])) {
            return false;
        }
        $row = self::get($id);
        if (!$row) {
            return false;
        }
        $details          = $row->details;
        $details['log'][] = array('t' => time(), 'by' => (int) $by, 'status' => $status, 'note' => (string) $note);
        global $wpdb;
        $wpdb->update(self::table(), array('status' => $status, 'lab_details' => wp_json_encode($details)), array('id' => (int) $id));
        do_action('ecare_lab_order_status', (int) $id, $status, $row->status);
        return true;
    }

    /** Set one key in an order's details (e.g. which emails went out). */
    public static function set_detail($id, $key, $value) {
        $row = self::get($id);
        if (!$row) {
            return false;
        }
        $details       = $row->details;
        $details[$key] = $value;
        global $wpdb;
        $wpdb->update(self::table(), array('lab_details' => wp_json_encode($details)), array('id' => (int) $id));
        return true;
    }

    /** Keep a note without changing the status. */
    public static function add_note($id, $note, $by = 0) {
        $row = self::get($id);
        if (!$row || trim((string) $note) === '') {
            return false;
        }
        $details          = $row->details;
        $details['log'][] = array('t' => time(), 'by' => (int) $by, 'status' => '', 'note' => (string) $note);
        global $wpdb;
        $wpdb->update(self::table(), array('lab_details' => wp_json_encode($details)), array('id' => (int) $id));
        return true;
    }

    /**
     * A report was uploaded (a private file reference). An order that had not
     * reached "Report ready" moves there.
     */
    public static function attach_report($id, $reference, $by = 0) {
        $row = self::get($id);
        if (!$row) {
            return false;
        }
        global $wpdb;
        $wpdb->update(self::table(), array('file_urls' => (string) $reference), array('id' => (int) $id));
        if (in_array($row->status, array('approved', 'sample_collected', 'processing'), true)) {
            self::set_status($id, 'report_ready', __('Report uploaded.', 'ecare-health-services'), $by);
        } else {
            self::add_note($id, __('Report uploaded.', 'ecare-health-services'), $by);
        }
        return true;
    }

    /** The signed link to a row's report, or ''. */
    public static function report_url($row) {
        $ref = (string) ($row->file_urls ?? '');
        if ($ref === '' || !class_exists('ECare_Secure_Files') || !ECare_Secure_Files::is_reference($ref)) {
            return '';
        }
        return ECare_Secure_Files::get_view_url($ref, ECare_Secure_Files::CTX_BOOKING, (int) $row->id);
    }

    // =======================================================================
    // Around WooCommerce's own pages
    // =======================================================================

    /**
     * An advance order has no products, so WooCommerce's "Subtotal: 0.00" row
     * on the payment page and receipt says nothing true. Leave it out there.
     */
    public static function order_totals($rows, $order) {
        if ($order && is_object($order) && method_exists($order, 'get_meta') && $order->get_meta(self::ORDER_META)) {
            unset($rows['cart_subtotal']);
        }
        return $rows;
    }

    public static function thankyou_note($order_id) {
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        $id    = $order ? (int) $order->get_meta(self::ORDER_META) : 0;
        if (!$id) {
            return;
        }
        echo '<div class="woocommerce-message" role="status">'
            . esc_html(sprintf(__('Lab order #%d is booked. We will come to collect the sample at the time you chose.', 'ecare-health-services'), $id))
            . ' <a class="button" href="' . esc_url(ECare_Lab_Front::url('cart', array('step' => 'orders'))) . '">' . esc_html__('My Lab Orders', 'ecare-health-services') . '</a></div>';
    }

    /** "Lab Orders" in My Account, just before Log out. */
    public static function account_menu($items) {
        $out = array();
        foreach ((array) $items as $k => $v) {
            if ($k === 'customer-logout') {
                $out['ecare-lab-orders'] = __('Lab Orders', 'ecare-health-services');
            }
            $out[$k] = $v;
        }
        if (!isset($out['ecare-lab-orders'])) {
            $out['ecare-lab-orders'] = __('Lab Orders', 'ecare-health-services');
        }
        return $out;
    }

    public static function account_menu_url($url, $endpoint) {
        return $endpoint === 'ecare-lab-orders' ? ECare_Lab_Front::url('cart', array('step' => 'orders')) : $url;
    }
}
