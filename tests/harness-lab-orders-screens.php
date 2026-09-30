<?php
/**
 * Guards the two Lab Orders screens: the admin's status/note update and the
 * patient's My Lab Orders.
 *
 * What it protects:
 *   - admin: a status change goes through ECare_Lab_Orders (so it is logged);
 *     a note alone is a note; an unknown status is refused
 *   - patient: Pay advance appears only for an unpaid order whose WooCommerce
 *     order can still be paid; View report only once the report is ready;
 *     the progress line marks where the order is; everything is escaped;
 *     admin notes are never shown to the patient
 */

define('ABSPATH', __DIR__ . '/');

function add_action() {}
function __($s, $d = null) { return $s; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_url($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_html__($s, $d = null) { return esc_html($s); }
function esc_attr__($s, $d = null) { return esc_attr($s); }
function esc_html_e($s, $d = null) { echo esc_html($s); }
function esc_attr_e($s, $d = null) { echo esc_attr($s); }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)); }
function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); }
function home_url($p = '') { return 'https://site' . $p; }
function get_current_user_id() { return 5; }
function wp_date($f, $ts) { return gmdate($f, $ts); }
$GLOBALS['wc'] = array();
function wc_get_order($id) { return $GLOBALS['wc'][$id] ?? null; }

class ECare_Lab_Front {
    public static function url($w, $a = array()) { return 'https://site/' . $w . '/' . ($a ? '?' . http_build_query($a) : ''); }
    public static function money($n) { $n = (float) $n; return '৳' . number_format($n, floor($n) == $n ? 0 : 2); }
    public static function icon($n) { return '<svg></svg>'; }
}
class ECare_Lab_Settings { public static function slot_label($s) { return 'L:' . $s; } }
class ECare_Lab_Orders {
    public static $rows = array(); public static $calls = array();
    public static function statuses() { return array('pending' => 'Pending payment', 'approved' => 'Confirmed', 'sample_collected' => 'Sample collected', 'processing' => 'At the lab', 'report_ready' => 'Report ready', 'completed' => 'Completed', 'cancelled' => 'Cancelled'); }
    public static function status_label($s) { return self::statuses()[$s] ?? $s; }
    public static function get($id) { return self::$rows[$id] ?? null; }
    public static function for_user($u) { return array_values(self::$rows); }
    public static function set_status($id, $s, $note, $by) { self::$calls[] = array('status', $id, $s, $note, $by); return isset(self::statuses()[$s]); }
    public static function add_note($id, $note, $by) { self::$calls[] = array('note', $id, $note, $by); return true; }
    public static function placed_at($row) { return (int) ($row->details['log'][0]['t'] ?? 0); }
    public static function report_url($row) { return $row->file_urls ? 'https://site/view?ref=' . $row->file_urls : ''; }
}
class Fake_WC { public $status; public function __construct($s) { $this->status = $s; }
    public function has_status($s) { return in_array($this->status, (array) $s, true); }
    public function get_checkout_payment_url() { return 'https://site/pay/701'; } }

require_once __DIR__ . '/../admin/class-ecare-lab-orders-admin.php';
require_once ($argv[1] ?? (__DIR__ . '/../includes/class-ecare-lab-orders-page.php'));

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; printf("  PASS  %s\n", $label); }
    else { $fail++; printf("  FAIL  %s\n          got:  %s\n          want: %s\n", $label, var_export($got, true), var_export($want, true)); }
}
function has($h, $n) { return strpos($h, $n) !== false; }
function order($id, $status, $extra = array()) {
    return (object) ($extra + array(
        'id' => $id, 'status' => $status, 'order_id' => 701, 'file_urls' => '', 'created_at' => '2026-10-01 10:00:00', 'total_amount' => 1304, 'is_new' => true,
        'details' => array(
            'lab' => array('id' => 99, 'name' => 'Popular <Lab>'), 'date' => '2026-10-02', 'slot' => '09:00-11:00',
            'address' => array('text' => 'House 12, Dhanmondi'),
            'items' => array(array('title' => 'FBS <b>', 'patients' => 2, 'price' => 380, 'line_total' => 760), array('title' => 'CBC', 'patients' => 1, 'price' => 400, 'line_total' => 400)),
            'quote' => array('total' => 1304.0, 'advance' => 261.0, 'later' => 1043.0, 'special' => 140.0, 'coupon' => 116.0, 'delivery' => 200.0, 'material' => 0.0),
            'log' => array(array('t' => 1790000000, 'by' => 0, 'status' => 'pending', 'note' => 'Order placed'), array('t' => 1790000100, 'by' => 7, 'status' => '', 'note' => 'Patient is rude')),
        ),
    ));
}
$A = 'ECare_Lab_Orders_Admin'; $P = 'ECare_Lab_Orders_Page';

// ===========================================================================
echo "\n=== A. admin: status and notes ===\n";
// ===========================================================================
ECare_Lab_Orders::$rows = array(31 => order(31, 'approved'));
check('missing order', $A::apply_update(99, array('status' => 'completed'), 1), 'missing');
check('status change, with the note as its reason', array($A::apply_update(31, array('status' => 'Sample_Collected', 'note' => ' Karim <b>collected</b> '), 1), ECare_Lab_Orders::$calls), array('status', array(array('status', 31, 'sample_collected', 'Karim collected', 1))));
ECare_Lab_Orders::$calls = array();
check('same status + a note: only a note', array($A::apply_update(31, array('status' => 'approved', 'note' => 'Called'), 1), ECare_Lab_Orders::$calls), array('note', array(array('note', 31, 'Called', 1))));
ECare_Lab_Orders::$calls = array();
check('an unknown status is refused', $A::apply_update(31, array('status' => 'teleported'), 1), 'bad_status');
check('nothing to do', $A::apply_update(31, array('status' => 'approved', 'note' => ''), 1), '');

// ===========================================================================
echo "\n=== B. patient: My Lab Orders ===\n";
// ===========================================================================
$GLOBALS['wc'][701] = new Fake_WC('pending');
$h = $P::render_order(order(31, 'pending'));
check('unpaid, payable: Pay advance with the amount', array(has($h, 'href="https://site/pay/701"'), has($h, 'Pay advance ৳261')), array(true, true));
check('unpaid: no progress line, "Advance" not "Advance paid"', array(has($h, 'ecl-ord-steps'), has($h, 'Advance paid')), array(false, false));
check('escaped', array(has($h, 'FBS &lt;b&gt; × 2'), has($h, 'Popular &lt;Lab&gt;'), has($h, '<b>')), array(true, true, false));
check('admin notes never reach the patient', has($h, 'rude'), false);
check('...nor an empty line where one was', substr_count($h, '<li><time>'), 1);
$GLOBALS['wc'][701]->status = 'cancelled';
check('the WooCommerce order can no longer be paid: no Pay button', has($P::render_order(order(31, 'pending')), 'Pay advance'), false);

$h = $P::render_order(order(31, 'processing', array('file_urls' => 'ecare-private/r.pdf')));
check('paid: no Pay button, "Advance paid"', array(has($h, 'Pay advance'), has($h, 'Advance paid')), array(false, true));
check('a report not yet released is not offered', has($h, 'View report'), false);
check('progress: two done, "At the lab" now', array(substr_count($h, 'class="is-done"'), (bool) preg_match('/class="is-now" aria-current="step"><span>At the lab/', $h)), array(2, true));
$h = $P::render_order(order(31, 'report_ready', array('file_urls' => 'ecare-private/r.pdf')));
check('report ready: View report', has($h, 'href="https://site/view?ref=ecare-private/r.pdf"'), true);
check('details: items, discounts shown as minus, empty rows left out', array(has($h, 'Special Discount</dt><dd>−৳140'), has($h, 'Coupon Discount</dt><dd>−৳116'), has($h, 'External Material Cost')), array(true, true, false));
check('collection time', has($h, 'L:09:00-11:00'), true);
check('placed date from the order\'s own clock', has($h, gmdate('j M Y', 1790000000) . ' · Popular'), true);
$h = $P::render_order(order(31, 'cancelled'));
check('cancelled: no progress line, no buttons', array(has($h, 'ecl-ord-steps'), has($h, 'ecl-btn')), array(false, false));

ECare_Lab_Orders::$rows = array();
$h = $P::render();
check('no orders: a way to the tests', array(has($h, 'No lab orders yet'), has($h, 'https://site/tests/')), array(true, true));
ECare_Lab_Orders::$rows = array(31 => order(31, 'approved'));
$_GET = array('placed' => '31');
check('arriving after a 0% order: confirmed notice', has($P::render(), 'Order #31 is confirmed.'), true);
ECare_Lab_Orders::$rows = array(31 => order(31, 'pending'));
check('...but not for an order still unpaid', has($P::render(), 'is confirmed'), false);
$_GET = array();

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
