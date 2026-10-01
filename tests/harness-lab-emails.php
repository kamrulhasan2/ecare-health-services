<?php
/**
 * Guards ECare_Lab_Emails: the patient's "order confirmed" and the lab's
 * "new order" emails.
 *
 * What it protects:
 *   - both go when an order is confirmed (pending -> approved), and only then
 *   - each goes once, however often the payment hooks fire; Resend sends again
 *   - the patient's account email (never a placeholder made from a phone
 *     number); the lab's notification emails, all of them
 *   - turned off in Settings: not sent; no address: not sent, and the order's
 *     history says why; the mail server refusing: history says so, and it can
 *     be sent again later
 *   - the copy-to address gets a hidden copy
 *   - the emails say what each reader needs, and escape what they print
 */

define('ABSPATH', __DIR__ . '/');

function add_action() {}
function __($s, $d = null) { return $s; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_url($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_html__($s, $d = null) { return esc_html($s); }
function sanitize_email($e) { return trim((string) $e); }
function is_email($e) { return (bool) filter_var($e, FILTER_VALIDATE_EMAIL); }
function get_bloginfo($k) { return 'Meditaj &amp; Co'; }
function wp_specialchars_decode($s, $q = null) { return htmlspecialchars_decode($s, ENT_QUOTES); }
function wp_date($f, $ts) { return gmdate($f, $ts); }
$GLOBALS['users'] = array(5 => 'rahim@example.com', 6 => '01712345678@no-email.meditaj.com', 7 => '');
function get_userdata($id) { return isset($GLOBALS['users'][$id]) ? (object) array('user_email' => $GLOBALS['users'][$id]) : false; }
$GLOBALS['lab_emails'] = array(99 => 'orders@popular.test, Lab@Popular.test; orders@popular.test, nonsense', 103 => '');
function get_post_meta($id, $k, $s = false) { return $k === '_ecare_notify_emails' ? ($GLOBALS['lab_emails'][$id] ?? '') : ''; }

// WooCommerce's mailer, recording what it was asked to send.
$GLOBALS['sent'] = array(); $GLOBALS['mail_ok'] = true;
class Fake_Mailer {
    public function wrap_message($heading, $html) { return '<wc-wrap><h1>' . $heading . '</h1>' . $html . '</wc-wrap>'; }
    public function send($to, $subject, $message, $headers) { $GLOBALS['sent'][] = compact('to', 'subject', 'message', 'headers'); return $GLOBALS['mail_ok']; }
}
class Fake_WC { public function mailer() { return new Fake_Mailer(); } }
function WC() { return new Fake_WC(); }

class ECare_Lab_Settings {
    public static $s = array('email_patient' => 1, 'email_lab' => 1, 'email_copy' => '', 'hotline' => '09610-000000');
    public static function get($k) { return self::$s[$k] ?? null; }
    public static function slot_label($s) { return $s === '09:00-11:00' ? '9:00 AM - 11:00 AM' : $s; }
}
class ECare_Lab_Providers {
    public static function clean_emails($raw) {
        $out = array();
        foreach (preg_split('/[\s,;]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY) as $e) { if (is_email($e) && !isset($out[strtolower($e)])) { $out[strtolower($e)] = $e; } }
        return $out;
    }
}
class ECare_Lab_Front {
    public static function money($n) { $n = (float) $n; return '৳' . number_format($n, floor($n) == $n ? 0 : 2); }
    public static function url($w, $a = array()) { return 'https://site/' . $w . '/?' . http_build_query($a); }
}
class ECare_Lab_Test_Info { public static function details($id) { return array('fasting' => $id === 101 ? 'yes' : 'no'); } }
class ECare_Lab_Orders {
    public static $rows = array(); public static $notes = array();
    public static function get($id) { return isset(self::$rows[$id]) ? clone self::$rows[$id] : null; }
    public static function set_detail($id, $k, $v) { self::$rows[$id]->details[$k] = $v; return true; }
    public static function add_note($id, $note, $by = 0) { self::$notes[$id][] = $note; return true; }
}

require_once ($argv[1] ?? (__DIR__ . '/../includes/class-ecare-lab-emails.php'));

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; printf("  PASS  %s\n", $label); }
    else { $fail++; printf("  FAIL  %s\n          got:  %s\n          want: %s\n", $label, var_export($got, true), var_export($want, true)); }
}
function has($h, $n) { return strpos($h, $n) !== false; }
function order($id, $user = 5, $lab = 99, $extra = array()) {
    ECare_Lab_Orders::$rows[$id] = (object) ($extra + array(
        'id' => $id, 'user_id' => $user, 'lab_provider_id' => $lab, 'is_new' => true, 'status' => 'approved', 'total_amount' => 1505,
        'patient_name' => 'Rahim <Uddin>', 'contact_phone' => '01712345678', 'address' => 'House 7, Mirpur-1',
        'details' => array(
            'lab' => array('id' => $lab, 'name' => 'Popular & Co'), 'date' => '2026-10-02', 'slot' => '09:00-11:00', 'delivery' => 'both', 'note' => 'Ring <twice>',
            'address' => array('text' => 'House 7, Road 3, Mirpur-1, Dhaka'),
            'items' => array(array('test_id' => 101, 'title' => 'FBS <b>', 'patients' => 2, 'price' => 400, 'line_total' => 800), array('test_id' => 105, 'title' => 'Diabetes Care Package', 'patients' => 1, 'price' => 650, 'line_total' => 650)),
            'quote' => array('subtotal_mrp' => 1600.0, 'special' => 150.0, 'material' => 0.0, 'coupon' => 145.0, 'delivery' => 200.0, 'service' => 0.0, 'total' => 1505.0, 'advance' => 301.0, 'later' => 1204.0),
        ),
    ));
}
$E = 'ECare_Lab_Emails';

// ===========================================================================
echo "\n=== A. when ===\n";
// ===========================================================================
order(10);
$E::on_status(10, 'sample_collected', 'approved');
$E::on_status(10, 'approved', 'cancelled');
check('only Pending -> Confirmed sends anything', $GLOBALS['sent'], array());
$E::on_status(10, 'approved', 'pending');
check('confirmed: the patient and the lab, one email each', array_map(function ($m) { return $m['to']; }, $GLOBALS['sent']), array(array('rahim@example.com'), array('orders@popular.test', 'Lab@Popular.test')));
check('...and each is remembered', array_keys(ECare_Lab_Orders::$rows[10]->details['mailed']), array('patient', 'lab'));
check('...and written in the order\'s history', ECare_Lab_Orders::$notes[10], array('Patient email sent to rahim@example.com.', 'Lab email sent to orders@popular.test, Lab@Popular.test.'));
$E::on_status(10, 'approved', 'pending'); $E::on_status(10, 'approved', 'pending');
check('the payment hooks fire again: nothing more is sent', count($GLOBALS['sent']), 2);
check('Resend sends again on purpose', array($E::send(10, 'lab', true), count($GLOBALS['sent'])), array('sent', 3));

// ===========================================================================
echo "\n=== B. who does not get one, and why ===\n";
// ===========================================================================
$GLOBALS['sent'] = array();
order(11, 6, 103);
check('a placeholder address made from a phone number is not a mailbox', $E::send(11, 'patient'), 'no_address');
check('a lab with no notification email', $E::send(11, 'lab'), 'no_address');
check('...nothing went out, and the history says why', array($GLOBALS['sent'], ECare_Lab_Orders::$notes[11]), array(array(), array(
    'Patient email not sent: the account has no email address.',
    'Lab email not sent: the lab has no notification email (Lab Providers).',
)));
order(12, 7);
check('a guest order with no account email', $E::send(12, 'patient'), 'no_address');
ECare_Lab_Settings::$s['email_patient'] = 0;
order(13);
check('turned off in Settings: not sent, and nothing noted', array($E::send(13, 'patient'), isset(ECare_Lab_Orders::$notes[13])), array('disabled', false));
check('...but Resend still works when asked for', $E::send(13, 'patient', true), 'sent');
ECare_Lab_Settings::$s['email_patient'] = 1;
order(14, 5, 99, array('is_new' => false));
check('an old one-row-per-test booking gets nothing', $E::send(14, 'patient'), 'missing');
check('an unknown recipient type', $E::send(13, 'boss'), 'missing');

$GLOBALS['mail_ok'] = false;
order(15);
check('the mail server refuses', $E::send(15, 'patient'), 'failed');
check('...noted, and not remembered as sent, so it can go later', array(end(ECare_Lab_Orders::$notes[15]), isset(ECare_Lab_Orders::$rows[15]->details['mailed'])), array('Patient email to rahim@example.com could not be sent (the mail server refused it).', false));
$GLOBALS['mail_ok'] = true;
check('...and it does', $E::send(15, 'patient'), 'sent');

// ===========================================================================
echo "\n=== C. what they say ===\n";
// ===========================================================================
$GLOBALS['sent'] = array();
ECare_Lab_Settings::$s['email_copy'] = 'boss@meditaj.test, junk';
order(20);
$E::send(20, 'patient'); $E::send(20, 'lab');
list($p, $l) = $GLOBALS['sent'];
check('a hidden copy to the copy-to address', array($p['headers'], $l['headers']), array("Content-Type: text/html\r\nBcc: boss@meditaj.test\r\n", "Content-Type: text/html\r\nBcc: boss@meditaj.test\r\n"));
check('in WooCommerce\'s email design', strpos($p['message'], '<wc-wrap><h1>Your lab order is confirmed</h1>') === 0, true);

check('patient subject', $p['subject'], 'Your lab order #20 is confirmed — Meditaj & Co');
check('patient: greeting, lab, when, where', array(has($p['message'], 'Hi Rahim &lt;Uddin&gt;,'), has($p['message'], 'Popular &amp; Co'), has($p['message'], 'Friday, 2 October 2026, 9:00 AM - 11:00 AM'), has($p['message'], 'House 7, Road 3, Mirpur-1, Dhaka')), array(true, true, true, true));
check('patient: tests escaped, with patients and totals', array(has($p['message'], 'FBS &lt;b&gt;'), has($p['message'], '<b>'), has($p['message'], '>৳800<')), array(true, false, true));
check('patient: the money - discounts minus, empty rows left out, advance and the rest', array(
    has($p['message'], '>Coupon Discount</th><td style="text-align:left;padding:6px 0;">−৳145'), has($p['message'], 'External Material Cost'),
    has($p['message'], 'Advance paid'), has($p['message'], '<strong>৳1,204</strong>'),
), array(true, false, true, true));
check('patient: a fasting test gets a reminder', has($p['message'], 'FBS &lt;b&gt; needs fasting'), true);
check('patient: My Lab Orders and the hotline', array(has($p['message'], 'https://site/cart/?step=orders'), has($p['message'], '09610-000000')), array(true, true));
check('patient: the lab\'s own note is not echoed back raw', has($p['message'], 'Ring <twice>'), false);

check('lab subject names the slot', $l['subject'], 'New lab order #20 — Fri 2 Oct, 9:00 AM - 11:00 AM');
check('lab: patient, tappable phone, address, report, note (escaped)', array(
    has($l['message'], 'Rahim &lt;Uddin&gt;'), has($l['message'], '<a href="tel:01712345678">01712345678</a>'),
    has($l['message'], 'House 7, Road 3, Mirpur-1, Dhaka'), has($l['message'], 'Soft and hard copy'), has($l['message'], 'Ring &lt;twice&gt;'),
), array(true, true, true, true, true));
check('lab: what was paid online and what to collect', array(has($l['message'], 'Paid online (advance)'), has($l['message'], 'To collect at sample collection</th><td style="text-align:left;padding:6px 0;"><strong>৳1,204</strong>')), array(true, true));
check('lab: no patient-only links', has($l['message'], 'step=orders'), false);
ECare_Lab_Settings::$s['hotline'] = '';
order(21);
ECare_Lab_Orders::$rows[21]->details['quote']['coupon'] = 0.0;
check('no coupon used: no coupon row', has($E::build(ECare_Lab_Orders::get(21), 'patient')['html'], 'Coupon Discount'), false);
check('no hotline set: no hotline line', has($E::build(ECare_Lab_Orders::get(21), 'patient')['html'], 'Questions? Call us'), false);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
