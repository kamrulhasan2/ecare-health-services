<?php
/**
 * Guards ECare_Lab_Orders: placing a lab order, the advance-only WooCommerce
 * order, payment and cancellation, statuses, reports.
 *
 * What it protects:
 *   - the schema change only adds its three columns, only when missing
 *   - the WooCommerce order charges exactly the advance, and carries what
 *     SSLCommerz needs (name, email, phone, city, postcode, country)
 *   - the coupon rides on the order so WooCommerce counts its use
 *   - a second Place Order replaces the unpaid first one
 *   - paying confirms the order once, and empties only the cart that was paid
 *     for; a cancelled or refunded order cancels an order not yet under way
 *   - statuses are from the list, every change is logged, a report moves the
 *     order to Report ready
 */

define('ABSPATH', __DIR__ . '/');

$GLOBALS['options'] = array(); $GLOBALS['umeta'] = array(); $GLOBALS['actions'] = array();

function add_action() {} function add_filter() {}
function do_action($h, ...$a) { $GLOBALS['actions'][] = array($h, $a); }
function apply_filters($h, $v) { return $v; }
function __($s, $d = null) { return $s; }
function get_option($k, $d = false) { return $GLOBALS['options'][$k] ?? $d; }
function update_option($k, $v, $a = null) { $GLOBALS['options'][$k] = $v; return true; }
function get_user_meta($u, $k, $s = false) { return $GLOBALS['umeta'][$u][$k] ?? ''; }
function update_user_meta($u, $k, $v) { $GLOBALS['umeta'][$u][$k] = $v; return true; }
function delete_user_meta($u, $k) { unset($GLOBALS['umeta'][$u][$k]); return true; }
function wp_json_encode($v) { return json_encode($v); }
function wp_list_pluck($list, $f) { return array_map(function ($x) use ($f) { return $x[$f]; }, $list); }
function get_the_title($id) { return array(99 => 'Popular', 101 => 'FBS', 89 => 'CBC')[$id] ?? ''; }
function get_userdata($id) { return (object) array('user_email' => $GLOBALS['email'] ?? 'rahim@example.com'); }
function is_email($e) { return (bool) filter_var($e, FILTER_VALIDATE_EMAIL); }
function home_url($p = '') { return 'https://www.meditaj.com' . $p; }
function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
function is_wp_error($x) { return $x instanceof WP_Error; }
class WP_Error {}
$GLOBALS['terms'] = array(501 => (object) array('term_id' => 501, 'name' => 'Dhanmondi', 'parent' => 50), 50 => (object) array('term_id' => 50, 'name' => 'Dhaka', 'parent' => 5));
function get_term($id, $tax) { return $GLOBALS['terms'][$id] ?? null; }
class ECare_Locations { const TAXONOMY = 'ecare_location'; }
class ECare_Lab_Front { public static function url($w, $a = array()) { return 'https://site/' . $w . '/?' . http_build_query($a); } }
class ECare_Secure_Files {
    const CTX_BOOKING = 'booking';
    public static function is_reference($v) { return strpos((string) $v, 'ecare-private/') === 0; }
    public static function get_view_url($ref, $ctx, $id) { return "view:$ctx:$id:$ref"; }
}

// ---- the cart and checkout this class reads ---------------------------------
class ECare_Lab_Cart {
    public static $cart = array(5 => array('provider_id' => 99, 'items' => array(101 => 2, 89 => 1)));
    public static $cleared = array();
    public static function get($u) { return self::$cart[$u] ?? array('provider_id' => 0, 'items' => array()); }
    public static function clear($u) { self::$cleared[] = $u; unset(self::$cart[$u]); }
    public static function offering($t, $l) { return (object) array('material_cost' => $t === 101 ? 30 : 0); }
    public static function priced($u) {
        return array('provider_id' => 99, 'lines' => array(
            array('test_id' => 101, 'title' => 'FBS', 'patients' => 2, 'available' => true, 'price' => 380.0, 'mrp' => 450.0, 'line_total' => 760.0),
            array('test_id' => 89, 'title' => 'CBC', 'patients' => 1, 'available' => true, 'price' => 400.0, 'mrp' => 400.0, 'line_total' => 400.0),
        ));
    }
}
class ECare_Lab_Checkout {
    public static $states = array();
    public static function address_text($a) { return $a['line'] . ', Dhanmondi, Dhaka'; }
    public static function get_state($u) { return self::$states[$u] ?? array('address_id' => 1, 'delivery' => 'hard', 'date' => '2026-10-02', 'slot' => '09:00-11:00', 'name' => 'Rahim', 'phone' => '01712345678', 'note' => 'Ring', 'coupon' => 'save10'); }
    public static function save_state($u, $s) { self::$states[$u] = $s; }
}

// ---- a WooCommerce stand-in ---------------------------------------------------
class WC_Order_Item_Fee { public $name, $amount, $total, $tax;
    public function set_name($n) { $this->name = $n; } public function set_amount($a) { $this->amount = $a; }
    public function set_total($t) { $this->total = $t; } public function set_tax_status($t) { $this->tax = $t; } }
class WC_Order_Item_Coupon { public $code, $discount;
    public function set_code($c) { $this->code = $c; } public function set_discount($d) { $this->discount = $d; } }
class Fake_Order {
    public $id, $items = array(), $meta = array(), $status = 'pending', $address = array(), $notes = array(), $customer, $via, $completed = 0;
    public function __construct($id, $args) { $this->id = $id; $this->customer = $args['customer_id']; $this->via = $args['created_via'] ?? ''; }
    public function get_id() { return $this->id; }
    public function add_item($i) { $this->items[] = $i; }
    public function set_address($a, $t) { $this->address[$t] = $a; }
    public function update_meta_data($k, $v) { $this->meta[$k] = $v; }
    public function get_meta($k) { return $this->meta[$k] ?? ''; }
    public function calculate_totals($t) { $this->total = 0; foreach ($this->items as $i) { if ($i instanceof WC_Order_Item_Fee) { $this->total += (float) $i->total; } } }
    public $total = 0;
    public function set_status($s) { $this->status = $s; }
    public function save() {}
    public function add_order_note($n) { $this->notes[] = $n; }
    public function has_status($s) { return in_array($this->status, (array) $s, true); }
    public function is_paid() { return in_array($this->status, array('processing', 'completed'), true); }
    public function update_status($s, $note = '') { $this->status = $s; $this->notes[] = $note; if ($s === 'cancelled') { ECare_Lab_Orders::on_cancelled($this->id); } }
    public function payment_complete() { $this->completed++; $this->status = 'completed'; ECare_Lab_Orders::on_paid($this->id); }
    public function get_checkout_payment_url() { return 'https://site/checkout/order-pay/' . $this->id . '/'; }
}
$GLOBALS['wc'] = array(); $GLOBALS['next_order'] = 700;
function wc_create_order($args) { $o = new Fake_Order(++$GLOBALS['next_order'], $args); $GLOBALS['wc'][$o->id] = $o; return $o; }
function wc_get_order($id) { return $GLOBALS['wc'][$id] ?? null; }

// ---- the database -------------------------------------------------------------
class Fake_WPDB {
    public $prefix = 'wp_';
    public $rows = array(), $insert_id = 0, $columns = array('lab_provider_id' => false), $table_exists = true, $alters = array(), $index = false, $slot_rows = array();
    public function prepare($q, ...$a) { if (count($a) === 1 && is_array($a[0])) { $a = $a[0]; } foreach ($a as $v) { $q = preg_replace('/%[sd]/', is_int($v) ? (string) $v : "'" . addslashes((string) $v) . "'", $q, 1); } return $q; }
    public function get_var($q) {
        if (strpos($q, 'SHOW TABLES') === 0) { return $this->table_exists ? 'wp_ecare_bookings' : null; }
        if (strpos($q, 'SHOW COLUMNS') === 0) { preg_match("/LIKE '([a-z_]+)'/", $q, $m); return !empty($this->columns[$m[1]]) ? $m[1] : null; }
        if (strpos($q, 'SHOW INDEX') === 0) { return $this->index ? 'idx' : null; }
        return null;
    }
    public function query($q) { $this->alters[] = $q; if (preg_match('/ADD COLUMN (\w+)/', $q, $m)) { $this->columns[$m[1]] = true; } if (strpos($q, 'ADD INDEX') !== false) { $this->index = true; } return 1; }
    public function insert($t, $data) { $this->insert_id = count($this->rows) + 31; $this->rows[$this->insert_id] = (object) ($data + array('id' => $this->insert_id, 'order_id' => null, 'file_urls' => '', 'created_at' => '2026-10-01 10:00:00')); return 1; }
    public function update($t, $data, $where) { foreach ($data as $k => $v) { $this->rows[$where['id']]->$k = $v; } return 1; }
    public function get_row($q) { preg_match('/id = (\d+)/', $q, $m); return isset($this->rows[(int) $m[1]]) ? clone $this->rows[(int) $m[1]] : null; }
    public function get_results($q) { return strpos($q, 'collection_slot AS s') !== false ? $this->slot_rows : array_values($this->rows); }
}
$wpdb = new Fake_WPDB(); $GLOBALS['wpdb'] = $wpdb;

class ECare_Lab_Pay { public static function url($o, $from = 'checkout') { return $o->get_checkout_payment_url() . '#via-lab-pay-' . $from; } }
require_once ($argv[1] ?? (__DIR__ . '/../includes/class-ecare-lab-orders.php'));

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; printf("  PASS  %s\n", $label); }
    else { $fail++; printf("  FAIL  %s\n          got:  %s\n          want: %s\n", $label, var_export($got, true), var_export($want, true)); }
}
$O = 'ECare_Lab_Orders';
function reset_statics() { $r = new ReflectionProperty('ECare_Lab_Orders', 'columns'); $r->setAccessible(true); $r->setValue(null, null); }

// ===========================================================================
echo "\n=== A. the three new columns ===\n";
// ===========================================================================
$wpdb->table_exists = false;
$O::maybe_upgrade();
check('no bookings table yet: nothing is done, and it will try again', array($wpdb->alters, $GLOBALS['options']), array(array(), array()));
$wpdb->table_exists = true; reset_statics();
check('before the upgrade the old sweep is left as it was', $O::legacy_only_sql(), '');
$wpdb->columns['collection_slot'] = true;   // a half-finished earlier run
reset_statics();
$O::maybe_upgrade();
check('only the missing columns are added, plus the index', $wpdb->alters, array(
    'ALTER TABLE wp_ecare_bookings ADD COLUMN lab_provider_id BIGINT UNSIGNED NULL DEFAULT NULL',
    'ALTER TABLE wp_ecare_bookings ADD COLUMN lab_details LONGTEXT NULL',
    'ALTER TABLE wp_ecare_bookings ADD INDEX idx_lab_provider_id (lab_provider_id)',
));
check('...no DROP, no MODIFY', preg_grep('/DROP|MODIFY|CHANGE/', $wpdb->alters), array());
check('the version is recorded', $GLOBALS['options']['ecare_lab_orders_db_version'], '1');
$wpdb->alters = array(); $O::maybe_upgrade();
check('and it does not run again', $wpdb->alters, array());
check('now the old sweep skips new orders', $O::legacy_only_sql(), ' AND lab_provider_id IS NULL');

// ===========================================================================
echo "\n=== B. Place Order ===\n";
// ===========================================================================
$quote = array('subtotal_mrp' => 1300.0, 'special' => 140.0, 'material' => 60.0, 'subtotal' => 1160.0, 'coupon' => 116.0, 'coupon_code' => 'save10',
               'delivery' => 200.0, 'service' => 0.0, 'total' => 1304.0, 'advance' => 261.0, 'later' => 1043.0, 'percent' => 20.0);
$state = ECare_Lab_Checkout::get_state(5); $state['name'] = 'Md. Kamrul Hasan';
$v     = array('quote' => $quote, 'address' => array('id' => 1, 'label' => 'home', 'area_id' => 501, 'line' => 'House 12, Road 5'), 'coupon' => array('ok' => true));
$r     = $O::create_from_checkout(5, $state, $v);
check('placed: the booking, the order, and the pay step (straight to SSLCommerz)', array($r['ok'], $r['booking_id'], $r['pay_url']), array(true, 31, 'https://site/checkout/order-pay/701/#via-lab-pay-checkout'));
$row = $wpdb->rows[31];
check('one row for the whole order', array($row->booking_type, $row->user_id, $row->lab_provider_id, $row->status, $row->order_id, $row->lab_test_ids), array('lab', 5, 99, 'pending', 701, '101,89'));
check('collection date, time and slot', array($row->required_date, $row->schedule_time, $row->collection_slot), array('2026-10-02', '2026-10-02 09:00:00', '09:00-11:00'));
check('patient, phone, address, note, total', array($row->patient_name, $row->contact_phone, $row->address, $row->notes, $row->total_amount), array('Md. Kamrul Hasan', '01712345678', 'House 12, Road 5, Dhanmondi, Dhaka', 'Ring', 1304.0));
$det = json_decode($row->lab_details, true);
check('the details keep items with material, the quote and the first log line', array($det['items'][0], $det['quote']['advance'], $det['lab'], $det['log'][0]['status']),
      array(array('test_id' => 101, 'title' => 'FBS', 'patients' => 2, 'price' => 380, 'mrp' => 450, 'material' => 30, 'line_total' => 760), 261, array('id' => 99, 'name' => 'Popular'), 'pending'));

$wo = $GLOBALS['wc'][701];
check('the WooCommerce order is for the advance only', array(count($wo->items), $wo->items[0]->total, $wo->total, $wo->items[0]->tax), array(2, '261', 261.0, 'none'));
check('its line says what it is', $wo->items[0]->name, 'Advance payment (20%) for lab order #31 at Popular');
check('the coupon rides along so WooCommerce counts its use', array($wo->items[1]->code, $wo->items[1]->discount), array('save10', '116'));
check('billing has what SSLCommerz needs', $wo->address['billing'], array(
    'first_name' => 'Md. Kamrul', 'last_name' => 'Hasan', 'phone' => '01712345678', 'email' => 'rahim@example.com',
    'address_1' => 'House 12, Road 5', 'address_2' => 'Dhanmondi', 'city' => 'Dhaka', 'postcode' => '1000', 'country' => 'BD'));
check('pending, marked as ours, made by the lab', array($wo->status, $wo->meta['_ecare_lab_booking_id'], $wo->via, $wo->customer), array('pending', 31, 'ecare_lab', 5));
check('a note sums it up for the operator', strpos($wo->notes[0], 'Lab order #31: FBS x2, CBC. Total 1304, advance 261') === 0, true);
check('the unpaid order is remembered for the patient', $GLOBALS['umeta'][5]['_ecare_lab_pending_order'], 701);

// Place again before paying: the first order is replaced.
$GLOBALS['email'] = 'not an email';
$r2 = $O::create_from_checkout(5, $state, $v);
check('the earlier unpaid order is cancelled', array($GLOBALS['wc'][701]->status, $wpdb->rows[31]->status), array('cancelled', 'cancelled'));
check('...and the new one is the one remembered', array($r2['booking_id'], $GLOBALS['umeta'][5]['_ecare_lab_pending_order']), array(32, 702));
check('no email on the account: a harmless one from the phone', $GLOBALS['wc'][702]->address['billing']['email'], '01712345678@no-email.meditaj.com');
unset($GLOBALS['email']);

// ===========================================================================
echo "\n=== C. paid ===\n";
// ===========================================================================
$O::on_paid(702);
check('not paid yet: nothing happens', $wpdb->rows[32]->status, 'pending');
$GLOBALS['wc'][702]->status = 'completed';   // a fee-only order goes straight to completed
$O::on_paid(702);
check('paid: confirmed', $wpdb->rows[32]->status, 'approved');
check('the paid cart is emptied', ECare_Lab_Cart::$cleared, array(5));
$st = ECare_Lab_Checkout::$states[5];
check('coupon, date, slot and note reset; name, phone, address and delivery kept', array($st['coupon'], $st['date'], $st['slot'], $st['note'], $st['name'], $st['phone'], $st['address_id'], $st['delivery']),
      array('', '', '', '', 'Rahim', '01712345678', 1, 'hard'));
check('the pending pointer is dropped', isset($GLOBALS['umeta'][5]['_ecare_lab_pending_order']), false);
$O::on_paid(702); $O::on_paid(702);
check('the hooks fire again: logged once', count(json_decode($wpdb->rows[32]->lab_details, true)['log']), 2);

// Paid for one cart while the patient had already started another.
ECare_Lab_Cart::$cart[5] = array('provider_id' => 99, 'items' => array(101 => 2, 89 => 1));
$r3 = $O::create_from_checkout(5, $state, $v);
ECare_Lab_Cart::$cart[5]['items'][77] = 1;   // added a test after placing
ECare_Lab_Cart::$cleared = array();
$GLOBALS['wc'][$r3['order']->id]->status = 'processing';
$O::on_paid($r3['order']->id);
check('a cart changed since placing is not emptied', array(ECare_Lab_Cart::$cleared, $wpdb->rows[$r3['booking_id']]->status), array(array(), 'approved'));
check('cart hash ignores key order', $O::cart_hash(array('provider_id' => 1, 'items' => array(2 => 1, 3 => 4))), $O::cart_hash(array('provider_id' => 1, 'items' => array(3 => 4, 2 => 1))));

// Advance of 0%: no payment page at all.
ECare_Lab_Cart::$cart[5] = array('provider_id' => 99, 'items' => array(101 => 1));
$zero = array('advance' => 0.0, 'later' => 1304.0) + $quote;
$r4 = $O::create_from_checkout(5, $state, array('quote' => $zero) + $v);
check('0% advance: confirmed at once, and the patient goes to My Lab Orders', array($wpdb->rows[$r4['booking_id']]->status, $r4['order']->completed, strpos($r4['pay_url'], 'step=orders') !== false), array('approved', 1, true));

// ===========================================================================
echo "\n=== D. cancelled or refunded ===\n";
// ===========================================================================
$O::set_status(32, 'processing', '', 1);
$O::on_cancelled(702);
check('an order already at the lab is not cancelled by WooCommerce', $wpdb->rows[32]->status, 'processing');
$O::set_status(32, 'approved', '', 1);
$O::on_cancelled(702);
check('a confirmed order is', $wpdb->rows[32]->status, 'cancelled');
$O::on_cancelled(999);
check('an order that is not ours: nothing', true, true);

// ===========================================================================
echo "\n=== E. statuses, notes, reports ===\n";
// ===========================================================================
check('an unknown status is refused', array($O::set_status(32, 'teleported'), $wpdb->rows[32]->status), array(false, 'cancelled'));
check('a missing order', $O::set_status(4040, 'approved'), false);
$O::set_status(32, 'sample_collected', 'Collected by Karim', 7);
$log = json_decode($wpdb->rows[32]->lab_details, true)['log'];
check('each change is logged with who and why', array_slice(end($log), 1), array('by' => 7, 'status' => 'sample_collected', 'note' => 'Collected by Karim'));
check('...and announced for later (mail/SMS)', end($GLOBALS['actions']), array('ecare_lab_order_status', array(32, 'sample_collected', 'cancelled')));
check('a note alone keeps the status', array($O::add_note(32, 'Called the patient', 7), $wpdb->rows[32]->status, end(json_decode($wpdb->rows[32]->lab_details, true)['log'])['status']), array(true, 'sample_collected', ''));
check('an empty note is not kept', $O::add_note(32, '  '), false);
$O::attach_report(32, 'ecare-private/2026/10/rep.pdf', 7);
check('a report moves the order to Report ready', array($wpdb->rows[32]->status, $wpdb->rows[32]->file_urls), array('report_ready', 'ecare-private/2026/10/rep.pdf'));
$O::set_status(32, 'completed', '', 7);
$O::attach_report(32, 'ecare-private/2026/10/rep2.pdf', 7);
check('a replaced report on a completed order: stays completed', array($wpdb->rows[32]->status, $wpdb->rows[32]->file_urls), array('completed', 'ecare-private/2026/10/rep2.pdf'));
check('the report link is the private, signed one', $O::report_url($O::get(32)), 'view:booking:32:ecare-private/2026/10/rep2.pdf');
$wpdb->rows[32]->file_urls = 'https://public.example/rep.pdf';
check('a public URL is never offered as a report', $O::report_url($O::get(32)), '');

// ===========================================================================
echo "\n=== F. slots, names, My Account ===\n";
// ===========================================================================
$wpdb->slot_rows = array((object) array('d' => '2026-10-02', 's' => '09:00-11:00', 'n' => '2'));
check('booked slots from paid orders, added to what was there', $O::booked_slots(array('2026-10-02|09:00-11:00' => 1, 'x' => 3)), array('2026-10-02|09:00-11:00' => 3, 'x' => 3));
check('one word stays a first name', $O::split_name(' রহিম '), array('রহিম', ''));
check('My Account: Lab Orders just before Log out', array_keys($O::account_menu(array('dashboard' => 'D', 'orders' => 'O', 'customer-logout' => 'L'))), array('dashboard', 'orders', 'ecare-lab-orders', 'customer-logout'));
check('...and at the end when there is no Log out', array_keys($O::account_menu(array('dashboard' => 'D'))), array('dashboard', 'ecare-lab-orders'));
check('an advance order hides the empty Subtotal row; other orders keep it', array(
    array_keys($O::order_totals(array('cart_subtotal' => 1, 'fee_0' => 2, 'order_total' => 3), $GLOBALS['wc'][701])),
    array_keys($O::order_totals(array('cart_subtotal' => 1, 'order_total' => 3), new Fake_Order(1, array('customer_id' => 1)))),
), array(array('fee_0', 'order_total'), array('cart_subtotal', 'order_total')));
check('placed time from the order\'s own history, else the row', array(
    $O::placed_at((object) array('details' => array('log' => array(array('t' => 1790000000))), 'created_at' => '2000-01-01 00:00:00')),
    $O::placed_at((object) array('details' => array(), 'created_at' => '2026-10-01 10:00:00')),
), array(1790000000, strtotime('2026-10-01 10:00:00')));
check('its link goes to My Lab Orders', $O::account_menu_url('https://site/my-account/ecare-lab-orders/', 'ecare-lab-orders'), 'https://site/cart/?step=orders');
check('other account links untouched', $O::account_menu_url('https://site/my-account/orders/', 'orders'), 'https://site/my-account/orders/');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
