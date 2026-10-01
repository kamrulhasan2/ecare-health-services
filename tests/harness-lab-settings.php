<?php
/**
 * Guards ECare_Lab_Settings: the numbers and schedule the lab checkout will use.
 *
 * What it protects:
 *   - advance payable is the configured percent, rounded up to a whole taka,
 *     never more than the total (Shukhee: 300 -> 60 at 20%)
 *   - report delivery fees
 *   - slots: bad input is dropped, not guessed at
 *   - only slots a collector can still reach are offered: not past, not
 *     inside the cut-off, not on a closed day, not beyond the booking window,
 *     not full
 *   - saving clamps every number and never leaves the service with no days
 *     or no slots
 */

define('ABSPATH', __DIR__ . '/');

$GLOBALS['options'] = array();
function add_action() {} function add_filter() {}
function is_admin() { return false; }
function __($s, $d = null) { return $s; }
function get_option($k, $d = false) { return $GLOBALS['options'][$k] ?? $d; }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); }
function esc_url_raw($u) { return preg_match('#^https?://#', $u) ? $u : ''; }
function wp_attachment_is_image($id) { return $id === 9; }

require_once ($argv[1] ?? (__DIR__ . '/../includes/class-ecare-lab-settings.php'));

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; printf("  PASS  %s\n", $label); }
    else { $fail++; printf("  FAIL  %s\n          got:  %s\n          want: %s\n", $label, var_export($got, true), var_export($want, true)); }
}
$S  = 'ECare_Lab_Settings';
$tz = new DateTimeZone('Asia/Dhaka');
$at = function ($s) use ($tz) { return new DateTimeImmutable($s, $tz); };
$set = function ($a) { $GLOBALS['options']['ecare_lab_settings'] = $a; };

// ===========================================================================
echo "\n=== A. money ===\n";
// ===========================================================================
check('20% of 300 is 60 (Shukhee)', $S::advance_amount(300), 60.0);
check('rounded up to a whole taka', $S::advance_amount(299), 60.0);
$set(array('advance_percent' => 100));
check('100% is the whole total', $S::advance_amount(1895), 1895.0);
$set(array('advance_percent' => 0));
check('0% is nothing online', $S::advance_amount(500), 0.0);
$set(array());
check('soft copy is free', $S::delivery_fee('soft'), 0.0);
check('hard copy default 200', $S::delivery_fee('hard'), 200.0);
check('both default 200', $S::delivery_fee('both'), 200.0);
check('unknown method is free', $S::delivery_fee('pigeon'), 0.0);

// ===========================================================================
echo "\n=== B. reading slots ===\n";
// ===========================================================================
check('tidied, sorted, repeats and junk dropped',
    $S::parse_slots("9:00 - 11:00\n07:00-09:00\nnoon\n11:00-10:00\n10:00-10:00\n25:00-26:00\n07:00-09:00\n22:00-24:00"),
    array('07:00-09:00', '09:00-11:00', '22:00-24:00'));
check('display label', $S::slot_label('07:00-09:00'), '7:00 AM - 9:00 AM');
check('noon and midnight', $S::slot_label('12:00-24:00'), '12:00 PM - 12:00 AM');

// ===========================================================================
echo "\n=== C. which slots are open ===\n";
// ===========================================================================
// Defaults: 7 days ahead, every day, 5 slots, 2-hour cut-off, no capacity.
$now = $at('2026-10-01 08:30');   // a Thursday
check('today: slots starting within 2h are gone',
    $S::open_slots('2026-10-01', $now), array('11:00-13:00', '15:00-17:00', '17:00-19:00'));
check('exactly at the cut-off is too late', $S::open_slots('2026-10-01', $at('2026-10-01 09:00')), array('15:00-17:00', '17:00-19:00'));
check('tomorrow: all five', count($S::open_slots('2026-10-02', $now)), 5);
check('yesterday: none', $S::open_slots('2026-09-30', $now), array());
check('day 7 is bookable', count($S::open_slots('2026-10-08', $now)), 5);
check('day 8 is not', $S::open_slots('2026-10-09', $now), array());
check('a malformed date is not', $S::open_slots('2026-02-30', $now), array());

$set(array('open_days' => array('sat', 'sun', 'mon', 'tue', 'wed', 'thu')));   // Friday closed
check('a closed weekday has no slots (2026-10-02 is a Friday)', $S::open_slots('2026-10-02', $now), array());

$set(array('slot_capacity' => 2));
$booked = array('2026-10-02|07:00-09:00' => 2, '2026-10-02|09:00-11:00' => 1);
check('a full slot is not offered, a half-full one is', array_slice($S::open_slots('2026-10-02', $now, $booked), 0, 2), array('09:00-11:00', '11:00-13:00'));

$set(array('days_ahead' => 1));
$late = $at('2026-10-01 18:00');
check('bookable dates skip a day with nothing left', $S::bookable_dates($late), array('2026-10-02'));

// ===========================================================================
echo "\n=== D. saving ===\n";
// ===========================================================================
$out = $S::sanitize(array(
    'advance_percent' => '150', 'service_charge' => '-5', 'hard_copy_fee' => '250', 'both_fee' => 'abc',
    'days_ahead' => '400', 'open_days' => array('fri', 'sat', 'bogus', 'sat'), 'slots' => "junk\n", 'cutoff_hours' => '-1',
    'slot_capacity' => '3', 'banner_id' => '10', 'banner_link' => 'javascript:alert(1)', 'hotline' => ' <b>16263</b> ',
    'steps' => array(array('title' => '', 'text' => 'Custom text'), array('title' => 'Mine', 'text' => '<i>x</i>')),
));
check('percent capped at 100', $out['advance_percent'], 100.0);
check('negative charge becomes 0', $out['service_charge'], 0.0);
check('non-numeric fee becomes 0', $out['both_fee'], 0.0);
check('days ahead capped at 60', $out['days_ahead'], 60);
check('open days: known, unique, week order', $out['open_days'], array('sat', 'fri'));
check('no valid slot falls back to the defaults, never none', $out['slots'], $S::defaults()['slots']);
check('negative cut-off becomes 0', $out['cutoff_hours'], 0);
check('a non-image banner is refused', $out['banner_id'], 0);
check('a javascript: link is refused', $out['banner_link'], '');
check('hotline tags stripped', $out['hotline'], '16263');
check('always four steps', count($out['steps']), 4);
check('an empty step title keeps the default', $out['steps'][0], array('title' => $S::defaults()['steps'][0]['title'], 'text' => 'Custom text'));
check('a step keeps its own title, markup stripped', $out['steps'][1], array('title' => 'Mine', 'text' => 'x'));
check('no days ticked falls back to every day', $S::sanitize(array('open_days' => array()))['open_days'], $S::DAYS);

echo "\n=== E. the go-live switch ===\n";
check('off by default: uploading the plugin changes nothing for patients', array($S::defaults()['new_front'], $S::sanitize(array())['new_front']), array(0, 0));
check('the hidden 0 then the ticked box: on', $S::sanitize(array('new_front' => '1'))['new_front'], 1);
check('unticked (only the hidden 0 posted): off', $S::sanitize(array('new_front' => '0'))['new_front'], 0);
$GLOBALS['options']['ecare_lab_settings'] = array('new_front' => 1);
check('is_live reads it', $S::is_live(), true);
$GLOBALS['options']['ecare_lab_settings'] = array();
check('a site that never saved settings is not live', $S::is_live(), false);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
