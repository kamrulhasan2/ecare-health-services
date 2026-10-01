<?php
/**
 * Guards ECare_Provider_Emails: emails to caregivers and ambulance providers.
 *
 * What it protects:
 *   - a registration sends "Pending" to the address on the form, and a
 *     "new registration" note to the admin addresses
 *   - Approved / Rejected go out when the status is written, whichever screen
 *     wrote it, once per decision; "pending" written at registration sends nothing
 *   - a rejection carries the reason, escaped
 *   - a booking reaches its provider once: caregiver on "approved", ambulance on
 *     approved / assigned / dispatched; nobody when no provider is attached
 *   - each email can be switched off; a switched-off email is not marked as sent
 *   - the admin "Send again" ignores the switch, and only admins with a nonce get in
 *   - ECare_Ajax::update_booking_status() tells the world what changed, and
 *     only when something did
 */

define('ABSPATH', __DIR__ . '/');

$GLOBALS['hooks'] = array(); $GLOBALS['meta'] = array(); $GLOBALS['types'] = array(); $GLOBALS['titles'] = array();
$GLOBALS['options'] = array(); $GLOBALS['mails'] = array(); $GLOBALS['mail_ok'] = true; $GLOBALS['fired'] = array();
function add_action($h, $cb, $p = 10, $a = 1) { $GLOBALS['hooks'][$h][] = $cb; }
function add_filter() {}
function do_action($h, ...$args) {
    $GLOBALS['fired'][] = array_merge(array($h), $args);
    foreach ($GLOBALS['hooks'][$h] ?? array() as $cb) { call_user_func_array($cb, $args); }
}
function __($s, $d = null) { return $s; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_url($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_html__($s, $d = null) { return esc_html($s); }
function sanitize_email($e) { return trim((string) $e); }
function is_email($e) { return (bool) filter_var($e, FILTER_VALIDATE_EMAIL); }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)); }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function get_option($k, $def = false) { return $GLOBALS['options'][$k] ?? $def; }
function get_bloginfo($k) { return 'Meditaj & Co'; }
function wp_specialchars_decode($s) { return html_entity_decode($s, ENT_QUOTES); }
function admin_url($p = '') { return 'https://site/wp-admin/' . $p; }
function get_post_type($id) { return $GLOBALS['types'][$id] ?? false; }
function get_the_title($id) { return $GLOBALS['titles'][$id] ?? ''; }
function get_post_meta($id, $k, $single = false) {
    $v = $GLOBALS['meta'][$id][$k] ?? null;
    if ($single) { return is_array($v) && isset($v['__multi']) ? ($v['__multi'][0] ?? '') : ($v ?? ''); }
    return is_array($v) && isset($v['__multi']) ? $v['__multi'] : ($v === null ? array() : array($v));
}
function update_post_meta($id, $k, $v) {
    $old = $GLOBALS['meta'][$id][$k] ?? null;
    if ($old === $v) { return false; }
    $GLOBALS['meta'][$id][$k] = $v;
    do_action($old === null ? 'added_post_meta' : 'updated_post_meta', 1, $id, $k, $v);
    return true;
}
function add_post_meta($id, $k, $v) { $GLOBALS['meta'][$id][$k]['__multi'][] = $v; return true; }
function get_userdata($id) { return $id == 9 ? (object) array('display_name' => 'Rahim Uddin') : false; }
function wp_date($f, $ts, $tz = null) { return gmdate($f, $ts); }
function nl2br_safe($s) { return nl2br($s); }
function wp_mail($to, $s, $b, $h = array()) { $GLOBALS['mails'][] = array('to' => $to, 'subject' => $s, 'body' => $b); return $GLOBALS['mail_ok']; }
function current_user_can($c) { return !empty($GLOBALS['admin']); }
function check_admin_referer($a) { if (($_GET['_wpnonce'] ?? '') !== 'N:' . $a) { throw new Exception('nonce'); } return 1; }
function wp_die($m = '', $code = 0) { throw new Exception('die:' . $code); }
function time_now() { return 0; }

class Fake_WPDB {
    public $prefix = 'wp_'; public $rows = array(); public $updates = array();
    public function prepare($q, ...$a) { foreach ($a as $v) { $q = preg_replace('/%[sd]/', is_int($v) ? (string) $v : "'" . $v . "'", $q, 1); } return $q; }
    public function get_row($q) { return preg_match('/id = (\d+)/', $q, $m) && isset($this->rows[(int) $m[1]]) ? (object) $this->rows[(int) $m[1]] : null; }
    public function get_var($q) { return preg_match('/id = (\d+)/', $q, $m) && isset($this->rows[(int) $m[1]]) ? $this->rows[(int) $m[1]]['status'] : null; }
    public function update($t, $data, $where) { $this->updates[] = array($data, $where); if (isset($this->rows[$where['id']])) { $this->rows[$where['id']] = array_merge($this->rows[$where['id']], $data); } return 1; }
}
$GLOBALS['wpdb'] = new Fake_WPDB();

require_once ($argv[1] ?? (__DIR__ . '/../includes/class-ecare-provider-emails.php'));
ECare_Provider_Emails::init();

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; printf("  PASS  %s\n", $label); }
    else { $fail++; printf("  FAIL  %s\n          got:  %s\n          want: %s\n", $label, var_export($got, true), var_export($want, true)); }
}
function mails() { ECare_Provider_Emails::flush(); $m = $GLOBALS['mails']; $GLOBALS['mails'] = array(); return $m; }   // flush: what save_post / shutdown do
function to_subj($m) { return array_map(function ($x) { return $x['to'] . ' | ' . $x['subject']; }, $m); }
function provider($id, $type, $email, $extra = array()) {
    $GLOBALS['types'][$id] = $type;
    $GLOBALS['titles'][$id] = $extra['title'] ?? 'Ayesha <Khan>';
    unset($extra['title']);
    $GLOBALS['meta'][$id] = array('_email' => $email, '_phone' => '01711000000') + $extra;
}
$E = 'ECare_Provider_Emails';
$GLOBALS['options']['admin_email'] = 'admin@meditaj.com';

echo "\n=== A. registration ===\n";
provider(10, 'ecare_caregiver', 'ayesha@x.com', array('_provider_type' => 'Nurse'));
update_post_meta(10, '_provider_status', 'pending');   // what the form handler writes
check('writing "pending" sends nothing by itself', mails(), array());
do_action('ecare_provider_registered', 10);
$m = mails();
check('registered: "Pending" to the form address, then the admin note', to_subj($m), array(
    'ayesha@x.com | We received your registration — Meditaj & Co',
    'admin@meditaj.com | New caregiver registration: Ayesha <Khan>',
));
check('...the provider is told the status is Pending, name escaped', array(strpos($m[0]['body'], 'Pending review') !== false, strpos($m[0]['body'], 'Hi Ayesha &lt;Khan&gt;,') !== false), array(true, true));
check('...the admin gets the details and a link to review', array(strpos($m[1]['body'], 'Nurse') !== false, strpos($m[1]['body'], 'post.php?post=10&amp;action=edit') !== false), array(true, true));
check('...and the log says so', array_map(function ($e) { return $e['kind'] . ':' . $e['result']; }, $GLOBALS['meta'][10]['_ecare_mail_log']), array('pending:sent', 'admin:sent'));

provider(20, 'ecare_ambulance', 'driver@x.com', array('_driver_name' => 'Karim', '_ambulance_type' => 'ICU'));
$GLOBALS['options']['ecare_provider_emails'] = array('admin_to' => 'ops@meditaj.com, OPS@meditaj.com, bad@', 'hotline' => '10657');
do_action('ecare_provider_registered', 20);
$m = mails();
check('ambulance: driver\'s name, its own wording; admin list cleaned, first spelling kept', array(to_subj($m), strpos($m[0]['body'], 'Hi Karim,') !== false, strpos($m[0]['body'], 'ambulance provider') !== false),
    array(array('driver@x.com | We received your registration — Meditaj & Co', 'ops@meditaj.com | New ambulance provider registration: Karim'), true, true));
check('the contact number appears', strpos($m[0]['body'], 'Call us on 10657') !== false, true);

provider(11, 'ecare_caregiver', 'not-an-email');
do_action('ecare_provider_registered', 11);
check('no valid address on the form: only the admin hears, the gap is logged', array(count(mails()), $GLOBALS['meta'][11]['_ecare_mail_log'][0]['result']), array(1, 'no_address'));

echo "\n=== B. approved / rejected ===\n";
update_post_meta(10, '_provider_status', 'approved');
$m = mails();
check('approved (✓ button or edit screen both write this meta): one email', to_subj($m), array('ayesha@x.com | Your registration is approved — Meditaj & Co'));
check('...it says bookings will follow', strpos($m[0]['body'], 'You can now receive bookings') !== false, true);
update_post_meta(10, '_provider_status', 'approved');
check('saved again unchanged: nothing', mails(), array());
$GLOBALS['meta'][10]['_provider_status'] = 'pending';   // back to pending by hand, then approved again
update_post_meta(10, '_provider_status', 'approved');
check('the same decision again: not repeated', mails(), array());

$GLOBALS['meta'][10]['_ecare_reject_reason'] = "NID photo is unreadable <b>\nPlease send it again";
update_post_meta(10, '_provider_status', 'rejected');
$m = mails();
check('rejected: one email, with the reason, escaped, lines kept', array(to_subj($m), strpos($m[0]['body'], 'unreadable &lt;b&gt;<br />') !== false),
    array(array('ayesha@x.com | Update on your registration — Meditaj & Co'), true));
update_post_meta(10, '_provider_status', 'approved');
check('approved after a rejection: the new decision is sent', count(mails()), 1);
update_post_meta(20, '_ambulance_status', 'approved');
check('an ambulance is watched on its own key', to_subj(mails()), array('driver@x.com | Your registration is approved — Meditaj & Co'));
update_post_meta(10, '_ambulance_status', 'rejected');
check('the wrong key for the type: ignored', mails(), array());
$GLOBALS['types'][30] = 'post'; update_post_meta(30, '_provider_status', 'approved');
check('other post types: ignored', mails(), array());

// A provider made in wp-admin: the edit screen saves Status before Email.
$GLOBALS['types'][14] = 'ecare_ambulance'; $GLOBALS['titles'][14] = 'New Van';
update_post_meta(14, '_ambulance_status', 'approved');
update_post_meta(14, '_email', 'van@x.com');
check('Status saved before Email in the same save: the email still reaches the new address', to_subj(mails()), array('van@x.com | Your registration is approved — Meditaj & Co'));
provider(15, 'ecare_caregiver', 'd@x.com');
update_post_meta(15, '_provider_status', 'approved');
update_post_meta(15, '_provider_status', 'pending');
check('approved and set back to pending in the same request: nothing is announced', mails(), array());
update_post_meta(15, '_provider_status', 'approved');
$GLOBALS['meta'][15]['_provider_status'] = 'rejected';   // changed by something that does not go through update_post_meta
check('changed again before the send: the stale decision is dropped', mails(), array());

echo "\n=== C. switches ===\n";
$GLOBALS['options']['ecare_provider_emails'] = array('rejected' => 0, 'reg_admin' => 0);
provider(12, 'ecare_caregiver', 'b@x.com');
update_post_meta(12, '_provider_status', 'rejected');
check('rejected switched off: nothing sent, and not marked as sent', array(mails(), $GLOBALS['meta'][12]['_ecare_status_mailed'] ?? null), array(array(), null));
check('...so "Send again" still can, ignoring the switch', array($E::send(12, 'rejected', true), count(mails())), array('sent', 1));
do_action('ecare_provider_registered', 12);
check('admin note switched off: the provider still gets "Pending"', to_subj(mails()), array('b@x.com | We received your registration — Meditaj & Co'));
$GLOBALS['options']['ecare_provider_emails'] = array();
$GLOBALS['mail_ok'] = false;
provider(13, 'ecare_caregiver', 'c@x.com');
update_post_meta(13, '_provider_status', 'approved');
ECare_Provider_Emails::flush();
check('the mail server refuses: logged as failed, not marked sent', array(end($GLOBALS['meta'][13]['_ecare_mail_log'])['result'], $GLOBALS['meta'][13]['_ecare_status_mailed'] ?? null), array('failed', null));
$GLOBALS['mail_ok'] = true; mails();

echo "\n=== D. bookings ===\n";
$db = $GLOBALS['wpdb'];
$db->rows[5] = array('id' => 5, 'booking_type' => 'caregiver', 'provider_id' => 10, 'user_id' => 9, 'package_type' => '12 Hours', 'patient_name' => 'Abba', 'patient_type' => 'Elderly',
    'required_date' => '2026-10-05', 'diaper_change' => 1, 'address' => "Road 5\nDhanmondi", 'contact_phone' => '01712 345678', 'disease' => 'Stroke <recovery>', 'status' => 'approved');
do_action('ecare_booking_status_changed', 5, 'pending', 'approved');
check('a caregiver booking that is not approved: nothing', mails(), array());
do_action('ecare_booking_status_changed', 5, 'approved', 'pending');
$m = mails();
check('approved: the booked caregiver hears, the date in the subject', to_subj($m), array('ayesha@x.com | New caregiver booking #5 — Monday, 5 October 2026'));
check('...patient, phone (tap to call), address, condition (escaped), diaper change', array(
    strpos($m[0]['body'], 'Abba (Elderly)') !== false, strpos($m[0]['body'], 'href="tel:01712345678"') !== false,
    strpos($m[0]['body'], "Road 5<br />\nDhanmondi") !== false, strpos($m[0]['body'], 'Stroke &lt;recovery&gt;') !== false, strpos($m[0]['body'], 'Diaper change') !== false,
), array(true, true, true, true, true));
do_action('ecare_booking_status_changed', 5, 'approved', 'completed');
check('approved again later: not repeated', mails(), array());

$db->rows[6] = array('id' => 6, 'booking_type' => 'ambulance', 'provider_id' => 20, 'user_id' => 9, 'ambulance_type' => 'ICU', 'pickup_address' => 'Mirpur 10', 'destination' => 'Square Hospital',
    'schedule_time' => '2026-10-02 14:30:00', 'contact_phone' => '01811000000', 'priority_level' => 'Emergency', 'notes' => '', 'status' => 'assigned');
do_action('ecare_booking_status_changed', 6, 'pending', 'assigned');
check('ambulance back to pending: nothing', mails(), array());
do_action('ecare_booking_status_changed', 6, 'assigned', 'pending');
$m = mails();
check('ambulance assigned: its provider hears, pickup time in the subject', to_subj($m), array('driver@x.com | New ambulance booking #6 — Friday, 2 October 2026, 2:30 PM'));
check('...pickup, destination, patient, emergency marked', array(strpos($m[0]['body'], 'Square Hospital') !== false, strpos($m[0]['body'], 'Rahim Uddin') !== false, strpos($m[0]['body'], 'color:#b91c1c;">Emergency') !== false), array(true, true, true));
do_action('ecare_booking_status_changed', 6, 'dispatched', 'assigned');
check('then dispatched: once per booking', mails(), array());
foreach (array('approved', 'dispatched') as $st) {
    $db->rows[7] = array('id' => 7, 'booking_type' => 'ambulance', 'provider_id' => 20, 'user_id' => 0, 'ambulance_type' => 'Standard', 'pickup_address' => 'A', 'destination' => 'B', 'schedule_time' => '', 'contact_phone' => '1', 'priority_level' => 'Normal', 'notes' => '', 'status' => $st);
    $GLOBALS['meta'][20]['_ecare_booking_mailed'] = array('__multi' => array(6));
    do_action('ecare_booking_status_changed', 7, $st, 'pending');
    check("ambulance \"$st\" also counts", count(mails()), 1);
}
$db->rows[8] = array('id' => 8, 'booking_type' => 'ambulance', 'provider_id' => null, 'status' => 'approved');
check('no ambulance was matched: nobody to tell', array($E::send_booking((object) $db->rows[8]), mails()), array('no_provider', array()));
$db->rows[9] = array('id' => 9, 'booking_type' => 'caregiver', 'provider_id' => 20, 'status' => 'approved');
check('a caregiver booking pointing at an ambulance: refused', $E::send_booking((object) $db->rows[9]), 'no_provider');
$db->rows[11] = array('id' => 11, 'booking_type' => 'lab', 'provider_id' => 10, 'status' => 'approved');
do_action('ecare_booking_status_changed', 11, 'approved', 'pending');
check('lab bookings: not ours', mails(), array());
$GLOBALS['mail_ok'] = false;
$db->rows[13] = $db->rows[5]; $db->rows[13]['id'] = 13;
do_action('ecare_booking_status_changed', 13, 'approved', 'pending');
check('the mail server refuses a booking email: not marked, so the next approval tries again', array(count(mails()), $E::booking_mailed(10, 13)), array(1, false));
$GLOBALS['mail_ok'] = true;
do_action('ecare_booking_status_changed', 13, 'approved', 'pending');
check('...and then it goes', array(count(mails()), $E::booking_mailed(10, 13)), array(1, true));
$GLOBALS['options']['ecare_provider_emails'] = array('booking' => 0);
$db->rows[12] = $db->rows[5]; $db->rows[12]['id'] = 12;
do_action('ecare_booking_status_changed', 12, 'approved', 'pending');
check('booking emails off: nothing, not marked', array(mails(), $E::booking_mailed(10, 12)), array(array(), false));
$GLOBALS['options']['ecare_provider_emails'] = array();

echo "\n=== E. settings ===\n";
check('cleaned: switches to 0/1, list deduped, hotline digits only', $E::sanitize(array('approved' => 'on', 'admin_to' => "a@x.com\nA@x.com, junk", 'hotline' => '<b>01324-422215</b>')),
    array('reg_pending' => 0, 'reg_admin' => 0, 'approved' => 1, 'rejected' => 0, 'booking' => 0, 'admin_to' => 'a@x.com', 'hotline' => '01324-422215'));
check('everything is on until changed', array($E::enabled('pending'), $E::enabled('admin'), $E::enabled('approved'), $E::enabled('rejected'), $E::enabled('booking'), $E::enabled('nope')), array(true, true, true, true, true, false));

echo "\n=== F. admin send again / preview ===\n";
function admin_call($m, $get, $admin) { $_GET = $get; $GLOBALS['admin'] = $admin; try { ECare_Provider_Emails::$m(); } catch (Exception $e) { return $e->getMessage(); } return 'ok'; }
check('not an admin: refused', admin_call('handle_send', array('id' => 10, 'kind' => 'approved', '_wpnonce' => 'N:ecare_provider_mail_10'), false), 'die:403');
check('a nonce for another provider: refused', admin_call('handle_send', array('id' => 10, 'kind' => 'approved', '_wpnonce' => 'N:ecare_provider_mail_20'), true), 'nonce');
check('preview of an unknown kind: 404', admin_call('handle_preview', array('id' => 10, 'kind' => 'zzz', '_wpnonce' => 'N:ecare_provider_mail_10'), true), 'die:404');
check('nothing was sent by any of that', mails(), array());
$_GET = array(); $GLOBALS['admin'] = false;

echo "\n=== G. the booking dropdown (ECare_Ajax::update_booking_status) ===\n";
function check_ajax_referer() { return 1; }
class Json_Out extends Exception {}
function wp_send_json_success($d = null) { throw new Json_Out('ok'); }
function wp_send_json_error($d = null) { throw new Json_Out('err'); }
function intval_($v) { return (int) $v; }
if (!function_exists('add_shortcode')) { function add_shortcode() {} }
require_once __DIR__ . '/../includes/class-ecare-ajax.php';
function set_booking($id, $status) { $GLOBALS['admin'] = true; $_POST = array('booking_id' => $id, 'status' => $status); $GLOBALS['fired'] = array(); try { ECare_Ajax::update_booking_status(); } catch (Json_Out $e) {} $_POST = array(); }
$db->rows[40] = array('id' => 40, 'booking_type' => 'caregiver', 'provider_id' => 10, 'status' => 'pending') + $db->rows[5];
$db->rows[40]['id'] = 40; $db->rows[40]['status'] = 'pending';
set_booking(40, 'approved');
$ev = array_values(array_filter($GLOBALS['fired'], function ($f) { return $f[0] === 'ecare_booking_status_changed'; }));
check('admin sets Approved: the event carries new and old, and the caregiver is emailed', array($ev, to_subj(mails())),
    array(array(array('ecare_booking_status_changed', 40, 'approved', 'pending')), array('ayesha@x.com | New caregiver booking #40 — Monday, 5 October 2026')));
set_booking(40, 'approved');
check('the same status again: no event', array_values(array_filter($GLOBALS['fired'], function ($f) { return $f[0] === 'ecare_booking_status_changed'; })), array());

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
