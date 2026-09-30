<?php
/**
 * Guards the Lab admin menu (ECare_Lab_Admin).
 *
 * Lab screens moved out of the E-Care Health menu into their own. What it
 * protects:
 *   - every lab screen is in the Lab menu, in order, and none is left behind
 *     in E-Care Health (or registered twice)
 *   - the caregiver and ambulance entries of E-Care Health are untouched
 *   - the old page slugs are kept, so existing links still open
 *   - the edit screens for tests, providers and locations light up the Lab menu,
 *     and nothing else does
 */

define('ABSPATH', __DIR__ . '/');

$GLOBALS['menus']    = array();
$GLOBALS['submenus'] = array();

function add_action() {} function add_filter() {}
function is_admin() { return true; }
function __($s, $d = null) { return $s; }
function add_menu_page($page_title, $menu_title, $cap, $slug, $cb = '', $icon = '', $pos = null) {
    $GLOBALS['menus'][] = array('slug' => $slug, 'cap' => $cap);
}
function add_submenu_page($parent, $page_title, $menu_title, $cap, $slug, $cb = '') {
    $GLOBALS['submenus'][] = array('parent' => $parent, 'title' => $menu_title, 'slug' => $slug, 'cap' => $cap, 'cb' => $cb);
}

require_once __DIR__ . '/../includes/class-ecare-locations.php';
require_once __DIR__ . '/../includes/class-ecare-lab-providers.php';
require_once __DIR__ . '/../includes/class-ecare-lab-taxonomies.php';
require_once __DIR__ . '/../admin/class-ecare-admin.php';
require_once ($argv[1] ?? (__DIR__ . '/../admin/class-ecare-lab-admin.php'));

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; printf("  PASS  %s\n", $label); }
    else { $fail++; printf("  FAIL  %s\n          got:  %s\n          want: %s\n", $label, var_export($got, true), var_export($want, true)); }
}
function slugs_under($parent) {
    $out = array();
    foreach ($GLOBALS['submenus'] as $s) if ($s['parent'] === $parent) $out[] = $s['slug'];
    return $out;
}

ECare_Admin::add_admin_menus();
ECare_Lab_Admin::add_menu();

// ===========================================================================
echo "\n=== A. the Lab menu ===\n";
// ===========================================================================
check('a top-level Lab menu exists', in_array('ecare-lab', array_column($GLOBALS['menus'], 'slug'), true), true);
check('its entries, in order', slugs_under('ecare-lab'), array(
    'ecare-lab',
    'ecare-lab-catalog',
    'post-new.php?post_type=ecare_lab_test',
    'edit-tags.php?taxonomy=ecare_lab_category&post_type=ecare_lab_test',
    'edit-tags.php?taxonomy=ecare_lab_collection&post_type=ecare_lab_test',
    'edit.php?post_type=ecare_lab_provider',
    'edit-tags.php?taxonomy=ecare_location&post_type=ecare_lab_test',
    'ecare-lab-orders',
));
$by_slug = array();
foreach ($GLOBALS['submenus'] as $s) $by_slug[$s['parent'] . '|' . $s['slug']] = $s;
check('All Tests still renders the catalogue', $by_slug['ecare-lab|ecare-lab-catalog']['cb'], array('ECare_Admin', 'render_lab_catalog'));
check('Lab Orders still renders the orders', $by_slug['ecare-lab|ecare-lab-orders']['cb'], array('ECare_Admin', 'render_lab_orders'));
check('core-screen links carry no callback', $by_slug['ecare-lab|edit.php?post_type=ecare_lab_provider']['cb'], '');
$caps = array_unique(array_column(array_filter($GLOBALS['submenus'], function ($s) { return $s['parent'] === 'ecare-lab'; }), 'cap'));
check('every entry needs manage_options', array_values($caps), array('manage_options'));

// ===========================================================================
echo "\n=== B. nothing lab is left in E-Care Health ===\n";
// ===========================================================================
$ecare = slugs_under('ecare-dashboard');
check('no lab entries under E-Care Health', array_values(array_filter($ecare, function ($s) { return strpos($s, 'lab') !== false || strpos($s, 'ecare_location') !== false; })), array());
check('caregiver and ambulance entries are all still there', $ecare, array(
    'ecare-dashboard',
    'ecare-overview-dashboard',
    'ecare-care-bookings',
    'ecare-care-providers',
    'ecare-ambulance-dispatch',
    'ecare-ambulance-providers',
));
$all = array_map(function ($s) { return $s['slug']; }, $GLOBALS['submenus']);
check('no slug is registered twice', count($all), count(array_unique($all)));

// ===========================================================================
echo "\n=== C. which screen lights up which entry ===\n";
// ===========================================================================
$sc = function ($a) { return (object) array_merge(array('post_type' => '', 'taxonomy' => '', 'base' => '', 'action' => ''), $a); };
$f  = array('ECare_Lab_Admin', 'submenu_for_screen');
check('editing a test -> All Tests', $f($sc(array('post_type' => 'ecare_lab_test', 'base' => 'post'))), 'ecare-lab-catalog');
check('adding a test -> Add New Test', $f($sc(array('post_type' => 'ecare_lab_test', 'base' => 'post', 'action' => 'add'))), 'post-new.php?post_type=ecare_lab_test');
check('provider list -> Lab Providers', $f($sc(array('post_type' => 'ecare_lab_provider', 'base' => 'edit'))), 'edit.php?post_type=ecare_lab_provider');
check('provider editor -> Lab Providers', $f($sc(array('post_type' => 'ecare_lab_provider', 'base' => 'post'))), 'edit.php?post_type=ecare_lab_provider');
check('area list -> Locations', $f($sc(array('taxonomy' => 'ecare_location', 'base' => 'edit-tags'))), 'edit-tags.php?taxonomy=ecare_location&post_type=ecare_lab_test');
check('editing one area -> Locations', $f($sc(array('taxonomy' => 'ecare_location', 'base' => 'term'))), 'edit-tags.php?taxonomy=ecare_location&post_type=ecare_lab_test');
check('a caregiver is not a lab screen', $f($sc(array('post_type' => 'ecare_caregiver', 'base' => 'post'))), '');
check('categories -> Categories', $f($sc(array('taxonomy' => 'ecare_lab_category', 'base' => 'edit-tags'))), 'edit-tags.php?taxonomy=ecare_lab_category&post_type=ecare_lab_test');
check('one collection -> Collections', $f($sc(array('taxonomy' => 'ecare_lab_collection', 'base' => 'term'))), 'edit-tags.php?taxonomy=ecare_lab_collection&post_type=ecare_lab_test');
check('caregiver types are not a lab screen', $f($sc(array('taxonomy' => 'ecare_caregiver_type', 'base' => 'edit-tags'))), '');
check('no screen, no answer', $f(null), '');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
