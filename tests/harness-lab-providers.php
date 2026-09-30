<?php
/**
 * Guards ECare_Lab_Providers: providers as records of their own, with the
 * areas they serve stored as ecare_location terms.
 *
 * What it protects:
 *   - a provider serves an area if the area OR its whole district is ticked,
 *     and never an area in some other district
 *   - "Popular" and "popular" cannot both be published
 *   - coverage input is cleaned: divisions, stray ids and areas already
 *     covered by a ticked district are dropped
 */

define('ABSPATH', __DIR__ . '/');

$GLOBALS['terms']      = array();   // id => (object) term
$GLOBALS['term_meta']  = array();
$GLOBALS['posts']      = array();   // id => (object) post
$GLOBALS['meta']       = array();
$GLOBALS['obj_terms']  = array();   // post id => [term ids]
$GLOBALS['transients'] = array();
$GLOBALS['last_get_posts'] = null;

class WP_Error { public function get_error_code() { return 'err'; } }

function add_action() {} function add_filter() {}
function is_admin() { return false; }
function __($s, $d = null) { return $s; }
function _n($one, $many, $n, $d = null) { return $n == 1 ? $one : $many; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_html__($s, $d = null) { return esc_html($s); }
function is_wp_error($v) { return $v instanceof WP_Error; }
function wp_unslash($v) { return is_array($v) ? array_map('wp_unslash', $v) : stripslashes((string) $v); }
function wp_slash($v) { return addslashes((string) $v); }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)); }
function sanitize_email($s) { return preg_replace('/[^a-zA-Z0-9.@_+\-]/', '', (string) $s); }
function is_email($s) { return (bool) filter_var($s, FILTER_VALIDATE_EMAIL); }
function wp_verify_nonce($n, $a) { return $n === 'good-nonce' && $a === 'ecare_lab_provider_meta'; }
function current_user_can() { return true; }
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
function wp_get_object_terms($id, $tax, $args = array()) { return $GLOBALS['obj_terms'][(int) $id] ?? array(); }
function wp_set_object_terms($id, $ids, $tax, $append = false) { $GLOBALS['obj_terms'][(int) $id] = array_values($ids); return $ids; }
function get_post($id) { return $GLOBALS['posts'][(int) $id] ?? null; }
function get_post_meta($id, $k, $single = false) { return $GLOBALS['meta'][$id][$k] ?? ''; }
function update_post_meta($id, $k, $v) { $GLOBALS['meta'][$id][$k] = $v; return true; }
function get_posts($args) {
    $GLOBALS['last_get_posts'] = $args;
    $out = array();
    foreach ($GLOBALS['posts'] as $p) {
        if ($p->post_type !== ($args['post_type'] ?? '')) continue;
        $statuses = (array) ($args['post_status'] ?? 'publish');
        if (!in_array($p->post_status, $statuses, true)) continue;
        if (in_array($p->ID, $args['post__not_in'] ?? array(), true)) continue;
        $out[] = $p;
    }
    return $out;
}

require_once __DIR__ . '/../includes/class-ecare-locations.php';
require_once ($argv[1] ?? (__DIR__ . '/../includes/class-ecare-lab-providers.php'));

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; printf("  PASS  %s\n", $label); }
    else { $fail++; printf("  FAIL  %s\n          got:  %s\n          want: %s\n", $label, var_export($got, true), var_export($want, true)); }
}

// A small tree: two divisions, a district in each, areas under the districts.
function term($id, $name, $parent, $level) {
    $GLOBALS['terms'][$id] = (object) array('term_id' => $id, 'name' => $name, 'parent' => $parent);
    $GLOBALS['term_meta'][$id]['ecare_level'] = $level;
}
term(1,  'Dhaka',      0, 'division');
term(2,  'Chattogram', 0, 'division');
term(10, 'Dhaka',      1, 'district');
term(20, 'Chattogram', 2, 'district');
term(11, 'Banani',     10, 'area');
term(12, 'Mirpur-1',   10, 'area');
term(13, 'Mirpur-10',  10, 'area');
term(21, 'Agrabad',    20, 'area');
term(22, 'Banani',     20, 'area');   // same name, other district

function provider($id, $title, $status = 'publish') {
    $GLOBALS['posts'][$id] = (object) array('ID' => $id, 'post_type' => 'ecare_lab_provider', 'post_status' => $status, 'post_title' => $title);
}
provider(100, 'Popular Diagnostic Centre');
provider(101, 'LabAid');
provider(102, 'Old Lab', 'trash');

// ===========================================================================
echo "\n=== A. coverage input is cleaned ===\n";
// ===========================================================================
$n = array('ECare_Lab_Providers', 'normalize_coverage');
check('areas are kept', $n(array(11, 12)), array(11, 12));
check('a division is dropped', $n(array(1, 11)), array(11));
check('unknown ids and junk are dropped', $n(array(999, 0, -4, 'x', 11)), array(11));
check('an area under a ticked district is folded into it', $n(array(10, 11, 12, 21)), array(10, 21));
check('duplicates collapse', $n(array(11, 11, '11')), array(11));
check('nothing ticked is nothing', $n(array()), array());

// ===========================================================================
echo "\n=== B. which areas a provider serves ===\n";
// ===========================================================================
$GLOBALS['obj_terms'][100] = array(11, 12);          // Banani, Mirpur-1 in Dhaka
$GLOBALS['obj_terms'][101] = array(20);              // all of Chattogram district
$c = array('ECare_Lab_Providers', 'covers_area');
check('a ticked area is served', $c(100, 11), true);
check('Mirpur-10 is not Mirpur-1', $c(100, 13), false);
check('Banani in Chattogram is not Banani in Dhaka', $c(100, 22), false);
check('a whole district serves each of its areas', $c(101, 21), true);
check('... including the Banani over there', $c(101, 22), true);
check('... and nothing in Dhaka', $c(101, 11), false);
check('a district id is not an area', $c(101, 20), false);
check('an unknown area is not served', $c(100, 999), false);

// ===========================================================================
echo "\n=== C. the query for an area asks for the area and its district ===\n";
// ===========================================================================
ECare_Lab_Providers::providers_for_area(22);
$q = $GLOBALS['last_get_posts'];
check('lab providers only', $q['post_type'], 'ecare_lab_provider');
check('published only', $q['post_status'], 'publish');
check('area and its district, no children', array($q['tax_query'][0]['terms'], $q['tax_query'][0]['include_children']), array(array(22, 20), false));
check('inactive providers are excluded', $q['meta_query'][0], array('key' => '_ecare_provider_status', 'value' => 'inactive', 'compare' => '!='));
$GLOBALS['last_get_posts'] = null;
check('a district id runs no query at all', array(ECare_Lab_Providers::providers_for_area(20), $GLOBALS['last_get_posts']), array(array(), null));

// ===========================================================================
echo "\n=== D. active ===\n";
// ===========================================================================
check('published with no status is active', ECare_Lab_Providers::is_active(100), true);
$GLOBALS['meta'][101]['_ecare_provider_status'] = 'inactive';
check('marked inactive is not', ECare_Lab_Providers::is_active(101), false);
unset($GLOBALS['meta'][101]['_ecare_provider_status']);
check('trashed is not', ECare_Lab_Providers::is_active(102), false);
check('a missing post is not', ECare_Lab_Providers::is_active(555), false);

// ===========================================================================
echo "\n=== E. one provider per name ===\n";
// ===========================================================================
$d = array('ECare_Lab_Providers', 'find_duplicate');
check('same name, other case', $d('popular diagnostic centre'), 100);
check('same name, extra spaces and punctuation', $d('  Popular   Diagnostic-Centre '), 100);
check('a different name is fine', $d('Popular Medical'), 0);
check('a provider is not its own duplicate', $d('LabAid', 101), 0);
check('a trashed provider does not block the name', $d('Old Lab'), 0);

$g = ECare_Lab_Providers::guard_publish(array('post_type' => 'ecare_lab_provider', 'post_status' => 'publish', 'post_title' => 'labaid'), array('ID' => 200));
check('publishing a duplicate is held as draft', $g['post_status'], 'draft');
check('and the admin is told which one', $GLOBALS['transients']['ecare_lab_provider_notice_5'] ?? '', 'duplicate:101');

$g = ECare_Lab_Providers::guard_publish(array('post_type' => 'ecare_lab_provider', 'post_status' => 'publish', 'post_title' => '   '), array('ID' => 201));
check('a nameless provider is held as draft', array($g['post_status'], $GLOBALS['transients']['ecare_lab_provider_notice_5']), array('draft', 'empty'));

$g = ECare_Lab_Providers::guard_publish(array('post_type' => 'ecare_lab_provider', 'post_status' => 'publish', 'post_title' => '  Ibn  Sina '), array('ID' => 202));
check('a new name publishes, tidied', array($g['post_status'], $g['post_title']), array('publish', 'Ibn Sina'));

$g = ECare_Lab_Providers::guard_publish(array('post_type' => 'ecare_lab_provider', 'post_status' => 'draft', 'post_title' => 'labaid'), array('ID' => 203));
check('a draft is left alone', $g['post_status'], 'draft');
$g = ECare_Lab_Providers::guard_publish(array('post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'labaid'), array());
check('other post types are left alone', $g['post_status'], 'publish');

// ===========================================================================
echo "\n=== F. saving the edit screen ===\n";
// ===========================================================================
$GLOBALS['posts'][300] = (object) array('ID' => 300, 'post_type' => 'ecare_lab_provider', 'post_status' => 'publish', 'post_title' => 'Ibn Sina');

$_POST = array('_ecare_phone' => '01700', 'ecare_coverage' => array('11'));
ECare_Lab_Providers::save(300, $GLOBALS['posts'][300]);
check('no nonce, nothing saved', array($GLOBALS['meta'][300] ?? null, $GLOBALS['obj_terms'][300] ?? null), array(null, null));

$_POST = array(
    'ecare_lab_provider_nonce' => 'good-nonce',
    '_ecare_phone'             => ' +8801700000000 ',
    '_ecare_notify_emails'     => 'orders@lab.com; ORDERS@lab.com, not-an-email  second@lab.com',
    '_ecare_address'           => "House 1\nRoad 2",
    '_ecare_provider_status'   => 'bogus',
    'ecare_coverage'           => array('10', '11', '21', '1'),
);
ECare_Lab_Providers::save(300, $GLOBALS['posts'][300]);
check('phone is trimmed', $GLOBALS['meta'][300]['_ecare_phone'], '+8801700000000');
check('emails: invalid dropped, case duplicates folded', $GLOBALS['meta'][300]['_ecare_notify_emails'], 'orders@lab.com, second@lab.com');
check('an unknown status falls back to active', $GLOBALS['meta'][300]['_ecare_provider_status'], 'active');
check('coverage is stored cleaned', $GLOBALS['obj_terms'][300], array(10, 21));

$_POST['ecare_coverage'] = null; unset($_POST['ecare_coverage']);
$_POST['_ecare_provider_status'] = 'inactive';
ECare_Lab_Providers::save(300, $GLOBALS['posts'][300]);
check('unticking everything clears coverage', $GLOBALS['obj_terms'][300], array());
check('inactive is kept', $GLOBALS['meta'][300]['_ecare_provider_status'], 'inactive');

// ===========================================================================
echo "\n=== G. the admin list summary ===\n";
// ===========================================================================
$GLOBALS['obj_terms'][400] = array(20, 11, 12);
check('whole district and area counts', ECare_Lab_Providers::coverage_summary(400), 'Chattogram (whole district) · Dhaka: 2 areas');
$GLOBALS['obj_terms'][401] = array(13);
check('one area reads singular', ECare_Lab_Providers::coverage_summary(401), 'Dhaka: 1 area');
check('no coverage is empty', ECare_Lab_Providers::coverage_summary(402), '');

$_POST = array();
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
