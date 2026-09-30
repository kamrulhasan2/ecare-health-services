<?php
/**
 * Guards ECare_Lab_Front: the lab home page, the shared test card, page links
 * and when the lab assets load.
 *
 * What it protects:
 *   - lab CSS/JS load only on pages with a lab shortcode or Elementor widget
 *   - links go to the page chosen in settings, else the auto-found one
 *   - the home search box never uses ?s= (WordPress's own search)
 *   - a card shows the struck price and % off only when there is a discount,
 *     "Includes N tests" only for packages, and escapes what it prints
 *   - empty collections and category grids are left out of the home page
 */

define('ABSPATH', __DIR__ . '/');
define('HOUR_IN_SECONDS', 3600);
define('ECARE_PLUGIN_DIR', __DIR__ . '/../');
define('ECARE_PLUGIN_URL', 'https://site/wp-content/plugins/ecare/');
define('ECARE_VERSION', 'test');

$GLOBALS['posts'] = array(); $GLOBALS['meta'] = array(); $GLOBALS['transients'] = array();
$GLOBALS['enqueued'] = array(); $GLOBALS['is_singular'] = true; $GLOBALS['current'] = null; $GLOBALS['db_page'] = 0;

function add_action() {} function add_filter() {} function add_shortcode() {} function do_action() {}
function apply_filters($h, $v) { return $v; }
function __($s, $d = null) { return $s; }
function _n($a, $b, $n, $d = null) { return $n == 1 ? $a : $b; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_url($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_html_e($s, $d = null) { echo esc_html($s); }
function esc_attr_e($s, $d = null) { echo esc_attr($s); }
function number_format_i18n($n, $d = 0) { return number_format($n, $d); }
function is_singular() { return $GLOBALS['is_singular']; }
function get_post($id = null) { return $id === null ? $GLOBALS['current'] : ($GLOBALS['posts'][(int) $id] ?? null); }
function get_post_meta($id, $k, $s = false) { return $GLOBALS['meta'][$id][$k] ?? ''; }
function get_post_status($id) { return isset($GLOBALS['posts'][$id]) ? $GLOBALS['posts'][$id]->post_status : false; }
function get_permalink($id) { return !empty($GLOBALS['plain']) ? 'https://site/?page_id=' . $id : 'https://site/' . $GLOBALS['posts'][$id]->post_name . '/'; }
function home_url($p = '') { return 'https://site' . $p; }
function add_query_arg($args, $url = null) {
    if (!is_array($args)) { $args = array($args => $url); $url = func_get_arg(2); }
    return $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($args);
}
function get_transient($k) { return $GLOBALS['transients'][$k] ?? false; }
function set_transient($k, $v, $t) { $GLOBALS['transients'][$k] = $v; return true; }
function wp_enqueue_style($h) { $GLOBALS['enqueued'][] = 'css:' . $h; }
function wp_enqueue_script($h) { $GLOBALS['enqueued'][] = 'js:' . $h; }
function wp_localize_script() {}
function wp_create_nonce() { return 'n'; }
function admin_url($p = '') { return 'https://site/wp-admin/' . $p; }
function wp_get_attachment_image_url() { return ''; }
function wp_unslash($v) { return is_string($v) ? stripslashes($v) : $v; }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)); }
function sanitize_title($s) { return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(strip_tags((string) $s))), '-'); }
function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
function selected($a, $b) { if ((string) $a === (string) $b) echo ' selected="selected"'; }
function checked($a, $b = true) { if ((string) $a === (string) $b) echo ' checked="checked"'; }

class Fake_WPDB {
    public $posts = 'wp_posts'; public $postmeta = 'wp_postmeta';
    public $queries = 0;
    public function esc_like($s) { return $s; }
    public function prepare($q, ...$a) { return $q; }
    public function get_var($q) { $this->queries++; return $GLOBALS['db_page']; }
}
$GLOBALS['wpdb'] = new Fake_WPDB();

// Stand-ins for the classes the front end reads from; each test sets what they return.
class ECare_Lab_Settings { public static $s = array(); public static function get($k) { return self::$s[$k] ?? 0; } public static function all() { return self::$s; } }
class ECare_Lab_Catalog {
    public static $filters = array(); public static $rows = array(); public static $result = array(); public static $last = null;
    public static function filters() { return self::$filters; }
    public static function collection_cards($id) { return self::$rows[$id] ?? array(); }
    public static function search($a) { self::$last = $a; return self::$result; }
}
class ECare_Lab_Taxonomies {
    const GROUP_ORGAN = 'organ'; const GROUP_CONCERN = 'concern';
    public static $cols = array(); public static $cats = array();
    public static function collections() { return self::$cols; }
    public static function categories($g = '') { return self::$cats[$g] ?? array(); }
    public static function icon_url($id) { return ''; }
}

require_once ($argv[1] ?? (__DIR__ . '/../includes/class-ecare-lab-front.php'));

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; printf("  PASS  %s\n", $label); }
    else { $fail++; printf("  FAIL  %s\n          got:  %s\n          want: %s\n", $label, var_export($got, true), var_export($want, true)); }
}
$F = 'ECare_Lab_Front';
function page($id, $slug, $content = '', $status = 'publish') { $GLOBALS['posts'][$id] = (object) array('ID' => $id, 'post_name' => $slug, 'post_content' => $content, 'post_status' => $status); }

// ===========================================================================
echo "\n=== A. when the lab assets load ===\n";
// ===========================================================================
page(10, 'lab', '[ecare_lab_home]');
page(11, 'about', 'Welcome');
page(12, 'elementor-lab', '');
$GLOBALS['meta'][12]['_elementor_data'] = '[{"widgetType":"ecare_lab_home"}]';
page(13, 'old-lab', '[ecare_lab_tests]');
$GLOBALS['current'] = $GLOBALS['posts'][10]; check('a page with [ecare_lab_home]', $F::on_lab_page(), true);
$GLOBALS['current'] = $GLOBALS['posts'][11]; check('an ordinary page', $F::on_lab_page(), false);
$GLOBALS['current'] = $GLOBALS['posts'][12]; check('an Elementor page with the widget', $F::on_lab_page(), true);
$GLOBALS['current'] = $GLOBALS['posts'][13]; check('the old catalogue page does not load the new assets', $F::on_lab_page(), false);
$GLOBALS['is_singular'] = false; check('an archive or the blog', $F::on_lab_page(), false); $GLOBALS['is_singular'] = true;
$GLOBALS['current'] = $GLOBALS['posts'][11]; $F::enqueue(); check('nothing enqueued on an ordinary page', $GLOBALS['enqueued'], array());
$GLOBALS['current'] = $GLOBALS['posts'][10]; $F::enqueue(); check('css and js on the lab page', $GLOBALS['enqueued'], array('css:ecare-lab', 'js:ecare-lab'));

// ===========================================================================
echo "\n=== B. links ===\n";
// ===========================================================================
page(20, 'lab-tests', '[ecare_lab_catalog]');
$GLOBALS['db_page'] = 20;
check('tests page found automatically', $F::url('tests'), 'https://site/lab-tests/');
$q = $GLOBALS['wpdb']->queries; $F::url('tests');
check('... and remembered', $GLOBALS['wpdb']->queries, $q);
$GLOBALS['transients'] = array(); $GLOBALS['db_page'] = 0; $F::url('cart'); $GLOBALS['db_page'] = 40; page(40, 'lab-cart', '[ecare_lab_cart]');
check('a miss is not remembered: a page added later is found at once', $F::url('cart'), 'https://site/lab-cart/');
page(21, 'all-tests', '');
ECare_Lab_Settings::$s = array('page_tests' => 21);
check('a page chosen in settings wins', $F::url('tests'), 'https://site/all-tests/');
check('filter arguments', $F::url('tests', array('category' => 'diabetes')), 'https://site/all-tests/?category=diabetes');
page(30, 'fbs', ''); $GLOBALS['posts'][30]->post_type = 'ecare_lab_test';
check('a test detail link uses its slug', $F::url('test', array('id' => 30)), 'https://site/all-tests/?lab_test=fbs');
$GLOBALS['posts'][21]->post_status = 'draft';
$GLOBALS['db_page'] = 0; $GLOBALS['transients'] = array();
check('a chosen page that is not published falls back, then to home', $F::url('tests'), 'https://site/');

// ===========================================================================
echo "\n=== C. money ===\n";
// ===========================================================================
check('whole taka', $F::money(300), '৳300');
check('thousands', $F::money(1895), '৳1,895');
check('paisa kept when present', $F::money(120.5), '৳120.50');

// ===========================================================================
echo "\n=== D. the card ===\n";
// ===========================================================================
ECare_Lab_Settings::$s = array('page_tests' => 20); $GLOBALS['posts'][20]->post_status = 'publish';
$card = array('id' => 30, 'title' => 'FBS <b>', 'image' => '', 'type' => 'single', 'items' => 0, 'report' => '12 hours',
              'price' => 380.0, 'mrp' => 450.0, 'discount' => 16, 'labs' => array(array('id' => 1, 'name' => 'LabAid', 'logo' => ''), array('id' => 2, 'name' => 'Popular', 'logo' => 'p.png')), 'more_labs' => 2);
$html = $F::render_card($card);
check('title is escaped', strpos($html, 'FBS &lt;b&gt;') !== false && strpos($html, 'FBS <b>') === false, true);
check('price, struck MRP, % off', array(strpos($html, '৳380') !== false, strpos($html, '<del>৳450</del>') !== false, strpos($html, '16% OFF') !== false), array(true, true, true));
check('a lab without a logo shows its initial; with a logo, the image', array(strpos($html, '>L</span>') !== false, strpos($html, 'src="p.png"') !== false), array(true, true));
check('+2 more labs', strpos($html, '+2') !== false, true);
check('report time', strpos($html, 'Report in 12 hours') !== false, true);
check('Book Test carries the test id', strpos($html, 'data-ecl-book="30"') !== false, true);
check('no "Includes" line on a single test', strpos($html, 'Includes') === false, true);
$card['mrp'] = 0.0; $card['discount'] = 0; $card['type'] = 'package'; $card['items'] = 3;
$html = $F::render_card($card);
check('no discount: no struck price, no badge', array(strpos($html, '<del>'), strpos($html, 'OFF')), array(false, false));
check('package: "Includes 3 tests"', strpos($html, 'Includes 3 tests') !== false, true);

// ===========================================================================
echo "\n=== E. the home page ===\n";
// ===========================================================================
ECare_Lab_Settings::$s = array('page_tests' => 20, 'banner_id' => 0, 'banner_link' => '', 'messenger_link' => '', 'hotline' => '',
                               'steps' => array(array('title' => 'Collect', 'text' => 'We come to you')));
ECare_Lab_Taxonomies::$cols = array((object) array('term_id' => 300, 'name' => 'Trending', 'slug' => 'trending'), (object) array('term_id' => 301, 'name' => 'Empty Row', 'slug' => 'empty'));
ECare_Lab_Catalog::$rows = array(300 => array(array('id' => 30) + $card));
ECare_Lab_Taxonomies::$cats = array('organ' => array((object) array('term_id' => 200, 'name' => 'Liver', 'slug' => 'liver'), (object) array('term_id' => 201, 'name' => 'Kidney', 'slug' => 'kidney')), 'concern' => array((object) array('term_id' => 202, 'name' => 'Cancer', 'slug' => 'cancer')));
ECare_Lab_Catalog::$filters = array('categories' => array(array('id' => 200, 'count' => 1)), 'labs' => array(array('id' => 1, 'name' => 'LabAid', 'logo' => '', 'count' => 3)));
$home = $F::render_home();
check('a collection with tests is shown, with View All', array(strpos($home, 'Trending') !== false, strpos($home, 'collection=trending') !== false), array(true, true));
check('an empty collection is left out', strpos($home, 'Empty Row'), false);
check('only categories with tests (Liver yes, Kidney no)', array(strpos($home, 'Liver') !== false, strpos($home, 'Kidney')), array(true, false));
check('a grid with nothing in it is left out', strpos($home, 'health concerns'), false);
check('the search box sends q, never s', array(strpos($home, 'name="q"') !== false, strpos($home, 'name="s"')), array(true, false));
check('no Messenger card without a link', strpos($home, 'Messenger'), false);
check('lab partners link to the tests page filtered by lab', strpos($home, 'lab=1') !== false, true);
check('how we work', strpos($home, 'We come to you') !== false, true);
ECare_Lab_Settings::$s['messenger_link'] = 'https://m.me/meditaj';
check('Messenger card with a link', strpos($F::render_home(), 'https://m.me/meditaj') !== false, true);

// ===========================================================================
echo "\n=== F. the tests page ===\n";
// ===========================================================================
$A = $F::catalog_args(array('q' => ' <b>fbs</b> ', 'category' => 'Diabetes Care!', 'collection' => 'trending', 'lab' => '7x', 'type' => 'PACKAGE', 'sort' => 'price_desc', 'pg' => '-3'));
check('query string is cleaned', $A, array('q' => 'fbs', 'category' => 'diabetes-care', 'collection' => 'trending', 'lab' => 7, 'type' => 'package', 'sort' => 'price_desc', 'pg' => 1));
check('unknown sort and type fall back', array_intersect_key($F::catalog_args(array('sort' => 'random', 'type' => 'x')), array('sort' => 1, 'type' => 1)), array('type' => '', 'sort' => 'name'));
check('search is capped at 100 characters', strlen($F::catalog_args(array('q' => str_repeat('a', 500)))['q']), 100);
ECare_Lab_Settings::$s = array('page_tests' => 20);
check('defaults are left out of links', $F::catalog_url(array('q' => '', 'category' => 'liver', 'collection' => '', 'lab' => 0, 'type' => '', 'sort' => 'name', 'pg' => 1)), 'https://site/lab-tests/?category=liver');

ECare_Lab_Catalog::$filters = array(
    'categories'  => array(array('id' => 200, 'slug' => 'liver', 'name' => 'Liver', 'icon' => '', 'count' => 2)),
    'collections' => array(array('id' => 300, 'slug' => 'trending', 'name' => 'Trending')),
    'labs'        => array(array('id' => 7, 'name' => 'LabAid', 'logo' => '', 'count' => 3)),
);
ECare_Lab_Catalog::$result = array('items' => array($card, $card), 'total' => 26, 'page' => 2, 'pages' => 3);
$_GET = array('q' => 'fbs', 'category' => 'liver', 'lab' => '7', 'sort' => 'price_asc', 'pg' => '2');
$html = $F::render_catalog();
check('the query reaches search() under its own names', ECare_Lab_Catalog::$last, array('s' => 'fbs', 'category' => 'liver', 'collection' => '', 'provider' => 7, 'type' => '', 'orderby' => 'price_asc', 'page' => 2, 'per_page' => 12));
check('count line', strpos($html, 'Showing 13–14 of 26') !== false, true);
check('chips name what is filtered', array(strpos($html, '&quot;fbs&quot;') !== false, strpos($html, '>Liver <span') !== false, strpos($html, '>LabAid <span') !== false), array(true, true, true));
check('removing the lab chip keeps the other filters', strpos($html, 'href="https://site/lab-tests/?q=fbs&amp;category=liver&amp;sort=price_asc"') !== false, true);
check('the chosen radio is checked', (bool) preg_match('/value="liver"\s+checked/', $html), true);
check('pagination keeps the filters', strpos($html, '?q=fbs&amp;category=liver&amp;lab=7&amp;sort=price_asc&amp;pg=3') !== false, true);
check('page 1 link drops pg', strpos($html, 'href="https://site/lab-tests/?q=fbs&amp;category=liver&amp;lab=7&amp;sort=price_asc" rel="prev"') !== false, true);
check('the search field is "q"', strpos($html, 'name="q" value="fbs"') !== false, true);

ECare_Lab_Catalog::$result = array('items' => array(), 'total' => 0, 'page' => 1, 'pages' => 1);
$html = $F::render_catalog();
check('nothing found: message and a way back', array(strpos($html, 'No tests match') !== false, strpos($html, 'Show all tests') !== false), array(true, true));

page(50, 'plain', ''); $GLOBALS['posts'][50]->post_status = 'publish';
function_exists('x');
ECare_Lab_Settings::$s = array('page_tests' => 51);
$GLOBALS['posts'][51] = (object) array('ID' => 51, 'post_name' => 'x', 'post_content' => '', 'post_status' => 'publish');
$GLOBALS['plain'] = true;
$html = $F::render_catalog();
check('plain permalinks: page_id rides along as a hidden field', strpos($html, 'name="page_id" value="51"') !== false, true);
$_GET = array();

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
