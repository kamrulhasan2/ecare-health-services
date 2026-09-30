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
 *   - the detail view (?lab_test=slug) shows only published, bookable tests,
 *     escapes what it prints, and names the test in the browser tab
 *   - the booking endpoints: anyone may read the options, only a logged-in
 *     patient may add, and a clash of labs comes back as 409 with the lab named
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
    public static $map = array(); public static $cards = array(); public static $related = array(); public static $labs = array();
    public static function bookable_map() { return self::$map; }
    public static function card($id) { return self::$cards[$id]; }
    public static function related($id, $n) { return self::$related; }
    public static function labs_for_test($id) { return self::$labs[$id] ?? array(); }
}
class ECare_Lab_Taxonomies {
    const GROUP_ORGAN = 'organ'; const GROUP_CONCERN = 'concern'; const CATEGORY = 'ecare_lab_category';
    public static $cols = array(); public static $cats = array();
    public static function collections() { return self::$cols; }
    public static function categories($g = '') { return self::$cats[$g] ?? array(); }
    public static function icon_url($id) { return ''; }
}


// --- step 11: detail page and booking endpoints -----------------------------
function esc_html__($s, $d = null) { return esc_html($s); }
function get_posts($a) {
    $out = array();
    foreach ($GLOBALS['posts'] as $p) {
        if (($p->post_type ?? '') === $a['post_type'] && $p->post_name === $a['name'] && $p->post_status === $a['post_status']) { $out[] = $p; }
    }
    return array_slice($out, 0, 1);
}
function wp_get_object_terms($id, $tax) { return $GLOBALS['terms'][$id] ?? array(); }
function is_wp_error($x) { return false; }
function wpautop($s) { return '<p>' . $s . '</p>'; }
function wp_kses_post($s) { return preg_replace('#<script\b.*?</script>#is', '', (string) $s); }
function get_the_title($id) { return isset($GLOBALS['posts'][$id]) ? ($GLOBALS['posts'][$id]->post_title ?? '') : ''; }
function get_the_post_thumbnail_url($id, $size = null) { return ''; }
function wp_trim_words($s, $n) { return implode(' ', array_slice(preg_split('/\s+/', trim($s)), 0, $n)); }
function wp_strip_all_tags($s) { return strip_tags($s); }
function check_ajax_referer($a, $k) { if (($_POST[$k] ?? '') !== 'n') { throw new Json_Out(false, array('nonce' => 'bad'), 403); } return 1; }
function is_user_logged_in() { return !empty($GLOBALS['uid']); }
function get_current_user_id() { return (int) ($GLOBALS['uid'] ?? 0); }
function wp_get_referer() { return 'https://site/lab-tests/?lab_test=fbs'; }
function wp_login_url($back) { return 'https://site/wp-login.php?redirect_to=' . rawurlencode($back); }
class Json_Out extends Exception { public $ok; public $data; public $status; public function __construct($ok, $data, $status) { $this->ok = $ok; $this->data = $data; $this->status = $status; } }
function wp_send_json_success($d = null, $status = 200) { throw new Json_Out(true, $d, $status); }
function wp_send_json_error($d = null, $status = 400) { throw new Json_Out(false, $d, $status); }
function ajax($method) { try { ECare_Lab_Front::$method(); } catch (Json_Out $e) { return array($e->ok, $e->status, $e->data); } return null; }
class ECare_Lab_Test_Info {
    public static $d = array();
    public static function details($id) {
        return (self::$d[$id] ?? array()) + array('type' => 'single', 'subtitle' => '', 'also_known_as' => '', 'parameters' => 0, 'sample' => '', 'fasting' => '', 'report' => '', 'available_for' => '', 'faq' => array());
    }
    public static function type($id) { return self::details($id)['type']; }
}
class ECare_Lab_Packages {
    public static $incl = array(); public static $extra = array();
    public static function included_test_ids($id) { return self::$incl[$id] ?? array(); }
    public static function extra_items($id) { return self::$extra[$id] ?? array(); }
}
class ECare_Lab_Cart {
    const MAX_PATIENTS = 10;
    public static $carts = array(); public static $add = null; public static $last_add = null;
    public static function get($u) { return self::$carts[$u] ?? array('provider_id' => 0, 'items' => array()); }
    public static function add($u, $t, $l, $n, $mode = '') { self::$last_add = array($u, $t, $l, $n, $mode); return self::$add; }
    public static function priced($u) { return array('count' => 2, 'subtotal' => 830.0); }
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


// ===========================================================================
echo "\n=== G. the test detail view ===\n";
// ===========================================================================
function test_post($id, $slug, $title, $status = 'publish', $content = '') {
    $GLOBALS['posts'][$id] = (object) array('ID' => $id, 'post_type' => 'ecare_lab_test', 'post_name' => $slug, 'post_title' => $title, 'post_status' => $status, 'post_content' => $content);
}
ECare_Lab_Settings::$s = array('page_tests' => 20, 'steps' => array(array('title' => 'Collect', 'text' => 'At home')));
$GLOBALS['plain'] = false; $GLOBALS['posts'][20]->post_status = 'publish';
unset($GLOBALS['posts'][30]);   // section B's stand-in used the same slug
test_post(101, 'fbs', 'FBS <i>', 'publish', "Checks sugar.<script>alert(1)</script>");
test_post(102, 'old-test', 'Old', 'draft');
test_post(103, 'no-lab', 'No Lab Yet');
test_post(105, 'diabetes-care', 'Diabetes Care');
test_post(106, 'hba1c', 'HbA1c');
page(107, 'fbs-page', ''); $GLOBALS['posts'][107]->post_type = 'page';

$_GET = array('lab_test' => 'fbs');           check('a published test by slug', $F::requested_test()->ID, 101);
$_GET = array('lab_test' => 'FBS');           check('the slug is case-insensitive', $F::requested_test()->ID, 101);
$_GET = array('lab_test' => '101');           check('a bare id works too', $F::requested_test()->ID, 101);
$_GET = array('lab_test' => 'old-test');      check('a draft is not shown', $F::requested_test(), null);
$_GET = array('lab_test' => '107');           check('another post type by id is not shown', $F::requested_test(), null);
$_GET = array('lab_test' => '');              check('empty', $F::requested_test(), null);

ECare_Lab_Catalog::$last = null;
$_GET = array('lab_test' => 'nothing-here');
$html = $F::render_catalog();
check('unknown test: "Test not found", and no search is run', array(strpos($html, 'Test not found') !== false, ECare_Lab_Catalog::$last), array(true, null));
$_GET = array('lab_test' => 'no-lab');
check('a test no lab takes: says so, no Book button', array(strpos($F::render_catalog(), 'not available right now') !== false, strpos($F::render_catalog(), 'data-ecl-book')), array(true, false));

ECare_Lab_Catalog::$map = array(101 => array('providers' => array(99 => 1, 103 => 1)), 105 => array('providers' => array(99 => 1)), 106 => array('providers' => array(99 => 1)));
$base = array('image' => '', 'items' => 0, 'report' => '', 'labs' => array(array('id' => 99, 'name' => 'Popular', 'logo' => '')), 'more_labs' => 0);
ECare_Lab_Catalog::$cards = array(
    101 => array('id' => 101, 'title' => 'FBS', 'type' => 'single', 'price' => 380.0, 'mrp' => 450.0, 'discount' => 16) + $base,
    105 => array('id' => 105, 'title' => 'Diabetes Care', 'type' => 'package', 'price' => 650.0, 'mrp' => 800.0, 'discount' => 19) + $base,
);
ECare_Lab_Test_Info::$d = array(101 => array('subtitle' => 'Fasting Blood Sugar', 'also_known_as' => 'Glucose <b>F</b>', 'sample' => 'Blood', 'fasting' => 'yes', 'report' => '12 hours',
    'faq' => array(array('q' => 'Why fast? <img>', 'a' => "Line one\nLine two"), array('q' => 'How long?', 'a' => ''))));
$GLOBALS['terms'][101] = array((object) array('slug' => 'diabetes', 'name' => 'Diabetes'));
ECare_Lab_Catalog::$related = array(ECare_Lab_Catalog::$cards[105]);

$_GET = array('lab_test' => 'fbs');
$html = $F::render_catalog();
check('title escaped', array(strpos($html, 'FBS &lt;i&gt;') !== false, strpos($html, 'FBS <i>')), array(true, false));
check('the Book button is the #book target and carries the id', (bool) preg_match('/data-ecl-book="101" id="book"/', $html), true);
check('"from" when more than one lab has it', strpos($html, '<small>from</small>') !== false, true);
check('price, struck MRP, % off', array(strpos($html, '৳380') !== false, strpos($html, '<del>৳450</del>') !== false, strpos($html, '16% OFF') !== false), array(true, true, true));
check('facts: report, fasting, sample', array(strpos($html, '12 hours') !== false, strpos($html, 'Fasting <strong>Yes') !== false, strpos($html, 'Blood') !== false), array(true, true, true));
check('also known as, escaped', strpos($html, 'Glucose &lt;b&gt;F&lt;/b&gt;') !== false, true);
check('a script in the description is stripped', array(strpos($html, 'Checks sugar.') !== false, strpos($html, '<script')), array(true, false));
check('FAQ question escaped, answer keeps its line break', array(strpos($html, 'Why fast? &lt;img&gt;') !== false, strpos($html, "Line one<br />") !== false), array(true, true));
check('only the first FAQ starts open', substr_count($html, '<details open'), 1);
check('category chip links to the filtered list', strpos($html, 'href="https://site/lab-tests/?category=diabetes"') !== false, true);
check('related tests and how-we-work are shown', array(strpos($html, 'Related tests') !== false, strpos($html, 'At home') !== false), array(true, true));
check('a single test has no "Package includes"', strpos($html, 'Package includes'), false);
check('the breadcrumb ends on this page', strpos($html, 'aria-current="page">Test Details') !== false, true);

ECare_Lab_Packages::$incl = array(105 => array(101, 103)); ECare_Lab_Packages::$extra = array(105 => array('Urine R/E'));
$_GET = array('lab_test' => 'diabetes-care');
$html = $F::render_catalog();
check('package: includes list with the extra item', array(strpos($html, 'Package includes') !== false, strpos($html, 'Urine R/E') !== false), array(true, true));
check('package: "3 tests covered"', strpos($html, '3 tests covered by this package') !== false, true);
check('one lab only: no "from"', strpos($html, '<small>from</small>'), false);
check('an included test links to its page only when bookable', array(strpos($html, '?lab_test=fbs') !== false, strpos($html, '?lab_test=no-lab')), array(true, false));

$GLOBALS['current'] = $GLOBALS['posts'][20]; $GLOBALS['posts'][20]->post_content = '[ecare_lab_catalog]';
$_GET = array('lab_test' => 'fbs');
check('browser tab names the test on the lab page', $F::document_title(array('title' => 'All Lab Tests', 'site' => 'Meditaj')), array('title' => 'FBS <i>', 'site' => 'Meditaj'));
$GLOBALS['current'] = $GLOBALS['posts'][11];
check('...and nowhere else', $F::document_title(array('title' => 'About')), array('title' => 'About'));
$_GET = array();

// ===========================================================================
echo "\n=== H. booking endpoints ===\n";
// ===========================================================================
$_POST = array('nonce' => 'bad', 'test_id' => '101');
check('a bad nonce is refused', ajax('ajax_book_options')[1], 403);
$_POST = array('nonce' => 'n', 'test_id' => '103');
check('options for a test no lab takes: 404', array_slice(ajax('ajax_book_options'), 0, 2), array(false, 404));

ECare_Lab_Catalog::$labs = array(101 => array(
    array('id' => 103, 'name' => 'LabAid', 'logo' => '', 'price' => 380.0, 'mrp' => 450.0, 'savings' => 70.0, 'discount' => 16, 'offering_id' => 7, 'material_cost' => 30.0, 'serves_area' => null),
    array('id' => 99, 'name' => 'Popular', 'logo' => '', 'price' => 400.0, 'mrp' => 400.0, 'savings' => 0.0, 'discount' => 0, 'offering_id' => 8, 'material_cost' => 0.0, 'serves_area' => null),
));
$GLOBALS['uid'] = 0;
$_POST = array('nonce' => 'n', 'test_id' => '101');
list($ok, $st, $d) = ajax('ajax_book_options');
check('options for anyone', array($ok, $d['id'], $d['logged_in'], $d['cart_lab'], $d['in_cart'], $d['max']), array(true, 101, false, 0, 0, 10));
check('labs cheapest first, no struck price without a discount', array_map(function ($l) { return array($l['id'], $l['mrp']); }, $d['labs']), array(array(103, 450.0), array(99, 0)));
check('material cost and internal ids stay on the server', array_keys($d['labs'][0]), array('id', 'name', 'logo', 'price', 'mrp', 'savings', 'discount'));
check('login link comes back to the page', $d['login_url'], 'https://site/wp-login.php?redirect_to=' . rawurlencode('https://site/lab-tests/?lab_test=fbs'));
$GLOBALS['uid'] = 5; ECare_Lab_Cart::$carts[5] = array('provider_id' => 103, 'items' => array(101 => 3));
list($ok, $st, $d) = ajax('ajax_book_options');
check('logged in: the cart lab and the count already in it', array($d['logged_in'], $d['cart_lab'], $d['in_cart']), array(true, 103, 3));

$GLOBALS['uid'] = 0; ECare_Lab_Cart::$last_add = null;
$_POST = array('nonce' => 'n', 'test_id' => '101', 'lab_id' => '103', 'patients' => '2');
list($ok, $st, $d) = ajax('ajax_cart_add');
check('adding needs a login: 401 with where to go, nothing added', array($ok, $st, $d['code'], ECare_Lab_Cart::$last_add), array(false, 401, 'login', null));
$GLOBALS['uid'] = 5;
ECare_Lab_Cart::$add = array('ok' => false, 'code' => 'other_lab', 'current_lab' => 101, 'can_switch' => true);
$_POST = array('nonce' => 'n', 'test_id' => '105', 'lab_id' => '99', 'patients' => '2', 'on_conflict' => 'DROP TABLE');
list($ok, $st, $d) = ajax('ajax_cart_add');
check('an unknown on_conflict is passed on as none', ECare_Lab_Cart::$last_add, array(5, 105, 99, 2, ''));
check('a lab clash: 409, the cart\'s lab named, whether it can move', array($ok, $st, $d['code'], $d['current_lab'], $d['can_switch']), array(false, 409, 'other_lab', 'FBS <i>', true));
$_POST['on_conflict'] = 'switch'; ajax('ajax_cart_add');
check('"switch" reaches the cart', ECare_Lab_Cart::$last_add[4], 'switch');
ECare_Lab_Cart::$add = array('ok' => false, 'code' => 'not_available');
list($ok, $st, $d) = ajax('ajax_cart_add');
check('a lab that stopped offering it: a message, no lab name', array($st, $d['code'], isset($d['current_lab']), $d['message'] !== ''), array(409, 'not_available', false, true));
ECare_Lab_Cart::$add = array('ok' => true, 'cart' => array());
list($ok, $st, $d) = ajax('ajax_cart_add');
check('added: count, total and the cart link', array($ok, $d['count'], $d['total'], isset($d['cart_url'])), array(true, 2, 830.0, true));
$_POST = array();

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
