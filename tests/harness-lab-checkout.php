<?php
/**
 * Guards ECare_Lab_Checkout: address book, form state, phone numbers,
 * WooCommerce coupons, the payment summary and the final check.
 *
 * What it protects:
 *   - an address needs a real area and a real street line; at most 5; an
 *     address whose area was deleted disappears rather than breaking checkout
 *   - Bangladeshi mobile numbers in any common spelling, and nothing else
 *   - coupons: only WooCommerce rules that make sense without products, each
 *     one enforced (expiry, usage, per user, email, min/max, sale items)
 *   - the summary adds up: MRP - special + material - coupon + delivery +
 *     service = total; advance is the configured percent, rounded up
 *   - Place Order refuses anything missing or no longer true
 */

define('ABSPATH', __DIR__ . '/');

$GLOBALS['umeta'] = array(); $GLOBALS['status'] = array(); $GLOBALS['filters'] = array();

function get_user_meta($u, $k, $s = false) { return $GLOBALS['umeta'][$u][$k] ?? ''; }
function update_user_meta($u, $k, $v) { $GLOBALS['umeta'][$u][$k] = $v; return true; }
function delete_user_meta($u, $k) { unset($GLOBALS['umeta'][$u][$k]); return true; }
function sanitize_text_field($s) { return trim(preg_replace('/\s+/', ' ', strip_tags((string) $s))); }
function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); }
function is_wp_error($x) { return false; }
function get_post_status($id) { return $GLOBALS['status'][$id] ?? false; }
function apply_filters($h, $v) { return $GLOBALS['filters'][$h] ?? $v; }
function get_userdata($id) { return (object) array('user_email' => 'rahim@example.com'); }
function wc_get_coupon_id_by_code($c) { return isset($GLOBALS['coupons'][$c]) ? $GLOBALS['coupons'][$c]->id : 0; }

// Locations: 501 Dhanmondi, 502 Agrabad are areas under districts 50 (Dhaka), 60 (Chattogram).
$GLOBALS['terms'] = array(
    50  => (object) array('term_id' => 50, 'name' => 'Dhaka', 'parent' => 5),
    60  => (object) array('term_id' => 60, 'name' => 'Chattogram', 'parent' => 6),
    501 => (object) array('term_id' => 501, 'name' => 'Dhanmondi', 'parent' => 50),
    502 => (object) array('term_id' => 502, 'name' => 'Agrabad', 'parent' => 60),
);
function get_term($id, $tax) { return $GLOBALS['terms'][$id] ?? null; }
class ECare_Locations { const TAXONOMY = 'ecare_location'; }

class ECare_Lab_Cart {
    public static $priced;
    public static function is_area($id) { return in_array((int) $id, array(501, 502), true) && isset($GLOBALS['terms'][$id]); }
    public static function priced($u) { return self::$priced; }
}
class ECare_Lab_Providers {
    public static $cov = array(99 => array(501));
    public static function covers_area($lab, $area) { return in_array((int) $area, self::$cov[$lab] ?? array(), true); }
}
class ECare_Lab_Settings {
    public static $s = array('advance_percent' => 20, 'service_charge' => 0, 'hard_copy_fee' => 200, 'both_fee' => 250);
    public static $slots = array();
    public static function get($k) { return self::$s[$k] ?? null; }
    public static function delivery_fee($m) { return $m === 'hard' ? (float) self::$s['hard_copy_fee'] : ($m === 'both' ? (float) self::$s['both_fee'] : 0.0); }
    public static function advance_amount($t) { return (float) min($t, ceil($t * self::$s['advance_percent'] / 100)); }
    public static function open_slots($date, $now, $booked = array()) { return self::$slots[$date] ?? array(); }
    public static function bookable_dates($now, $booked = array()) { return array_keys(array_filter(self::$slots)); }
}

// A stand-in with WC_Coupon's getters.
class Fake_Coupon {
    public $id = 7, $code = 'SAVE10', $type = 'percent', $amount = 10, $expires = null, $limit = 0, $count = 0, $per = 0, $used_by = array();
    public $emails = array(), $min = 0, $max = 0, $sale = false, $products = array(), $cats = array();
    public function get_id() { return $this->id; }
    public function get_code() { return $this->code; }
    public function get_discount_type() { return $this->type; }
    public function get_amount() { return $this->amount; }
    public function get_date_expires() { return $this->expires; }
    public function get_usage_limit() { return $this->limit; }
    public function get_usage_count() { return $this->count; }
    public function get_usage_limit_per_user() { return $this->per; }
    public function get_used_by() { return $this->used_by; }
    public function get_email_restrictions() { return $this->emails; }
    public function get_minimum_amount() { return $this->min; }
    public function get_maximum_amount() { return $this->max; }
    public function get_exclude_sale_items() { return $this->sale; }
    public function get_product_ids() { return $this->products; }
    public function get_product_categories() { return $this->cats; }
}
class WC_Coupon extends Fake_Coupon { public function __construct($code) { foreach (get_object_vars($GLOBALS['coupons'][$code]) as $k => $v) { $this->$k = $v; } } }

require_once ($argv[1] ?? (__DIR__ . '/../includes/class-ecare-lab-checkout.php'));

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; printf("  PASS  %s\n", $label); }
    else { $fail++; printf("  FAIL  %s\n          got:  %s\n          want: %s\n", $label, var_export($got, true), var_export($want, true)); }
}
$K = 'ECare_Lab_Checkout';
const U = 5;

// ===========================================================================
echo "\n=== A. address book ===\n";
// ===========================================================================
check('empty', $K::addresses(U), array());
check('an area is required', $K::add_address(U, array('area_id' => 50, 'line' => 'House 12, Road 5')), array('ok' => false, 'code' => 'area'));
check('a street line is required', $K::add_address(U, array('area_id' => 501, 'line' => '  12 ')), array('ok' => false, 'code' => 'line'));
check('first address', $K::add_address(U, array('label' => 'home', 'area_id' => 501, 'line' => "House 12,\n  Road <b>5</b>")), array('ok' => true, 'id' => 1));
check('stored clean', $K::addresses(U)[1], array('id' => 1, 'label' => 'home', 'area_id' => 501, 'line' => 'House 12, Road 5'));
check('an unknown label becomes home', ($K::add_address(U, array('label' => 'castle', 'area_id' => 502, 'line' => 'Agrabad C/A 3')) + array())['id'], 2);
check('...stored as home', $K::addresses(U)[2]['label'], 'home');
check('a long line is cut at 200', (function () use ($K) { $K::add_address(U, array('area_id' => 501, 'line' => str_repeat('ক', 300))); return mb_strlen($K::addresses(U)[3]['line']); })(), 200);
$K::add_address(U, array('area_id' => 501, 'line' => 'Office floor 4'));
$K::add_address(U, array('area_id' => 501, 'line' => 'Mother\'s house'));
check('five fit, the sixth does not', $K::add_address(U, array('area_id' => 501, 'line' => 'One more place')), array('ok' => false, 'code' => 'full'));
$K::delete_address(U, 3);
check('delete one; the next id is never reused', array(array_keys($K::addresses(U)), $K::add_address(U, array('area_id' => 501, 'line' => 'New house here'))['id']), array(array(1, 2, 4, 5), 6));
check('text: line, area, district', $K::address_text($K::addresses(U)[1]), 'House 12, Road 5, Dhanmondi, Dhaka');
unset($GLOBALS['terms'][502]);
check('an address whose area was deleted drops out', array_keys($K::addresses(U)), array(1, 4, 5, 6));
$GLOBALS['terms'][502] = (object) array('term_id' => 502, 'name' => 'Agrabad', 'parent' => 60);
foreach (array_keys($K::addresses(U)) as $id) { $K::delete_address(U, $id); }
check('all deleted: the book is empty', $K::addresses(U), array());
check('...and a new address still gets a fresh id', $K::add_address(U, array('area_id' => 501, 'line' => 'Brand new home'))['id'], 7);
$K::delete_address(U, 7);
check('deleting the newest does not hand its id out again', $K::add_address(U, array('area_id' => 501, 'line' => 'Another new home'))['id'], 8);
$K::delete_address(U, 8);
$GLOBALS['umeta'][U]['_ecare_lab_addresses'] = array(3 => array('area_id' => 501, 'line' => 'old shape'));
check('a damaged book reads as empty', $K::addresses(U), array());
unset($GLOBALS['umeta'][U]['_ecare_lab_addresses']);

// ===========================================================================
echo "\n=== B. form state and phone ===\n";
// ===========================================================================
$s = $K::clean_state(array('address_id' => '-3', 'delivery' => 'pigeon', 'date' => '2026-10-02; drop', 'slot' => '09:00-11:00', 'name' => ' <b>Rahim</b> ', 'phone' => '+880 1712-345678x', 'coupon' => ' EID 2026! '));
check('state is cleaned', $s, array('address_id' => 0, 'delivery' => 'soft', 'date' => '', 'slot' => '09:00-11:00', 'name' => 'Rahim', 'phone' => '+880 1712-345678', 'note' => '', 'coupon' => 'eid2026'));
check('garbage state reads as defaults', $K::get_state(U)['delivery'], 'soft');
foreach (array('01712345678' => '01712345678', '+8801712345678' => '01712345678', '8801912345678' => '01912345678', '017-1234-5678' => '01712345678', '1712345678' => '01712345678',
               '01212345678' => '', '0171234567' => '', '017123456789' => '', 'abc' => '', '' => '') as $in => $want) {
    check('phone "' . $in . '"', $K::normalize_phone((string) $in), $want);
}

// ===========================================================================
echo "\n=== C. coupons ===\n";
// ===========================================================================
$GLOBALS['status'][7] = 'publish';
$c = new Fake_Coupon();
check('a plain percent coupon', $K::coupon_rules($c, U, 'rahim@example.com', 1000), array('ok' => true, 'code' => 'save10', 'type' => 'percent', 'amount' => 10.0, 'exclude_sale' => false));
$GLOBALS['status'][7] = 'draft';
check('a draft coupon does not exist', $K::coupon_rules($c, U, '', 1000)['error'], 'not_found');
$GLOBALS['status'][7] = 'publish';
$bad = function ($prop, $val) use ($c) { $x = clone $c; $x->$prop = $val; return $x; };
check('a product coupon type is refused', $K::coupon_rules($bad('type', 'fixed_product'), U, '', 1000)['error'], 'products');
check('a coupon tied to products is refused', $K::coupon_rules($bad('products', array(12)), U, '', 1000)['error'], 'products');
check('a coupon tied to categories is refused', $K::coupon_rules($bad('cats', array(3)), U, '', 1000)['error'], 'products');
check('expired', $K::coupon_rules($bad('expires', new DateTime('-1 day')), U, '', 1000)['error'], 'expired');
check('not yet expired', $K::coupon_rules($bad('expires', new DateTime('+1 day')), U, '', 1000)['ok'], true);
$x = $bad('limit', 5); $x->count = 5;
check('used up', $K::coupon_rules($x, U, '', 1000)['error'], 'used_up');
$x = $bad('per', 1); $x->used_by = array('5');
check('already used by this user (by id)', $K::coupon_rules($x, U, '', 1000)['error'], 'used_by_you');
$x->used_by = array('RAHIM@example.com');
check('already used by this user (by email)', $K::coupon_rules($x, U, 'rahim@example.com', 1000)['error'], 'used_by_you');
$x->used_by = array('9', 'other@example.com');
check('used by others only', $K::coupon_rules($x, U, 'rahim@example.com', 1000)['ok'], true);
check('email restriction with a wildcard', array(
    $K::coupon_rules($bad('emails', array('*@meditaj.com')), U, 'rahim@example.com', 1000)['error'] ?? 'ok',
    $K::coupon_rules($bad('emails', array('*@EXAMPLE.com')), U, 'rahim@example.com', 1000)['ok'],
), array('email', true));
check('below the minimum, with the amount', $K::coupon_rules($bad('min', 1500), U, '', 1000), array('ok' => false, 'error' => 'min', 'min' => 1500.0));
check('above the maximum', $K::coupon_rules($bad('max', 500), U, '', 1000)['error'], 'max');

$GLOBALS['coupons'] = array('save10' => $c);
check('load_coupon finds it case-insensitively', $K::load_coupon(' SAVE10 ', U, 1000)['ok'], true);
check('an unknown code', $K::load_coupon('nope', U, 1000), array('ok' => false, 'error' => 'not_found'));

$lines = array(
    array('available' => true, 'price' => 380.0, 'mrp' => 450.0, 'line_total' => 760.0),   // on sale
    array('available' => true, 'price' => 400.0, 'mrp' => 400.0, 'line_total' => 400.0),
    array('available' => false, 'price' => 0.0, 'mrp' => 0.0, 'line_total' => 0.0),
);
check('10% of the tests', $K::coupon_discount(array('ok' => true, 'type' => 'percent', 'amount' => 10, 'exclude_sale' => false), $lines), 116.0);
check('10%, sale items excluded', $K::coupon_discount(array('ok' => true, 'type' => 'percent', 'amount' => 10, 'exclude_sale' => true), $lines), 40.0);
check('fixed amount', $K::coupon_discount(array('ok' => true, 'type' => 'fixed_cart', 'amount' => 150, 'exclude_sale' => false), $lines), 150.0);
check('never more than the tests', $K::coupon_discount(array('ok' => true, 'type' => 'fixed_cart', 'amount' => 5000, 'exclude_sale' => false), $lines), 1160.0);
check('a percent above 100 is capped', $K::coupon_discount(array('ok' => true, 'type' => 'percent', 'amount' => 150, 'exclude_sale' => false), $lines), 1160.0);
check('no coupon, nothing off', $K::coupon_discount(null, $lines), 0.0);

// ===========================================================================
echo "\n=== D. the payment summary ===\n";
// ===========================================================================
$priced = array('provider_id' => 99, 'lines' => $lines, 'count' => 2, 'subtotal_mrp' => 1300.0, 'subtotal' => 1160.0, 'savings' => 140.0, 'material' => 60.0);
ECare_Lab_Settings::$s['service_charge'] = 50;
$q = $K::quote($priced, 'hard', array('ok' => true, 'code' => 'save10', 'type' => 'percent', 'amount' => 10, 'exclude_sale' => false));
check('every line', array($q['subtotal_mrp'], $q['special'], $q['material'], $q['coupon'], $q['delivery'], $q['service']), array(1300.0, 140.0, 60.0, 116.0, 200.0, 50.0));
check('total = 1160 + 60 - 116 + 200 + 50', $q['total'], 1354.0);
check('advance 20% rounded up; the rest later', array($q['advance'], $q['later'], $q['percent']), array(271.0, 1083.0, 20.0));
check('coupon code carried for the order', $q['coupon_code'], 'save10');
$q = $K::quote($priced, 'soft');
check('soft copy, no coupon', array($q['delivery'], $q['coupon'], $q['total'], $q['coupon_code']), array(0.0, 0.0, 1270.0, ''));
ECare_Lab_Settings::$s['service_charge'] = -30;
check('a negative service charge counts as none', $K::quote($priced, 'soft')['service'], 0.0);
ECare_Lab_Settings::$s['service_charge'] = 0;
ECare_Lab_Settings::$s['advance_percent'] = 100;
check('100% advance: nothing later', $K::quote($priced, 'both')['later'], 0.0);
ECare_Lab_Settings::$s['advance_percent'] = 20;

// ===========================================================================
echo "\n=== E. Place Order checks ===\n";
// ===========================================================================
$now = new DateTimeImmutable('2026-10-01 10:00');
ECare_Lab_Settings::$slots = array('2026-10-02' => array('09:00-11:00', '15:00-17:00'), '2026-10-03' => array());
check('schedule: only dates with open slots', array_keys($K::schedule($now)), array('2026-10-02'));
$GLOBALS['filters']['ecare_lab_booked_slots'] = array('2026-10-02|09:00-11:00' => 3);
check('booked slots come from the filter step 14 fills', $K::booked_slots(), array('2026-10-02|09:00-11:00' => 3));
unset($GLOBALS['filters']['ecare_lab_booked_slots']);

ECare_Lab_Cart::$priced = array('provider_id' => 0, 'lines' => array(), 'count' => 0, 'subtotal_mrp' => 0.0, 'subtotal' => 0.0, 'savings' => 0.0, 'material' => 0.0);
check('empty cart', $K::validate(U, $K::clean_state(array()), $now)['errors'], array('cart' => 'empty'));

ECare_Lab_Cart::$priced = $priced;
$K::add_address(U, array('area_id' => 501, 'line' => 'House 12, Road 5'));   // id 1 (the book above was reset)
$K::add_address(U, array('area_id' => 502, 'line' => 'Agrabad C/A 3'));      // id 2
$ok = $K::clean_state(array('address_id' => 1, 'delivery' => 'soft', 'date' => '2026-10-02', 'slot' => '15:00-17:00', 'name' => 'Rahim', 'phone' => '01712345678'));
$ECP = $priced; $ECP['lines'] = array_slice($lines, 0, 2);
ECare_Lab_Cart::$priced = $ECP;
$v = $K::validate(U, $ok, $now);
check('everything in order: no errors, a quote, the address', array($v['errors'], $v['quote']['total'], $v['address']['id']), array(array(), 1220.0, 1));

ECare_Lab_Cart::$priced = $priced;
check('a test the lab dropped', $K::validate(U, $ok, $now)['errors'], array('cart' => 'unavailable'));
ECare_Lab_Cart::$priced = $ECP;

$e = $K::validate(U, array('address_id' => 2) + $ok, $now)['errors'];
check('the lab does not collect at that address', $e, array('address' => 'lab_area'));
check('no address', $K::validate(U, array('address_id' => 99) + $ok, $now)['errors'], array('address' => 'missing'));
check('name and phone', $K::validate(U, array('name' => '', 'phone' => '12345') + $ok, $now)['errors'], array('name' => 'missing', 'phone' => 'invalid'));
check('phone missing', $K::validate(U, array('phone' => '') + $ok, $now)['errors'], array('phone' => 'missing'));
check('a closed date', $K::validate(U, array('date' => '2026-10-03') + $ok, $now)['errors'], array('date' => 'missing'));
check('a slot that is not open that day', $K::validate(U, array('slot' => '07:00-09:00') + $ok, $now)['errors'], array('slot' => 'missing'));
ECare_Lab_Settings::$slots['2026-10-02'] = array('09:00-11:00');
check('a slot that filled up since it was chosen', $K::validate(U, $ok, $now)['errors'], array('slot' => 'missing'));
ECare_Lab_Settings::$slots['2026-10-02'] = array('09:00-11:00', '15:00-17:00');
$v = $K::validate(U, array('coupon' => 'save10') + $ok, $now);
check('a good coupon is applied', array($v['errors'], $v['quote']['coupon']), array(array(), 116.0));
$GLOBALS['coupons']['save10']->expires = new DateTime('-1 hour');
$v = $K::validate(U, array('coupon' => 'save10') + $ok, $now);
check('a coupon that expired since: refused, and not applied', array($v['errors'], $v['quote']['coupon']), array(array('coupon' => 'expired'), 0.0));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
