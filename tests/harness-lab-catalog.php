<?php
/**
 * Guards ECare_Lab_Catalog: what patients are shown.
 *
 * What it protects:
 *   - a test appears only if it could be booked right now (published, active,
 *     at least one active price row whose lab is published and active)
 *   - its "from" price is the cheapest bookable one, with that row's MRP and % off
 *   - search, category, collection, lab and type filters narrow correctly, and
 *     an unknown category or collection returns nothing rather than everything
 *   - price ordering and pagination
 *   - the booking modal lists labs cheapest first, serving labs first for an area
 *   - the cart's vendor list only offers labs that can do every test in it
 *   - the cached answer is thrown away when something changes
 */

define('ABSPATH', __DIR__ . '/');

$GLOBALS['options'] = array(); $GLOBALS['transients'] = array();
$GLOBALS['posts'] = array(); $GLOBALS['meta'] = array(); $GLOBALS['obj'] = array();
$GLOBALS['terms'] = array(); $GLOBALS['term_meta'] = array();
$GLOBALS['offers'] = array(); $GLOBALS['sql_count'] = 0;

class WP_Error {}
function add_action() {} function add_filter() {} function do_action() {}
function is_admin() { return false; }
function __($s, $d = null) { return $s; }
function _n($a, $b, $n, $d = null) { return $n == 1 ? $a : $b; }
function is_wp_error($v) { return $v instanceof WP_Error; }
function get_option($k, $d = false) { return $GLOBALS['options'][$k] ?? $d; }
function update_option($k, $v, $a = null) { $GLOBALS['options'][$k] = $v; return true; }
function get_transient($k) { return $GLOBALS['transients'][$k] ?? false; }
function set_transient($k, $v, $t) { $GLOBALS['transients'][$k] = $v; return true; }
function get_post($id) { return $GLOBALS['posts'][(int) $id] ?? null; }
function get_post_meta($id, $k, $s = false) { return $GLOBALS['meta'][$id][$k] ?? ''; }
function get_the_title($id) { return $GLOBALS['posts'][(int) $id]->post_title ?? ''; }
function get_the_post_thumbnail_url($id, $size = '') { return isset($GLOBALS['meta'][$id]['_thumb']) ? 'img-' . $id : false; }
function get_term($id, $tax = '') { $t = $GLOBALS['terms'][(int) $id] ?? null; return ($t && $t->taxonomy === $tax) ? $t : null; }
function get_term_by($f, $v, $tax) { foreach ($GLOBALS['terms'] as $t) if ($t->taxonomy === $tax && $t->slug === $v) return $t; return false; }
function get_term_meta($id, $k, $s = false) { return $GLOBALS['term_meta'][$id][$k] ?? ''; }
function get_terms($a) { return array_values(array_filter($GLOBALS['terms'], function ($t) use ($a) { return $t->taxonomy === $a['taxonomy']; })); }
function wp_get_object_terms($id, $tax, $a = array()) { return $GLOBALS['obj'][$tax][(int) $id] ?? array(); }
function get_ancestors($id, $t, $x) { $o = array(); $t = $GLOBALS['terms'][$id] ?? null; while ($t && $t->parent) { $o[] = $t->parent; $t = $GLOBALS['terms'][$t->parent] ?? null; } return $o; }
function wp_attachment_is_image() { return true; }
function wp_get_attachment_image_url() { return ''; }

/** Honours what the classes ask of get_posts: type, status, post__in, s, one tax_query, the active meta_query. */
function get_posts($a) {
    $out = array();
    foreach ($GLOBALS['posts'] as $id => $p) {
        if ($p->post_type !== ($a['post_type'] ?? '')) continue;
        if (!in_array($p->post_status, (array) ($a['post_status'] ?? 'publish'), true)) continue;
        if (isset($a['post__in']) && !in_array($id, $a['post__in'], true)) continue;
        if (!empty($a['s']) && stripos($p->post_title, $a['s']) === false) continue;
        if (!empty($a['tax_query'])) {
            $tq = $a['tax_query'][0];
            if (!array_intersect($tq['terms'], $GLOBALS['obj'][$tq['taxonomy']][$id] ?? array())) continue;
        }
        if (!empty($a['meta_query']) && ($GLOBALS['meta'][$id]['_test_status'] ?? '') === 'inactive') continue;
        $out[$id] = $p->post_title;
    }
    natcasesort($out);
    return array_keys($out);
}

class Fake_WPDB {
    public $prefix = 'wp_';
    public function prepare($q, ...$a) { $i = 0; return preg_replace_callback('/%[sd]/', function ($m) use (&$i, $a) { return $m[0] === '%d' ? (int) $a[$i++] : "'" . $a[$i++] . "'"; }, $q); }
    public function get_results($q) {
        if (strpos($q, 'ecare_bookings') !== false) return array();
        $GLOBALS['sql_count']++;
        $rows = array_values($GLOBALS['offers']);
        if (preg_match('/test_id = (\d+)/', $q, $m)) $rows = array_values(array_filter($rows, function ($r) use ($m) { return $r['test_id'] == $m[1]; }));
        if (strpos($q, "status = 'active'") !== false) $rows = array_values(array_filter($rows, function ($r) { return $r['status'] === 'active'; }));
        return array_map(function ($r) { return (object) $r; }, $rows);
    }
}
$GLOBALS['wpdb'] = new Fake_WPDB();

foreach (array('locations', 'lab-providers', 'lab-offerings', 'lab-test-info', 'lab-packages', 'lab-taxonomies') as $f) {
    require_once __DIR__ . '/../includes/class-ecare-' . $f . '.php';
}
require_once ($argv[1] ?? (__DIR__ . '/../includes/class-ecare-lab-catalog.php'));

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; printf("  PASS  %s\n", $label); }
    else { $fail++; printf("  FAIL  %s\n          got:  %s\n          want: %s\n", $label, var_export($got, true), var_export($want, true)); }
}
$C = 'ECare_Lab_Catalog';
function post($id, $type, $title, $status = 'publish', $meta = array()) { $GLOBALS['posts'][$id] = (object) array('ID' => $id, 'post_type' => $type, 'post_status' => $status, 'post_title' => $title); $GLOBALS['meta'][$id] = $meta; }
function term($id, $tax, $name, $slug, $parent = 0, $meta = array()) { $GLOBALS['terms'][$id] = (object) array('term_id' => $id, 'taxonomy' => $tax, 'name' => $name, 'slug' => $slug, 'parent' => $parent); $GLOBALS['term_meta'][$id] = $meta; }
$oid = 0;
function offer($test, $lab, $mrp, $price = 0, $status = 'active') { global $oid; $oid++; $GLOBALS['offers'][$oid] = array('id' => $oid, 'test_id' => $test, 'provider_id' => $lab, 'mrp' => $mrp, 'price' => $price, 'material_cost' => 0, 'status' => $status); }
function titles($r) { return array_map(function ($c) { return $c['title']; }, $r['items']); }

// Places: Dhaka district (10) with Banani (11) and Gulshan (12).
term(1, 'ecare_location', 'Dhaka', 'dhaka-division', 0, array('ecare_level' => 'division'));
term(10, 'ecare_location', 'Dhaka', 'dhaka-district', 1, array('ecare_level' => 'district'));
term(11, 'ecare_location', 'Banani', 'banani', 10, array('ecare_level' => 'area'));
term(12, 'ecare_location', 'Gulshan', 'gulshan', 10, array('ecare_level' => 'area'));
// Labs
post(100, 'ecare_lab_provider', 'Popular', 'publish', array('_thumb' => 1)); $GLOBALS['obj']['ecare_location'][100] = array(11);
post(101, 'ecare_lab_provider', 'LabAid');                                  $GLOBALS['obj']['ecare_location'][101] = array(10);
post(102, 'ecare_lab_provider', 'Closed', 'publish', array('_ecare_provider_status' => 'inactive'));
post(103, 'ecare_lab_provider', 'Ibn Sina');                                $GLOBALS['obj']['ecare_location'][103] = array(12);
post(104, 'ecare_lab_provider', 'Praava');                                  $GLOBALS['obj']['ecare_location'][104] = array(10);
// Categories and collections
term(200, 'ecare_lab_category', 'Diabetes', 'diabetes'); term(201, 'ecare_lab_category', 'Liver', 'liver');
term(300, 'ecare_lab_collection', 'Trending', 'trending', 0, array('ecare_mode' => 'manual', 'ecare_limit' => 2));
// Tests
post(1, 'ecare_lab_test', 'FBS');             offer(1, 100, 400); offer(1, 101, 450, 380); offer(1, 102, 200); offer(1, 103, 500, 0, 'inactive');
post(2, 'ecare_lab_test', 'CBC');             offer(2, 101, 300); offer(2, 104, 350, 290);
post(3, 'ecare_lab_test', 'Lipid Profile');   offer(3, 102, 900);                                   // only a closed lab
post(4, 'ecare_lab_test', 'HbA1c', 'draft');  offer(4, 100, 800);                                   // draft
post(5, 'ecare_lab_test', 'SGPT', 'publish', array('_test_status' => 'inactive')); offer(5, 100, 300);
post(6, 'ecare_lab_test', 'Diabetes Care', 'publish', array('_ecare_test_type' => 'package', '_ecare_package_tests' => array(1, 2), '_ecare_package_extra' => array('HbA1c'))); offer(6, 100, 1200, 950);
post(7, 'ecare_lab_test', 'Thyroid');         // no prices at all
$GLOBALS['obj']['ecare_lab_category'] = array(1 => array(200), 6 => array(200), 2 => array(201));
$GLOBALS['obj']['ecare_lab_collection'] = array(1 => array(300), 2 => array(300), 6 => array(300));

// ===========================================================================
echo "\n=== A. what is bookable ===\n";
// ===========================================================================
$map = $C::bookable_map();
check('only FBS, CBC and the package', array_keys($map), array(1, 2, 6));
check('FBS from 380 (LabAid discount), not the closed lab\'s 200', array($map[1]['min'], $map[1]['mrp']), array(380.0, 450.0));
check('FBS labs, cheapest first; closed and switched-off rows excluded', $map[1]['providers'], array(101 => 380.0, 100 => 400.0));

// ===========================================================================
echo "\n=== B. cards ===\n";
// ===========================================================================
$card = $C::card(1);
check('price, struck MRP and % off', array($card['price'], $card['mrp'], $card['discount']), array(380.0, 450.0, 16));
check('lab logos in price order', array_map(function ($l) { return $l['name']; }, $card['labs']), array('LabAid', 'Popular'));
check('a logo when the lab has one', $card['labs'][1]['logo'], 'img-100');
$pkg = $C::card(6);
check('package: type, item count and discount', array($pkg['type'], $pkg['items'], $pkg['discount']), array('package', 3, 21));
check('CBC from 290 at Praava, struck 350', array($C::card(2)['price'], $C::card(2)['mrp']), array(290.0, 350.0));

// ===========================================================================
echo "\n=== C. search and filters ===\n";
// ===========================================================================
check('everything, by name', titles($C::search()), array('CBC', 'Diabetes Care', 'FBS'));
check('words', titles($C::search(array('s' => 'fb'))), array('FBS'));
check('category by slug', titles($C::search(array('category' => 'diabetes'))), array('Diabetes Care', 'FBS'));
check('category by id', titles($C::search(array('category' => 201))), array('CBC'));
check('an unknown category finds nothing', $C::search(array('category' => 'nope'))['total'], 0);
check('an unknown collection finds nothing', $C::search(array('collection' => 'nope'))['total'], 0);
check('a lab: only tests it can do', titles($C::search(array('provider' => 104))), array('CBC'));
check('a closed lab: nothing', $C::search(array('provider' => 102))['total'], 0);
check('packages only', titles($C::search(array('type' => 'package'))), array('Diabetes Care'));
check('price low to high', titles($C::search(array('orderby' => 'price_asc'))), array('CBC', 'FBS', 'Diabetes Care'));
check('price high to low', titles($C::search(array('orderby' => 'price_desc'))), array('Diabetes Care', 'FBS', 'CBC'));
$p2 = $C::search(array('per_page' => 2, 'page' => 2));
check('page 2 of 2', array(titles($p2), $p2['total'], $p2['page'], $p2['pages']), array(array('FBS'), 3, 2, 2));
check('a page past the end clamps to the last', $C::search(array('per_page' => 2, 'page' => 9))['page'], 2);
check('per_page is capped', count($C::search(array('per_page' => 1000))['items']), 3);
check('collection filter shows all of it ("View All")', titles($C::search(array('collection' => 'trending'))), array('CBC', 'Diabetes Care', 'FBS'));
check('home page row respects the limit of 2', array_map(function ($c) { return $c['title']; }, $C::collection_cards('trending')), array('CBC', 'Diabetes Care'));

$f = $C::filters();
check('category counts (bookable tests only)', array_map(function ($c) { return $c['name'] . ':' . $c['count']; }, $f['categories']), array('Diabetes:2', 'Liver:1'));
check('lab vendors with counts, closed lab absent', array_map(function ($l) { return $l['name'] . ':' . $l['count']; }, $f['labs']), array('LabAid:2', 'Popular:2', 'Praava:1'));
check('related tests share a category', array_map(function ($c) { return $c['title']; }, $C::related(1)), array('Diabetes Care'));

// ===========================================================================
echo "\n=== D. the booking modal ===\n";
// ===========================================================================
$labs = $C::labs_for_test(1);
check('bookable labs, cheapest first', array_map(function ($l) { return $l['name'] . ' ' . $l['price']; }, $labs), array('LabAid 380', 'Popular 400'));
check('you save on LabAid', $labs[0]['savings'], 70.0);
$labs = $C::labs_for_test(1, 12);   // Gulshan: LabAid covers all of Dhaka, Popular only Banani
check('for Gulshan: who serves it', array_map(function ($l) { return $l['name'] . '=' . var_export($l['serves_area'], true); }, $labs), array('LabAid=true', 'Popular=false'));
$labs = $C::labs_for_test(1, 11);   // Banani: both serve
check('for Banani: both serve, still cheapest first', array_map(function ($l) { return $l['name']; }, $labs), array('LabAid', 'Popular'));

// ===========================================================================
echo "\n=== E. the cart's vendor list ===\n";
// ===========================================================================
$v = $C::labs_for_cart(array(1, 2));
check('only LabAid does both FBS and CBC', array_map(function ($l) { return $l['name'] . ' ' . $l['total']; }, $v), array('LabAid 680'));
check('... and its MRP total', $v[0]['mrp'], 750.0);
$v = $C::labs_for_cart(array(1), 11);
check('one test, Banani: both labs, cheapest first', array_map(function ($l) { return $l['name']; }, $v), array('LabAid', 'Popular'));
$v = $C::labs_for_cart(array(1), 12);
check('one test, Gulshan: only LabAid serves it, and it comes first', array_map(function ($l) { return $l['name'] . '=' . var_export($l['serves_area'], true); }, $v), array('LabAid=true', 'Popular=false'));
check('an empty cart has no vendors', $C::labs_for_cart(array()), array());

// ===========================================================================
echo "\n=== F. caching ===\n";
// ===========================================================================
$n = $GLOBALS['sql_count'];
$C::bookable_map();
check('a second read comes from the cache', $GLOBALS['sql_count'], $n);
$GLOBALS['offers'][2]['price'] = 0;     // LabAid FBS discount removed: back to 450...
check('... a stale cache still says 380', $C::bookable_map()[1]['min'], 380.0);
$C::bump();
check('after bump the new price shows (Popular 400 is now cheapest)', $C::bookable_map()[1]['min'], 400.0);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
