<?php
/**
 * Guards caregiver package pricing - finding #10, and the regression that came
 * with an earlier attempt at it.
 *
 * The booking modal and submit_caregiver_booking() both price from
 * ECare_Ajax::caregiver_packages(). If they ever stop agreeing, a customer is
 * quoted one number and charged another; a previous fix read the caregiver's
 * own price meta on the server while the UI kept showing the caregiver-type
 * rate, which on live data meant 1700 shown and 100 taken.
 */

define('ABSPATH', __DIR__ . '/');

$GLOBALS['meta'] = array();
$GLOBALS['terms'] = array();
$GLOBALS['term_meta'] = array();
$GLOBALS['options'] = array(
    'ecare_default_daily_12_price'      => 1700,
    'ecare_default_daily_24_price'      => 2200,
    'ecare_default_monthly_12_price'    => 30000,
    'ecare_default_monthly_24_price'    => 50000,
    'ecare_default_physio_regular_price' => 1500,
    'ecare_default_physio_premium_price' => 2000,
);

function add_action() {} function add_filter() {}
function __($s, $d = null) { return $s; }
function get_post_meta($id, $k, $single = false) { return $GLOBALS['meta'][$id][$k] ?? ''; }
function get_option($k, $default = false) { return $GLOBALS['options'][$k] ?? $default; }
function get_term_by($field, $value, $tax) {
    return isset($GLOBALS['terms'][$value]) ? (object) array('term_id' => $GLOBALS['terms'][$value]) : false;
}
function get_term_meta($id, $k, $single = false) { return $GLOBALS['term_meta'][$id][$k] ?? ''; }

require_once ($argv[1] ?? (__DIR__ . '/../includes/class-ecare-ajax.php'));

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; printf("  PASS  %s\n", $label); }
    else { $fail++; printf("  FAIL  %s\n          got:  %s\n          want: %s\n", $label, var_export($got, true), var_export($want, true)); }
}

// A caregiver type with the standard four packages at the usual rates.
$GLOBALS['terms']['Nurse'] = 7;
$GLOBALS['term_meta'][7]['ecare_packages'] = array(
    array('label' => 'Daily (12 Hours)',   'price' => 1700),
    array('label' => 'Daily (24 Hours)',   'price' => 2200),
    array('label' => 'Monthly (12 Hours)', 'price' => 30000),
    array('label' => 'Monthly (24 Hours)', 'price' => 50000),
);

echo "\n=== the caregiver type sets the rate by default ===\n";
$GLOBALS['meta'][101] = array('_provider_type' => 'Nurse');
$p = ECare_Ajax::caregiver_packages(101);
check('four packages offered', array_keys($p), array('Daily (12 Hours)', 'Daily (24 Hours)', 'Monthly (12 Hours)', 'Monthly (24 Hours)'));
check('daily 12h is the term rate', $p['Daily (12 Hours)'], 1700.0);

echo "\n=== an administrator can override one rate ===\n";
$GLOBALS['meta'][102] = array('_provider_type' => 'Nurse', '_daily_12_price' => '950');
$p = ECare_Ajax::caregiver_packages(102);
check('the overridden rate wins', $p['Daily (12 Hours)'], 950.0);
check('the others keep the term rate', $p['Daily (24 Hours)'], 2200.0);

echo "\n=== a blank field means standard, never free ===\n";
foreach (array('' => 'blank', '0' => 'zero', '-5' => 'negative') as $value => $label) {
    $GLOBALS['meta'][103] = array('_provider_type' => 'Nurse', '_daily_12_price' => $value);
    $p = ECare_Ajax::caregiver_packages(103);
    check("$label override falls back to the term rate", $p['Daily (12 Hours)'], 1700.0);
}

echo "\n=== the type falls back to the site defaults when it has no packages ===\n";
$GLOBALS['terms']['Nanny'] = 8;
$GLOBALS['term_meta'][8]['ecare_packages'] = '';
$GLOBALS['meta'][104] = array('_provider_type' => 'Nanny');
$p = ECare_Ajax::caregiver_packages(104);
check('defaults are used', $p['Daily (12 Hours)'], 1700.0);
check('all four are present', count($p), 4);

echo "\n=== physiotherapists have their own two packages ===\n";
$GLOBALS['terms']['Physiotherapist'] = 9;
$GLOBALS['term_meta'][9]['ecare_packages'] = '';
$GLOBALS['meta'][105] = array('_provider_type' => 'Physiotherapist');
$p = ECare_Ajax::caregiver_packages(105);
check('two packages', array_keys($p), array('Daily Regular (1 Hour)', 'Daily Premium (1 Hour)'));
check('regular rate', $p['Daily Regular (1 Hour)'], 1500.0);
$GLOBALS['meta'][106] = array('_provider_type' => 'Physiotherapist', '_daily_24_price' => '2750');
$p = ECare_Ajax::caregiver_packages(106);
check('premium override maps onto _daily_24_price', $p['Daily Premium (1 Hour)'], 2750.0);

echo "\n=== nothing priced at zero is ever bookable ===\n";
$GLOBALS['terms']['Senior Care'] = 10;
$GLOBALS['term_meta'][10]['ecare_packages'] = array(
    array('label' => 'Daily (12 Hours)', 'price' => 1700),
    array('label' => 'Trial Visit',      'price' => 0),
);
$GLOBALS['meta'][107] = array('_provider_type' => 'Senior Care');
$p = ECare_Ajax::caregiver_packages(107);
check('the zero-priced package is dropped', isset($p['Trial Visit']), false);
check('the priced one survives', $p['Daily (12 Hours)'], 1700.0);

echo "\n=== an unknown caregiver still yields something sane ===\n";
$GLOBALS['meta'][108] = array();
$p = ECare_Ajax::caregiver_packages(108);
check('falls back to the standard four', count($p), 4);

printf("\n---------------------------------------\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
