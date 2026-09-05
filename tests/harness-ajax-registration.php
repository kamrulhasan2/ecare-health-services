<?php
/**
 * Guards the AJAX registration map.
 *
 * ECare_Ajax::init() used to loop one list and register wp_ajax_nopriv_ for
 * every action in it, admin-only ones included. Nothing was open at the time
 * because each admin handler checked manage_options itself, but the default was
 * wrong: one forgotten capability check in a future handler would have exposed
 * an admin endpoint to the world. delete_caregiver calls wp_delete_post() with
 * force, so the blast radius was real.
 *
 * This asserts the split stays split.
 */

define('ABSPATH', __DIR__ . '/');

$GLOBALS['hooks'] = array();
function add_action($hook, $cb, $p = 10, $a = 1) { $GLOBALS['hooks'][] = $hook; }
function add_filter($hook, $cb, $p = 10, $a = 1) {}

require_once ($argv[1] ?? (__DIR__ . '/../includes/class-ecare-ajax.php'));
ECare_Ajax::init();

$hooks = $GLOBALS['hooks'];
$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; printf("  PASS  %s\n", $label); }
    else { $fail++; printf("  FAIL  %s\n          got:  %s\n          want: %s\n", $label, var_export($got, true), var_export($want, true)); }
}

// Anything a logged-out visitor must never be able to call.
$admin_only = array(
    'add_caregiver_type',
    'get_caregiver_types',
    'delete_caregiver_type',
    'delete_caregiver',
    'update_booking_status',
    'update_provider_status',
);

// Anything the public catalogue, guest checkout or sign-up forms need.
$public = array(
    'filter_caregivers',
    'get_caregiver_details',
    'get_locations',
    'filter_lab_tests',
    'add_lab_test_to_cart',
    'submit_caregiver_booking',
    'submit_caregiver_registration',
    'submit_ambulance_request',
    'submit_ambulance_registration',
    'create_family_member',
    'refresh_nonce',
);

echo "\n=== admin-only actions are not reachable by guests ===\n";
foreach ($admin_only as $a) {
    check("$a registered for logged-in users", in_array("wp_ajax_ecare_{$a}", $hooks, true), true);
    check("$a NOT registered as nopriv", in_array("wp_ajax_nopriv_ecare_{$a}", $hooks, true), false);
}

echo "\n=== public actions still work for guests ===\n";
foreach ($public as $a) {
    check("$a is nopriv", in_array("wp_ajax_nopriv_ecare_{$a}", $hooks, true), true);
}

echo "\n=== nothing unexpected slipped into the nopriv set ===\n";
$nopriv = array();
foreach ($hooks as $h) {
    if (strpos($h, 'wp_ajax_nopriv_ecare_') === 0) { $nopriv[] = substr($h, strlen('wp_ajax_nopriv_ecare_')); }
}
sort($nopriv); $expected = $public; sort($expected);
check('the nopriv set is exactly the public list', $nopriv, $expected);

printf("\n---------------------------------------\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
