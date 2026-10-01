<?php
/**
 * Guards ECare_Lab_Checkout_Page: the checkout form and its posts, run
 * against the real ECare_Lab_Checkout rules.
 *
 * What it protects:
 *   - every post needs this user's nonce; each button does its one job and
 *     keeps everything else typed into the form
 *   - Place Order: nothing is fired unless every check passes; on failure the
 *     page jumps to the first problem
 *   - pressing Enter can never delete an address (a harmless default button
 *     comes first)
 *   - an address the lab does not serve cannot be chosen
 *   - the summary on the page is the quote, and escapes what it prints
 */

define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);

$GLOBALS['uid'] = 5; $GLOBALS['umeta'] = array(); $GLOBALS['transients'] = array(); $GLOBALS['fired'] = array();
$GLOBALS['status'] = array(); $GLOBALS['coupons'] = array();

function add_action() {}
function do_action($h, ...$a) { $GLOBALS['fired'][] = array($h, $a); }
function apply_filters($h, $v) { return $v; }
function __($s, $d = null) { return $s; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_url($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_textarea($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_html__($s, $d = null) { return esc_html($s); }
function esc_attr__($s, $d = null) { return esc_attr($s); }
function esc_html_e($s, $d = null) { echo esc_html($s); }
function esc_attr_e($s, $d = null) { echo esc_attr($s); }
function checked($a, $b = true) { if ((string) $a === (string) $b) echo ' checked="checked"'; }
function selected($a, $b) { if ((string) $a === (string) $b) echo ' selected="selected"'; }
function disabled($c) { if ($c) echo ' disabled="disabled"'; }
function wp_create_nonce($a) { return 'nonce-' . $a . '-' . $GLOBALS['uid']; }
function wp_verify_nonce($n, $a) { return $n === 'nonce-' . $a . '-' . $GLOBALS['uid'] ? 1 : false; }
function admin_url($p = '') { return 'https://site/wp-admin/' . $p; }
function home_url($p = '') { return 'https://site' . $p; }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)); }
function sanitize_text_field($s) { return trim(preg_replace('/\s+/', ' ', strip_tags((string) $s))); }
function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); }
function wp_unslash($v) { return $v; }
function wp_json_encode($v) { return json_encode($v); }
function wp_date($f, $ts, $tz = null) { return (new DateTimeImmutable('@' . $ts))->setTimezone($tz)->format($f); }
function is_user_logged_in() { return true; }
function get_current_user_id() { return $GLOBALS['uid']; }
function wp_get_current_user() { return new class { public $first_name = 'Rahim'; public $last_name = 'Uddin'; public $display_name = 'rahim'; public function exists() { return true; } }; }
function get_userdata($id) { return (object) array('user_email' => 'rahim@example.com'); }
function get_user_meta($u, $k, $s = false) { return $GLOBALS['umeta'][$u][$k] ?? ''; }
function update_user_meta($u, $k, $v) { $GLOBALS['umeta'][$u][$k] = $v; return true; }
function delete_user_meta($u, $k) { unset($GLOBALS['umeta'][$u][$k]); return true; }
function get_transient($k) { return $GLOBALS['transients'][$k] ?? false; }
function set_transient($k, $v, $t) { $GLOBALS['transients'][$k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['transients'][$k]); return true; }
function get_the_title($id) { return array(99 => 'Popular <Lab>')[$id] ?? ''; }
function is_wp_error($x) { return false; }
function get_post_status($id) { return $GLOBALS['status'][$id] ?? false; }
function wc_get_order($id) { return $GLOBALS['wc_orders'][$id] ?? null; }
function wc_get_coupon_id_by_code($c) { return isset($GLOBALS['coupons'][$c]) ? 7 : 0; }

$GLOBALS['terms'] = array(
    50  => (object) array('term_id' => 50, 'name' => 'Dhaka', 'parent' => 5),
    501 => (object) array('term_id' => 501, 'name' => 'Dhanmondi', 'parent' => 50),
    502 => (object) array('term_id' => 502, 'name' => 'Mirpur', 'parent' => 50),
);
function get_term($id, $tax) { return $GLOBALS['terms'][$id] ?? null; }
class ECare_Locations { const TAXONOMY = 'ecare_location'; }

class ECare_Lab_Front {
    public static function url($w, $a = array()) { return 'https://site/' . $w . '/' . ($a ? '?' . http_build_query($a) : ''); }
    public static function login_url($b) { return 'https://site/login'; }
    public static function money($n) { $n = (float) $n; return '৳' . number_format($n, floor($n) == $n ? 0 : 2); }
    public static function icon($n) { return '<svg></svg>'; }
}
class ECare_Lab_Orders {
    const PENDING_META = '_ecare_lab_pending_order'; const ORDER_META = '_ecare_lab_booking_id';
    public static $calls = array(); public static $result = array('ok' => true, 'pay_url' => 'https://site/checkout/order-pay/77/?pay_for_order=true&key=wc_k');
    public static function create_from_checkout($u, $state, $v) { self::$calls[] = array($u, $state, $v); return self::$result; }
}
class ECare_Lab_Cart_Page {
    public static function area_options() { return array('Dhaka' => array(501 => 'Dhanmondi', 502 => 'Mirpur')); }
}
class ECare_Lab_Cart {
    public static $priced; public static $area = 0;
    public static function is_area($id) { return in_array((int) $id, array(501, 502), true); }
    public static function priced($u) { return self::$priced; }
    public static function get_area($u) { return self::$area; }
    public static function set_area($u, $a) { self::$area = (int) $a; return true; }
}
class ECare_Lab_Providers {
    public static function covers_area($lab, $area) { return (int) $area === 501; }   // Popular collects in Dhanmondi only
}
class ECare_Lab_Settings {
    public static $s = array('advance_percent' => 20, 'service_charge' => 0, 'hard_copy_fee' => 200, 'both_fee' => 250);
    public static $slots = array();
    public static function get($k) { return self::$s[$k] ?? null; }
    public static function delivery_fee($m) { return $m === 'hard' ? 200.0 : ($m === 'both' ? 250.0 : 0.0); }
    public static function advance_amount($t) { return (float) min($t, ceil($t * self::$s['advance_percent'] / 100)); }
    public static function open_slots($date, $now, $booked = array()) { return self::$slots[$date] ?? array(); }
    public static function bookable_dates($now, $booked = array()) { return array_keys(array_filter(self::$slots)); }
    public static function slot_label($s) { return 'L:' . $s; }
}
class WC_Coupon {
    public function __construct($c) {} public function get_id() { return 7; } public function get_code() { return 'SAVE10'; }
    public function get_discount_type() { return 'percent'; } public function get_amount() { return 10; }
    public function get_date_expires() { return null; } public function get_usage_limit() { return 0; } public function get_usage_count() { return 0; }
    public function get_usage_limit_per_user() { return 0; } public function get_used_by() { return array(); } public function get_email_restrictions() { return array(); }
    public function get_minimum_amount() { return $GLOBALS['coupon_min'] ?? 0; } public function get_maximum_amount() { return 0; } public function get_exclude_sale_items() { return false; }
    public function get_product_ids() { return array(); } public function get_product_categories() { return array(); }
}

class ECare_Lab_Pay { public static function url($o, $from = 'checkout') { return $o->get_checkout_payment_url() . '#via-lab-pay-' . $from; } }
require_once __DIR__ . '/../includes/class-ecare-lab-checkout.php';
require_once ($argv[1] ?? (__DIR__ . '/../includes/class-ecare-lab-checkout-page.php'));

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; printf("  PASS  %s\n", $label); }
    else { $fail++; printf("  FAIL  %s\n          got:  %s\n          want: %s\n", $label, var_export($got, true), var_export($want, true)); }
}
function has($h, $n) { return strpos($h, $n) !== false; }
$P = 'ECare_Lab_Checkout_Page'; $K = 'ECare_Lab_Checkout';
$N = 'nonce-ecare_lab_checkout-5';
$now = new DateTimeImmutable('2026-10-01 10:00', new DateTimeZone('Asia/Dhaka'));

ECare_Lab_Cart::$priced = array('provider_id' => 99, 'count' => 2, 'subtotal_mrp' => 1300.0, 'subtotal' => 1160.0, 'savings' => 140.0, 'material' => 60.0, 'lines' => array(
    array('test_id' => 101, 'title' => 'FBS <b>', 'patients' => 2, 'available' => true, 'price' => 380.0, 'mrp' => 450.0, 'line_total' => 760.0),
    array('test_id' => 89, 'title' => 'CBC', 'patients' => 1, 'available' => true, 'price' => 400.0, 'mrp' => 400.0, 'line_total' => 400.0),
));
ECare_Lab_Settings::$slots = array('2026-10-01' => array('15:00-17:00'), '2026-10-02' => array('09:00-11:00', '15:00-17:00'));

// ===========================================================================
echo "\n=== A. posts ===\n";
// ===========================================================================
$r = $P::apply(5, array('do' => 'place', 'name' => 'X'), $now);
check('no nonce: expired, nothing saved or fired', array($r['msg'], isset($GLOBALS['umeta'][5]), $GLOBALS['fired']), array('expired', false, array()));

$form = array('_ecl' => $N, 'name' => 'Rahim <script>', 'phone' => '+880 1712 345678', 'delivery' => 'hard', 'date' => '2026-10-02', 'slot' => '09:00-11:00', 'note' => 'Ring twice');
$r = $P::apply(5, $form + array('do' => 'add_address', 'new_label' => 'office', 'new_area' => '501', 'new_line' => 'House 12, Road 5'), $now);
check('add address: saved, chosen, message', array($r['msg'], $r['errors'], $r['state']['address_id'], $r['anchor']), array('address_added', array(), 1, 'ecl-co-address'));
check('...and everything else typed is kept', array_intersect_key($K::get_state(5), array_flip(array('name', 'phone', 'delivery', 'date', 'slot', 'note'))),
      array('delivery' => 'hard', 'date' => '2026-10-02', 'slot' => '09:00-11:00', 'name' => 'Rahim', 'phone' => '+880 1712 345678', 'note' => 'Ring twice'));
check('the chosen address sets the area everywhere else', ECare_Lab_Cart::$area, 501);
$r = $P::apply(5, $form + array('address_id' => 1, 'do' => 'add_address', 'new_area' => '', 'new_line' => 'Somewhere'), $now);
check('a bad new address: an error, the old choice kept', array($r['errors'], $r['state']['address_id']), array(array('new_address' => 'area'), 1));
$P::apply(5, $form + array('address_id' => 1, 'do' => 'add_address', 'new_area' => '502', 'new_line' => 'Mirpur 10 circle'), $now);

$f = $form + array('address_id' => 1);
$GLOBALS['status'][7] = 'publish'; $GLOBALS['coupons']['save10'] = true;
$r = $P::apply(5, $f + array('do' => 'coupon', 'coupon_input' => ' Save10 '), $now);
check('coupon applied', array($r['msg'], $r['state']['coupon']), array('coupon_applied', 'save10'));
$r = $P::apply(5, $f + array('do' => 'coupon', 'coupon_input' => 'nope'), $now);
check('unknown coupon: error, jumps to the summary', array($r['errors'], $r['anchor'], $r['state']['coupon']), array(array('coupon' => 'not_found'), 'ecl-co-sum', ''));
$GLOBALS['coupon_min'] = 5000;
$r = $P::apply(5, $f + array('do' => 'coupon', 'coupon_input' => 'save10'), $now);
check('below the minimum: the amount travels with the error', $r['errors'], array('coupon' => 'min', 'coupon_amount' => '5000'));
unset($GLOBALS['coupon_min']);
check('an empty code', $P::apply(5, $f + array('do' => 'coupon', 'coupon_input' => ''), $now)['errors'], array('coupon' => 'empty'));
$r = $P::apply(5, $f + array('coupon' => 'save10', 'do' => 'remove_coupon'), $now);
check('remove coupon', $r['state']['coupon'], '');
check('another button keeps an applied coupon', $P::apply(5, $f + array('coupon' => 'save10', 'do' => 'update'), $now)['state']['coupon'], 'save10');

$GLOBALS['fired'] = array();
$r = $P::apply(5, array('_ecl' => $N, 'address_id' => 2, 'name' => '', 'phone' => '0171', 'date' => '2026-10-02', 'slot' => '09:00-11:00', 'do' => 'place'), $now);
check('place with problems: nothing fired', $GLOBALS['fired'], array());
check('...every problem named, and the page jumps to the first', array($r['msg'], $r['errors'], $r['anchor']), array('fix', array('address' => 'lab_area', 'name' => 'missing', 'phone' => 'invalid'), 'ecl-co-address'));
$r = $P::apply(5, array_merge($f, array('slot' => '', 'do' => 'place')), $now);
check('only the time missing: jumps to the time', array($r['errors'], $r['anchor']), array(array('slot' => 'missing'), 'ecl-co-time'));

$r = $P::apply(5, $f + array('coupon' => 'save10', 'do' => 'place'), $now);
check('place: straight on to the pay step', array($r['msg'], $r['errors'], $r['redirect']), array('', array(), 'https://site/checkout/order-pay/77/?pay_for_order=true&key=wc_k'));
check('the order is made once, from the checked state and its quote', array(count(ECare_Lab_Orders::$calls), ECare_Lab_Orders::$calls[0][1]['phone'], ECare_Lab_Orders::$calls[0][2]['address']['id'], ECare_Lab_Orders::$calls[0][2]['quote']['advance']), array(1, '01712345678', 1, 261.0));
check('the ready hook fires once, with the cleaned phone and the quote', array(count($GLOBALS['fired']), $GLOBALS['fired'][0][0], $GLOBALS['fired'][0][1][1]['phone'], $GLOBALS['fired'][0][1][2]['quote']['total'], $GLOBALS['fired'][0][1][2]['quote']['coupon']),
      array(1, 'ecare_lab_checkout_ready', '01712345678', 1304.0, 116.0));
$GLOBALS['fired'] = array();
ECare_Lab_Orders::$result = array('ok' => false, 'code' => 'order');
$r = $P::apply(5, $f + array('do' => 'place'), $now);
check('the order could not be made: said so, no redirect', array($r['msg'], $r['redirect']), array('order_failed', ''));
ECare_Lab_Orders::$result = array('ok' => true, 'pay_url' => 'x'); ECare_Lab_Orders::$calls = array(); $GLOBALS['fired'] = array();
$r = $P::apply(5, array_merge($f, array('phone' => '', 'do' => 'place')), $now);
check('with a problem, no order is made', ECare_Lab_Orders::$calls, array());

$r = $P::apply(5, $f + array('del_address' => '1', 'do' => 'place'), $now);
check('× on an address deletes it (and does not place the order)', array($r['msg'], array_keys($K::addresses(5)), $r['state']['address_id'], $GLOBALS['fired']), array('address_deleted', array(2), 0, array()));
$P::apply(5, $form + array('do' => 'add_address', 'new_area' => '501', 'new_line' => 'House 12, Road 5'), $now);   // id 3, chosen

// ===========================================================================
echo "\n=== B. the page ===\n";
// ===========================================================================
$GLOBALS['transients']['ecare_lab_co_err_5'] = array('phone' => 'invalid');
$_GET = array();
$html = $P::render($now);
check('an error from the last post is shown once', array(has($html, 'Write a Bangladeshi mobile number'), isset($GLOBALS['transients']['ecare_lab_co_err_5'])), array(true, false));
$first_del = strpos($html, 'name="del_address"'); $default = strpos($html, 'class="ecl-co-default"');
check('the first button in the form is the harmless default, not an address ×', $default !== false && $default < $first_del && $default < strpos($html, 'name="do" value="place"'), true);
check('an address the lab does not serve: disabled and labelled', array((bool) preg_match('/value="2"[^>]*disabled/', $html), has($html, 'UNAVAILABLE for Popular &lt;Lab&gt;')), array(true, true));
check('the chosen address is checked', (bool) preg_match('/name="address_id" value="3" checked/', $html), true);
check('address text', has($html, 'House 12, Road 5, Dhanmondi, Dhaka'), true);
check('values are escaped', array(has($html, 'FBS &lt;b&gt;'), has($html, 'FBS <b>')), array(true, false));
check('delivery: hard copy chosen, fees shown', array((bool) preg_match('/value="hard" data-fee="200" checked/', $html), has($html, '+৳250')), array(true, true));
check('Today / Tomorrow on the date chips', array(has($html, '>Today</span>'), has($html, '>Tomorrow</span>')), array(true, true));
check('the saved slot is checked on its date', (bool) preg_match('/value="09:00-11:00" checked/', $html), true);
check('every date\'s slots are there for the script', has($html, esc_attr(json_encode(array('2026-10-01' => array(array('15:00-17:00', 'L:15:00-17:00')))))) || has($html, '&quot;2026-10-01&quot;:[[&quot;15:00-17:00&quot;'), true);
check('summary: MRP, special, material, delivery, total, advance, later', array(
    has($html, 'Subtotal (MRP)</dt><dd>৳1,300'), has($html, 'Special Discount</dt><dd>−৳140'), has($html, 'External Material Cost</dt><dd>৳60'),
    has($html, 'data-ecl-sum="delivery">৳200'), has($html, 'data-ecl-sum="total">৳1,420'), has($html, 'Advance Payable (20%)</dt><dd data-ecl-sum="advance">৳284'), has($html, 'data-ecl-sum="later">৳1,136'),
), array(true, true, true, true, true, true, true));
check('the script gets the total without delivery, and the percent', has($html, 'data-base="1220" data-pct="20"'), true);
check('no service charge: the row is left out', has($html, 'Service Charge'), false);
check('Place Order is live', (bool) preg_match('/value="place" class="[^"]*"\s*>/', $html), true);
check('Place Order names the advance it opens SSLCommerz for, kept in step by the script', array(
    has($html, 'Place Order <span class="ecl-cp-go-amt">— <span data-ecl-sum="advance">৳284</span></span></button>'), has($html, 'straight to SSLCommerz'),
), array(true, true));
$_GET = array('co_msg' => 'pay_cancelled');
check('back from a cancelled payment: the order is kept', has($P::render($now), 'Payment cancelled. Your order is saved'), true);
$_GET = array('co_msg' => 'pay_failed');
check('back from a failed payment: nothing charged, Pay now again', has($P::render($now), 'nothing was charged. Your order is saved'), true);
$_GET = array();

$st = $K::get_state(5); $st['coupon'] = 'save10'; $K::save_state(5, $st);
$html = $P::render($now);
check('an applied coupon: shown, carried in the form, taken off', array(has($html, '<strong>SAVE10</strong>'), has($html, 'name="coupon" value="save10"'), has($html, 'Coupon Discount</dt><dd>−৳116')), array(true, true, true));

$_GET = array('co_msg' => 'order_failed');
check('a failed order says nothing was charged', has($P::render($now), 'Nothing was charged'), true);
$_GET = array();
$GLOBALS['umeta'][5]['_ecare_lab_pending_order'] = 77;
$GLOBALS['wc_orders'][77] = new class { public $status = 'pending';
    public function has_status($s) { return in_array($this->status, (array) $s, true); }
    public function get_meta($k) { return $k === '_ecare_lab_booking_id' ? 31 : ''; }
    public function get_checkout_payment_url() { return 'https://site/pay/77'; } };
$html = $P::render($now);
check('an unpaid earlier order: named, with Pay now through the pay step', array(has($html, 'Order #31 is waiting for its advance payment.'), has($html, 'href="https://site/pay/77#via-lab-pay-checkout"')), array(true, true));
$GLOBALS['wc_orders'][77]->status = 'completed';
check('...and gone once it is paid', has($P::render($now), 'waiting for its advance'), false);
unset($GLOBALS['umeta'][5]['_ecare_lab_pending_order']);

ECare_Lab_Settings::$slots = array();
$html = $P::render($now);
check('no open times: said plainly, Place Order disabled', array(has($html, 'No collection times are open'), (bool) preg_match('/value="place" class="[^"]*" disabled/', $html)), array(true, true));

$GLOBALS['umeta'][5] = array();
ECare_Lab_Settings::$slots = array('2026-10-02' => array('09:00-11:00'));
$html = $P::render($now);
check('first visit: name from the account, the address form open', array(has($html, 'value="Rahim Uddin"'), has($html, '<details class="ecl-co-new" open>')), array(true, true));

ECare_Lab_Cart::$priced = array('provider_id' => 0, 'lines' => array(), 'count' => 0, 'subtotal_mrp' => 0.0, 'subtotal' => 0.0, 'savings' => 0.0, 'material' => 0.0);
check('empty cart: no form', array(has($P::render($now), '<form'), has($P::render($now), 'Your lab cart is empty')), array(false, true));

check('date label for a later day', $P::date_label('2026-10-05', $now), 'Mon, 5 Oct');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
