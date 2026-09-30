<?php
/**
 * Guards ECare_Lab_Tests: where a lab test is offered.
 *
 * One provider per test entry; the test is offered in every area the provider
 * serves, or a chosen subset of them. What it protects:
 *   - a test is never offered where its provider does not go, even when the
 *     subset names such an area or the provider's coverage shrinks later
 *   - saving an old test without choosing a provider leaves its old typed-in
 *     data alone (it would otherwise vanish from the site)
 *   - the old comma-list meta is rewritten from the new data, so the current
 *     front end keeps working until step 4 moves it over
 */

define('ABSPATH', __DIR__ . '/');

$GLOBALS['terms']      = array();
$GLOBALS['term_meta']  = array();
$GLOBALS['posts']      = array();
$GLOBALS['meta']       = array();
$GLOBALS['obj_terms']  = array();
$GLOBALS['transients'] = array();

class WP_Error { public function get_error_code() { return 'err'; } }

function add_action() {} function add_filter() {}
function is_admin() { return false; }
function __($s, $d = null) { return $s; }
function _n($one, $many, $n, $d = null) { return $n == 1 ? $one : $many; }
function is_wp_error($v) { return $v instanceof WP_Error; }
function wp_unslash($v) { return is_array($v) ? array_map('wp_unslash', $v) : stripslashes((string) $v); }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)); }
function wp_is_post_revision() { return false; }
function get_current_user_id() { return 5; }
function set_transient($k, $v, $t) { $GLOBALS['transients'][$k] = $v; return true; }

function get_term($id, $tax = '') { return $GLOBALS['terms'][(int) $id] ?? null; }
function get_term_meta($id, $k, $single = false) { return $GLOBALS['term_meta'][$id][$k] ?? ''; }
function get_ancestors($id, $tax, $type) {
    $out = array(); $t = $GLOBALS['terms'][$id] ?? null;
    while ($t && $t->parent) { $out[] = $t->parent; $t = $GLOBALS['terms'][$t->parent] ?? null; }
    return $out;
}
function get_terms($args) {
    $out = array();
    foreach ($GLOBALS['terms'] as $t) {
        if (isset($args['parent']) && (int) $t->parent !== (int) $args['parent']) continue;
        $out[] = ($args['fields'] ?? '') === 'ids' ? $t->term_id : $t;
    }
    return $out;
}
function wp_get_object_terms($id, $tax, $args = array()) { return $GLOBALS['obj_terms'][(int) $id] ?? array(); }
function wp_set_object_terms($id, $ids, $tax, $append = false) { $GLOBALS['obj_terms'][(int) $id] = array_values($ids); return $ids; }
function get_post($id) { return $GLOBALS['posts'][(int) $id] ?? null; }
function get_post_meta($id, $k, $single = false) { return $GLOBALS['meta'][$id][$k] ?? ''; }
function update_post_meta($id, $k, $v) { $GLOBALS['meta'][$id][$k] = $v; return true; }
function delete_post_meta($id, $k) { unset($GLOBALS['meta'][$id][$k]); return true; }

require_once __DIR__ . '/../includes/class-ecare-locations.php';
require_once __DIR__ . '/../includes/class-ecare-lab-providers.php';
require_once ($argv[1] ?? (__DIR__ . '/../includes/class-ecare-lab-tests.php'));

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; printf("  PASS  %s\n", $label); }
    else { $fail++; printf("  FAIL  %s\n          got:  %s\n          want: %s\n", $label, var_export($got, true), var_export($want, true)); }
}

function term($id, $name, $parent, $level) {
    $GLOBALS['terms'][$id] = (object) array('term_id' => $id, 'name' => $name, 'parent' => $parent);
    $GLOBALS['term_meta'][$id]['ecare_level'] = $level;
}
term(1,  'Dhaka',      0,  'division');
term(2,  'Chattogram', 0,  'division');
term(10, 'Dhaka',      1,  'district');
term(20, 'Chattogram', 2,  'district');
term(11, 'Banani',     10, 'area');
term(12, 'Mirpur-1',   10, 'area');
term(13, 'Mirpur-10',  10, 'area');
term(21, 'Agrabad',    20, 'area');
term(22, 'Banani',     20, 'area');

function post($id, $type, $title, $status = 'publish') {
    $GLOBALS['posts'][$id] = (object) array('ID' => $id, 'post_type' => $type, 'post_status' => $status, 'post_title' => $title);
}
post(100, 'ecare_lab_provider', 'Popular Diagnostic Centre');
$GLOBALS['obj_terms'][100] = array(11, 12, 20);      // Banani, Mirpur-1 in Dhaka + all of Chattogram
post(101, 'ecare_lab_provider', 'LabAid');
$GLOBALS['obj_terms'][101] = array();                 // no coverage yet
post(900, 'page', 'Not a provider');

// ===========================================================================
echo "\n=== A. districts expand to their areas ===\n";
// ===========================================================================
$e = array('ECare_Locations', 'expand_to_areas');
check('an area is itself', $e(array(11)), array(11));
check('a district is every area under it', $e(array(20)), array(21, 22));
check('mixed, de-duplicated, sorted', $e(array(20, 12, 11, 21)), array(11, 12, 21, 22));
check('divisions and junk reach nothing', $e(array(1, 0, -3, 999)), array());

// ===========================================================================
echo "\n=== B. a test follows its provider ===\n";
// ===========================================================================
post(500, 'ecare_lab_test', 'FBS');
$GLOBALS['meta'][500] = array('_ecare_provider_id' => 100);
check('default mode is the provider\'s coverage', ECare_Lab_Tests::mode(500), 'provider');
check('offered in all the provider\'s areas', ECare_Lab_Tests::effective_area_ids(500), array(11, 12, 21, 22));
check('served: Banani, Dhaka', ECare_Lab_Tests::serves_area(500, 11), true);
check('not served: Mirpur-10', ECare_Lab_Tests::serves_area(500, 13), false);
check('linked', ECare_Lab_Tests::is_linked(500), true);
check('summary by district', ECare_Lab_Tests::location_summary(500), 'Dhaka: 2 areas · Chattogram: 2 areas');

$GLOBALS['obj_terms'][100] = array(11);     // the provider cuts back to Banani only
check('shrinking the provider shrinks the test', ECare_Lab_Tests::effective_area_ids(500), array(11));
check('... and Agrabad is no longer served', ECare_Lab_Tests::serves_area(500, 21), false);
$GLOBALS['obj_terms'][100] = array(11, 12, 20);

// ===========================================================================
echo "\n=== C. a subset never escapes the provider ===\n";
// ===========================================================================
post(501, 'ecare_lab_test', 'CBC');
$GLOBALS['meta'][501] = array('_ecare_provider_id' => 100, '_ecare_coverage_mode' => 'custom');
$GLOBALS['obj_terms'][501] = array(10);     // "all of Dhaka district"
check('whole Dhaka narrows to the provider\'s Dhaka areas', ECare_Lab_Tests::effective_area_ids(501), array(11, 12));
check('Mirpur-10 is still out', ECare_Lab_Tests::serves_area(501, 13), false);
check('Chattogram is out of the subset', ECare_Lab_Tests::serves_area(501, 21), false);
$GLOBALS['obj_terms'][501] = array(13, 22);
check('an area outside the provider is ignored', ECare_Lab_Tests::effective_area_ids(501), array(22));
check('... and not served', ECare_Lab_Tests::serves_area(501, 13), false);
check('... while one inside is', ECare_Lab_Tests::serves_area(501, 22), true);

post(502, 'ecare_lab_test', 'Lipid');
$GLOBALS['meta'][502] = array('_ecare_provider_id' => 101);
check('a provider with no coverage offers nowhere', ECare_Lab_Tests::effective_area_ids(502), array());
check('... and the summary is empty', ECare_Lab_Tests::location_summary(502), '');

post(503, 'ecare_lab_test', 'Old');
$GLOBALS['meta'][503] = array('_lab_provider' => 'Popular', '_area' => 'Banani');
check('an old test is not linked', ECare_Lab_Tests::is_linked(503), false);
check('... has old data', ECare_Lab_Tests::has_legacy(503), true);
check('... and serves nothing through the new path', ECare_Lab_Tests::serves_area(503, 11), false);
$GLOBALS['meta'][504] = array('_ecare_provider_id' => 900);
post(504, 'ecare_lab_test', 'Bad link');
check('a provider id pointing at a page does not count', ECare_Lab_Tests::is_linked(504), false);

// ===========================================================================
echo "\n=== D. old meta is written from the new data ===\n";
// ===========================================================================
$v = ECare_Lab_Tests::legacy_values(500);
check('provider name', $v['_lab_provider'], 'Popular Diagnostic Centre');
check('one Banani, not two', $v['_area'], 'Banani, Mirpur-1, Agrabad');
check('districts', $v['_district'], 'Dhaka, Chattogram');
check('divisions', $v['_division'], 'Dhaka, Chattogram');
$v = ECare_Lab_Tests::legacy_values(501);
check('a subset writes only its areas', array($v['_area'], $v['_district']), array('Banani', 'Chattogram'));

// ===========================================================================
echo "\n=== E. saving the edit form ===\n";
// ===========================================================================
$_POST = array('_ecare_provider_id' => '0');
ECare_Lab_Tests::save_location(503);
check('no provider: old data is left alone', $GLOBALS['meta'][503]['_area'], 'Banani');
check('no provider: nothing new is stored', isset($GLOBALS['meta'][503]['_ecare_provider_id']), false);

$_POST = array('_ecare_provider_id' => '900');
ECare_Lab_Tests::save_location(503);
check('a non-provider id is refused', isset($GLOBALS['meta'][503]['_ecare_provider_id']), false);

$_POST = array('_ecare_provider_id' => '100', '_ecare_coverage_mode' => 'provider', 'ecare_test_coverage' => array('11'));
ECare_Lab_Tests::save_location(503);
check('choosing a provider links the test', ECare_Lab_Tests::is_linked(503), true);
check('provider mode stores no subset', $GLOBALS['obj_terms'][503], array());
check('old meta now mirrors the provider', $GLOBALS['meta'][503]['_area'], 'Banani, Mirpur-1, Agrabad');
check('old provider text is replaced', $GLOBALS['meta'][503]['_lab_provider'], 'Popular Diagnostic Centre');

$_POST = array('_ecare_provider_id' => '100', '_ecare_coverage_mode' => 'custom', 'ecare_test_coverage' => array('20', '21', '12', '1'));
ECare_Lab_Tests::save_location(503);
check('custom subset is cleaned (district folds its areas, division dropped)', $GLOBALS['obj_terms'][503], array(12, 20));
check('custom mode stored', $GLOBALS['meta'][503]['_ecare_coverage_mode'], 'custom');
check('old meta follows the subset', $GLOBALS['meta'][503]['_area'], 'Mirpur-1, Agrabad, Banani');

$_POST = array('_ecare_provider_id' => '100', '_ecare_coverage_mode' => 'nonsense');
ECare_Lab_Tests::save_location(503);
check('an unknown mode falls back to provider', array($GLOBALS['meta'][503]['_ecare_coverage_mode'], $GLOBALS['obj_terms'][503]), array('provider', array()));

// ===========================================================================
echo "\n=== F. publishing without a provider ===\n";
// ===========================================================================
$g = function ($id, $pid) {
    $_POST = array('ecare_lab_test_meta_nonce' => 'x', '_ecare_provider_id' => (string) $pid);
    return ECare_Lab_Tests::guard_publish(array('post_type' => 'ecare_lab_test', 'post_status' => 'publish'), array('ID' => $id))['post_status'];
};
post(600, 'ecare_lab_test', 'New');
check('a new test with no provider is held as draft', $g(600, 0), 'draft');
check('and the admin is told', $GLOBALS['transients']['ecare_lab_test_notice_5'] ?? '', 'no_provider');
check('with a provider it publishes', $g(600, 100), 'publish');
$GLOBALS['meta'][601] = array('_area' => 'Banani', '_lab_provider' => 'X');
post(601, 'ecare_lab_test', 'Old one');
check('an old test with old data still publishes', $g(601, 0), 'publish');
$_POST = array();
check('outside the edit form nothing is touched', ECare_Lab_Tests::guard_publish(array('post_type' => 'ecare_lab_test', 'post_status' => 'publish'), array('ID' => 600))['post_status'], 'publish');

$_POST = array();
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
