<?php
/**
 * Guards ECare_Lab_Packages: what a health package contains.
 *
 * What it protects:
 *   - only single catalogue tests go in a package: never the package itself,
 *     never another package, never a page, never the same test twice
 *   - a test that later becomes a package drops out instead of nesting
 *   - "Includes N tests" counts catalogue tests plus other items
 *   - the per-lab guide price sums that lab's active MRPs and counts what it
 *     does not offer
 */

define('ABSPATH', __DIR__ . '/');

$GLOBALS['posts'] = array(); $GLOBALS['meta'] = array(); $GLOBALS['offers'] = array();

function add_action() {} function add_filter() {}
function is_admin() { return false; }
function __($s, $d = null) { return $s; }
function wp_unslash($v) { return is_array($v) ? array_map('wp_unslash', $v) : stripslashes((string) $v); }
function sanitize_text_field($s) { return trim(preg_replace('/\s+/', ' ', strip_tags((string) $s))); }
function wp_verify_nonce($n, $a) { return $n === 'good' && $a === 'ecare_lab_package'; }
function wp_is_post_revision() { return false; }
function current_user_can() { return true; }
function get_post($id) { return $GLOBALS['posts'][(int) $id] ?? null; }
function get_post_meta($id, $k, $s = false) { return $GLOBALS['meta'][$id][$k] ?? ''; }
function update_post_meta($id, $k, $v) { $GLOBALS['meta'][$id][$k] = $v; return true; }

// Only for_test() is needed from the offerings class; stand it in directly.
class ECare_Lab_Offerings {
    const STATUS_ACTIVE = 'active';
    public static function for_test($id) { return array_map(function ($r) { return (object) $r; }, $GLOBALS['offers'][$id] ?? array()); }
    public static function effective_price($row) { $m = (float) $row->mrp; $p = (float) $row->price; return ($p > 0 && $p <= $m) ? $p : $m; }
}
require_once __DIR__ . '/../includes/class-ecare-lab-test-info.php';
require_once ($argv[1] ?? (__DIR__ . '/../includes/class-ecare-lab-packages.php'));

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; printf("  PASS  %s\n", $label); }
    else { $fail++; printf("  FAIL  %s\n          got:  %s\n          want: %s\n", $label, var_export($got, true), var_export($want, true)); }
}
$P = 'ECare_Lab_Packages';
function post($id, $title, $type = 'ecare_lab_test', $status = 'publish', $pkg = false) {
    $GLOBALS['posts'][$id] = (object) array('ID' => $id, 'post_type' => $type, 'post_status' => $status, 'post_title' => $title);
    if ($pkg) { $GLOBALS['meta'][$id]['_ecare_test_type'] = 'package'; }
}
post(1, 'T3, T4, TSH'); post(2, 'FBS'); post(3, 'Lipid Profile'); post(4, 'Old', 'ecare_lab_test', 'trash');
post(5, 'Other Package', 'ecare_lab_test', 'publish', true);
post(6, 'A page', 'page');
post(7, 'Draft test', 'ecare_lab_test', 'draft');
post(50, 'Thyroid & Metabolic Package', 'ecare_lab_test', 'publish', true);

// ===========================================================================
echo "\n=== A. what can go in ===\n";
// ===========================================================================
check('single tests kept in order; self, package, page, trash, repeats and junk dropped',
    $P::clean_test_ids(50, array('3', 1, 50, 5, 6, 4, 1, 0, -2, 'x', 2, 7)), array(3, 1, 2, 7));
post(51, 'Being turned into a package');   // type not stored yet in this request
check('a test never includes itself, even before its type is saved', $P::clean_test_ids(51, array(51, 2)), array(2));
for ($i = 1000; $i < 1060; $i++) { post($i, 'T' . $i); }
check('the cap holds at 50', count($P::clean_test_ids(50, range(1000, 1059))), 50);
check('extra items: blanks and case repeats dropped', $P::clean_extra("Serum Calcium\r\n\r\n  serum calcium \nHbA1c\n<b>Vit D</b>"), array('Serum Calcium', 'HbA1c', 'Vit D'));

// ===========================================================================
echo "\n=== B. saving ===\n";
// ===========================================================================
$_POST = array('ecare_package_tests' => array('1'));
$P::save(50, null);
check('no nonce, nothing saved', isset($GLOBALS['meta'][50]['_ecare_package_tests']), false);
$_POST = array('ecare_lab_package_nonce' => 'good', 'ecare_package_tests' => array('1', '2', '3', '5'), 'ecare_package_extra' => "Serum Calcium");
$P::save(50, null);
check('saved contents', $P::included_test_ids(50), array(1, 2, 3));
check('count includes other items', $P::item_count(50), 4);
$GLOBALS['meta'][3]['_ecare_test_type'] = 'package';
check('a test that became a package drops out', $P::included_test_ids(50), array(1, 2));
unset($GLOBALS['meta'][3]['_ecare_test_type']);
$GLOBALS['posts'][2]->post_status = 'trash';
check('a trashed test drops out', $P::included_test_ids(50), array(1, 3));
$GLOBALS['posts'][2]->post_status = 'publish';

// ===========================================================================
echo "\n=== C. the per-lab guide price ===\n";
// ===========================================================================
// Lab 100 (Dr Lal) offers all three; lab 101 (Probe) offers two, one switched off.
$GLOBALS['offers'][1] = array(array('provider_id' => 100, 'mrp' => 2100, 'price' => 1800, 'status' => 'active'), array('provider_id' => 101, 'mrp' => 2200, 'price' => 0, 'status' => 'active'));
$GLOBALS['offers'][2] = array(array('provider_id' => 100, 'mrp' => 150, 'price' => 0, 'status' => 'active'), array('provider_id' => 101, 'mrp' => 200, 'price' => 0, 'status' => 'inactive'));
$GLOBALS['offers'][3] = array(array('provider_id' => 100, 'mrp' => 1000, 'price' => 0, 'status' => 'active'));
check('Dr Lal: sum of MRPs, nothing missing', $P::lab_sum(50, 100), array(3250.0, 0));
check('Probe: switched-off and absent tests are missing', $P::lab_sum(50, 101), array(2200.0, 2));
check('a lab with nothing', $P::lab_sum(50, 999), array(0.0, 3));

$_POST = array();
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
