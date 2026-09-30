<?php
/**
 * Guards ECare_Lab_Cart_Page: the [ecare_lab_cart] screen and its form posts.
 *
 * What it protects:
 *   - every action needs this user's nonce; each posted action reaches the
 *     right cart call, and switching lab is checked against the saved area
 *   - the area list only offers real areas, grouped by district
 *   - a lab or test that cannot be booked says UNAVAILABLE and why, and
 *     cannot be chosen; a dropped test gets no stepper and is not charged
 *   - Proceed to Checkout is a link only when nothing blocks it
 *   - the page never caches, and escapes what it prints
 */

define('ABSPATH', __DIR__ . '/');

$GLOBALS['uid'] = 5; $GLOBALS['posts'] = array(); $GLOBALS['meta'] = array(); $GLOBALS['actions'] = array();

function add_shortcode() {} function add_action() {}
function do_action($h) { $GLOBALS['actions'][] = $h; }
function __($s, $d = null) { return $s; }
function _n($a, $b, $n, $d = null) { return $n == 1 ? $a : $b; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_url($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_html__($s, $d = null) { return esc_html($s); }
function esc_attr__($s, $d = null) { return esc_attr($s); }
function esc_html_e($s, $d = null) { echo esc_html($s); }
function esc_attr_e($s, $d = null) { echo esc_attr($s); }
function selected($a, $b) { if ((string) $a === (string) $b) echo ' selected="selected"'; }
function disabled($c) { if ($c) echo ' disabled="disabled"'; }
function wp_create_nonce($a) { return 'nonce-' . $a . '-' . $GLOBALS['uid']; }
function wp_verify_nonce($n, $a) { return $n === 'nonce-' . $a . '-' . $GLOBALS['uid'] ? 1 : false; }
function admin_url($p = '') { return 'https://site/wp-admin/' . $p; }
function home_url($p = '') { return 'https://site' . $p; }
function add_query_arg($k, $v, $url) { return $url . (strpos($url, '?') === false ? '?' : '&') . $k . '=' . $v; }
function remove_query_arg($k, $url) { return $url; }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)); }
function wp_unslash($v) { return $v; }
function is_user_logged_in() { return !empty($GLOBALS['uid']); }
function get_current_user_id() { return (int) $GLOBALS['uid']; }
function get_the_title($id) { return array(99 => 'Popular', 103 => 'LabAid <Dhaka>', 120 => 'Ibn Sina', 101 => 'FBS <b>', 89 => 'CBC', 105 => 'Diabetes Care')[$id] ?? ''; }
function get_the_post_thumbnail_url($id, $s) { return $id === 99 ? 'https://site/popular.png' : ''; }
function is_wp_error($x) { return false; }
function is_singular() { return true; }
function get_post() { return $GLOBALS['current']; }
function get_post_meta($id, $k, $s = false) { return $GLOBALS['meta'][$id][$k] ?? ''; }
function nocache_headers() { $GLOBALS['nocache'] = true; }
$GLOBALS['terms'] = array();
function get_terms($a) { return $GLOBALS['terms']; }

class ECare_Locations { const TAXONOMY = 'ecare_location'; }
class ECare_Lab_Front {
    public static function url($w, $a = array()) { return 'https://site/' . $w . '/' . ($a ? '?' . http_build_query($a) : ''); }
    public static function login_url($b) { return 'https://site/login?to=' . $b; }
    public static function money($n) { $n = (float) $n; return '৳' . number_format($n, floor($n) == $n ? 0 : 2); }
    public static function icon($n) { return '<svg></svg>'; }
    public static function page_id($w) { return 0; }
}
class ECare_Lab_Cart {
    const MAX_PATIENTS = 10;
    public static $calls = array(); public static $priced; public static $vendors = array(); public static $problems = array();
    public static $area = 0; public static $switch = array('ok' => true);
    public static function priced($u) { return self::$priced; }
    public static function vendors($u, $a) { self::$calls[] = array('vendors', $a); return self::$vendors; }
    public static function problems($u, $a) { return self::$problems; }
    public static function get_area($u) { return self::$area; }
    public static function set_area($u, $a) { self::$calls[] = array('set_area', $u, $a); return $a === 101; }
    public static function set_patients($u, $t, $n) { self::$calls[] = array('set_patients', $u, $t, $n); }
    public static function remove($u, $t) { self::$calls[] = array('remove', $u, $t); }
    public static function clear($u) { self::$calls[] = array('clear', $u); }
    public static function switch_lab($u, $l, $a) { self::$calls[] = array('switch_lab', $u, $l, $a); return self::$switch; }
}

require_once ($argv[1] ?? (__DIR__ . '/../includes/class-ecare-lab-cart-page.php'));

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; printf("  PASS  %s\n", $label); }
    else { $fail++; printf("  FAIL  %s\n          got:  %s\n          want: %s\n", $label, var_export($got, true), var_export($want, true)); }
}
$P = 'ECare_Lab_Cart_Page';
$N = 'nonce-ecare_lab_cart-5';
function has($html, $needle) { return strpos($html, $needle) !== false; }

// ===========================================================================
echo "\n=== A. form posts ===\n";
// ===========================================================================
check('no nonce: nothing happens', array($P::apply(5, array('do' => 'clear')), ECare_Lab_Cart::$calls), array('expired', array()));
check("another user's nonce: nothing happens", array($P::apply(5, array('_ecl' => 'nonce-ecare_lab_cart-6', 'do' => 'clear')), ECare_Lab_Cart::$calls), array('expired', array()));
check('patients: quietly updated', array($P::apply(5, array('_ecl' => $N, 'do' => 'patients', 'test' => '101', 'n' => '3')), ECare_Lab_Cart::$calls), array('', array(array('set_patients', 5, 101, 3))));
ECare_Lab_Cart::$calls = array();
check('remove', array($P::apply(5, array('_ecl' => $N, 'do' => 'remove', 'test' => '89')), ECare_Lab_Cart::$calls), array('removed', array(array('remove', 5, 89))));
ECare_Lab_Cart::$calls = array();
check('clear', array($P::apply(5, array('_ecl' => $N, 'do' => 'Clear')), ECare_Lab_Cart::$calls), array('cleared', array(array('clear', 5))));
ECare_Lab_Cart::$calls = array(); ECare_Lab_Cart::$area = 101;
check('change lab: checked against the saved area', array($P::apply(5, array('_ecl' => $N, 'do' => 'lab', 'lab' => '103')), ECare_Lab_Cart::$calls), array('switched', array(array('switch_lab', 5, 103, 101))));
ECare_Lab_Cart::$switch = array('ok' => false, 'code' => 'area');
check('a refused switch says why', $P::apply(5, array('_ecl' => $N, 'do' => 'lab', 'lab' => '99')), 'switch_area');
check('every refusal code has a message', array_diff(array('switch_area', 'switch_missing', 'switch_empty'), array_keys($P::messages())), array());
ECare_Lab_Cart::$calls = array();
check('area saved', array($P::apply(5, array('_ecl' => $N, 'do' => 'area', 'area' => '101')), ECare_Lab_Cart::$calls[0]), array('area', array('set_area', 5, 101)));
check('not an area', $P::apply(5, array('_ecl' => $N, 'do' => 'area', 'area' => '10')), 'bad_area');
ECare_Lab_Cart::$calls = array();
check('an unknown action does nothing', array($P::apply(5, array('_ecl' => $N, 'do' => 'drop_table')), ECare_Lab_Cart::$calls), array('', array()));

// ===========================================================================
echo "\n=== B. the area list ===\n";
// ===========================================================================
function term($id, $name, $parent) { return (object) array('term_id' => $id, 'name' => $name, 'parent' => $parent); }
$GLOBALS['terms'] = array(
    term(1, 'Dhaka Division', 0), term(2, 'Chattogram Division', 0),
    term(10, 'Dhaka', 1), term(20, 'Chattogram', 2), term(30, 'Gazipur', 1),
    term(100, 'Mirpur', 10), term(101, 'Dhanmondi', 10), term(200, 'Agrabad', 20), term(102, 'mirpur-10', 10),
);
check('areas only, grouped by district, both sorted; empty districts left out', $P::area_options(), array(
    'Chattogram' => array(200 => 'Agrabad'),
    'Dhaka'      => array(101 => 'Dhanmondi', 100 => 'Mirpur', 102 => 'mirpur-10'),
));

// ===========================================================================
echo "\n=== C. logged out, empty ===\n";
// ===========================================================================
$GLOBALS['uid'] = 0;
$html = $P::render();
check('logged out: a login link that comes back, and no forms', array(has($html, 'https://site/login?to=https://site/cart/'), has($html, '<form')), array(true, false));
$GLOBALS['uid'] = 5;
ECare_Lab_Cart::$priced = array('provider_id' => 0, 'lines' => array(), 'count' => 0, 'subtotal_mrp' => 0.0, 'subtotal' => 0.0, 'savings' => 0.0, 'material' => 0.0);
$_GET = array('cart_msg' => 'cleared');
$html = $P::render();
check('empty: says so, offers the tests, and shows the message', array(has($html, 'Your lab cart is empty'), has($html, 'https://site/tests/'), has($html, 'Your lab cart is empty now.')), array(true, true, true));
check('empty: no checkout button', has($html, 'Proceed to Checkout'), false);

// ===========================================================================
echo "\n=== D. a full cart ===\n";
// ===========================================================================
ECare_Lab_Cart::$priced = array('provider_id' => 103, 'count' => 2, 'subtotal_mrp' => 1400.0, 'subtotal' => 1210.0, 'savings' => 190.0, 'material' => 60.0, 'lines' => array(
    array('test_id' => 101, 'title' => 'FBS <b>', 'patients' => 1, 'available' => true, 'price' => 380.0, 'mrp' => 450.0, 'line_total' => 380.0),
    array('test_id' => 89, 'title' => 'CBC', 'patients' => 10, 'available' => true, 'price' => 83.0, 'mrp' => 95.0, 'line_total' => 830.0),
    array('test_id' => 105, 'title' => 'Diabetes Care', 'patients' => 2, 'available' => false, 'price' => 0.0, 'mrp' => 0.0, 'line_total' => 0.0),
));
ECare_Lab_Cart::$vendors = array(
    array('id' => 103, 'total' => 1210.0, 'mrp' => 1400.0, 'savings' => 190.0, 'missing' => array(), 'serves_area' => true, 'available' => true, 'reason' => '', 'current' => true),
    array('id' => 99, 'total' => 1300.0, 'mrp' => 1300.0, 'savings' => 0.0, 'material' => 40.0, 'missing' => array(), 'serves_area' => true, 'available' => true, 'reason' => '', 'current' => false),
    array('id' => 120, 'total' => 900.0, 'mrp' => 900.0, 'savings' => 0.0, 'missing' => array(89, 105), 'serves_area' => true, 'available' => false, 'reason' => 'missing', 'current' => false),
    array('id' => 777, 'total' => 1000.0, 'mrp' => 1000.0, 'savings' => 0.0, 'missing' => array(), 'serves_area' => false, 'available' => false, 'reason' => 'area', 'current' => false),
);
ECare_Lab_Cart::$problems = array('unavailable');
ECare_Lab_Cart::$area = 101;
$_GET = array('cart_msg' => '<script>');
$html = $P::render();

check('an unknown message code shows nothing', array(has($html, 'ecl-notice'), has($html, '<script>')), array(false, false));
check('the Change list is asked for with the saved area', end(ECare_Lab_Cart::$calls), array('vendors', 101));
check('names are escaped', array(has($html, 'FBS &lt;b&gt;'), has($html, 'FBS <b>'), has($html, 'LabAid &lt;Dhaka&gt;')), array(true, false, true));
check('title counts the tests', has($html, 'Lab Cart <span>(3 tests)</span>'), true);
check('the saved area is selected, grouped by district', array(has($html, '<option value="101" selected="selected">Dhanmondi'), has($html, '<optgroup label="Chattogram">')), array(true, true));

check('1 patient: minus disabled, plus posts 2', array((bool) preg_match('/value="0" class="ecl-step-btn"[^>]*disabled/', $html), has($html, 'name="n" value="2"')), array(true, true));
check('10 patients: plus disabled', (bool) preg_match('/value="11" class="ecl-step-btn"[^>]*disabled/', $html), true);
check('a dropped test: UNAVAILABLE with the reason, no stepper, still removable', array(
    has($html, 'LabAid &lt;Dhaka&gt; no longer offers this test.'),
    substr_count($html, 'name="test" value="105"'),
    has($html, 'aria-label="Remove Diabetes Care"'),
), array(true, 1, true));
check('line totals', array(has($html, '>৳380</strong>'), has($html, '>৳830</strong>')), array(true, true));

check('Change list starts closed', has($html, 'id="ecl-vendors" hidden'), true);
check('the current lab is marked, not re-selectable', array(has($html, 'Selected</span>'), has($html, 'name="lab" value="103"')), array(true, false));
check('another able lab can be chosen', has($html, 'name="lab" value="99"'), true);
check('a lab with material cost says so beside its price', array(has($html, '+ ৳40 material cost'), substr_count($html, 'material cost</small>')), array(true, 1));
check('a lab lacking tests: UNAVAILABLE, names them, cannot be chosen', array(has($html, 'Does not offer: CBC, Diabetes Care'), has($html, 'name="lab" value="120"')), array(true, false));
check('a lab outside the area: says where, cannot be chosen', array(has($html, 'Does not collect in Dhanmondi, Dhaka'), has($html, 'name="lab" value="777"')), array(true, false));

check('summary: MRP, special discount, material, total with material', array(
    has($html, 'Subtotal (MRP)</dt><dd>৳1,400'), has($html, 'Special Discount</dt><dd>−৳190'), has($html, 'External Material Cost</dt><dd>৳60'), has($html, 'Total</dt><dd>৳1,270'),
), array(true, true, true, true));
check('blocked: the reason is shown, the button is disabled, no checkout link', array(
    has($html, 'Remove the tests marked unavailable'), (bool) preg_match('/ecl-cp-go" disabled/', $html), has($html, 'step=checkout'),
), array(true, true, false));
check('Add More Tests keeps to the same lab', has($html, 'https://site/tests/?lab=103'), true);
check('every form carries the action and this user\'s nonce', substr_count($html, '<form') === substr_count($html, 'value="nonce-ecare_lab_cart-5"') && substr_count($html, '<form') === substr_count($html, 'name="action" value="ecare_lab_cart"'), true);
check('forms post to admin-post.php', substr_count($html, '<form method="post" action="https://site/wp-admin/admin-post.php"'), substr_count($html, '<form'));

ECare_Lab_Cart::$problems = array('lab_area');
$_GET = array('change' => '1');
$html = $P::render();
check('?change=1 opens the list without JavaScript', array(has($html, 'id="ecl-vendors" hidden'), has($html, 'id="ecl-vendors">')), array(false, true));
check('the chosen lab is flagged for the area, and the reason names both', array(has($html, 'UNAVAILABLE in your area'), has($html, 'LabAid &lt;Dhaka&gt; does not collect samples in Dhanmondi, Dhaka.')), array(true, true));

ECare_Lab_Cart::$problems = array();
$_GET = array();
$html = $P::render();
check('nothing blocks: Proceed is a link to checkout', (bool) preg_match('/<a class="ecl-btn ecl-btn-lg ecl-cp-go" href="https:\/\/site\/cart\/\?step=checkout"/', $html), true);
check('no discount, no material: those rows are left out', (function () use ($P) {
    ECare_Lab_Cart::$priced['savings'] = 0.0; ECare_Lab_Cart::$priced['material'] = 0.0;
    $h = $P::render();
    return array(has($h, 'Special Discount'), has($h, 'External Material Cost'));
})(), array(false, false));
$GLOBALS['terms'] = array();
check('no areas set up at all: no area box', has($P::render(), 'ecl-cp-area-sel'), false);

$_GET = array('step' => 'checkout');
check('the checkout step is a placeholder until step 13', has($P::render(), 'Checkout is almost ready'), true);
$_GET = array();

// ===========================================================================
echo "\n=== E. caching ===\n";
// ===========================================================================
$GLOBALS['current'] = (object) array('ID' => 7, 'post_content' => '[ecare_lab_cart]');
check('the cart page', $P::is_cart_page(), true);
$GLOBALS['current'] = (object) array('ID' => 8, 'post_content' => '');
$GLOBALS['meta'][8]['_elementor_data'] = '[{"widgetType":"ecare_lab_cart"}]';
check('an Elementor cart page', $P::is_cart_page(), true);
$GLOBALS['current'] = (object) array('ID' => 9, 'post_content' => '[ecare_lab_catalog]');
check('another lab page', $P::is_cart_page(), false);
$P::no_cache();
check('...is left cacheable', array(isset($GLOBALS['nocache']), defined('DONOTCACHEPAGE')), array(false, false));
$GLOBALS['current'] = (object) array('ID' => 7, 'post_content' => '[ecare_lab_cart]');
$P::no_cache();
check('the cart page is never cached (headers, WP constant, LiteSpeed)', array($GLOBALS['nocache'] ?? false, defined('DONOTCACHEPAGE'), in_array('litespeed_control_set_nocache', $GLOBALS['actions'], true)), array(true, true, true));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
