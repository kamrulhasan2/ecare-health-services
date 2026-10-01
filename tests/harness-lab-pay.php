<?php
/**
 * Guards ECare_Lab_Pay: Place Order / Pay now go straight to SSLCommerz.
 *
 * What it protects:
 *   - with an enabled SSLCommerz gateway, pay links go to our pay step;
 *     without one, to WooCommerce's order-pay page as before
 *   - only the patient who owns an unpaid lab order can open a payment for
 *     it, and only with a valid nonce; nothing else reaches SSLCommerz
 *   - the session carries the order number as tran_id and the plugin's own
 *     success URL, so the gateway's success handler / IPN still confirm it
 *   - the patient only ever leaves the site for an https sslcommerz.com page;
 *     any other reply brings them back with "nothing was charged"
 *   - WooCommerce's order-pay page is skipped for a payable lab order
 */

define('ABSPATH', __DIR__ . '/');

class Redirected extends Exception { public $url; public function __construct($u) { $this->url = $u; } }
function add_action() {}
function __($s, $d = null) { return $s; }
function add_query_arg($args, $url, $u3 = null) { if (!is_array($args)) { $args = array($args => $url); $url = $u3; } return $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($args); }
function admin_url($p = '') { return 'https://site/wp-admin/' . $p; }
function wp_create_nonce($a) { return 'N:' . $a; }
function wp_verify_nonce($n, $a) { return $n === 'N:' . $a ? 1 : false; }
function wc_get_order($id) { return $GLOBALS['orders'][$id] ?? false; }
function get_permalink($id) { return 'https://site/thanks-' . $id . '/'; }
function get_site_url() { return 'https://site'; }
function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
function wc_format_decimal($n, $d) { return number_format((float) $n, $d, '.', ''); }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function is_wp_error($x) { return $x instanceof WP_Error; }
class WP_Error { public $m; public function __construct($c, $m) { $this->m = $m; } public function get_error_message() { return $this->m; } }
function wp_remote_post($url, $args) { $GLOBALS['api'][] = array($url, $args['body']); return $GLOBALS['reply']; }
function wp_remote_retrieve_response_code($r) { return $r['response']['code'] ?? 0; }
function wp_remote_retrieve_body($r) { return $r['body'] ?? ''; }
function is_user_logged_in() { return !empty($GLOBALS['uid']); }
function get_current_user_id() { return (int) ($GLOBALS['uid'] ?? 0); }
function is_wc_endpoint_url($e) { return ($GLOBALS['endpoint'] ?? '') === $e; }
function get_query_var($k) { return $GLOBALS['qv'][$k] ?? ''; }
function absint($n) { return abs((int) $n); }
function wp_safe_redirect($u) { throw new Redirected($u); }

class ECare_Lab_Orders { const ORDER_META = '_ecare_lab_booking_id'; }
class ECare_Lab_Front { public static function url($w, $a = array()) { return 'https://site/lab-cart/' . ($a ? '?' . http_build_query($a) : ''); }
    public static function login_url($b) { return 'https://site/login'; } }
class ECare_Lab_Checkout_Page { public static function url($a = array()) { return 'https://site/lab-cart/?' . http_build_query(array('step' => 'checkout') + $a); } }

class WC_Gateway_SSLCommerz {
    public $opts = array('enabled' => 'yes', 'store_id' => 'store1', 'store_password' => 'pw', 'testmode' => 'yes', 'redirect_page_id' => '9');
    public function get_option($k) { return $this->opts[$k] ?? ''; }
}
class Fake_Countries { public function get_countries() { return array('BD' => 'Bangladesh'); } }
class Fake_Gateways { public function payment_gateways() { return $GLOBALS['gateways']; } }
class Fake_WC { public $countries; public function __construct() { $this->countries = new Fake_Countries(); } public function payment_gateways() { return new Fake_Gateways(); } }
function WC() { static $wc; return $wc ?: ($wc = new Fake_WC()); }

class Fake_Order {
    public $id; public $user; public $status = 'pending'; public $total = 94.0; public $meta = array(); public $pm = ''; public $saved = 0; public $notes = array();
    public function __construct($id, $user, $booking) { $this->id = $id; $this->user = $user; if ($booking) { $this->meta['_ecare_lab_booking_id'] = $booking; } }
    public function get_id() { return $this->id; }
    public function get_meta($k) { return $this->meta[$k] ?? ''; }
    public function get_customer_id() { return $this->user; }
    public function has_status($s) { return in_array($this->status, (array) $s, true); }
    public function get_total() { return $this->total; }
    public function get_currency() { return 'BDT'; }
    public function get_checkout_payment_url() { return 'https://site/checkout/order-pay/' . $this->id . '/?pay_for_order=true&key=k'; }
    public function get_billing_first_name() { return 'EG'; } public function get_billing_last_name() { return 'Kamrul'; }
    public function get_billing_email() { return 'p@x.com'; } public function get_billing_phone() { return '01601780015'; }
    public function get_billing_address_1() { return 'kazipara'; } public function get_billing_city() { return 'Dhaka'; }
    public function get_billing_state() { return ''; } public function get_billing_postcode() { return '1000'; } public function get_billing_country() { return 'BD'; }
    public function get_payment_method() { return $this->pm; }
    public function set_payment_method($g) { $this->pm = is_object($g) ? 'sslcommerz' : $g; }
    public function save() { $this->saved++; }
    public function add_order_note($n) { $this->notes[] = $n; }
}

require_once ($argv[1] ?? (__DIR__ . '/../includes/class-ecare-lab-pay.php'));

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; printf("  PASS  %s\n", $label); }
    else { $fail++; printf("  FAIL  %s\n          got:  %s\n          want: %s\n", $label, var_export($got, true), var_export($want, true)); }
}
function ok_reply($url = 'https://sandbox.sslcommerz.com/EasyCheckOut/testcde123') {
    return array('response' => array('code' => 200), 'body' => json_encode(array('status' => 'SUCCESS', 'GatewayPageURL' => $url)));
}
$P = 'ECare_Lab_Pay';
$gw = new WC_Gateway_SSLCommerz();
$GLOBALS['gateways'] = array('sslcommerz' => $gw, 'cod' => new stdClass());
$GLOBALS['orders'] = array(1353 => new Fake_Order(1353, 5, 89), 1400 => new Fake_Order(1400, 6, 90), 50 => new Fake_Order(50, 5, 0));
$o = $GLOBALS['orders'][1353];

echo "\n=== A. where pay links go ===\n";
check('gateway on: our pay step, with the order, where to come back and a nonce for this order',
    $P::url($o, 'orders'), 'https://site/wp-admin/admin-post.php?action=ecare_lab_pay&order=1353&from=orders&_wpnonce=N%3Aecare_lab_pay_1353');
check('an unknown "from" is the checkout', strpos($P::url($o, 'zzz'), 'from=checkout') !== false, true);
$gw->opts['enabled'] = 'no';
check('gateway disabled: WooCommerce\'s order-pay page, as before', $P::url($o), $o->get_checkout_payment_url());
$gw->opts['enabled'] = 'yes'; $gw->opts['store_id'] = '';
check('gateway without a store id counts as off', $P::gateway(), null);
$gw->opts['store_id'] = 'store1';
$GLOBALS['gateways'] = array('cod' => new stdClass());
check('no SSLCommerz installed: off', $P::gateway(), null);
$GLOBALS['gateways'] = array('sslcommerz' => $gw);

echo "\n=== B. who may pay ===\n";
check('the owner, unpaid, with an advance', $P::payable($o, 5), true);
check('someone else', $P::payable($o, 6), false);
check('nobody logged in', $P::payable($o, 0), false);
check('not a lab order', $P::payable($GLOBALS['orders'][50], 5), false);
$o->status = 'failed';
check('a failed attempt can be paid again', $P::payable($o, 5), true);
$o->status = 'processing';
check('already paid: no', $P::payable($o, 5), false);
$o->status = 'pending'; $o->total = 0.0;
check('nothing to pay: no', $P::payable($o, 5), false);
$o->total = 94.0;

echo "\n=== C. a pay request ===\n";
$GLOBALS['api'] = array(); $GLOBALS['reply'] = ok_reply();
check('a wrong nonce: back to the checkout, nothing sent', array($P::decide(1353, 'N:other', 5, 'checkout'), count($GLOBALS['api'])),
    array(array('url' => 'https://site/lab-cart/?step=checkout&co_msg=pay_expired', 'external' => false), 0));
check('someone else\'s order: My Lab Orders, nothing sent', array($P::decide(1400, 'N:ecare_lab_pay_1400', 5, 'checkout'), count($GLOBALS['api'])),
    array(array('url' => 'https://site/lab-cart/?step=orders', 'external' => false), 0));
check('an order that does not exist: My Lab Orders', $P::decide(999, 'N:ecare_lab_pay_999', 5, 'orders')['url'], 'https://site/lab-cart/?step=orders');
$o->status = 'processing';
check('already paid: shown where it stands, nothing sent', array($P::decide(1353, 'N:ecare_lab_pay_1353', 5, 'orders'), count($GLOBALS['api'])),
    array(array('url' => 'https://site/lab-cart/?step=orders&placed=89', 'external' => false), 0));
$o->status = 'pending';

$r = $P::decide(1353, 'N:ecare_lab_pay_1353', 5, 'checkout');
check('payable: straight to the SSLCommerz page, the order marked as paid by SSLCommerz', array($r, $o->pm, $o->saved),
    array(array('url' => 'https://sandbox.sslcommerz.com/EasyCheckOut/testcde123', 'external' => true), 'sslcommerz', 1));
list($api, $f) = $GLOBALS['api'][0];
check('test mode: the sandbox API', $api, 'https://sandbox.sslcommerz.com/gwprocess/v4/api.php');
check('the session: the order number as tran_id, the advance, BDT', array($f['tran_id'], $f['total_amount'], $f['currency'], $f['store_id'], $f['store_passwd']), array('1353', '94.00', 'BDT', 'store1', 'pw'));
check('...the plugin\'s own success URL and IPN, so it confirms the payment', array($f['success_url'], $f['ipn_url']),
    array('https://site/thanks-9/?wc-api=WC_Gateway_SSLCommerz', 'https://site/easyCheckout.php?sslcommerzipn'));
check('...fail and cancel come back to the page they left, order kept', array($f['fail_url'], $f['cancel_url']),
    array('https://site/lab-cart/?step=checkout&co_msg=pay_failed', 'https://site/lab-cart/?step=checkout&co_msg=pay_cancelled'));
check('...the patient, as SSLCommerz requires', array($f['cus_name'], $f['cus_email'], $f['cus_phone'], $f['cus_add1'], $f['cus_city'], $f['cus_postcode'], $f['cus_country']),
    array('EG Kamrul', 'p@x.com', '01601780015', 'kazipara', 'Dhaka', '1000', 'Bangladesh'));
check('...one item named for the lab order, not the WooCommerce cart', array($f['product_name'], $f['num_of_item'], $f['shipping_method'], $f['value_a']),
    array('Lab test advance - order #89', 1, 'NO', '89'));

$gw->opts['testmode'] = 'no'; $GLOBALS['api'] = array();
$P::decide(1353, 'N:ecare_lab_pay_1353', 5, 'orders');
check('live mode: the live API; from My Lab Orders, back there', array($GLOBALS['api'][0][0], $GLOBALS['api'][0][1]['cancel_url']),
    array('https://securepay.sslcommerz.com/gwprocess/v4/api.php', 'https://site/lab-cart/?step=orders&pay=cancelled'));
$gw->opts['testmode'] = 'yes';

$GLOBALS['reply'] = array('response' => array('code' => 200), 'body' => json_encode(array('status' => 'FAILED', 'failedreason' => 'Store Credential Error')));
$o->notes = array();
check('SSLCommerz refuses: back with "nothing was charged", reason noted on the order',
    array($P::decide(1353, 'N:ecare_lab_pay_1353', 5, 'checkout'), $o->notes),
    array(array('url' => 'https://site/lab-cart/?step=checkout&co_msg=pay_failed', 'external' => false), array('SSLCommerz could not open a payment page: Store Credential Error')));
$GLOBALS['reply'] = new WP_Error('x', 'cURL error 28');
check('no connection: the same', $P::decide(1353, 'N:ecare_lab_pay_1353', 5, 'orders')['url'], 'https://site/lab-cart/?step=orders&pay=failed');
$GLOBALS['reply'] = array('response' => array('code' => 500), 'body' => '');
check('a server error: the same', $P::decide(1353, 'N:ecare_lab_pay_1353', 5, 'checkout')['external'], false);
$GLOBALS['reply'] = ok_reply('https://evil.example/pay');
check('a "payment page" that is not SSLCommerz: never followed', $P::decide(1353, 'N:ecare_lab_pay_1353', 5, 'checkout')['external'], false);
$gw->opts['enabled'] = 'no'; $GLOBALS['api'] = array();
check('gateway off: WooCommerce\'s order-pay page, nothing sent', array($P::decide(1353, 'N:ecare_lab_pay_1353', 5, 'checkout'), count($GLOBALS['api'])),
    array(array('url' => $o->get_checkout_payment_url(), 'external' => false), 0));
$gw->opts['enabled'] = 'yes';

echo "\n=== D. only SSLCommerz pages ===\n";
check('https sandbox / securepay / bare domain', array($P::is_gateway_url('https://sandbox.sslcommerz.com/x'), $P::is_gateway_url('https://securepay.sslcommerz.com/x'), $P::is_gateway_url('https://sslcommerz.com/x')), array(true, true, true));
check('http, look-alikes, junk: no', array($P::is_gateway_url('http://sandbox.sslcommerz.com/x'), $P::is_gateway_url('https://evilsslcommerz.com/x'),
    $P::is_gateway_url('https://sslcommerz.com.evil.io/x'), $P::is_gateway_url(''), $P::is_gateway_url('javascript:alert(1)')), array(false, false, false, false, false));

echo "\n=== E. WooCommerce's order-pay page ===\n";
function visit() { try { ECare_Lab_Pay::skip_order_pay(); } catch (Redirected $e) { return $e->url; } return null; }
$GLOBALS['endpoint'] = 'order-pay'; $GLOBALS['qv'] = array('order-pay' => '1353'); $GLOBALS['uid'] = 5;
check('a payable lab order: on to the pay step (back to My Lab Orders on failure)', visit(), $P::url($o, 'orders'));
$GLOBALS['uid'] = 6;
check('someone else: left to WooCommerce', visit(), null);
$GLOBALS['uid'] = 0;
check('logged out: left to WooCommerce (it asks for a login)', visit(), null);
$GLOBALS['uid'] = 5; $GLOBALS['qv'] = array('order-pay' => '50');
check('not a lab order: left alone', visit(), null);
$GLOBALS['qv'] = array('order-pay' => '1353'); $gw->opts['enabled'] = 'no';
check('gateway off: left alone, so it cannot loop', visit(), null);
$gw->opts['enabled'] = 'yes'; $GLOBALS['endpoint'] = 'view-order';
check('other pages: left alone', visit(), null);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
