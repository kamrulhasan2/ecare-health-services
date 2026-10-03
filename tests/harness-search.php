<?php
/**
 * Guards ECare_Search: the site search results page.
 *
 * What it protects:
 *   - matching: every word must be found; joining words ("with") are ignored;
 *     names that start with the query rank first; any script (Bangla) works
 *   - lab tests match by name, other name, subtitle and category, only when
 *     there is a tests page to link to
 *   - only approved caregivers, ambulances and (EG Care) doctors are listed
 *   - nothing private reaches the page: phone, email, NID, bank, plate
 *   - EG Care's doctor redirect is kept unless this page has more to show
 *   - the page is used only for an unscoped front-end search
 */

define('ABSPATH', __DIR__ . '/');
define('ECARE_PLUGIN_DIR', __DIR__ . '/../');
define('ECARE_PLUGIN_URL', 'https://site/p/');
define('ECARE_VERSION', 't');
define('HOUR_IN_SECONDS', 3600);

$GLOBALS['posts'] = array(); $GLOBALS['meta'] = array(); $GLOBALS['terms'] = array(); $GLOBALS['transients'] = array();
$GLOBALS['is_search'] = true; $GLOBALS['is_admin'] = false; $GLOBALS['s'] = '';
function add_filter() {} function add_action() {}
function __($s, $d = null) { return $s; }
function _n($a, $b, $n, $d = null) { return $n == 1 ? $a : $b; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_url($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_html__($s, $d = null) { return esc_html($s); }
function esc_html_e($s, $d = null) { echo esc_html($s); }
function esc_attr_e($s, $d = null) { echo esc_attr($s); }
function is_admin() { return $GLOBALS['is_admin']; }
function is_search() { return $GLOBALS['is_search']; }
function get_search_query($e = true) { return $GLOBALS['s']; }
function home_url($p = '') { return 'https://site' . $p; }
function get_permalink($id) { return 'https://site/page-' . (is_object($id) ? $id->ID : $id) . '/'; }
function get_the_title($p) { return is_object($p) ? $p->post_title : ($GLOBALS['posts'][$p]->post_title ?? ''); }
function get_the_excerpt($p) { return $p->post_excerpt ?? ''; }
function wp_trim_words($s, $n) { return $s; }
function wp_strip_all_tags($s) { return strip_tags($s); }
function paginate_links($a) { return ''; }
function add_query_arg($k, $v, $u) { return $u . (strpos($u, '?') === false ? '?' : '&') . $k . '=' . $v; }
function remove_query_arg($k, $u) { return $u; }
function post_type_exists($t) { return $t === 'doctors' && !empty($GLOBALS['egcare']); }
function get_transient($k) { return $GLOBALS['transients'][$k] ?? false; }
function set_transient($k, $v, $t) { $GLOBALS['transients'][$k] = $v; }
function delete_transient($k) { unset($GLOBALS['transients'][$k]); }
function get_post_meta($id, $k, $s = false) { return $GLOBALS['meta'][$id][$k] ?? ''; }
function has_post_thumbnail($id) { return false; }
function get_the_post_thumbnail_url($id, $size = null) { return ''; }
function get_the_terms($id, $tax) { return array_map(function ($n) { return (object) array('name' => $n); }, $GLOBALS['terms'][$id][$tax] ?? array()); }
function wp_list_pluck($list, $f) { return array_map(function ($o) use ($f) { return is_object($o) ? $o->$f : $o[$f]; }, $list); }
function update_object_term_cache($ids, $t) { $GLOBALS['term_primes'][] = $ids; }
function get_posts($a) {
    $out = array();
    foreach ($GLOBALS['posts'] as $p) {
        if ($p->post_type !== $a['post_type'] || $p->post_status !== 'publish') { continue; }
        if (isset($a['post__in']) && !in_array($p->ID, $a['post__in'], true)) { continue; }
        foreach ($a['meta_query'] ?? array() as $mq) { if (($GLOBALS['meta'][$p->ID][$mq['key']] ?? '') !== $mq['value']) { continue 2; } }
        $out[] = $p;
    }
    return $out;
}
function post($id, $type, $title, $meta = array(), $status = 'publish') { $GLOBALS['posts'][$id] = (object) array('ID' => $id, 'post_type' => $type, 'post_title' => $title, 'post_status' => $status, 'post_excerpt' => ''); $GLOBALS['meta'][$id] = $meta; }

class ECare_Lab_Catalog {
    public static $map = array();
    public static function bookable_map() { return self::$map; }
    public static function card($id) { return array('id' => $id, 'title' => get_the_title($id)); }
}
class ECare_Lab_Front {
    public static $tests_page = 20;
    public static function page_id($w) { return $w === 'tests' ? self::$tests_page : 0; }
    public static function url($w, $a = array()) { return 'https://site/' . $w . '/'; }
    public static function catalog_url($a) { return 'https://site/tests/?q=' . $a['q']; }
    public static function render_card($c) { return '<article class="ecl-card">' . esc_html($c['title']) . '</article>'; }
    public static function icon($n) { return '<svg></svg>'; }
    public static function money($n) { return '৳' . number_format($n); }
}
class ECare_Lab_Test_Info { public static $d = array(); public static function details($id) { return (self::$d[$id] ?? array()) + array('also_known_as' => '', 'subtitle' => ''); } }
class ECare_Lab_Taxonomies { const CATEGORY = 'ecare_lab_category'; }

class Fake_WPDB {
    public $posts = 'wp_posts'; public $postmeta = 'wp_postmeta'; public $doctors = array(); public $page = 0;
    public function esc_like($s) { return $s; }
    public function prepare($q, ...$a) { $this->last = $a; return $q; }
    public function get_var($q) { return $this->page; }
    public $sql = ''; public function get_results($q) { $this->sql = preg_replace('/\s+/', ' ', $q); return $this->doctors; }
}
$GLOBALS['wpdb'] = new Fake_WPDB();
$GLOBALS['wp_query'] = (object) array('posts' => array());

require_once ($argv[1] ?? (__DIR__ . '/../includes/class-ecare-search.php'));

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; printf("  PASS  %s\n", $label); }
    else { $fail++; printf("  FAIL  %s\n          got:  %s\n          want: %s\n", $label, var_export($got, true), var_export($want, true)); }
}
function names($rows) { return array_map(function ($r) { return $r['name']; }, $rows); }
$S = 'ECare_Search';

echo "\n=== A. matching ===\n";
check('punctuation and joining words dropped, case folded', $S::tokens('CBC with ESR!'), array('cbc', 'esr'));
check('Bangla letters kept', $S::tokens('ফাতেমা  ইয়াসমিন'), array('ফাতেমা', 'ইয়াসমিন'));
check('only joining words: nothing to search', $S::tokens('with the'), array());
check('name starts with the query: 3', $S::score(array('cbc', 'esr'), 'CBC with ESR'), 3);
check('every word in the name: 2', $S::score(array('esr', 'cbc'), 'CBC with ESR'), 2);
check('a word only in the other names: 1', $S::score(array('blood', 'count'), 'CBC', 'Complete Blood Count'), 1);
check('a word nowhere: 0', $S::score(array('cbc', 'urine'), 'CBC with ESR', 'Hematology'), 0);
check('part of a word counts (typing)', $S::score(array('fate'), 'Fatema Yasmin'), 3);
check('Bangla name matches', $S::score($S::tokens('ফাতেমা'), 'ফাতেমা ইয়াসমিন'), 3);
check('ranked: best score, then name', names($S::rank(array(array('score' => 1, 'name' => 'B'), array('score' => 3, 'name' => 'Z'), array('score' => 1, 'name' => 'A')))), array('Z', 'A', 'B'));

echo "\n=== B. lab tests ===\n";
post(101, 'ecare_lab_test', 'CBC with ESR'); post(102, 'ecare_lab_test', 'Fasting Blood Sugar'); post(103, 'ecare_lab_test', 'Lipid Profile'); post(104, 'ecare_lab_test', 'CBC Draft', array(), 'draft'); post(105, 'ecare_lab_test', 'Unbookable CBC');
ECare_Lab_Catalog::$map = array(101 => 1, 102 => 1, 103 => 1, 104 => 1);
ECare_Lab_Test_Info::$d = array(102 => array('also_known_as' => 'FBS, Glucose'));
$GLOBALS['terms'][103] = array('ecare_lab_category' => array('Heart Health'));
check('by name; only bookable and published', names($S::labs($S::tokens('cbc'))), array('CBC with ESR'));
check('by other name', names($S::labs($S::tokens('glucose'))), array('Fasting Blood Sugar'));
check('by category', names($S::labs($S::tokens('heart'))), array('Lipid Profile'));
ECare_Lab_Front::$tests_page = 0;
check('no tests page to link to: no lab section', $S::labs($S::tokens('cbc')), array());
ECare_Lab_Front::$tests_page = 20;

echo "\n=== C. caregivers and ambulances ===\n";
post(201, 'ecare_caregiver', 'Fatema Yasmin', array('_provider_status' => 'approved', '_provider_type' => 'Nurse', '_experience' => '5', '_phone' => '01711111111', '_email' => 'f@x.com', '_nid_passport' => 'NID-999', '_bank_account' => 'BANK-777'));
post(202, 'ecare_caregiver', 'Fatema Pending', array('_provider_status' => 'pending', '_provider_type' => 'Nurse'));
post(203, 'ecare_caregiver', 'Rina Akter', array('_provider_status' => 'approved', '_provider_type' => 'Nanny'));
check('approved only', names($S::caregivers($S::tokens('fatema'))), array('Fatema Yasmin'));
check('by type', names($S::caregivers($S::tokens('nanny'))), array('Rina Akter'));
post(301, 'ecare_ambulance', 'City Ambulance Co', array('_ambulance_status' => 'approved', '_driver_name' => 'Karim', '_ambulance_type' => 'ICU', '_license_plate' => 'DHA-PLATE-1', '_phone' => '01822222222'));
post(302, 'ecare_ambulance', 'Rejected Van', array('_ambulance_status' => 'rejected', '_ambulance_type' => 'ICU'));
$a = $S::ambulances($S::tokens('icu ambulance'));
check('approved only; driver named, company kept, type label', array(names($a), $a[0]['company'], $a[0]['type_label']), array(array('Karim'), 'City Ambulance Co', 'ICU (AC)'));
check('by company name too', names($S::ambulances($S::tokens('city'))), array('Karim'));

echo "\n=== D. doctors (EG Care) ===\n";
check('without EG Care: no doctors, no error', $S::doctors($S::tokens('fatema')), array());
$GLOBALS['egcare'] = true;
eval('namespace EGCare; class DB { public static function get_table($k) { return "wp_egc_doctors_meta"; } } class Shortcodes { public static function page_with_shortcode($s) { return "https://site/doctors-list/"; } }');
$GLOBALS['wpdb']->doctors = array((object) array('ID' => 401, 'post_title' => 'Dr. Fatema Yasmin', 'degree' => 'MBBS, FCPS', 'designation' => 'Consultant', 'consultation_fee' => '800'));
$GLOBALS['terms'][401] = array('specialty' => array('Gynecology'));
$d = $S::doctors($S::tokens('gynecology'));
check('by specialty; degree, fee kept; terms read in one go', array(names($d), $d[0]['degree'], $d[0]['fee'], $GLOBALS['term_primes'][0]), array(array('Dr. Fatema Yasmin'), 'MBBS, FCPS', 800.0, array(401)));
check('only published, approved doctors are asked for', array(strpos($GLOBALS['wpdb']->sql, "p.post_status = 'publish'") !== false, strpos($GLOBALS['wpdb']->sql, "m.verification_status = 'approved'") !== false), array(true, true));
check('doctor list link carries the search', $S::doctor_list_url('Fatema'), 'https://site/doctors-list/?eg_search=Fatema');

echo "\n=== E. EG Care's doctor redirect ===\n";
check('only a doctor matches: EG Care still sends them to its list', $S::egcare_redirect('https://site/doctors-list/?eg_search=x', 'Dr. Fatema'), 'https://site/doctors-list/?eg_search=x');
check('a caregiver matches too: stay here', $S::egcare_redirect('https://site/doctors-list/?eg_search=x', 'Fatema'), '');

echo "\n=== F. when the page is used ===\n";
$_GET = array();
check('front-end search: ours', basename($S::template('theme-search.php')), 'ecare-search.php');
$_GET = array('post_type' => 'product');
check('scoped to a post type: the theme\'s', $S::template('theme-search.php'), 'theme-search.php');
$_GET = array(); $GLOBALS['is_search'] = false;
check('not a search: the theme\'s', $S::template('page.php'), 'page.php');
$GLOBALS['is_search'] = true;
check('lab CSS/JS load on it', $S::lab_assets(false, null), true);

echo "\n=== G. the page ===\n";
$GLOBALS['wpdb']->page = 50;
$GLOBALS['s'] = 'Fatema <';
$GLOBALS['wp_query']->posts = array((object) array('ID' => 9, 'post_type' => 'post', 'post_title' => 'Fatema joins us', 'post_excerpt' => ''), (object) array('ID' => 401, 'post_type' => 'doctors', 'post_title' => 'Dr. Fatema Yasmin', 'post_excerpt' => ''));
$html = $S::render();
check('the query is escaped', array(strpos($html, 'Fatema &lt;') !== false, strpos($html, 'Fatema <')), array(true, false));
check('sections: doctors, caregivers, other pages (doctors not repeated there)', array(strpos($html, 'id="ecl-sr-doctor"') !== false, strpos($html, 'id="ecl-sr-caregiver"') !== false, strpos($html, 'Fatema joins us') !== false, substr_count($html, 'Dr. Fatema Yasmin')), array(true, true, true, 1));
check('caregiver link opens that caregiver on the caregiver page', strpos($html, 'https://site/page-50/?ecare_caregiver=201') !== false, true);
check('nothing private: phone, email, NID, bank', array(strpos($html, '01711111111'), strpos($html, 'f@x.com'), strpos($html, 'NID-999'), strpos($html, 'BANK-777')), array(false, false, false, false));
$GLOBALS['s'] = 'icu';
$html = $S::render();
check('ambulance: Book link chooses the type; plate and phone never shown', array(strpos($html, 'ecare_amb_type=ICU') !== false, strpos($html, 'DHA-PLATE-1'), strpos($html, '01822222222')), array(true, false, false));
$GLOBALS['s'] = 'zzzz'; $GLOBALS['wp_query']->posts = array();
$html = $S::render();
check('nothing found: said, with places to browse', array(strpos($html, 'Nothing found for &quot;zzzz&quot;') !== false, strpos($html, 'Find a Caregiver') !== false), array(true, true));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
