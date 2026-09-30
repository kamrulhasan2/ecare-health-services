<?php
/**
 * Guards ECare_Lab_Offerings (each lab's price for a test) and the parts of
 * ECare_Lab_Tests built on it.
 *
 * What it protects:
 *   - a patient is never charged more than MRP, and a blank price means MRP
 *   - one row per lab; rows without a real lab or an MRP are dropped
 *   - saving updates, inserts and deletes so the table matches the form
 *   - only an active row with an active, published lab is bookable, cheapest first
 *   - a test is offered in an area only if one of its bookable labs covers it
 *   - the old front end's meta (_price, _lab_provider, areas) follows the labs,
 *     and an old test with no lab rows keeps its old data
 *   - publishing a new test with no lab is held as a draft
 */

define('ABSPATH', __DIR__ . '/');

$GLOBALS['terms'] = array(); $GLOBALS['term_meta'] = array();
$GLOBALS['posts'] = array(); $GLOBALS['meta'] = array(); $GLOBALS['obj_terms'] = array();
$GLOBALS['transients'] = array();

class WP_Error {}
function add_action() {} function add_filter() {}
function do_action($hook, ...$args) { $GLOBALS["actions"][] = $hook; }
function is_admin() { return false; }
function __($s, $d = null) { return $s; }
function _n($a, $b, $n, $d = null) { return $n == 1 ? $a : $b; }
function is_wp_error($v) { return $v instanceof WP_Error; }
function wp_unslash($v) { return is_array($v) ? array_map('wp_unslash', $v) : stripslashes((string) $v); }
function number_format_i18n($n, $d = 0) { return number_format($n, $d); }
function wp_is_post_revision() { return false; }
function get_current_user_id() { return 5; }
function set_transient($k, $v, $t) { $GLOBALS['transients'][$k] = $v; return true; }
function get_term($id, $tax = '') { return $GLOBALS['terms'][(int) $id] ?? null; }
function get_term_meta($id, $k, $s = false) { return $GLOBALS['term_meta'][$id][$k] ?? ''; }
function get_ancestors($id, $tax, $type) { $o = array(); $t = $GLOBALS['terms'][$id] ?? null; while ($t && $t->parent) { $o[] = $t->parent; $t = $GLOBALS['terms'][$t->parent] ?? null; } return $o; }
function get_terms($args) {
    $out = array();
    foreach ($GLOBALS['terms'] as $t) {
        if (isset($args['parent']) && (int) $t->parent !== (int) $args['parent']) continue;
        $out[] = ($args['fields'] ?? '') === 'ids' ? $t->term_id : $t;
    }
    return $out;
}
function wp_get_object_terms($id, $tax, $a = array()) { return $GLOBALS['obj_terms'][(int) $id] ?? array(); }
function wp_set_object_terms($id, $ids, $tax, $ap = false) { $GLOBALS['obj_terms'][(int) $id] = array_values($ids); return $ids; }
function get_post($id) { return $GLOBALS['posts'][(int) $id] ?? null; }
function get_post_type($id) { return $GLOBALS['posts'][(int) $id]->post_type ?? false; }
function get_the_title($id) { return $GLOBALS['posts'][(int) $id]->post_title ?? ''; }
function get_post_meta($id, $k, $s = false) { return $GLOBALS['meta'][$id][$k] ?? ''; }
function update_post_meta($id, $k, $v) { $GLOBALS['meta'][$id][$k] = $v; return true; }
function delete_post_meta($id, $k) { unset($GLOBALS['meta'][$id][$k]); return true; }

/** An in-memory offerings table that understands the few statements the class sends. */
class Fake_WPDB {
    public $prefix = 'wp_';
    public $rows = array();
    public $next = 1;
    public function prepare($q, ...$a) { $i = 0; return preg_replace_callback('/%[sd]/', function ($m) use (&$i, $a) { return $m[0] === '%d' ? (int) $a[$i++] : "'" . $a[$i++] . "'"; }, $q); }
    private function test_id($q) { return preg_match('/test_id = (\d+)/', $q, $m) ? (int) $m[1] : -1; }
    public function get_results($q) {
        $t = $this->test_id($q);
        $out = array_values(array_filter($this->rows, function ($r) use ($t) { return (int) $r['test_id'] === $t; }));
        usort($out, function ($a, $b) { return $a['id'] <=> $b['id']; });
        return array_map(function ($r) { return (object) $r; }, $out);
    }
    public function get_var($q) { return count($this->get_results($q)); }
    public function insert($table, $data, $f = null) { $data['id'] = $this->next++; $this->rows[$data['id']] = $data; return 1; }
    public function update($table, $data, $where, $f = null, $wf = null) { $this->rows[$where['id']] = array_merge($this->rows[$where['id']], $data); return 1; }
    public function delete($table, $where, $f = null) {
        foreach ($this->rows as $id => $r) { foreach ($where as $k => $v) { if ((int) $r[$k] !== (int) $v) continue 2; } unset($this->rows[$id]); }
        return 1;
    }
}
$GLOBALS['wpdb'] = new Fake_WPDB();

require_once __DIR__ . '/../includes/class-ecare-locations.php';
require_once __DIR__ . '/../includes/class-ecare-lab-providers.php';
require_once ($argv[1] ?? (__DIR__ . '/../includes/class-ecare-lab-offerings.php'));
require_once __DIR__ . '/../includes/class-ecare-lab-tests.php';

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; printf("  PASS  %s\n", $label); }
    else { $fail++; printf("  FAIL  %s\n          got:  %s\n          want: %s\n", $label, var_export($got, true), var_export($want, true)); }
}
$O = 'ECare_Lab_Offerings';

// Locations: Dhaka > Dhaka > Banani, Mirpur-1 ; Chattogram > Chattogram > Agrabad
function term($id, $name, $parent, $level) { $GLOBALS['terms'][$id] = (object) array('term_id' => $id, 'name' => $name, 'parent' => $parent); $GLOBALS['term_meta'][$id]['ecare_level'] = $level; }
term(1, 'Dhaka', 0, 'division'); term(10, 'Dhaka', 1, 'district'); term(11, 'Banani', 10, 'area'); term(12, 'Mirpur-1', 10, 'area');
term(2, 'Chattogram', 0, 'division'); term(20, 'Chattogram', 2, 'district'); term(21, 'Agrabad', 20, 'area');

function post($id, $type, $title, $status = 'publish') { $GLOBALS['posts'][$id] = (object) array('ID' => $id, 'post_type' => $type, 'post_status' => $status, 'post_title' => $title); }
post(100, 'ecare_lab_provider', 'Popular');     $GLOBALS['obj_terms'][100] = array(11);   // Banani only
post(101, 'ecare_lab_provider', 'LabAid');      $GLOBALS['obj_terms'][101] = array(20);   // all Chattogram
post(102, 'ecare_lab_provider', 'Closed Lab');  $GLOBALS['meta'][102]['_ecare_provider_status'] = 'inactive'; $GLOBALS['obj_terms'][102] = array(12);
post(103, 'ecare_lab_provider', 'Draft Lab', 'draft'); $GLOBALS['obj_terms'][103] = array(12);
post(900, 'page', 'Not a lab');
post(500, 'ecare_lab_test', 'FBS');

// ===========================================================================
echo "\n=== A. prices ===\n";
// ===========================================================================
$row = function ($mrp, $price) { return (object) array('mrp' => $mrp, 'price' => $price); };
check('a real discount is charged', $O::effective_price($row(600, 500)), 500.0);
check('no price means MRP', $O::effective_price($row(600, 0)), 600.0);
check('a price above MRP means MRP', $O::effective_price($row(600, 900)), 600.0);
check('percent off', $O::discount_percent($row(600, 500)), 17);
check('no discount, 0%', $O::discount_percent($row(600, 600)), 0);
check('you save: 4730 MRP at 1895 (Shukhee\'s example)', $O::savings($row(4730, 1895)), 2835.0);
check('you save: nothing without a discount', $O::savings($row(300, 0)), 0.0);

// ===========================================================================
echo "\n=== B. cleaning the form ===\n";
// ===========================================================================
$clean = $O::clean_rows(array(
    array('provider_id' => 100, 'mrp' => '600', 'price' => '500', 'material_cost' => '50', 'active' => true),
    array('provider_id' => 100, 'mrp' => '999', 'price' => '', 'active' => true),     // same lab again
    array('provider_id' => 900, 'mrp' => '300', 'active' => true),                     // a page
    array('provider_id' => 0,   'mrp' => '300', 'active' => true),                     // no lab
    array('provider_id' => 101, 'mrp' => '0',   'active' => true),                     // no MRP
    array('provider_id' => 101, 'mrp' => '450', 'price' => '700', 'material_cost' => '-5', 'active' => false),
));
check('two rows survive', count($clean), 2);
check('the first row for a lab wins', $clean[0], array('provider_id' => 100, 'mrp' => 600.0, 'price' => 500.0, 'material_cost' => 50.0, 'status' => 'active'));
check('a price above MRP is clamped, negative material cost is zero, inactive kept', $clean[1], array('provider_id' => 101, 'mrp' => 450.0, 'price' => 450.0, 'material_cost' => 0.0, 'status' => 'inactive'));

// ===========================================================================
echo "\n=== C. saving makes the table match the form ===\n";
// ===========================================================================
$post_form = function ($rows) {
    $_POST = array('ecare_offer_present' => '1', 'ecare_offer_provider' => array(), 'ecare_offer_mrp' => array(), 'ecare_offer_price' => array(), 'ecare_offer_material' => array(), 'ecare_offer_active' => array());
    foreach ($rows as $r) {
        $_POST['ecare_offer_provider'][] = (string) $r[0];
        $_POST['ecare_offer_mrp'][]      = (string) $r[1];
        $_POST['ecare_offer_price'][]    = (string) $r[2];
        $_POST['ecare_offer_material'][] = '';
        $_POST['ecare_offer_active'][]   = $r[3] ? '1' : '0';
    }
};
$post_form(array(array(100, 400, '', 1), array(101, 600, 350, 1), array(102, 200, '', 1)));
$O::save_from_post(500);
check('three rows stored', count($O::for_test(500)), 3);
$ids_before = array_map(function ($r) { return (int) $r->id; }, $O::for_test(500));

$post_form(array(array(101, 600, 320, 1), array(103, 300, '', 1)));
$O::save_from_post(500);
$rows = $O::for_test(500);
check('removed labs are deleted, a new one inserted', array_map(function ($r) { return (int) $r->provider_id; }, $rows), array(101, 103));
check('a kept lab is updated in place, not re-inserted', (int) $rows[0]->id, $ids_before[1]);
check('its new price is stored', (float) $rows[0]->price, 320.0);

$_POST = array();
$O::save_from_post(500);
check('a save without the table on the form leaves rows alone', count($O::for_test(500)), 2);
check('saving announces the change (so the catalogue cache clears)', in_array('ecare_lab_offerings_changed', $GLOBALS['actions'] ?? array(), true), true);

// ===========================================================================
echo "\n=== D. what a patient can book ===\n";
// ===========================================================================
$post_form(array(array(100, 400, '', 1), array(101, 600, 350, 1), array(102, 200, '', 1), array(103, 150, '', 1)));
$O::save_from_post(500);
$avail = array_map(function ($r) { return (int) $r->provider_id; }, $O::available_for_test(500));
check('inactive and draft labs are not bookable; cheapest first', $avail, array(101, 100));
check('cheapest bookable', (int) $O::cheapest(500)->provider_id, 101);
$post_form(array(array(100, 400, '', 0), array(101, 600, 350, 1)));
$O::save_from_post(500);
check('a switched-off row is not bookable', array_map(function ($r) { return (int) $r->provider_id; }, $O::available_for_test(500)), array(101));

// ===========================================================================
echo "\n=== E. where the test is offered ===\n";
// ===========================================================================
$post_form(array(array(100, 400, '', 1), array(101, 600, 350, 1), array(102, 200, '', 1)));
$O::save_from_post(500);
check('Banani (Popular)', ECare_Lab_Tests::serves_area(500, 11), true);
check('Agrabad (LabAid, whole district)', ECare_Lab_Tests::serves_area(500, 21), true);
check('Mirpur-1 only via an inactive lab: no', ECare_Lab_Tests::serves_area(500, 12), false);
check('all reachable areas', ECare_Lab_Tests::effective_area_ids(500), array(11, 21));

// ===========================================================================
echo "\n=== F. the old front end's meta ===\n";
// ===========================================================================
$GLOBALS['meta'][500] = array('_ecare_provider_id' => 100, '_ecare_coverage_mode' => 'custom');
$GLOBALS['obj_terms'][500] = array(11);
ECare_Lab_Tests::sync_legacy(500);
$m = $GLOBALS['meta'][500];
check('_price is the cheapest bookable price', $m['_price'], '350');
check('_lab_provider lists bookable labs, cheapest first', $m['_lab_provider'], 'LabAid, Popular');
check('areas', $m['_area'], 'Banani, Agrabad');
check('districts', $m['_district'], 'Dhaka, Chattogram');
check('the one-provider link from before is cleared', array(isset($m['_ecare_provider_id']), isset($m['_ecare_coverage_mode']), $GLOBALS['obj_terms'][500]), array(false, false, array()));
check('labs summary, in table order, marks the inactive lab', ECare_Lab_Tests::labs_summary(500), 'LabAid ৳350 · Popular ৳400 · Closed Lab ৳200 (lab inactive)');

post(501, 'ecare_lab_test', 'Old CBC');
$GLOBALS['meta'][501] = array('_lab_provider' => 'Ever Care,LabAid', '_area' => 'Mirpur', '_price' => '300');
ECare_Lab_Tests::sync_legacy(501);
check('an old test with no lab rows keeps its old data', array($GLOBALS['meta'][501]['_lab_provider'], $GLOBALS['meta'][501]['_price']), array('Ever Care,LabAid', '300'));
check('... and is not linked', ECare_Lab_Tests::is_linked(501), false);

// ===========================================================================
echo "\n=== G. publishing ===\n";
// ===========================================================================
$g = function ($id) { return ECare_Lab_Tests::guard_publish(array('post_type' => 'ecare_lab_test', 'post_status' => 'publish'), array('ID' => $id))['post_status']; };
post(600, 'ecare_lab_test', 'New');
$post_form(array());
check('a new test with no lab row is held as draft', $g(600), 'draft');
check('and the admin is told', $GLOBALS['transients']['ecare_lab_test_notice_5'] ?? '', 'no_lab');
$post_form(array(array(900, 300, '', 1)));
check('a row pointing at a page does not count', $g(600), 'draft');
$post_form(array(array(100, 300, '', 1)));
check('one real lab row publishes', $g(600), 'publish');
$post_form(array());
check('an old test with old data still publishes', $g(501), 'publish');
$_POST = array();
check('outside the edit form nothing is touched', $g(600), 'publish');

// ===========================================================================
echo "\n=== H. deleting ===\n";
// ===========================================================================
$O::on_delete_post(101);
check('deleting a lab removes its rows', array_map(function ($r) { return (int) $r->provider_id; }, $O::for_test(500)), array(100, 102));
$O::on_delete_post(500);
check('deleting a test removes its rows', $O::for_test(500), array());

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
