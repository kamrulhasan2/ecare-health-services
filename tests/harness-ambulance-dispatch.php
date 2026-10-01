<?php
/**
 * Guards ECare_Ambulance_Dispatch: putting an ambulance on a booking.
 *
 * What it protects:
 *   - only an approved ambulance can be assigned, only to an open ambulance
 *     booking; choosing one makes Pending/Approved bookings Assigned and
 *     emails that provider at once (and reports how the email went)
 *   - a changed ambulance emails the new provider; the old one is not re-sent
 *   - removing the ambulance puts an Assigned booking back to Approved (paid)
 *     or Pending (not paid); a dispatched one keeps it until its status changes
 *   - the list puts the requested type first, least busy first, and keeps a
 *     no-longer-approved current unit visible instead of silently changing it
 *   - the counts behind "Active Now" and "Needs an ambulance"
 *   - "Booked" times are corrected for a database clock in another zone
 *   - the status dropdown refuses Assigned / Dispatched with no ambulance
 */

define('ABSPATH', __DIR__ . '/');
define('ECARE_PLUGIN_DIR', __DIR__ . '/../');
define('ECARE_PLUGIN_URL', 'https://site/p/');
define('ECARE_VERSION', 't');

$GLOBALS['fired'] = array(); $GLOBALS['meta'] = array(); $GLOBALS['types'] = array(); $GLOBALS['titles'] = array();
function add_action() {} function add_filter() {}
function do_action($h, ...$a) { $GLOBALS['fired'][] = array_merge(array($h), $a); }
function __($s, $d = null) { return $s; }
function _n($a, $b, $n, $d = null) { return $n == 1 ? $a : $b; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_html__($s, $d = null) { return esc_html($s); }
function get_post_type($id) { return $GLOBALS['types'][$id] ?? false; }
function get_the_title($id) { return $GLOBALS['titles'][$id] ?? ''; }
function get_post_meta($id, $k, $s = false) { return $GLOBALS['meta'][$id][$k] ?? ''; }
function get_posts($a) {
    $out = array();
    foreach ($GLOBALS['types'] as $id => $t) {
        if ($t === 'ecare_ambulance' && ($GLOBALS['meta'][$id]['_ambulance_status'] ?? '') === 'approved') { $out[] = $id; }
    }
    return $out;
}
function wp_date($f, $ts) { return gmdate($f, $ts); }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function check_ajax_referer() { return 1; }
function current_user_can($c) { return !empty($GLOBALS['admin']); }
class Json_Out extends Exception { public $ok; public $data; public $code; public function __construct($ok, $d, $c) { $this->ok = $ok; $this->data = $d; $this->code = $c; } }
function wp_send_json_success($d = null, $c = 200) { throw new Json_Out(true, $d, $c); }
function wp_send_json_error($d = null, $c = 400) { throw new Json_Out(false, $d, $c); }
function wc_get_order($id) { return $GLOBALS['orders'][$id] ?? false; }
class Fake_Order { public $paid; public function __construct($p) { $this->paid = $p; } public function is_paid() { return $this->paid; } }

class ECare_Provider_Emails {
    public static $sent = array(); public static $result = 'sent';
    public static function send_booking($row) { self::$sent[] = array((int) $row->id, (int) $row->provider_id, $row->status); return self::$result; }
}

class Fake_WPDB {
    public $prefix = 'wp_'; public $rows = array(); public $now = null; public $vars = array();
    public function prepare($q, ...$a) { if (count($a) === 1 && is_array($a[0])) { $a = $a[0]; } foreach ($a as $v) { $q = preg_replace('/%[sd]/', is_int($v) ? (string) $v : "'" . $v . "'", $q, 1); } return $q; }
    public function get_row($q) { return preg_match('/WHERE id = (\d+)/', $q, $m) && isset($this->rows[(int) $m[1]]) ? (object) $this->rows[(int) $m[1]] : null; }
    public function update($t, $data, $where) { $this->rows[$where['id']] = array_merge($this->rows[$where['id']], $data); return 1; }
    public function get_results($q) {
        $n = array();
        foreach ($this->rows as $r) {
            if ($r['booking_type'] === 'ambulance' && (int) $r['provider_id'] > 0 && in_array($r['status'], array('pending', 'approved', 'assigned', 'dispatched'), true)) { $n[(int) $r['provider_id']] = ($n[(int) $r['provider_id']] ?? 0) + 1; }
        }
        $o = array(); foreach ($n as $p => $c) { $o[] = (object) array('provider_id' => $p, 'n' => $c); } return $o;
    }
    public function get_var($q) {
        if ($q === 'SELECT NOW()') { return $this->now; }
        $this->vars[] = $q;
        $c = 0;
        foreach ($this->rows as $r) {
            if ($r['booking_type'] !== 'ambulance') { continue; }
            $open = in_array($r['status'], array('pending', 'approved', 'assigned', 'dispatched'), true);
            if (strpos($q, 'provider_id IS NULL') !== false) {
                // Read the query, not the intent: zero ids and the open-only filter count only if asked for.
                $none = $r['provider_id'] === null || ((int) $r['provider_id'] === 0 && strpos($q, 'provider_id = 0') !== false);
                $only = strpos($q, "status IN ('pending', 'approved', 'assigned', 'dispatched')") !== false;
                $c += ($none && (!$only || $open)) ? 1 : 0;
            }
            elseif (strpos($q, "status IN ('approved', 'assigned', 'dispatched')") !== false) { $c += in_array($r['status'], array('approved', 'assigned', 'dispatched'), true) ? 1 : 0; }
            elseif (strpos($q, "status = 'completed'") !== false) { $c += $r['status'] === 'completed' ? 1 : 0; }
            elseif (strpos($q, "priority_level = 'Emergency'") !== false) { $c += $r['priority_level'] === 'Emergency' ? 1 : 0; }
            else { $c++; }
        }
        return $c;
    }
}
$GLOBALS['wpdb'] = new Fake_WPDB();
$db = $GLOBALS['wpdb'];

require_once ($argv[1] ?? (__DIR__ . '/../admin/class-ecare-ambulance-dispatch.php'));

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; printf("  PASS  %s\n", $label); }
    else { $fail++; printf("  FAIL  %s\n          got:  %s\n          want: %s\n", $label, var_export($got, true), var_export($want, true)); }
}
function unit($id, $name, $type, $plate, $status = 'approved', $email = '') {
    $GLOBALS['types'][$id] = 'ecare_ambulance'; $GLOBALS['titles'][$id] = $name . ' Co';
    $GLOBALS['meta'][$id] = array('_driver_name' => $name, '_ambulance_type' => $type, '_license_plate' => $plate, '_ambulance_status' => $status, '_email' => $email);
}
function booking($id, $status, $unit = null, $type = 'Standard', $extra = array()) {
    $GLOBALS['wpdb']->rows[$id] = array_merge(array('id' => $id, 'booking_type' => 'ambulance', 'status' => $status, 'provider_id' => $unit, 'ambulance_type' => $type, 'order_id' => 0, 'priority_level' => 'Normal'), $extra);
}
function fired() { $f = $GLOBALS['fired']; $GLOBALS['fired'] = array(); return $f; }
$D = 'ECare_Ambulance_Dispatch';

unit(50, 'Karim', 'Standard', 'DHA-11', 'approved', 'karim@x.com');
unit(51, 'Rafiq', 'Standard', 'DHA-22');
unit(52, 'Selim', 'ICU', 'DHA-33');
unit(53, 'Old <Van>', 'Standard', '', 'rejected');
$GLOBALS['types'][60] = 'ecare_caregiver';

echo "\n=== A. assigning ===\n";
booking(1, 'approved');
$r = $D::assign(1, 50);
check('approved + an approved unit: Assigned, unit saved, provider emailed with the new state', array($r['ok'], $r['code'], $r['status'], $db->rows[1]['provider_id'], $db->rows[1]['status'], ECare_Provider_Emails::$sent, $r['mail']),
    array(true, 'assigned', 'assigned', 50, 'assigned', array(array(1, 50, 'assigned')), 'sent'));
check('...then the world is told: unit changed, status changed (after the email, so it is not sent twice)', fired(), array(
    array('ecare_booking_provider_changed', 1, 50, 0),
    array('ecare_booking_status_changed', 1, 'assigned', 'approved'),
));
check('...and the admin reads where the email went', $D::message($r, 50), 'Assigned. Booking details emailed to karim@x.com.');
ECare_Provider_Emails::$sent = array();
check('the same unit again: nothing happens', array($D::assign(1, 50)['code'], ECare_Provider_Emails::$sent, fired()), array('same', array(), array()));
$r = $D::assign(1, 51);
check('changed to another unit: the new provider is emailed, status stays Assigned', array($r['status'], $db->rows[1]['provider_id'], ECare_Provider_Emails::$sent, fired()),
    array('assigned', 51, array(array(1, 51, 'assigned')), array(array('ecare_booking_provider_changed', 1, 51, 50))));
ECare_Provider_Emails::$sent = array();

booking(2, 'pending');
check('a pending (unpaid) booking can be assigned too', $D::assign(2, 52)['status'], 'assigned');
booking(3, 'dispatched', 50);
check('a dispatched booking changes unit but stays Dispatched', array($D::assign(3, 51)['status'], $db->rows[3]['status']), array('dispatched', 'dispatched'));
fired(); ECare_Provider_Emails::$sent = array();

booking(4, 'approved');
check('a unit that is not approved: refused, nothing saved or sent', array($D::assign(4, 53)['code'], $db->rows[4]['provider_id'], ECare_Provider_Emails::$sent, fired()), array('bad_unit', null, array(), array()));
check('a caregiver id: refused', $D::assign(4, 60)['code'], 'bad_unit');
check('an id that is nothing: refused', $D::assign(4, 999)['code'], 'bad_unit');
foreach (array('completed', 'cancelled') as $st) { booking(5, $st); check("a $st booking: closed", $D::assign(5, 50)['code'], 'closed'); }
booking(6, 'approved', null, 'Standard', array('booking_type' => 'caregiver'));
check('not an ambulance booking: refused', $D::assign(6, 50)['code'], 'missing');
check('no such booking: refused', $D::assign(77, 50)['code'], 'missing');

echo "\n=== B. removing ===\n";
booking(7, 'assigned', 50, 'Standard', array('order_id' => 900)); $GLOBALS['orders'][900] = new Fake_Order(true);
$r = $D::assign(7, 0);
check('removed from a paid, assigned booking: back to Approved, unit cleared', array($r['code'], $r['status'], $db->rows[7]['provider_id'], $db->rows[7]['status']), array('cleared', 'approved', null, 'approved'));
check('...told as such', fired(), array(array('ecare_booking_provider_changed', 7, 0, 50), array('ecare_booking_status_changed', 7, 'approved', 'assigned')));
booking(8, 'assigned', 50, 'Standard', array('order_id' => 901)); $GLOBALS['orders'][901] = new Fake_Order(false);
check('...from an unpaid one: back to Pending', $D::assign(8, 0)['status'], 'pending');
booking(9, 'dispatched', 50);
check('a dispatched ambulance cannot just be removed', array($D::assign(9, 0)['code'], $db->rows[9]['provider_id']), array('on_the_way', 50));
booking(10, 'approved', 50);
check('removed while still Approved: status unchanged', $D::assign(10, 0)['status'], 'approved');
fired();

echo "\n=== C. the list ===\n";
$db->rows = array();
booking(20, 'assigned', 50); booking(21, 'dispatched', 50); booking(22, 'completed', 51); booking(23, 'approved', 52);
$units = $D::units();
check('approved units only, with their open bookings counted', array_map(function ($u) { return $u['name'] . ':' . $u['load']; }, array_values($units)), array('Karim:2', 'Rafiq:0', 'Selim:1'));
check('a unit\'s line: name, plate, load', array($D::unit_label($units[50]), $D::unit_label($units[51]), $D::unit_label($units[52], true)), array('Karim · DHA-11 · 2 active', 'Rafiq · DHA-22 · free', 'Selim · DHA-33 · ICU · 1 active'));
$html = $D::options_html((object) array('provider_id' => 0, 'ambulance_type' => 'Standard'), $units);
preg_match_all('/<option value="(\d+)"/', $html, $m);
check('Standard requested: "not assigned" (chosen), Standard least busy first, then other types', array($m[1], strpos($html, '<option value="0" selected>') !== false, strpos($html, 'label="Standard ambulances"') < strpos($html, 'label="Other types"')),
    array(array('0', '51', '50', '52'), true, true));
$html = $D::options_html((object) array('provider_id' => 53, 'ambulance_type' => 'Standard'), $units);
check('the current unit is no longer approved: still shown and chosen, escaped', array(strpos($html, '<option value="53" selected>Old &lt;Van&gt; (no longer approved)</option>') !== false, strpos($html, '<option value="0" selected>')), array(true, false));
$html = $D::options_html((object) array('provider_id' => 0, 'ambulance_type' => 'Freezer'), $units);
check('no unit of the requested type: only "Other types"', array(strpos($html, 'Freezer ambulances'), strpos($html, 'Other types') !== false), array(false, true));

echo "\n=== D. counts and times ===\n";
booking(24, 'pending'); booking(25, 'approved', 0, 'ICU', array('priority_level' => 'Emergency')); booking(26, 'cancelled'); booking(27, 'completed', 0); booking(28, 'pending', 0);
check('active = approved + assigned + dispatched; unassigned = open with no unit', $D::counts(), array('total' => 9, 'active' => 4, 'completed' => 2, 'emergency' => 1, 'unassigned' => 3));
$db->now = gmdate('Y-m-d H:i:s', time() + 6 * 3600 + 7);   // a database clock 6 hours ahead
check('database clock 6 hours ahead: offset found (rounded)', $D::db_offset(), 21600);
check('...and "Booked" is shown in real time', $D::booked_at('2026-10-01 18:00:00'), '1 Oct 2026, 12:00 PM');
check('no date: nothing', $D::booked_at(''), '');

echo "\n=== E. the AJAX door ===\n";
function call($post, $admin) { $_POST = $post; $GLOBALS['admin'] = $admin; try { ECare_Ambulance_Dispatch::ajax_assign(); } catch (Json_Out $e) { return array($e->ok, $e->code, $e->data); } return null; }
booking(30, 'approved');
check('not an admin: 403, nothing saved', array(array_slice(call(array('booking_id' => 30, 'unit_id' => 50), false), 0, 2), $db->rows[30]['provider_id']), array(array(false, 403), null));
list($ok, $code, $d) = call(array('booking_id' => 30, 'unit_id' => 50), true);
check('admin: saved; the reply has the status, its label, the new unassigned count and the email result', array($ok, $d['status'], $d['status_label'], $d['unit'], $d['mail'], is_int($d['unassigned'])), array(true, 'assigned', 'Assigned', 50, 'sent', true));
list($ok, $code, $d) = call(array('booking_id' => 30, 'unit_id' => 53), true);
check('a refused unit: 409 with a reason the admin can act on', array($ok, $code, $d['message']), array(false, 409, 'That ambulance is not approved. Approve it in Ambulance Providers first.'));
ECare_Provider_Emails::$result = 'no_address';
booking(31, 'approved');
list($ok, $code, $d) = call(array('booking_id' => 31, 'unit_id' => 51), true);
check('assigned to a provider with no email: the admin is told to call', $d['message'], 'Assigned, but the provider has no valid email address: please call them.');
ECare_Provider_Emails::$result = 'sent';

echo "\n=== F. the status dropdown (ECare_Ajax::update_booking_status) ===\n";
if (!function_exists('add_shortcode')) { function add_shortcode() {} }
require_once __DIR__ . '/../includes/class-ecare-ajax.php';
function set_status($id, $status) { $_POST = array('booking_id' => $id, 'status' => $status); $GLOBALS['admin'] = true; try { ECare_Ajax::update_booking_status(); } catch (Json_Out $e) { return array($e->ok, $e->data['code'] ?? ''); } return null; }
booking(40, 'approved');
check('no ambulance: Assigned refused', array(set_status(40, 'assigned'), $db->rows[40]['status']), array(array(false, 'no_unit'), 'approved'));
check('no ambulance: Dispatched refused', set_status(40, 'dispatched'), array(false, 'no_unit'));
check('no ambulance: Cancelled is fine', array(set_status(40, 'cancelled'), $db->rows[40]['status']), array(array(true, ''), 'cancelled'));
booking(41, 'assigned', 50);
check('with an ambulance: Dispatched is fine', array(set_status(41, 'dispatched'), $db->rows[41]['status']), array(array(true, ''), 'dispatched'));
booking(42, 'approved', null, 'Standard', array('booking_type' => 'caregiver'));
check('a caregiver booking is not held to the ambulance rule', set_status(42, 'assigned'), array(true, ''));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
