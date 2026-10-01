<?php
/**
 * Guards ECare_Lab_Tests: a test's reach and the bridge to the old front end.
 * (Offering rows themselves are covered by harness-lab-offerings.php.)
 *
 * What it protects:
 *   - old free-text location data is recognised, and survives until labs are added
 *   - when no lab on a test is bookable any more, the old front end stops
 *     offering it (empty lists, no price) instead of keeping stale areas
 *   - a whole-district lab reaches every area of that district, including
 *     ones added later
 *   - after go-live the old lists are no longer written; turning the switch
 *     off again rewrites them for every test with labs (and only then)
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
    public function get_col($q) { return array_values(array_unique(array_map(function ($r) { return $r['test_id']; }, $this->rows))); }
    public function insert($table, $data, $f = null) { $data['id'] = $this->next++; $this->rows[$data['id']] = $data; return 1; }
    public function update($table, $data, $where, $f = null, $wf = null) { $this->rows[$where['id']] = array_merge($this->rows[$where['id']], $data); return 1; }
    public function delete($table, $where, $f = null) {
        foreach ($this->rows as $id => $r) { foreach ($where as $k => $v) { if ((int) $r[$k] !== (int) $v) continue 2; } unset($this->rows[$id]); }
        return 1;
    }
}
$GLOBALS['wpdb'] = new Fake_WPDB();
// The switch-over (Lab Settings -> Go live); off unless a test says so.
class ECare_Lab_Settings { const OPTION = 'ecare_lab_settings'; public static $live = false; public static function is_live() { return self::$live; } }

require_once __DIR__ . '/../includes/class-ecare-locations.php';
require_once __DIR__ . '/../includes/class-ecare-lab-providers.php';
require_once __DIR__ . '/../includes/class-ecare-lab-offerings.php';
require_once ($argv[1] ?? (__DIR__ . '/../includes/class-ecare-lab-tests.php'));

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; printf("  PASS  %s\n", $label); }
    else { $fail++; printf("  FAIL  %s\n          got:  %s\n          want: %s\n", $label, var_export($got, true), var_export($want, true)); }
}
$T = 'ECare_Lab_Tests';
function term($id, $name, $parent, $level) { $GLOBALS['terms'][$id] = (object) array('term_id' => $id, 'name' => $name, 'parent' => $parent); $GLOBALS['term_meta'][$id]['ecare_level'] = $level; }
term(1, 'Dhaka', 0, 'division'); term(10, 'Dhaka', 1, 'district'); term(11, 'Banani', 10, 'area');
function post($id, $type, $title, $status = 'publish') { $GLOBALS['posts'][$id] = (object) array('ID' => $id, 'post_type' => $type, 'post_status' => $status, 'post_title' => $title); }
post(100, 'ecare_lab_provider', 'Popular'); $GLOBALS['obj_terms'][100] = array(10);   // whole Dhaka district
post(500, 'ecare_lab_test', 'FBS');
$add = function ($test, $pid, $mrp, $status = 'active') { $GLOBALS['wpdb']->insert('t', array('test_id' => $test, 'provider_id' => $pid, 'mrp' => $mrp, 'price' => $mrp, 'material_cost' => 0, 'status' => $status)); };

// ===========================================================================
echo "\n=== A. old data ===\n";
// ===========================================================================
$GLOBALS['meta'][500] = array('_area' => '  ');
check('whitespace is not old data', $T::has_legacy(500), false);
$GLOBALS['meta'][500] = array('_district' => 'Dhaka');
check('any one list is old data', $T::has_legacy(500), true);
check('no rows: not linked', $T::is_linked(500), false);

// ===========================================================================
echo "\n=== B. a whole-district lab reaches areas added later ===\n";
// ===========================================================================
$add(500, 100, 400);
check('Banani is reached', $T::effective_area_ids(500), array(11));
term(12, 'Gulshan-1', 10, 'area');
check('a new Dhaka area is reached with no edit to the lab', $T::effective_area_ids(500), array(11, 12));
check('... and served', $T::serves_area(500, 12), true);

// ===========================================================================
echo "\n=== C. no bookable lab left ===\n";
// ===========================================================================
$T::sync_legacy(500);
check('while bookable, the old front end gets the areas', $GLOBALS['meta'][500]['_area'], 'Banani, Gulshan-1');
$GLOBALS['wpdb']->rows = array();
$add(500, 100, 400, 'inactive');
$T::sync_legacy(500);
$m = $GLOBALS['meta'][500];
check('switched off: lists are emptied, not left stale', array($m['_lab_provider'], $m['_area'], $m['_district'], $m['_price']), array('', '', '', ''));
check('the test still counts as linked (it has a row)', $T::is_linked(500), true);

// ===========================================================================
echo "\n=== D. go-live: the old lists stop, and come back on switching off ===\n";
// ===========================================================================
$GLOBALS['wpdb']->rows = array();
$add(500, 100, 400);
$T::sync_legacy(500);
check('before go-live: the old lists are written', array($GLOBALS['meta'][500]['_price'], $GLOBALS['meta'][500]['_lab_provider']), array('400', 'Popular'));
ECare_Lab_Settings::$live = true;
$GLOBALS['wpdb']->update('t', array('mrp' => 450, 'price' => 450), array('id' => array_key_last($GLOBALS['wpdb']->rows)));
$GLOBALS['meta'][500]['_ecare_provider_id'] = 9;
$T::sync_legacy(500);
check('live: the old lists are left as they were', $GLOBALS['meta'][500]['_price'], '400');
check('...but the superseded one-provider link is still cleared', isset($GLOBALS['meta'][500]['_ecare_provider_id']), false);
post(501, 'ecare_lab_test', 'CBC'); $add(501, 100, 300);
post(502, 'page', 'Not a test'); $add(502, 100, 1);
$T::on_settings_saved(array('new_front' => 1), array('new_front' => 1));
check('saving settings while staying live: nothing rewritten', array($GLOBALS['meta'][500]['_price'], isset($GLOBALS['meta'][501]['_price'])), array('400', false));
ECare_Lab_Settings::$live = false;
$T::on_settings_saved(array('new_front' => 0), array('new_front' => 0));
check('saving while staying off: nothing rewritten either', isset($GLOBALS['meta'][501]['_price']), false);
$T::on_settings_saved(array('new_front' => 1), array('new_front' => 0));
check('switched off: every test with labs is brought up to date', array($GLOBALS['meta'][500]['_price'], $GLOBALS['meta'][501]['_price']), array('450', '300'));
check('...and nothing that is not a lab test is touched', isset($GLOBALS['meta'][502]), false);
check('resync_all counts what it rewrote', $T::resync_all(), 2);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
