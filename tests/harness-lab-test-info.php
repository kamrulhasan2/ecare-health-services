<?php
/**
 * Guards ECare_Lab_Test_Info: the descriptive fields of a lab test (type,
 * subtitle, fasting, report time, who it is for, FAQ).
 *
 * What it protects:
 *   - labels read the way the detail page will print them ("12 hours",
 *     "5-7 days", "Women, 18-45 years")
 *   - a test saved before these fields existed still shows its old
 *     turnaround, and the old front end keeps getting whole days
 *   - posted input is cleaned: bad ranges, ages, units and FAQ rows
 */

define('ABSPATH', __DIR__ . '/');

$GLOBALS['meta'] = array();

function add_action() {} function add_filter() {}
function is_admin() { return false; }
function __($s, $d = null) { return $s; }
function _n($one, $many, $n, $d = null) { return $n == 1 ? $one : $many; }
function wp_unslash($v) { return is_array($v) ? array_map('wp_unslash', $v) : stripslashes((string) $v); }
function sanitize_text_field($s) { return trim(preg_replace('/\s+/', ' ', strip_tags((string) $s))); }
function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)); }
function wp_verify_nonce($n, $a) { return $n === 'good' && $a === 'ecare_lab_test_info'; }
function wp_is_post_revision() { return false; }
function current_user_can() { return true; }
function get_post_meta($id, $k, $single = false) { return $GLOBALS['meta'][$id][$k] ?? ''; }
function update_post_meta($id, $k, $v) { $GLOBALS['meta'][$id][$k] = $v; return true; }
function delete_post_meta($id, $k) { unset($GLOBALS['meta'][$id][$k]); return true; }

require_once ($argv[1] ?? (__DIR__ . '/../includes/class-ecare-lab-test-info.php'));

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; printf("  PASS  %s\n", $label); }
    else { $fail++; printf("  FAIL  %s\n          got:  %s\n          want: %s\n", $label, var_export($got, true), var_export($want, true)); }
}
$I = 'ECare_Lab_Test_Info';

// ===========================================================================
echo "\n=== A. report time reads the way the page prints it ===\n";
// ===========================================================================
$GLOBALS['meta'][1] = array('_ecare_report_min' => 12, '_ecare_report_unit' => 'hours');
check('hours', $I::report_label(1), '12 hours');
$GLOBALS['meta'][2] = array('_ecare_report_min' => 1, '_ecare_report_unit' => 'days');
check('one day is singular', $I::report_label(2), '1 day');
$GLOBALS['meta'][3] = array('_ecare_report_min' => 5, '_ecare_report_max' => 7, '_ecare_report_unit' => 'days');
check('a range', $I::report_label(3), '5-7 days');
$GLOBALS['meta'][4] = array('_turnaround_days' => '2');
check('an old test falls back to its turnaround days', $I::report_label(4), '2 days');
$GLOBALS['meta'][5] = array();
check('nothing set, nothing printed', $I::report_label(5), '');

// ===========================================================================
echo "\n=== B. who a test is for ===\n";
// ===========================================================================
$GLOBALS['meta'][10] = array('_ecare_gender' => 'women', '_ecare_age_min' => 18, '_ecare_age_max' => 45);
check('women with an age range', $I::available_for_label(10), 'Women, 18-45 years');
$GLOBALS['meta'][11] = array('_ecare_gender' => 'all', '_ecare_age_min' => 1, '_ecare_age_max' => 70);
check('everyone with an age range', $I::available_for_label(11), 'Men & Women, 1-70 years');
$GLOBALS['meta'][12] = array('_ecare_gender' => 'men');
check('men, no ages', $I::available_for_label(12), 'Men');
$GLOBALS['meta'][13] = array('_ecare_gender' => 'all', '_ecare_age_min' => 40);
check('only a lower bound', $I::available_for_label(13), 'Men & Women, 40+ years');
check('never set, nothing printed', $I::available_for_label(5), '');

// ===========================================================================
echo "\n=== C. cleaning input ===\n";
// ===========================================================================
check('max not above min is dropped', $I::clean_report(5, 3, 'days'), array(5, 0, 'days'));
check('a real range is kept', $I::clean_report(5, 7, 'days'), array(5, 7, 'days'));
check('an unknown unit becomes days', $I::clean_report(2, 0, 'weeks'), array(2, 0, 'days'));
check('negative becomes zero', $I::clean_report(-3, 4, 'hours'), array(0, 0, 'hours'));
check('12 hours is 1 day for the old front end', $I::turnaround_days(12, 'hours'), 1);
check('30 hours is 2 days', $I::turnaround_days(30, 'hours'), 2);
check('days pass through', $I::turnaround_days(3, 'days'), 3);
check('ages are capped at 120', $I::clean_ages(0, 300), array(0, 120));
check('a max below the min is dropped', $I::clean_ages(40, 18), array(40, 0));
check('FAQ rows pair up; empty questions go', $I::clean_faq(array('What is it?', '', ' Why? '), array('A test.', 'orphan', 'Because.')),
    array(array('q' => 'What is it?', 'a' => 'A test.'), array('q' => 'Why?', 'a' => 'Because.')));
check('FAQ markup is stripped', $I::clean_faq(array('<b>Q</b>'), array('<script>x</script>A')), array(array('q' => 'Q', 'a' => 'xA')));
check('FAQ is capped', count($I::clean_faq(array_fill(0, 50, 'q'), array())), $I::MAX_FAQ);

// ===========================================================================
echo "\n=== D. saving ===\n";
// ===========================================================================
$_POST = array('_ecare_subtitle' => 'x');
$I::save(20, null);
check('no nonce, nothing saved', $GLOBALS['meta'][20] ?? null, null);

$_POST = array(
    'ecare_lab_test_info_nonce' => 'good',
    '_ecare_test_type'     => 'package',
    '_ecare_subtitle'      => '  Thyroid <i>function</i> panel ',
    '_ecare_also_known_as' => 'TFT',
    '_ecare_parameters'    => '5',
    '_ecare_fasting'       => 'yes',
    '_ecare_report_min'    => '12',
    '_ecare_report_max'    => '',
    '_ecare_report_unit'   => 'hours',
    '_ecare_gender'        => 'women',
    '_ecare_age_min'       => '18',
    '_ecare_age_max'       => '100',
    'ecare_faq_q'          => array('What is it?'),
    'ecare_faq_a'          => array('A thyroid panel.'),
);
$I::save(20, null);
$d = $I::details(20);
check('type', $d['type'], 'package');
check('subtitle is cleaned', $d['subtitle'], 'Thyroid function panel');
check('fasting', $d['fasting'], 'yes');
check('report', $d['report'], '12 hours');
check('old turnaround follows (1 day)', $GLOBALS['meta'][20]['_turnaround_days'], 1);
check('available for', $d['available_for'], 'Women, 18-100 years');
check('faq', $d['faq'], array(array('q' => 'What is it?', 'a' => 'A thyroid panel.')));

$_POST['_ecare_test_type'] = 'weird';
$_POST['_ecare_fasting']   = 'maybe';
$_POST['_ecare_gender']    = 'robots';
$I::save(20, null);
check('unknown type falls back to single', $I::type(20), 'single');
check('unknown fasting is not stated', $I::details(20)['fasting'], '');
check('unknown gender is everyone', $GLOBALS['meta'][20]['_ecare_gender'], 'all');

$_POST['_ecare_report_min'] = '';
$I::save(20, null);
check('emptying the report time clears the old turnaround too', array($I::report_label(20), isset($GLOBALS['meta'][20]['_turnaround_days'])), array('', false));

// An old test opened and saved without touching anything: the form was
// pre-filled from _turnaround_days, so the same value comes back.
$GLOBALS['meta'][30] = array('_turnaround_days' => '3');
list($min, $max, $unit) = $I::report_time(30);
$_POST = array('ecare_lab_test_info_nonce' => 'good', '_ecare_report_min' => (string) $min, '_ecare_report_max' => '', '_ecare_report_unit' => $unit);
$I::save(30, null);
check('an untouched old test keeps its 3 days', array($I::report_label(30), $GLOBALS['meta'][30]['_turnaround_days']), array('3 days', 3));

$_POST = array();
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
