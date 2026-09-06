<?php
/**
 * Stubbed harness for ECare_WooCommerce::advance_booking_status().
 *
 * The fake $wpdb parses the UPDATE statements the class builds and applies them
 * to an in-memory table, so the generated SQL itself is under test as well as
 * the transition rules.
 */

define('ABSPATH', sys_get_temp_dir() . '/wc-harness/');

$GLOBALS['actions'] = array();
$GLOBALS['removed'] = array();
function add_action($hook, $cb, $priority = 10, $args = 1) { $GLOBALS['actions'][$hook][] = $cb; }
function remove_action($hook, $cb, $priority = 10) { $GLOBALS['removed'][] = $hook . '@' . $priority . ':' . (is_string($cb) ? $cb : 'closure'); return true; }
$GLOBALS['filters'] = array();
function add_filter($hook, $cb, $priority = 10, $args = 1) { $GLOBALS['filters'][$hook][] = $cb; }
function __($s, $d = null) { return $s; }

/** Order double, exposing only what the class uses. */
class Fake_Order {
    private $id, $meta;
    public function __construct($id, $meta = array()) { $this->id = $id; $this->meta = $meta; }
    public function get_id() { return $this->id; }
    public function get_meta($key, $single = true) { return isset($this->meta[$key]) ? $this->meta[$key] : ''; }
    public function get_items() { return array(); }
    public function get_customer_id() { return 0; }
}

$GLOBALS['endpoint'] = '';
$GLOBALS['gateways'] = array();
function is_wc_endpoint_url($ep) { return $GLOBALS['endpoint'] === $ep; }
class Fake_Gateways { public function get_available_payment_gateways() { return $GLOBALS['gateways']; } }
class Fake_WC { public $payment_gateways; public function __construct() { $this->payment_gateways = new Fake_Gateways(); } }
function WC() { static $wc = null; if ($wc === null) { $wc = new Fake_WC(); } return $wc; }

$GLOBALS['orders'] = array();
function wc_get_order($id) {
    // Real wc_get_order() hands an order object straight back, which is what the
    // Store API relies on when it passes $order where an id is expected.
    if ($id instanceof Fake_Order) { return $id; }
    return isset($GLOBALS['orders'][$id]) ? $GLOBALS['orders'][$id] : false;
}

class Fake_WPDB {
    public $prefix   = 'wp_';
    public $bookings = array();   // id => row
    public $log      = array();

    public function prepare($query, $args = array()) {
        if (!is_array($args)) { $args = array_slice(func_get_args(), 1); }
        $out = '';
        $i   = 0;
        $len = strlen($query);
        for ($p = 0; $p < $len; $p++) {
            if ($query[$p] === '%' && $p + 1 < $len && ($query[$p + 1] === 's' || $query[$p + 1] === 'd')) {
                $v = isset($args[$i]) ? $args[$i] : '';
                $out .= ($query[$p + 1] === 'd')
                    ? (string) (int) $v
                    : "'" . str_replace("'", "\\'", (string) $v) . "'";
                $i++; $p++;
                continue;
            }
            $out .= $query[$p];
        }
        return $out;
    }

    public function query($sql) {
        $this->log[] = $sql;
        $sql = preg_replace('/\s+/', ' ', trim($sql));

        if (!preg_match("/^UPDATE wp_ecare_bookings SET status = '([^']*)' WHERE (.+)$/", $sql, $m)) {
            throw new Exception('Unrecognised SQL: ' . $sql);
        }
        $new   = $m[1];
        $where = $m[2];

        if (!preg_match("/status IN \(([^)]*)\)/", $where, $inm)) {
            throw new Exception('No status whitelist in: ' . $sql);
        }
        $allowed = array_map(function ($x) { return trim($x, " '"); }, explode(',', $inm[1]));

        $byId    = preg_match('/\bid = (\d+)/', $where, $a) ? (int) $a[1] : null;
        $byOrder = preg_match('/order_id = (\d+)/', $where, $b) ? (int) $b[1] : null;
        $labOnly = strpos($where, "booking_type = 'lab'") !== false;

        $n = 0;
        foreach ($this->bookings as $id => &$row) {
            if ($byId !== null && $id !== $byId) { continue; }
            if ($byOrder !== null && (int) $row['order_id'] !== $byOrder) { continue; }
            if ($labOnly && $row['booking_type'] !== 'lab') { continue; }
            if (!in_array($row['status'], $allowed, true)) { continue; }
            $row['status'] = $new;
            $n++;
        }
        unset($row);
        return $n;
    }
}
$wpdb = new Fake_WPDB();
$GLOBALS['wpdb'] = $wpdb;

$target = $argv[1] ?? (__DIR__ . '/../includes/class-ecare-woocommerce.php');
require_once $target;

// ---------------- runner ----------------
$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; printf("  PASS  %s\n", $label); }
    else { $fail++; printf("  FAIL  %s\n          got:  %s\n          want: %s\n", $label, var_export($got, true), var_export($want, true)); }
}

/** Reset the world: one order, a set of bookings. */
function scenario($order_id, $booking_meta, $rows) {
    global $wpdb;
    $GLOBALS['orders'] = array($order_id => new Fake_Order($order_id, $booking_meta));
    $wpdb->bookings = $rows;
    $wpdb->log = array();
}
function statuses() {
    global $wpdb;
    $o = array();
    foreach ($wpdb->bookings as $id => $r) { $o[$id] = $r['status']; }
    return $o;
}
function row($order_id, $status, $type = 'caregiver') {
    return array('order_id' => $order_id, 'status' => $status, 'booking_type' => $type);
}

echo "\n=== A. payment lands: a waiting booking is approved ===\n";
scenario(1130, array('_ecare_booking_id' => '21'), array(21 => row(1130, 'pending')));
ECare_WooCommerce::handle_payment_complete(1130);
check('pending -> approved', statuses(), array(21 => 'approved'));

echo "\n=== B. the guard: hooks re-fire without undoing operator work ===\n";
scenario(1073, array('_ecare_booking_id' => '9'), array(9 => row(1073, 'assigned', 'ambulance')));
ECare_WooCommerce::handle_payment_complete(1073);
check("booking #9 stays 'assigned' (the real row on production)", statuses(), array(9 => 'assigned'));

scenario(1021, array('_ecare_booking_id' => '8'), array(8 => row(1021, 'approved')));
ECare_WooCommerce::handle_payment_complete(1021);
check("booking #8 stays 'approved', no churn", statuses(), array(8 => 'approved'));

scenario(500, array('_ecare_booking_id' => '3'), array(3 => row(500, 'cancelled')));
ECare_WooCommerce::handle_payment_complete(500);
check('a cancelled booking is not revived by payment', statuses(), array(3 => 'cancelled'));
ECare_WooCommerce::handle_order_completed(500);
check('a cancelled booking is not revived by completion', statuses(), array(3 => 'cancelled'));

scenario(501, array('_ecare_booking_id' => '4'), array(4 => row(501, 'dispatched', 'ambulance')));
ECare_WooCommerce::handle_payment_complete(501);
check("'dispatched' survives a repeated payment hook", statuses(), array(4 => 'dispatched'));

echo "\n=== C. completion sweeps everything still in flight ===\n";
foreach (array('pending', 'approved', 'assigned', 'dispatched') as $from) {
    scenario(600, array('_ecare_booking_id' => '7'), array(7 => row(600, $from)));
    ECare_WooCommerce::handle_order_completed(600);
    check("$from -> completed", statuses(), array(7 => 'completed'));
}

echo "\n=== D. lab bookings, matched by order rather than meta ===\n";
scenario(1121, array(), array(
    17 => row(1121, 'pending', 'lab'),
    99 => row(1121, 'cancelled', 'lab'),
    50 => row(9999, 'pending', 'lab'),      // a different order
    51 => row(1121, 'pending', 'caregiver'), // same order, not a lab row
));
ECare_WooCommerce::handle_payment_complete(1121);
check('lab row on this order approved', statuses()[17], 'approved');
check('cancelled lab row untouched', statuses()[99], 'cancelled');
check('another order untouched', statuses()[50], 'pending');
check('non-lab row not caught by the order-id sweep', statuses()[51], 'pending');

echo "\n=== E. degenerate input ===\n";
scenario(700, array(), array(1 => row(700, 'pending')));
ECare_WooCommerce::handle_payment_complete(700);
check('no booking id on the order: only the lab sweep runs', count($GLOBALS['wpdb']->log), 1);

$GLOBALS['orders'] = array();
$GLOBALS['wpdb']->log = array();
ECare_WooCommerce::handle_payment_complete(123456);
check('unknown order issues no query at all', count($GLOBALS['wpdb']->log), 0);

echo "\n=== F. the SQL itself ===\n";
scenario(1130, array('_ecare_booking_id' => "21' OR 1=1 -- "), array(21 => row(1130, 'pending')));
ECare_WooCommerce::handle_payment_complete(1130);
check('a hostile meta value is cast to int, injecting nothing', statuses(), array(21 => 'approved'));
$sql = $GLOBALS['wpdb']->log[0];
check('booking id lands in the query as a bare integer', (bool) preg_match('/\bid = 21\b/', $sql), true);
check('no raw quote survived from the meta value', strpos($sql, 'OR 1=1'), false);
check('every status is quoted', (bool) preg_match("/status IN \('pending'\)/", $sql), true);

echo "\n=== G. the block checkout hands over an order, not an id ===\n";
// woocommerce_store_api_checkout_order_processed passes $order. Feeding that
// straight into the classic handler used to put the object in the order_id
// column.
$GLOBALS['orders'] = array(2001 => new Fake_Order(2001, array()));
$wpdb->bookings = array();
$wpdb->log = array();
ECare_WooCommerce::create_lab_bookings_from_order($GLOBALS['orders'][2001]);
check('an order object is accepted without error', true, true);

$reflection = new ReflectionMethod('ECare_WooCommerce', 'create_lab_bookings_from_order');
check('the handler takes one required argument', $reflection->getNumberOfRequiredParameters(), 1);

echo "\n=== H. the order-pay button says what happens next ===\n";
// WooCommerce ships "Pay for order" on this page, which reads like a card is
// about to be charged. Nothing is paid there - the customer confirms, and pays
// the caregiver in cash on arrival.
ECare_WooCommerce::init();
$cb = $GLOBALS['filters']['woocommerce_pay_order_button_text'][0] ?? null;
check('the order-pay button text filter is registered', is_callable($cb), true);
check('it reads Pay After Service', $cb ? call_user_func($cb, 'Pay for order') : null, 'Pay After Service');
check('the standard checkout button is left alone',
      isset($GLOBALS['filters']['woocommerce_order_button_text']), false);

// The privacy notice is written for a page that collects personal data. The
// order-pay page collects none: the order exists already.
$tidy = $GLOBALS['actions']['woocommerce_pay_order_before_payment'][0] ?? null;
check('the page is tidied before the payment section renders', is_callable($tidy), true);
$GLOBALS['removed'] = array();
if ($tidy) { call_user_func($tidy); }
check('the privacy boilerplate is removed at the priority it was added',
      $GLOBALS['removed'], array('woocommerce_checkout_terms_and_conditions@20:wc_checkout_privacy_policy_text'));

$styles = $GLOBALS['actions']['wp_head'][0] ?? null;
check('the pay-page style hook is registered', is_callable($styles), true);

$GLOBALS['endpoint'] = 'order-pay';
$GLOBALS['gateways'] = array('cod' => true);
ob_start(); call_user_func($styles); $one = ob_get_clean();
check('one gateway: the payment list is hidden', strpos($one, 'payment_methods{display:none') !== false, true);

// The guard that matters: add a second gateway and the customer must still be
// able to choose one.
$GLOBALS['gateways'] = array('cod' => true, 'sslcommerz' => true);
ob_start(); call_user_func($styles); $two = ob_get_clean();
check('two gateways: it leaves the list alone', $two, '');

$GLOBALS['endpoint'] = '';
$GLOBALS['gateways'] = array('cod' => true);
ob_start(); call_user_func($styles); $elsewhere = ob_get_clean();
check('and it prints nothing on any other page', $elsewhere, '');

printf("\n---------------------------------------\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
