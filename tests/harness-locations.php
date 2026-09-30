<?php
/**
 * Guards the lab location master (ECare_Locations): the Division > District >
 * Area tree that replaces the free-text comma lists on each lab test.
 *
 * The bugs it exists to prevent, all seen on the live site:
 *   - Chattogram areas offered after picking Dhaka (no area knew its district)
 *   - "Banani" and "banani" as two separate options
 *   - "Mirpur-1" matching "Mirpur-10"
 *
 * WordPress is replaced by an in-memory term store. wp_insert_term() runs the
 * pre_insert_term guard the way core does, so the guard is exercised through
 * the same path an admin's "Add New Area" takes.
 */

define('ABSPATH', __DIR__ . '/');

$GLOBALS['options']   = array();
$GLOBALS['terms']     = array();   // id => (object) term
$GLOBALS['term_meta'] = array();
$GLOBALS['next_id']   = 1;
$GLOBALS['inserts']   = 0;

class WP_Error {
    public $code; public $message;
    public function __construct($code = '', $message = '') { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
}

function add_action() {} function add_filter() {}
function is_admin() { return false; }
function __($s, $d = null) { return $s; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_html__($s, $d = null) { return esc_html($s); }
function is_wp_error($v) { return $v instanceof WP_Error; }
function get_option($k, $default = false) { return $GLOBALS['options'][$k] ?? $default; }
function update_option($k, $v, $autoload = null) { $GLOBALS['options'][$k] = $v; return true; }
function taxonomy_exists($t) { return $t === 'ecare_location'; }
function sanitize_title($s) { return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(str_replace("'", '', $s))), '-'); }
function get_term_meta($id, $k, $single = false) { return $GLOBALS['term_meta'][$id][$k] ?? ''; }
function update_term_meta($id, $k, $v) { $GLOBALS['term_meta'][$id][$k] = $v; return true; }

function get_term_by($field, $value, $tax) {
    foreach ($GLOBALS['terms'] as $t) {
        if ($field === 'slug' && $t->slug === $value) return $t;
    }
    return false;
}
function get_term($id, $tax = '') { return $GLOBALS['terms'][(int) $id] ?? null; }
function get_terms($args) {
    $out = array();
    foreach ($GLOBALS['terms'] as $t) {
        if (!isset($args['parent']) || (int) $t->parent === (int) $args['parent']) $out[] = $t;
    }
    return $out;
}
function get_ancestors($id, $tax, $type) {
    $out = array();
    $t = $GLOBALS['terms'][$id] ?? null;
    while ($t && $t->parent) { $out[] = $t->parent; $t = $GLOBALS['terms'][$t->parent] ?? null; }
    return $out;
}
function wp_insert_term($name, $tax, $args = array()) {
    $args += array('parent' => 0, 'slug' => '');
    // Core applies this filter first and aborts on a WP_Error.
    $name = ECare_Locations::guard_new_term($name, $tax, $args);
    if (is_wp_error($name)) return $name;
    $slug  = $args['slug'] !== '' ? $args['slug'] : sanitize_title($name);
    $taken = function ($s) { foreach ($GLOBALS['terms'] as $t) if ($t->slug === $s) return true; return false; };
    if ($taken($slug)) {
        // wp_unique_term_slug(): a child term gets its parent's slug appended.
        if ($args['slug'] === '' && $args['parent'] && isset($GLOBALS['terms'][$args['parent']])) {
            $slug .= '-' . $GLOBALS['terms'][$args['parent']]->slug;
        }
        if ($taken($slug)) return new WP_Error('term_exists', 'slug taken');
    }
    $id = $GLOBALS['next_id']++;
    $GLOBALS['terms'][$id] = (object) array('term_id' => $id, 'name' => $name, 'slug' => $slug, 'parent' => (int) $args['parent']);
    $GLOBALS['inserts']++;
    ECare_Locations::mark_new_area($id);   // the created_{taxonomy} action
    return array('term_id' => $id, 'term_taxonomy_id' => $id);
}

require_once ($argv[1] ?? (__DIR__ . '/../includes/class-ecare-locations.php'));

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; printf("  PASS  %s\n", $label); }
    else { $fail++; printf("  FAIL  %s\n          got:  %s\n          want: %s\n", $label, var_export($got, true), var_export($want, true)); }
}
function term_by_slug($slug) { $t = get_term_by('slug', $slug, 'ecare_location'); return $t ?: null; }
function count_level($level) {
    $n = 0;
    foreach ($GLOBALS['terms'] as $t) if (ECare_Locations::get_level($t->term_id) === $level) $n++;
    return $n;
}

// ===========================================================================
echo "\n=== A. the seed builds the whole country ===\n";
// ===========================================================================
ECare_Locations::maybe_seed();
check('eight divisions', count_level('division'), 8);
check('sixty-four districts', count_level('district'), 64);
check('no areas are invented', count_level('area'), 0);
check('the seed version is recorded', get_option('ecare_locations_seed_version'), ECare_Locations::SEED_VERSION);

$div = term_by_slug('dhaka-division');
$dis = term_by_slug('dhaka-district');
check('division Dhaka and district Dhaka are two terms', $div && $dis && $div->term_id !== $dis->term_id, true);
check('district Dhaka sits under division Dhaka', $dis->parent, $div->term_id);
check('Chattogram district sits under Chattogram division', term_by_slug('chattogram-district')->parent, term_by_slug('chattogram-division')->term_id);
check("Cox's Bazar gets a clean slug", term_by_slug('coxs-bazar-district') !== null, true);
check('built-in terms are flagged', ECare_Locations::is_builtin($dis->term_id), true);
check('Bangla names are stored', get_term_meta($dis->term_id, 'ecare_name_bn', true), 'ঢাকা');

$every_district_has_a_division = true;
foreach ($GLOBALS['terms'] as $t) {
    if (ECare_Locations::get_level($t->term_id) === 'district'
        && ECare_Locations::get_level($t->parent) !== 'division') {
        $every_district_has_a_division = false;
    }
}
check('every district hangs off a division', $every_district_has_a_division, true);

// ===========================================================================
echo "\n=== B. seeding is idempotent ===\n";
// ===========================================================================
$before = $GLOBALS['inserts'];
ECare_Locations::seed();
check('a second full seed inserts nothing', $GLOBALS['inserts'] - $before, 0);
ECare_Locations::maybe_seed();
check('maybe_seed() is a no-op once recorded', $GLOBALS['inserts'] - $before, 0);

// ===========================================================================
echo "\n=== C. one key per place name ===\n";
// ===========================================================================
$k = array('ECare_Locations', 'name_key');
check('case folds away', $k('Banani'), $k('banani'));
check('spaces and dashes fold away', $k('Mirpur-1'), $k(' mirpur 1 '));
check('Mirpur-1 is not Mirpur-10', $k('Mirpur-1') === $k('Mirpur-10'), false);
check('Bangla letters survive', $k('মিরপুর'), 'মিরপুর');
check('punctuation-only is empty', $k(' - , '), '');
check('clean_name collapses inner spaces', ECare_Locations::clean_name("  Banani   DOHS "), 'Banani DOHS');

// ===========================================================================
echo "\n=== D. admins add areas, under a district, once ===\n";
// ===========================================================================
$r = wp_insert_term('  Banani  ', 'ecare_location', array('parent' => $dis->term_id));
check('an area under a district is accepted', is_array($r), true);
$banani = $r['term_id'];
check('its name is cleaned', get_term($banani)->name, 'Banani');
check('it is marked as an area', ECare_Locations::get_level($banani), 'area');
check('it is not built in', ECare_Locations::is_builtin($banani), false);

$r = wp_insert_term('banani', 'ecare_location', array('parent' => $dis->term_id));
check('a lowercase duplicate is refused', is_wp_error($r) ? $r->get_error_code() : 'accepted', 'ecare_location_duplicate');

wp_insert_term('Mirpur-1', 'ecare_location', array('parent' => $dis->term_id));
$r = wp_insert_term('Mirpur 1', 'ecare_location', array('parent' => $dis->term_id));
check('"Mirpur 1" is the same area as "Mirpur-1"', is_wp_error($r) ? $r->get_error_code() : 'accepted', 'ecare_location_duplicate');
$r = wp_insert_term('Mirpur-10', 'ecare_location', array('parent' => $dis->term_id));
check('"Mirpur-10" is a different area', is_array($r), true);

$ctg = term_by_slug('chattogram-district');
$r = wp_insert_term('Banani', 'ecare_location', array('parent' => $ctg->term_id));
check('the same name in another district is allowed', is_array($r), true);

$r = wp_insert_term('Somewhere', 'ecare_location', array('parent' => $div->term_id));
check('an area straight under a division is refused', is_wp_error($r) ? $r->get_error_code() : 'accepted', 'ecare_location_parent');
$r = wp_insert_term('Atlantis', 'ecare_location', array('parent' => 0));
check('a new top-level division is refused', is_wp_error($r) ? $r->get_error_code() : 'accepted', 'ecare_location_parent');
$r = wp_insert_term('Atlantis', 'ecare_location', array('parent' => -1));
check('"None" from the parent picker (-1) is refused', is_wp_error($r) ? $r->get_error_code() : 'accepted', 'ecare_location_parent');
$r = wp_insert_term('Road 11', 'ecare_location', array('parent' => $banani));
check('an area under an area is refused', is_wp_error($r) ? $r->get_error_code() : 'accepted', 'ecare_location_parent');
$r = wp_insert_term(' - ', 'ecare_location', array('parent' => $dis->term_id));
check('a name with no letters is refused', is_wp_error($r) ? $r->get_error_code() : 'accepted', 'ecare_location_empty');
$r = wp_insert_term('ধানমন্ডি', 'ecare_location', array('parent' => $dis->term_id));
check('a Bangla area name is accepted', is_array($r), true);

check('another taxonomy is left alone', ECare_Locations::guard_new_term('x', 'category', array('parent' => 0)), 'x');

// ===========================================================================
echo "\n=== E. moving terms ===\n";
// ===========================================================================
check('a district cannot be moved', ECare_Locations::guard_parent_change($ctg->term_id, $dis->term_id, 'ecare_location'), $div->term_id);
check('an area can move to another district', ECare_Locations::guard_parent_change($ctg->term_id, $banani, 'ecare_location'), $ctg->term_id);
check('an area cannot move under a division', ECare_Locations::guard_parent_change($div->term_id, $banani, 'ecare_location'), $dis->term_id);
check('an area cannot become top-level', ECare_Locations::guard_parent_change(0, $banani, 'ecare_location'), $dis->term_id);

// ===========================================================================
echo "\n=== F. admin list ===\n";
// ===========================================================================
$actions = ECare_Locations::row_actions(array('edit' => 'e', 'delete' => 'd'), $dis);
check('built-in rows lose their delete link', array_keys($actions), array('edit'));
$actions = ECare_Locations::row_actions(array('edit' => 'e', 'delete' => 'd'), get_term($banani));
check('area rows keep it', array_keys($actions), array('edit', 'delete'));
check('level column reads Area', ECare_Locations::column_content('', 'ecare_level', $banani), 'Area');
check('level column reads District', ECare_Locations::column_content('', 'ecare_level', $dis->term_id), 'District');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
