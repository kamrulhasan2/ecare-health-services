<?php
/**
 * Guards the admin list queries - finding #14.
 *
 * The booking tables used to select every row with no LIMIT, and the search box
 * above them was an input with nothing behind it. Twenty-one bookings hid both
 * problems; a few thousand would render an enormous table, run the page out of
 * memory, and leave no way to find anything.
 */

define('ABSPATH', __DIR__ . '/');

function add_action() {} function add_filter() {}
function __($s, $d = null) { return $s; }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_url($s) { return $s; }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function wp_unslash($v) { return is_string($v) ? stripslashes($v) : $v; }
function apply_filters($h, $v) { return $v; }
function admin_url($p = '') { return '/wp-admin/' . $p; }

class Fake_WPDB {
    public $prefix = 'wp_';
    public $posts = 'wp_posts';
    public $postmeta = 'wp_postmeta';
    public $log = array();
    public function esc_like($t) { return addcslashes((string) $t, '_%\\'); }
    public function prepare($q, $args = array()) {
        if (!is_array($args)) { $args = array_slice(func_get_args(), 1); }
        $out = ''; $i = 0; $len = strlen($q);
        for ($p = 0; $p < $len; $p++) {
            if ($q[$p] === '%' && $p + 1 < $len && strpos('sd', $q[$p + 1]) !== false) {
                $v = $args[$i] ?? '';
                $out .= $q[$p + 1] === 'd' ? (string) (int) $v : "'" . str_replace("'", "\\'", (string) $v) . "'";
                $i++; $p++; continue;
            }
            $out .= $q[$p];
        }
        return $out;
    }
    public function get_var($q) { $this->log[] = $q; return 137; }
    public function get_results($q) { $this->log[] = $q; return array(); }
}
$GLOBALS['wpdb'] = new Fake_WPDB();

require_once ($argv[1] ?? (__DIR__ . '/../admin/class-ecare-admin.php'));

$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; printf("  PASS  %s\n", $label); }
    else { $fail++; printf("  FAIL  %s\n          got:  %s\n          want: %s\n", $label, var_export($got, true), var_export($want, true)); }
}
function call($name, ...$args) {
    $m = new ReflectionMethod('ECare_Admin', $name);
    $m->setAccessible(true);
    return $m->invoke(null, ...$args);
}
function sql($i = null) {
    $l = $GLOBALS['wpdb']->log;
    return $i === null ? $l : ($l[$i] ?? '');
}
function reset_log() { $GLOBALS['wpdb']->log = array(); }

echo "\n=== every list query is bounded ===\n";
reset_log();
call('query_bookings', 'caregiver', '', 20, 1);
check('two queries: one COUNT, one page', count(sql()), 2);
check('the count is a COUNT(*)', (bool) preg_match('/SELECT COUNT\(\*\)/', sql(0)), true);
check('the page query has a LIMIT', (bool) preg_match('/LIMIT 20 OFFSET 0/', sql(1)), true);
check('no LIKE when nothing was searched', strpos(sql(1), 'LIKE'), false);
check('the booking type is quoted, not interpolated', (bool) preg_match("/booking_type = 'caregiver'/", sql(1)), true);

echo "\n=== paging arithmetic ===\n";
foreach (array(1 => 0, 2 => 20, 5 => 80) as $page => $offset) {
    reset_log();
    call('query_bookings', 'lab', '', 20, $page);
    check("page $page starts at offset $offset", (bool) preg_match("/LIMIT 20 OFFSET {$offset}/", sql(1)), true);
}
reset_log();
call('query_bookings', 'lab', '', 5, 3);
check('a different page size is honoured', (bool) preg_match('/LIMIT 5 OFFSET 10/', sql(1)), true);

echo "\n=== the search box actually searches ===\n";
reset_log();
call('query_bookings', 'ambulance', 'Rina', 20, 1);
check('name is searched', strpos(sql(1), "patient_name LIKE '%Rina%'") !== false, true);
check('phone is searched', strpos(sql(1), "contact_phone LIKE '%Rina%'") !== false, true);
check('a numeric id is searched too', strpos(sql(1), 'id = 0') !== false, true);
check('the count is filtered by the same terms', strpos(sql(0), "patient_name LIKE '%Rina%'") !== false, true);

reset_log();
call('query_bookings', 'caregiver', '21', 20, 1);
check('searching a number matches that booking id', strpos(sql(1), 'id = 21') !== false, true);

echo "\n=== hostile search input ===\n";
reset_log();
call('query_bookings', 'caregiver', "' OR 1=1 -- ", 20, 1);
check('the quote is escaped', strpos(sql(1), "\\' OR 1=1") !== false, true);
check('the injection stays inside a string literal, the query still ends properly',
      (bool) preg_match('/LIMIT 20 OFFSET 0$/', trim(sql(1))), true);
check('no bare quote broke out of the literal', substr_count(sql(1), "'") % 2, 0);
reset_log();
call('query_bookings', 'caregiver', '100%', 20, 1);
check('a LIKE wildcard in the term is escaped', strpos(sql(1), '100\\%') !== false, true);

echo "\n=== KPI tiles count the whole set, not the page ===\n";
reset_log();
call('status_counts', 'ecare_caregiver', '_provider_status');
check('one grouped query', count(sql()), 1);
check('it groups by status', (bool) preg_match('/GROUP BY status/', sql(0)), true);
check('a missing value still counts as pending', strpos(sql(0), "'pending'") !== false, true);
check('it is scoped to published posts of the type', strpos(sql(0), "post_type = 'ecare_caregiver'") !== false, true);

echo "\n=== page size ===\n";
check('defaults to 20', call('per_page'), 20);
$_GET['paged'] = '3';
check('reads the current page', call('current_page'), 3);
$_GET['paged'] = '-4';
check('a negative page falls back to the first', call('current_page'), 1);
unset($_GET['paged']);
check('no paged parameter means page one', call('current_page'), 1);
$_GET['ecare_s'] = '  Rina <b>x</b> ';
check('the search term is sanitised', call('search_term'), 'Rina x');
unset($_GET['ecare_s']);

printf("\n---------------------------------------\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
