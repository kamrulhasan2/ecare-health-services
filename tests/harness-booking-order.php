<?php
/**
 * Guards how a booking becomes a WooCommerce order - finding #21.
 *
 * The product carrying the price used to be keyed on the display name alone, so
 * one caregiver had a single product shared by all four packages, with its
 * price rewritten on each booking. Two people booking the same caregiver on
 * different packages at the same moment could end up with one order's line item
 * priced from the other's package. The order total hid it, because it was
 * stamped on with set_total() instead of summed from the lines.
 */

define('ABSPATH', __DIR__ . '/');

function add_action() {} function add_filter() {}
function __($s, $d = null) { return $s; }
function sanitize_title($s) {
    $s = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', (string) $s), '-'));
    return $s;
}
function class_exists_wc() { return true; }

$GLOBALS['products'] = array();   // sku => product
$GLOBALS['orders']   = array();
$GLOBALS['next_id']  = 1000;

class WC_Product_Simple {
    public $id, $name, $sku, $price, $regular, $virtual = false, $visibility = '';
    public function __construct() { $this->id = ++$GLOBALS['next_id']; }
    public function set_name($v) { $this->name = $v; }
    public function get_name() { return $this->name; }
    public function set_sku($v) { $this->sku = $v; }
    public function get_sku() { return $this->sku; }
    public function set_price($v) { $this->price = (float) $v; }
    public function get_price() { return $this->price; }
    public function set_regular_price($v) { $this->regular = (float) $v; }
    public function set_virtual($v) { $this->virtual = $v; }
    public function set_catalog_visibility($v) { $this->visibility = $v; }
    public function get_id() { return $this->id; }
    public function save() { $GLOBALS['products'][$this->sku] = $this; return $this->id; }
}
class WooCommerce {}

function wc_get_product_id_by_sku($sku) {
    return isset($GLOBALS['products'][$sku]) ? $GLOBALS['products'][$sku]->get_id() : 0;
}
function wc_get_product($id) {
    foreach ($GLOBALS['products'] as $p) { if ($p->get_id() === $id) { return $p; } }
    return false;
}

class Fake_Order {
    public $id, $items = array(), $total = null, $meta = array(), $status = '', $address = array();
    public function __construct($id) { $this->id = $id; }
    public function get_id() { return $this->id; }
    /** Mirrors WC: the line is priced from the product at the moment it is added. */
    public function add_product($product, $qty = 1) {
        $this->items[] = array('sku' => $product->get_sku(), 'name' => $product->get_name(), 'line_total' => $product->get_price() * $qty);
    }
    public function calculate_totals($and_taxes = true) {
        $this->total = 0.0;
        foreach ($this->items as $i) { $this->total += $i['line_total']; }
        return $this->total;
    }
    public function set_total($v) { $this->total = (float) $v; }
    public function get_total() { return $this->total; }
    public function update_meta_data($k, $v) { $this->meta[$k] = $v; }
    public function set_address($a, $t) { $this->address = $a; }
    public function set_status($s) { $this->status = $s; }
    public function save() { $GLOBALS['orders'][$this->id] = $this; }
    public function get_checkout_payment_url() { return '/pay/' . $this->id; }
    public function get_items() { return array(); }
}
function wc_create_order($args = array()) { return new Fake_Order(++$GLOBALS['next_id']); }

require_once ($argv[1] ?? (__DIR__ . '/../includes/class-ecare-ajax.php'));

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; printf("  PASS  %s\n", $label); }
    else { $fail++; printf("  FAIL  %s\n          got:  %s\n          want: %s\n", $label, var_export($got, true), var_export($want, true)); }
}

$make = new ReflectionMethod('ECare_Ajax', 'create_woocommerce_order');
$make->setAccessible(true);
$order = function ($amount, $name, $key) use ($make) {
    return $make->invoke(null, 0, $amount, $name, 1, array(), $key);
};

echo "\n=== the collision: one display name, two price points ===\n";
// Deliberately the OLD display name, with no package in it. Before this fix the
// SKU was derived from that name, so both packages landed on one product and
// the second booking rewrote the first one's price. The sku_key is what keeps
// them apart now, not the nicer name.
$SHARED = 'Caregiver Booking - Rina Begum';
$a = $order(1700,  $SHARED, 'cg922-daily-12-hours');
$b = $order(50000, $SHARED, 'cg922-monthly-24-hours');
check('same name, two distinct SKUs', $a->items[0]['sku'] !== $b->items[0]['sku'], true);
check('cheap package keeps its own price', $a->items[0]['line_total'], 1700.0);
check('expensive package keeps its own price', $b->items[0]['line_total'], 50000.0);
check('the cheap order was not repriced by the expensive one', $a->get_total(), 1700.0);

echo "\n=== interleaved requests, as two visitors would produce ===\n";
$one   = $order(1700,  $SHARED, 'cg922-daily-12-hours');
$two   = $order(50000, $SHARED, 'cg922-monthly-24-hours');
$three = $order(1700,  $SHARED, 'cg922-daily-12-hours');
check('the first order is untouched by the second', $one->items[0]['line_total'], 1700.0);
check('and its total too', $one->get_total(), 1700.0);
check('a later booking of the same package still prices right', $three->items[0]['line_total'], 1700.0);

echo "\n=== renaming a caregiver reuses the same product ===\n";
$r1 = $order(1700, 'Caregiver Booking - Rina Begum (Daily (12 Hours))', 'cg922-daily-12-hours');
$r2 = $order(1700, 'Caregiver Booking - Rina B. (Daily (12 Hours))',    'cg922-daily-12-hours');
check('the SKU survives a rename', $r1->items[0]['sku'], $r2->items[0]['sku']);

echo "\n=== different caregivers stay separate ===\n";
$c = $order(1700, $SHARED, 'cg919-daily-12-hours');
check('per-caregiver SKUs differ', $c->items[0]['sku'] !== $a->items[0]['sku'], true);

echo "\n=== the line items and the order total agree ===\n";
foreach (array($a, $b, $c) as $i => $o) {
    $sum = 0.0;
    foreach ($o->items as $it) { $sum += $it['line_total']; }
    check("order " . ($i + 1) . ": total equals the sum of its lines", $o->get_total(), $sum);
}

echo "\n=== ambulance types get one product each ===\n";
$s1 = $order(1500, 'Ambulance Booking - Standard', 'amb-standard');
$s2 = $order(3000, 'Ambulance Booking - ICU', 'amb-icu');
check('standard and ICU differ', $s1->items[0]['sku'] !== $s2->items[0]['sku'], true);
check('ICU priced at 3000', $s2->items[0]['line_total'], 3000.0);
check('SKU shape is readable', $s2->items[0]['sku'], 'ecare-booking-amb-icu');

echo "\n=== an administrator changing a package price still flows through ===\n";
$before = $order(1700, 'Caregiver Booking - Rina Begum (Daily (12 Hours))', 'cg922-daily-12-hours');
$after  = $order(1900, 'Caregiver Booking - Rina Begum (Daily (12 Hours))', 'cg922-daily-12-hours');
check('the new price is used', $after->items[0]['line_total'], 1900.0);
check('and the total follows it', $after->get_total(), 1900.0);

printf("\n---------------------------------------\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
