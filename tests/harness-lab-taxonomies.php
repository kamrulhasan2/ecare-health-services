<?php
/**
 * Guards ECare_Lab_Taxonomies: lab categories and collections.
 *
 * What it protects:
 *   - the seed builds Shukhee's two home-page grids and the three collections,
 *     once
 *   - a grid shows only its own categories, in the admin's order
 *   - "Most booked" ranks by paid lab bookings, keeps pinned tests first, and
 *     never shows a test that is inactive or unpublished
 *   - the old free-text category the current front end prints follows the
 *     ticked categories
 */

define('ABSPATH', __DIR__ . '/');
define('HOUR_IN_SECONDS', 3600);

$GLOBALS['options']    = array();
$GLOBALS['terms']      = array();    // id => (object) term with ->taxonomy
$GLOBALS['term_meta']  = array();
$GLOBALS['next_id']    = 1;
$GLOBALS['posts']      = array();    // id => array(status, title, active, collections[], categories[])
$GLOBALS['meta']       = array();
$GLOBALS['transients'] = array();
$GLOBALS['sql']        = array();
$GLOBALS['booking_rows'] = array();

class WP_Error {}
function add_action() {} function add_filter() {}
function is_admin() { return false; }
function __($s, $d = null) { return $s; }
function is_wp_error($v) { return $v instanceof WP_Error; }
function wp_unslash($v) { return is_array($v) ? array_map('wp_unslash', $v) : stripslashes((string) $v); }
function sanitize_text_field($s) { return trim((string) $s); }
function sanitize_title($s) { return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-'); }
function wp_verify_nonce($n, $a) { return $n === 'good' && $a === 'ecare_lab_term'; }
function current_user_can() { return true; }
function wp_is_post_revision() { return false; }
function wp_attachment_is_image($id) { return $id === 77; }
function get_option($k, $d = false) { return $GLOBALS['options'][$k] ?? $d; }
function update_option($k, $v, $a = null) { $GLOBALS['options'][$k] = $v; return true; }
function taxonomy_exists($t) { return in_array($t, array('ecare_lab_category', 'ecare_lab_collection'), true); }
function get_term_meta($id, $k, $s = false) { return $GLOBALS['term_meta'][$id][$k] ?? ''; }
function update_term_meta($id, $k, $v) { $GLOBALS['term_meta'][$id][$k] = $v; return true; }
function get_transient($k) { return $GLOBALS['transients'][$k] ?? false; }
function set_transient($k, $v, $t) { $GLOBALS['transients'][$k] = $v; return true; }
function get_post_meta($id, $k, $s = false) { return $GLOBALS['meta'][$id][$k] ?? ''; }
function update_post_meta($id, $k, $v) { $GLOBALS['meta'][$id][$k] = $v; return true; }

function get_term_by($field, $value, $tax) {
    foreach ($GLOBALS['terms'] as $t) if ($t->taxonomy === $tax && $t->slug === $value) return $t;
    return false;
}
function wp_insert_term($name, $tax, $args = array()) {
    $id = $GLOBALS['next_id']++;
    $GLOBALS['terms'][$id] = (object) array('term_id' => $id, 'name' => $name, 'slug' => $args['slug'] ?? sanitize_title($name), 'taxonomy' => $tax);
    return array('term_id' => $id);
}
function get_terms($args) {
    return array_values(array_filter($GLOBALS['terms'], function ($t) use ($args) { return $t->taxonomy === $args['taxonomy']; }));
}
function wp_get_object_terms($id, $tax, $args = array()) {
    $ids = $GLOBALS['posts'][$id][$tax === 'ecare_lab_category' ? 'categories' : 'collections'] ?? array();
    return array_map(function ($tid) { return $GLOBALS['terms'][$tid]->name; }, $ids);
}
// Honours what the class asks for: publish status, the active meta_query, a collection tax_query.
function get_posts($args) {
    $out = array();
    foreach ($GLOBALS['posts'] as $id => $p) {
        if ($p['status'] !== 'publish' || !$p['active']) continue;
        if (!empty($args['tax_query'])) {
            $want = $args['tax_query'][0]['terms'][0];
            if (!in_array($want, $p['collections'], true)) continue;
        }
        $out[$id] = $p['title'];
    }
    asort($out);
    return array_keys($out);
}

class Fake_WPDB {
    public $prefix = 'wp_';
    public function prepare($q, ...$a) { $i = 0; return preg_replace_callback('/%s/', function () use (&$i, $a) { return "'" . $a[$i++] . "'"; }, $q); }
    public function get_results($q) { $GLOBALS['sql'][] = preg_replace('/\s+/', ' ', $q); return $GLOBALS['booking_rows']; }
}
$GLOBALS['wpdb'] = new Fake_WPDB();

require_once ($argv[1] ?? (__DIR__ . '/../includes/class-ecare-lab-taxonomies.php'));

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; printf("  PASS  %s\n", $label); }
    else { $fail++; printf("  FAIL  %s\n          got:  %s\n          want: %s\n", $label, var_export($got, true), var_export($want, true)); }
}
$T = 'ECare_Lab_Taxonomies';
function names($terms) { return array_map(function ($t) { return $t->name; }, $terms); }

// ===========================================================================
echo "\n=== A. the seed ===\n";
// ===========================================================================
$T::maybe_seed();
check('fifteen categories', count($T::categories()), 15);
check('the vital-organ grid, in Shukhee order', names($T::categories('organ')), array('Reproductive Tests', 'Thyroid Disorder', 'Liver Disease', 'Kidney Disease', 'Heart Disease', 'Body Checkup', 'Hematology'));
check('the health-concern grid', names($T::categories('concern')), array('Thyroid Disorder', 'Liver Disease', 'Kidney Disease', 'Heart Disease', 'Thalassemia', 'Diabetes', 'Anemia Profile', 'Cancer'));
check('three collections in order', names($T::collections()), array('Trending Health Tests', 'Affordable Packages', 'Most Booked Test'));
$mb = get_term_by('slug', 'most-booked', 'ecare_lab_collection');
check('Most Booked is automatic', $T::collection_mode($mb->term_id), 'most_booked');
check('Trending is manual', $T::collection_mode(get_term_by('slug', 'trending', 'ecare_lab_collection')->term_id), 'manual');
check('seed version recorded', get_option('ecare_lab_taxonomies_seed_version'), '1');
$before = count($GLOBALS['terms']);
$T::seed();
check('a second seed adds nothing', count($GLOBALS['terms']), $before);

// ===========================================================================
echo "\n=== B. saving a category ===\n";
// ===========================================================================
$diab = get_term_by('slug', 'diabetes', 'ecare_lab_category')->term_id;
$_POST = array('ecare_lab_term_nonce' => 'good', 'ecare_groups' => array('organ', 'bogus', 'organ'), 'ecare_order' => '5', 'ecare_icon' => '77');
$T::save_category_meta($diab);
check('groups are cleaned and de-duplicated', $T::category_groups($diab), array('organ'));
check('Diabetes now leads the organ grid (order 5)', names($T::categories('organ'))[0], 'Diabetes');
check('... and has left the concern grid', in_array('Diabetes', names($T::categories('concern')), true), false);
check('an image is kept as the icon', get_term_meta($diab, 'ecare_icon'), 77);
$_POST['ecare_icon'] = '78';
$T::save_category_meta($diab);
check('a non-image attachment is refused', get_term_meta($diab, 'ecare_icon'), 0);
$_POST = array('ecare_groups' => array('concern'));
$T::save_category_meta($diab);
check('no nonce, no change', $T::category_groups($diab), array('organ'));

$_POST = array('ecare_lab_term_nonce' => 'good', 'ecare_mode' => 'something', 'ecare_limit' => '500');
$tr = get_term_by('slug', 'trending', 'ecare_lab_collection')->term_id;
$T::save_collection_meta($tr);
check('unknown mode is manual; limit capped at 50', array($T::collection_mode($tr), $T::collection_limit($tr)), array('manual', 50));

// ===========================================================================
echo "\n=== C. what a collection shows ===\n";
// ===========================================================================
$GLOBALS['posts'] = array(
    1 => array('status' => 'publish', 'title' => 'CBC',       'active' => true,  'collections' => array($tr), 'categories' => array()),
    2 => array('status' => 'publish', 'title' => 'Amylase',   'active' => true,  'collections' => array($tr, $mb->term_id), 'categories' => array()),
    3 => array('status' => 'publish', 'title' => 'FBS',       'active' => true,  'collections' => array(), 'categories' => array()),
    4 => array('status' => 'publish', 'title' => 'Lipid',     'active' => false, 'collections' => array($tr), 'categories' => array()),
    5 => array('status' => 'draft',   'title' => 'Draft one', 'active' => true,  'collections' => array($tr), 'categories' => array()),
    6 => array('status' => 'publish', 'title' => 'TSH',       'active' => true,  'collections' => array(), 'categories' => array()),
);
check('manual: ticked, published, active tests by title', $T::collection_test_ids($tr), array(2, 1));

$GLOBALS['booking_rows'] = array(
    (object) array('test_id' => '3', 'n' => 9),   // FBS
    (object) array('test_id' => '4', 'n' => 7),   // inactive
    (object) array('test_id' => '6', 'n' => 5),   // TSH
    (object) array('test_id' => '5', 'n' => 4),   // draft
    (object) array('test_id' => '2', 'n' => 1),   // Amylase, pinned anyway
);
$ids = $T::collection_test_ids($mb->term_id);
check('most booked: pinned first, then by bookings, skipping inactive and drafts', $ids, array(2, 3, 6));
check('cancelled bookings are not counted', strpos(end($GLOBALS['sql']), "status <> 'cancelled'") !== false, true);
check('only lab bookings are counted', strpos(end($GLOBALS['sql']), "booking_type = 'lab'") !== false, true);
$n = count($GLOBALS['sql']);
$T::collection_test_ids($mb->term_id);
check('counts are cached', count($GLOBALS['sql']), $n);
update_term_meta($mb->term_id, 'ecare_limit', 2);
check('the limit applies', $T::collection_test_ids($mb->term_id), array(2, 3));

// ===========================================================================
echo "\n=== D. the old free-text category follows ===\n";
// ===========================================================================
$liver = get_term_by('slug', 'liver-disease', 'ecare_lab_category')->term_id;
$GLOBALS['posts'][3]['categories'] = array($diab, $liver);
$T::sync_legacy_category(3);
check('ticked categories are written as text', $GLOBALS['meta'][3]['_test_category'], 'Diabetes, Liver Disease');
$GLOBALS['meta'][6]['_test_category'] = 'Hormone';
$T::sync_legacy_category(6);
check('no ticked categories: the old text is left for the migration', $GLOBALS['meta'][6]['_test_category'], 'Hormone');

$_POST = array();
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
