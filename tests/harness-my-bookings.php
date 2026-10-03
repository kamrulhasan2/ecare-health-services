<?php
/**
 * Guards ECare_My_Bookings: lab, caregiver and ambulance bookings inside the
 * patient dashboard (EG Care hooks) and the [ecare_my_bookings] shortcode.
 *
 * What it protects:
 *   - only the signed-in patient's own bookings are read and shown
 *   - open vs earlier split per kind; tab badges count open bookings only
 *   - "Pay now" only for a pending booking whose order is the patient's and unpaid
 *   - the driver's name / phone / plate only once the ambulance is assigned
 *   - everything shown is escaped
 *   - the dashboard hook and the shortcode never print the panels twice
 *   - nothing for a logged-out visitor or for another user's dashboard
 *
 * Run: php tests/harness-my-bookings.php   (ECARE_MB_CLASS=path overrides the class file)
 */

define('ABSPATH', __DIR__ . '/');
define('ECARE_PLUGIN_DIR', __DIR__ . '/../');
define('ECARE_PLUGIN_URL', 'https://site/p/');
define('ECARE_VERSION', 't');

$GLOBALS['rows'] = array(); $GLOBALS['lab_orders'] = array(); $GLOBALS['wc'] = array();
$GLOBALS['meta'] = array(); $GLOBALS['types'] = array(); $GLOBALS['titles'] = array();
$GLOBALS['uid'] = 0; $GLOBALS['enq'] = array(); $GLOBALS['queries'] = array();

function add_shortcode($t, $c) { $GLOBALS['hooks']['sc:' . $t] = $c; }
function add_action($t, $c) { $GLOBALS['hooks'][$t] = $c; }
function __($s, $d = null) { return $s; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_url($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_html__($s, $d = null) { return esc_html($s); }
function esc_attr__($s, $d = null) { return esc_attr($s); }
function wp_date($f, $ts, $tz = null) { return gmdate($f, $ts); }
function is_user_logged_in() { return $GLOBALS['uid'] > 0; }
function get_current_user_id() { return $GLOBALS['uid']; }
function get_the_title($id) { return $GLOBALS['titles'][(int) $id] ?? ''; }
function get_post_type($id) { return $GLOBALS['types'][(int) $id] ?? false; }
function get_post_meta($id, $k, $s = false) { return $GLOBALS['meta'][$id][$k] ?? ''; }
function wp_enqueue_style($h) { $GLOBALS['enq'][] = $h; }
function wp_enqueue_script($h) { $GLOBALS['enq'][] = $h; }
function wc_get_order($id) { return $GLOBALS['wc'][$id] ?? false; }

class FakeOrder {
    public $c; public $paid;
    function __construct($c, $paid) { $this->c = $c; $this->paid = $paid; }
    function get_customer_id() { return $this->c; }
    function needs_payment() { return !$this->paid; }
    function get_checkout_payment_url() { return 'https://site/pay/?k=' . $this->c; }
}

class FakeWpdb {
    public $prefix = 'wp_';
    function prepare($q, ...$a) {
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $a) {
            $v = $a[$i++];
            return $m[0] === '%d' ? (string) (int) $v : "'" . addslashes($v) . "'";
        }, $q);
    }
    function get_results($q) {
        $GLOBALS['queries'][] = $q;
        if (strpos($q, 'wp_ecare_bookings') === false) { throw new Exception('wrong table: ' . $q); }
        if (!preg_match("/user_id = (\d+) AND booking_type = '(\w+)'/", $q, $m)) { throw new Exception('unfiltered: ' . $q); }
        $legacy_only = strpos($q, 'lab_provider_id IS NULL') !== false;
        $out = array();
        foreach ($GLOBALS['rows'] as $r) {
            if ((int) $r->user_id !== (int) $m[1] || $r->booking_type !== $m[2]) { continue; }
            if ($legacy_only && !empty($r->lab_provider_id)) { continue; }
            $out[] = $r;
        }
        usort($out, function ($a, $b) { return $b->id - $a->id; });
        return $out;
    }
}
$GLOBALS['wpdb'] = new FakeWpdb();

class ECare_Lab_Orders {
    public static function open_statuses() { return array('approved', 'sample_collected', 'processing', 'report_ready'); }
    public static function legacy_only_sql() { return ' AND lab_provider_id IS NULL'; }
    public static function for_user($u) { return array_values(array_filter($GLOBALS['lab_orders'], function ($o) use ($u) { return (int) $o->user_id === (int) $u; })); }
}
class ECare_Lab_Orders_Page {
    public static function render_order($o) { return '<article class="lab-order">LAB#' . (int) $o->id . '</article>'; }
}
class ECare_Lab_Front {
    public static function page_id($w) { return 5; }
    public static function url($w) { return 'https://site/lab/'; }
    public static function money($n) { return '৳' . number_format((float) $n); }
}
class ECare_Search {
    public static function ambulance_types() { return array('ac' => 'AC Ambulance', 'icu' => 'ICU Ambulance'); }
    public static function caregiver_url() { return 'https://site/care/'; }
    public static function ambulance_url() { return 'https://site/amb/'; }
}

require getenv('ECARE_MB_CLASS') ?: __DIR__ . '/../includes/class-ecare-my-bookings.php';

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; } else { $fail++; echo "FAIL: $m\n"; } }
function has($h, $n, $m) { ok(strpos($h, $n) !== false, $m . ' (missing ' . $n . ')'); }
function hasnt($h, $n, $m) { ok(strpos($h, $n) === false, $m . ' (found ' . $n . ')'); }
function row($a) {
    return (object) array_merge(array(
        'id' => 0, 'booking_type' => 'caregiver', 'user_id' => 0, 'provider_id' => 0, 'package_type' => '', 'patient_name' => '',
        'required_date' => '2026-10-10', 'address' => '', 'lab_test_ids' => '', 'ambulance_type' => '', 'pickup_address' => '',
        'destination' => '', 'schedule_time' => '2026-10-10 09:30:00', 'priority_level' => 'Normal', 'total_amount' => 0,
        'status' => 'pending', 'order_id' => 0, 'lab_provider_id' => null,
    ), $a);
}
function reset_flags() {
    foreach (array('printed', 'tabbed') as $p) {
        $r = new ReflectionProperty('ECare_My_Bookings', $p); $r->setAccessible(true); $r->setValue(null, false);
    }
}
function capture($cb) { ob_start(); $cb(); return ob_get_clean(); }

ECare_My_Bookings::init();
ok(isset($GLOBALS['hooks']['eg_care_patient_dashboard_after_stats'], $GLOBALS['hooks']['eg_care_patient_dashboard_end'], $GLOBALS['hooks']['sc:ecare_my_bookings']), 'init registers both EG Care hooks and the shortcode');

// ---------------------------------------------------------------------------
// Data: own rows only, open / past split
// ---------------------------------------------------------------------------
$GLOBALS['titles'] = array(300 => 'Rahima Care', 400 => 'Unit 7', 50 => 'CBC');
$GLOBALS['types']  = array(300 => 'ecare_caregiver', 400 => 'ecare_ambulance', 401 => 'ecare_caregiver');
$GLOBALS['meta'][400] = array('_driver_name' => 'Karim <b>', '_phone' => '+880 1711-000000', '_license_plate' => 'DHA-11');

$GLOBALS['rows'] = array(
    row(array('id' => 1, 'user_id' => 7, 'booking_type' => 'caregiver', 'provider_id' => 300, 'status' => 'approved', 'patient_name' => '<script>x</script>', 'package_type' => '12h')),
    row(array('id' => 2, 'user_id' => 7, 'booking_type' => 'caregiver', 'status' => 'completed')),
    row(array('id' => 3, 'user_id' => 8, 'booking_type' => 'caregiver', 'status' => 'pending', 'patient_name' => 'OTHER-USER')),
    row(array('id' => 4, 'user_id' => 7, 'booking_type' => 'ambulance', 'provider_id' => 400, 'status' => 'dispatched', 'ambulance_type' => 'icu', 'priority_level' => 'Emergency')),
    row(array('id' => 5, 'user_id' => 7, 'booking_type' => 'ambulance', 'provider_id' => 400, 'status' => 'approved', 'ambulance_type' => 'ac')),
    row(array('id' => 6, 'user_id' => 7, 'booking_type' => 'ambulance', 'status' => 'cancelled', 'ambulance_type' => 'zz')),
    row(array('id' => 7, 'user_id' => 7, 'booking_type' => 'lab', 'lab_test_ids' => '50', 'status' => 'pending')),
    row(array('id' => 8, 'user_id' => 7, 'booking_type' => 'lab', 'lab_provider_id' => 9, 'status' => 'approved')),   // new-style: comes from for_user
);
$GLOBALS['lab_orders'] = array(
    (object) array('id' => 8, 'user_id' => 7, 'status' => 'approved'),
    (object) array('id' => 9, 'user_id' => 7, 'status' => 'completed'),
    (object) array('id' => 10, 'user_id' => 7, 'status' => 'pending'),
    (object) array('id' => 11, 'user_id' => 8, 'status' => 'approved'),
);

$d = ECare_My_Bookings::data(7);
$ids = function ($list) { return array_map(function ($i) { return $i['kind'] . ':' . $i['row']->id; }, $list); };
ok($ids($d['caregiver']['open']) === array('caregiver:1'), 'caregiver open = approved only, own: ' . implode(',', $ids($d['caregiver']['open'])));
ok($ids($d['caregiver']['past']) === array('caregiver:2'), 'caregiver past = completed');
ok($ids($d['ambulance']['open']) === array('ambulance:5', 'ambulance:4'), 'ambulance open newest first: ' . implode(',', $ids($d['ambulance']['open'])));
ok($ids($d['ambulance']['past']) === array('ambulance:6'), 'ambulance past = cancelled');
ok($ids($d['lab']['open']) === array('lab:8', 'lab:10', 'legacy:7'), 'lab open = new orders (approved, pending) + old pending row: ' . implode(',', $ids($d['lab']['open'])));
ok($ids($d['lab']['past']) === array('lab:9'), 'lab past = completed order');
foreach ($GLOBALS['queries'] as $q) { ok(strpos($q, 'user_id = 7') !== false, 'every query is limited to the patient: ' . $q); }
$GLOBALS['queries'] = array();
ECare_My_Bookings::data(7);
ok(!$GLOBALS['queries'], 'data is read once per request');
$e = ECare_My_Bookings::data(0);
ok(!$e['lab']['open'] && !$e['caregiver']['past'] && !$e['ambulance']['open'], 'no user = nothing read');

// ---------------------------------------------------------------------------
// Words
// ---------------------------------------------------------------------------
ok(ECare_My_Bookings::status('ambulance', 'dispatched')[0] === 'On the way', 'dispatched reads On the way');
ok(ECare_My_Bookings::status('ambulance', 'assigned')[0] === 'Ambulance assigned', 'assigned label');
ok(ECare_My_Bookings::status('caregiver', 'approved')[0] === 'Confirmed', 'approved reads Confirmed');
ok(ECare_My_Bookings::status('caregiver', 'weird')[0] === 'Weird', 'unknown status falls back to its name');

// ---------------------------------------------------------------------------
// Pay link
// ---------------------------------------------------------------------------
$GLOBALS['wc'] = array(900 => new FakeOrder(7, false), 901 => new FakeOrder(7, true), 902 => new FakeOrder(8, false));
ok(ECare_My_Bookings::pay_url(row(array('user_id' => 7, 'order_id' => 900))) === 'https://site/pay/?k=7', 'unpaid own pending order has a pay link');
ok(ECare_My_Bookings::pay_url(row(array('user_id' => 7, 'order_id' => 901))) === '', 'paid order: no pay link');
ok(ECare_My_Bookings::pay_url(row(array('user_id' => 7, 'order_id' => 902))) === '', 'someone else\'s order: no pay link');
ok(ECare_My_Bookings::pay_url(row(array('user_id' => 7, 'order_id' => 900, 'status' => 'approved'))) === '', 'not pending: no pay link');
ok(ECare_My_Bookings::pay_url(row(array('user_id' => 7, 'order_id' => 0))) === '', 'no order: no pay link');
ok(ECare_My_Bookings::pay_url(row(array('user_id' => 7, 'order_id' => 999))) === '', 'missing order: no pay link');

// ---------------------------------------------------------------------------
// Driver
// ---------------------------------------------------------------------------
foreach (array('pending', 'approved', 'completed', 'cancelled') as $s) {
    ok(ECare_My_Bookings::driver(row(array('booking_type' => 'ambulance', 'provider_id' => 400, 'status' => $s))) === null, "no driver while $s");
}
foreach (array('assigned', 'dispatched') as $s) {
    $dr = ECare_My_Bookings::driver(row(array('booking_type' => 'ambulance', 'provider_id' => 400, 'status' => $s)));
    ok($dr && $dr['name'] === 'Karim <b>' && $dr['phone'] === '+880 1711-000000' && $dr['plate'] === 'DHA-11', "driver shown when $s");
}
ok(ECare_My_Bookings::driver(row(array('booking_type' => 'ambulance', 'provider_id' => 401, 'status' => 'assigned'))) === null, 'a provider that is not an ambulance is never shown as driver');
ok(ECare_My_Bookings::driver(row(array('booking_type' => 'ambulance', 'provider_id' => 0, 'status' => 'assigned'))) === null, 'no unit: no driver');
$GLOBALS['meta'][402] = array('_phone' => ''); $GLOBALS['types'][402] = 'ecare_ambulance'; $GLOBALS['titles'][402] = 'Unit 9';
ok(ECare_My_Bookings::driver(row(array('provider_id' => 402, 'status' => 'assigned')))['name'] === 'Unit 9', 'no driver name: the unit\'s name');

// ---------------------------------------------------------------------------
// Panels
// ---------------------------------------------------------------------------
$h = ECare_My_Bookings::panels_html(7, true);
has($h, 'class="ecl ecl-mb is-tabbed"', 'tabbed panels are marked for the script');
foreach (array('lab', 'caregiver', 'ambulance') as $t) { has($h, 'data-ecl-panel="' . $t . '"', "panel $t"); }
has($h, '&lt;script&gt;x&lt;/script&gt;', 'patient name escaped');
hasnt($h, '<script>x', 'no raw script');
hasnt($h, 'OTHER-USER', 'another user\'s booking never shown');
has($h, 'Caregiver: Rahima Care', 'caregiver name in title');
has($h, 'Ambulance: ICU Ambulance', 'ambulance type label');
has($h, 'Ambulance: zz', 'unknown type shown as stored');
has($h, 'Karim &lt;b&gt;', 'driver name escaped');
has($h, 'href="tel:+8801711000000"', 'driver phone is a clean tel: link');
ok(substr_count($h, 'Karim') === 1, 'driver shown only on the dispatched booking, not the approved one');
has($h, 'ecl-mb-emergency', 'emergency priority highlighted');
has($h, 'LAB#8', 'lab order card from My Lab Orders');
has($h, 'CBC', 'old lab row titled by its test');
hasnt($h, 'LAB#11', 'another user\'s lab order not shown');
has($h, 'Earlier bookings (1)', 'past count');
ok(preg_match('/<details class="ecl-mb-past"><summary>/', $h) === 1, 'past list closed when there are open bookings');
hasnt($h, 'Pay now', 'no pay link without an unpaid order');

$GLOBALS['rows'][] = row(array('id' => 20, 'user_id' => 12, 'booking_type' => 'caregiver', 'status' => 'pending', 'order_id' => 900));
$GLOBALS['wc'][900] = new FakeOrder(12, false);
$GLOBALS['rows'][] = row(array('id' => 21, 'user_id' => 12, 'booking_type' => 'ambulance', 'status' => 'completed'));
$h = ECare_My_Bookings::panels_html(12, false);
has($h, 'class="ecl ecl-mb"', 'untabbed panels when there is no tab bar');
has($h, 'href="https://site/pay/?k=12">Pay now', 'unpaid booking gets Pay now');
has($h, '<details class="ecl-mb-past" open>', 'only earlier bookings: list opened');
has($h, 'Nothing in progress.', 'no open ambulance booking');
has($h, 'No lab tests yet.', 'empty lab panel');
has($h, 'href="https://site/lab/">Book a lab test', 'empty lab panel links to booking');

// ---------------------------------------------------------------------------
// Tab bar
// ---------------------------------------------------------------------------
$b = ECare_My_Bookings::tab_bar_html(7);
ok(preg_match_all('/data-ecl-tab="(\w+)"/', $b, $m) && $m[1] === array('consult', 'lab', 'caregiver', 'ambulance'), 'tabs in order');
has($b, 'Lab Tests <span class="ecl-mb-badge">3</span>', 'lab badge = open lab');
has($b, 'Caregiver <span class="ecl-mb-badge">1</span>', 'caregiver badge');
has($b, 'Ambulance <span class="ecl-mb-badge">2</span>', 'ambulance badge');
has($b, 'data-ecl-tab="consult" aria-selected="true">Doctor Consultations</button>', 'consult first and selected, no badge');

// ---------------------------------------------------------------------------
// Hooks, shortcode, access
// ---------------------------------------------------------------------------
reset_flags(); $GLOBALS['uid'] = 0;
ok(capture(function () { ECare_My_Bookings::tab_bar(7); ECare_My_Bookings::panels_at_end(7); }) === '', 'logged out: hooks print nothing');
has(ECare_My_Bookings::shortcode(), 'Please log in', 'logged out: shortcode asks to log in');

reset_flags(); $GLOBALS['uid'] = 8;
ok(capture(function () { ECare_My_Bookings::tab_bar(7); ECare_My_Bookings::panels_at_end(7); }) === '', 'another user\'s dashboard: nothing');

reset_flags(); $GLOBALS['uid'] = 7; $GLOBALS['enq'] = array();
$out = capture(function () { ECare_My_Bookings::tab_bar(7); ECare_My_Bookings::panels_at_end(7); });
has($out, 'data-ecl-mb-tabs', 'hook prints the tab bar');
has($out, 'ecl-mb is-tabbed', 'panels follow the tab bar as tabbed');
ok(in_array('ecare-lab', $GLOBALS['enq'], true) && in_array('ecare-mybookings', $GLOBALS['enq'], true), 'style and script enqueued');
ok(ECare_My_Bookings::shortcode() === '', 'shortcode on the same page adds nothing');
ok(capture(function () { ECare_My_Bookings::panels_at_end(7); }) === '', 'panels never printed twice');

reset_flags();
$sc = ECare_My_Bookings::shortcode();
has($sc, 'class="ecl ecl-mb"', 'shortcode alone prints untabbed panels');
ok(capture(function () { ECare_My_Bookings::panels_at_end(7); }) === '', 'hook after the shortcode adds nothing');

reset_flags();
$out = capture(function () { ECare_My_Bookings::panels_at_end(7); });
has($out, 'class="ecl ecl-mb"', 'end hook without the tab hook: untabbed (older EG Care template)');

echo "my-bookings: $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
