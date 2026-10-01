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
 *   - the go-live checklist says what is really missing (migration, tests
 *     without labs, pages, an online payment method, Dhaka time, the switch)
 *   - the E-Care Setup Guide documents the new lab shortcodes, with links to
 *     the screens that feed them, and says what happens to the old one
 */

define('ABSPATH', __DIR__ . '/');

$GLOBALS['menus']    = array();
$GLOBALS['submenus'] = array();

function add_action() {} function add_filter() {}
// For the go-live checklist.
define('HOUR_IN_SECONDS', 3600);
$GLOBALS['opt'] = array(); $GLOBALS['tz'] = 'Asia/Dhaka'; $GLOBALS['gateways'] = array(); $GLOBALS['pages'] = array();
function get_option($k, $d = false) { return $GLOBALS['opt'][$k] ?? $d; }
function admin_url($p = '') { return 'https://site/wp-admin/' . $p; }
function _n($a, $b, $n, $d = null) { return $n == 1 ? $a : $b; }
function wp_timezone() { return new DateTimeZone($GLOBALS['tz']); }
function WC() { return new class { public function payment_gateways() { return new class { public function payment_gateways() { return $GLOBALS['gateways']; } }; } }; }
class ECare_Lab_Front { public static function page_id($w) { return $GLOBALS['pages'][$w] ?? 0; } }
// Labs for the "notification email" item: id => [title, active, emails].
$GLOBALS['labs'] = array();
function get_posts($a) { $o = array(); foreach ($GLOBALS['labs'] as $id => $l) { $o[] = (object) array('ID' => $id, 'post_title' => $l[0], 'post_type' => 'ecare_lab_provider', 'post_status' => 'publish'); } return $o; }
function get_post($id) { return isset($GLOBALS['labs'][$id]) ? (object) array('ID' => $id, 'post_type' => 'ecare_lab_provider', 'post_status' => 'publish') : null; }
function get_post_meta($id, $k, $s = false) { $l = $GLOBALS['labs'][$id] ?? null; if (!$l) { return ''; } return $k === '_ecare_provider_status' ? ($l[1] ? 'active' : 'inactive') : ($k === '_ecare_notify_emails' ? $l[2] : ''); }
function sanitize_email($e) { return trim($e); }
function is_email($e) { return (bool) filter_var($e, FILTER_VALIDATE_EMAIL); }
// For the Setup Guide.
function _e($s, $d = null) { echo $s; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_html__($s, $d = null) { return esc_html($s); }
function esc_html_e($s, $d = null) { echo esc_html($s); }
function esc_url($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function wp_kses($s, $allowed) {   // keeps only the allowed tags, like the real one
    return preg_replace_callback('#</?([a-z0-9]+)[^>]*>#i', function ($m) use ($allowed) { return isset($allowed[strtolower($m[1])]) ? $m[0] : ''; }, $s);
}
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
require_once __DIR__ . '/../includes/class-ecare-lab-settings.php';
require_once __DIR__ . '/../includes/class-ecare-lab-migration.php';
require_once __DIR__ . '/../admin/class-ecare-admin.php';
require_once __DIR__ . '/../admin/class-ecare-lab-orders-admin.php';
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
    'ecare-lab-migration',
    'ecare-lab-settings',
));
$by_slug = array();
foreach ($GLOBALS['submenus'] as $s) $by_slug[$s['parent'] . '|' . $s['slug']] = $s;
check('All Tests still renders the catalogue', $by_slug['ecare-lab|ecare-lab-catalog']['cb'], array('ECare_Admin', 'render_lab_catalog'));
check('Settings renders the settings page', $by_slug['ecare-lab|ecare-lab-settings']['cb'], array('ECare_Lab_Settings', 'render_page'));
check('Lab Orders renders the new orders screen (same slug, so old links still work)', $by_slug['ecare-lab|ecare-lab-orders']['cb'], array('ECare_Lab_Orders_Admin', 'render'));
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

// ===========================================================================
echo "\n=== F. the go-live checklist ===\n";
// ===========================================================================
$ok = function ($c) { return array_map(function ($i) { return $i['ok']; }, $c); };
$gw = function ($id, $enabled) { return (object) array('id' => $id, 'enabled' => $enabled); };
$stats = array('unlinked' => 3, 'providers' => 0);
$c = ECare_Lab_Admin::checklist($stats);
check('a fresh upload: what is left to do', $ok($c), array('migration' => false, 'tests' => false, 'labs' => false, 'lab_emails' => true, 'pages' => false, 'payment' => false, 'timezone' => true, 'live' => false));
check('tests without labs are counted', $c['tests']['detail'], '3 tests have none and will not be shown on the new pages.');
check('missing pages are named', $c['pages']['detail'], 'Missing: Lab home, All tests, Lab cart');

$GLOBALS['gateways'] = array('cod' => $gw('cod', 'yes'), 'bacs' => $gw('bacs', 'no'));
$GLOBALS['tz'] = 'UTC';
$c = ECare_Lab_Admin::checklist($stats);
check('Cash on Delivery alone is not an online payment', array($c['payment']['ok'], strpos($c['payment']['detail'], 'Cash on Delivery') !== false), array(false, true));
check('a UTC site is flagged, with its timezone named', array($c['timezone']['ok'], $c['timezone']['detail']), array(false, 'Now UTC. Collection slots and cut-off times follow it.'));

$GLOBALS['gateways']['sslcommerz'] = $gw('sslcommerz', 'yes');
$GLOBALS['tz'] = '+06:00';
$GLOBALS['opt']['ecare_lab_migration_log'] = array('applied' => 1);
$GLOBALS['opt']['ecare_lab_settings'] = array('new_front' => 1);
$GLOBALS['pages'] = array('home' => 1, 'tests' => 2, 'cart' => 3);
$c = ECare_Lab_Admin::checklist(array('unlinked' => 0, 'providers' => 2));
check('everything in place, switch on', $ok($c), array('migration' => true, 'tests' => true, 'labs' => true, 'lab_emails' => true, 'pages' => true, 'payment' => true, 'timezone' => true, 'live' => true));
check('every item links somewhere', count(array_filter(array_map(function ($i) { return $i['link']; }, $c))), 8);
$GLOBALS['labs'] = array(5 => array('Popular', true, 'orders@popular.test'), 6 => array('LabAid', true, ''), 7 => array('Closed Lab', false, ''), 8 => array('Ibn Sina', true, 'not-an-email'));
$GLOBALS['opt']['ecare_lab_settings']['email_lab'] = 1;
$c = ECare_Lab_Admin::checklist(array('unlinked' => 0, 'providers' => 4));
check('active labs with no (valid) notification email are named; inactive ones are not', array($c['lab_emails']['ok'], $c['lab_emails']['detail']), array(false, 'No email, so new orders are not sent to: LabAid, Ibn Sina'));
$GLOBALS['opt']['ecare_lab_settings']['email_lab'] = 0;
check('lab emails turned off in Settings: nothing to warn about', ECare_Lab_Admin::checklist(array('unlinked' => 0, 'providers' => 4))['lab_emails']['ok'], true);
$GLOBALS['labs'] = array();
unset($GLOBALS['opt']['ecare_lab_migration_log']);
check('no migration log but nothing old left either: fine', ECare_Lab_Admin::checklist(array('unlinked' => 0, 'providers' => 2))['migration']['ok'], true);

// ===========================================================================
echo "\n=== G. the E-Care Setup Guide ===\n";
// ===========================================================================
ob_start(); ECare_Admin::render_setup_guide(); $g = ob_get_clean();
check('a section for the new lab, before the checklist (now 3.)', array(strpos($g, '2. Lab Shortcodes (New Lab)') !== false, strpos($g, '3. Setup &amp; Requirements Checklist') !== false || strpos($g, '3. Setup & Requirements Checklist') !== false, strpos($g, '2. Lab Shortcodes') < strpos($g, '3. Setup')), array(true, true, true));
foreach (array('[ecare_lab_home]' => 'E-Care Lab Home', '[ecare_lab_catalog]' => 'E-Care Lab Tests (new)', '[ecare_lab_cart]' => 'E-Care Lab Cart') as $code => $widget) {
    check("$code is listed with its Elementor widget", strpos($g, $code) !== false && strpos($g, 'Elementor widget: ' . $widget) !== false, true);
}
foreach (array('page=ecare-lab-settings', 'taxonomy=ecare_lab_collection', 'taxonomy=ecare_lab_category', 'page=ecare-lab-catalog', 'post_type=ecare_lab_provider', 'page=ecare-lab-orders', 'page=ecare-lab"') as $screen) {
    check("links to the screen that feeds it: $screen", strpos($g, 'https://site/wp-admin/admin.php?' . ltrim($screen, '"')) !== false || strpos($g, $screen) !== false, true);
}
check('the cart page explains its three screens', strpos($g, '<code>?step=checkout</code>') !== false && strpos($g, '<code>?step=orders</code>') !== false, true);
check('the old shortcode says it is taken over at go-live', strpos($g, 'After Lab → Settings → Go live, a page with this shortcode shows the new All Lab Tests page') !== false, true);
check('the other shortcodes are untouched', array(strpos($g, '[ecare_caregiver_booking]') !== false, strpos($g, '[ecare_ambulance_registration]') !== false), array(true, true));
check('no stray markup gets through', preg_match('#<(script|iframe)#i', $g), 0);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
