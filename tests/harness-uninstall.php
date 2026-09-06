<?php
/**
 * Stubbed harness for uninstall.php.
 *
 * WordPress is replaced by an in-memory double: an options array, a post store,
 * a fake $wpdb that records the SQL it is handed, and a real temporary uploads
 * directory on disk. The uninstall routine is then run twice against it.
 *
 *   Run 1 - the opt-in option is absent, which is what every site has unless
 *           somebody sets it. Nothing may be removed.
 *   Run 2 - the option is set to 'yes'. Everything the plugin owns must go,
 *           and nothing that belongs to anyone else may be touched.
 *
 *   php tests/harness-uninstall.php
 */

define('WP_UNINSTALL_PLUGIN', true);
define('HOUR_IN_SECONDS', 3600);

$UNINSTALL = $argv[1] ?? (__DIR__ . '/../uninstall.php');
if (!is_file($UNINSTALL)) {
    fwrite(STDERR, "uninstall.php not found at {$UNINSTALL}\n");
    exit(2);
}

// ---------------------------------------------------------------------------
// WordPress doubles
// ---------------------------------------------------------------------------

function wp_normalize_path($path) {
    $path = str_replace('\\', '/', (string) $path);
    return preg_replace('#/+#', '/', $path);
}

function untrailingslashit($string) {
    return rtrim((string) $string, '/\\');
}

function get_option($name, $default = false) {
    return array_key_exists($name, $GLOBALS['options']) ? $GLOBALS['options'][$name] : $default;
}

function delete_option($name) {
    if (!array_key_exists($name, $GLOBALS['options'])) {
        return false;
    }
    unset($GLOBALS['options'][$name]);
    $GLOBALS['deleted_options'][] = $name;
    return true;
}

/** Every status core registers, including the two 'any' quietly drops. */
function get_post_stati() {
    return array(
        'publish'    => 'publish',
        'future'     => 'future',
        'draft'      => 'draft',
        'pending'    => 'pending',
        'private'    => 'private',
        'trash'      => 'trash',
        'auto-draft' => 'auto-draft',
        'inherit'    => 'inherit',
    );
}

function get_posts($args) {
    $type     = $args['post_type'];
    $statuses = (array) $args['post_status'];
    $limit    = isset($args['posts_per_page']) ? (int) $args['posts_per_page'] : -1;

    $out = array();
    foreach ($GLOBALS['posts'] as $id => $post) {
        if ($post['post_type'] !== $type) {
            continue;
        }
        if (!in_array($post['post_status'], $statuses, true)) {
            continue;
        }
        $out[] = $id;
        if ($limit > 0 && count($out) >= $limit) {
            break;
        }
    }

    $GLOBALS['get_posts_calls'][] = array('type' => $type, 'returned' => count($out));

    return $out;
}

function wp_delete_post($id, $force = false) {
    if (!isset($GLOBALS['posts'][$id])) {
        return false;
    }
    if (!$force) {
        return false;   // the routine must always force
    }
    if (!empty($GLOBALS['undeletable'][$id])) {
        return false;   // stands in for a post another plugin refuses to release
    }
    unset($GLOBALS['posts'][$id]);
    return (object) array('ID' => $id);
}

function wp_upload_dir() {
    return array('basedir' => $GLOBALS['uploads_basedir'], 'error' => false);
}

class Fake_WPDB {
    public $prefix             = 'wp_';
    public $options            = 'wp_options';
    public $posts              = 'wp_posts';
    public $terms              = 'wp_terms';
    public $termmeta           = 'wp_termmeta';
    public $term_taxonomy      = 'wp_term_taxonomy';
    public $term_relationships = 'wp_term_relationships';

    public $queries = array();   // raw SQL handed to query()
    public $deletes = array();   // [table, where] pairs handed to delete()
    public $terms_rows = array();

    public function esc_like($text) {
        return addcslashes((string) $text, '_%\\');
    }

    public function prepare($query, ...$args) {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $i   = 0;
        $out = '';
        $len = strlen($query);
        for ($p = 0; $p < $len; $p++) {
            if ($query[$p] === '%' && $p + 1 < $len && ($query[$p + 1] === 's' || $query[$p + 1] === 'd')) {
                $v    = isset($args[$i]) ? $args[$i] : '';
                $out .= ($query[$p + 1] === 'd')
                    ? (string) (int) $v
                    : "'" . str_replace("'", "\\'", (string) $v) . "'";
                $i++; $p++;
                continue;
            }
            $out .= $query[$p];
        }
        return $out;
    }

    public function query($sql) {
        $this->queries[] = preg_replace('/\s+/', ' ', trim($sql));
        return 1;
    }

    public function get_results($sql) {
        $this->queries[] = preg_replace('/\s+/', ' ', trim($sql));
        // Only the caregiver-type lookup reaches here.
        return $this->terms_rows;
    }

    public function delete($table, $where, $format = null) {
        $this->deletes[] = array($table, $where);
        return 1;
    }
}

// ---------------------------------------------------------------------------
// World building
// ---------------------------------------------------------------------------

function rm_tree($path) {
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) { return; }
    foreach (array_diff(scandir($path), array('.', '..')) as $entry) {
        rm_tree($path . '/' . $entry);
    }
    @rmdir($path);
}

/**
 * @param bool $opt_in  Whether the site owner has asked for the data to go.
 * @param int  $bulk    Extra lab tests, to exercise the batching loop.
 */
function reset_world($opt_in, $bulk = 0) {
    global $wpdb;

    $GLOBALS['options'] = array(
        'ecare_activation_date'              => '2026-01-01',
        'ecare_rewrite_version'              => '1.1.2',
        'ecare_default_daily_12_price'       => 1700,
        'ecare_default_daily_24_price'       => 2200,
        'ecare_default_monthly_12_price'     => 30000,
        'ecare_default_monthly_24_price'     => 50000,
        'ecare_default_physio_regular_price' => 1500,
        'ecare_default_physio_premium_price' => 2000,
        // Somebody else's option. It must survive both runs.
        'woocommerce_currency'               => 'BDT',
    );
    if ($opt_in) {
        $GLOBALS['options']['ecare_delete_data_on_uninstall'] = 'yes';
    }

    $GLOBALS['deleted_options'] = array();
    $GLOBALS['get_posts_calls'] = array();
    $GLOBALS['undeletable']     = array();

    $GLOBALS['posts'] = array(
        1  => array('post_type' => 'ecare_caregiver', 'post_status' => 'publish'),
        2  => array('post_type' => 'ecare_caregiver', 'post_status' => 'draft'),
        // The two 'any' would have missed.
        3  => array('post_type' => 'ecare_caregiver', 'post_status' => 'trash'),
        4  => array('post_type' => 'ecare_lab_test',  'post_status' => 'auto-draft'),
        5  => array('post_type' => 'ecare_lab_test',  'post_status' => 'publish'),
        6  => array('post_type' => 'ecare_ambulance', 'post_status' => 'publish'),
        // Not ours.
        7  => array('post_type' => 'page',            'post_status' => 'publish'),
        8  => array('post_type' => 'product',         'post_status' => 'publish'),
        9  => array('post_type' => 'shop_order',      'post_status' => 'wc-completed'),
        10 => array('post_type' => 'attachment',      'post_status' => 'inherit'),
    );

    for ($i = 0; $i < $bulk; $i++) {
        $GLOBALS['posts'][1000 + $i] = array('post_type' => 'ecare_lab_test', 'post_status' => 'publish');
    }

    $wpdb = new Fake_WPDB();
    $GLOBALS['wpdb'] = $wpdb;
    $wpdb->terms_rows = array(
        (object) array('term_id' => 21, 'term_taxonomy_id' => 31),
        (object) array('term_id' => 22, 'term_taxonomy_id' => 32),
    );

    // A real uploads tree, so the file deletion is genuinely exercised.
    $base = sys_get_temp_dir() . '/ecare-uninstall-' . bin2hex(random_bytes(4));
    rm_tree($base);
    mkdir($base . '/ecare-private/2026/09', 0777, true);
    mkdir($base . '/2026/09', 0777, true);
    file_put_contents($base . '/ecare-private/.htaccess', "Require all denied\n");
    file_put_contents($base . '/ecare-private/2026/09/abc-prescription.pdf', 'secret');
    file_put_contents($base . '/2026/09/logo.png', 'public');       // ordinary media
    file_put_contents($base . '/../ecare-outside.txt', 'untouched');

    // A symlink pointing out of the private tree. It must be unlinked, never
    // followed - otherwise an attacker with write access to the directory could
    // have the uninstall routine delete arbitrary files.
    $GLOBALS['symlinked'] = false;
    $target = $base . '/2026/09/logo.png';
    if (@symlink($target, $base . '/ecare-private/escape.png')) {
        $GLOBALS['symlinked'] = true;
    }

    $GLOBALS['uploads_basedir'] = $base;
}

// ---------------------------------------------------------------------------
// Runner
// ---------------------------------------------------------------------------
$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    if ($got === $want) { $pass++; printf("  PASS  %s\n", $label); }
    else { $fail++; printf("  FAIL  %s\n          got:  %s\n          want: %s\n", $label, var_export($got, true), var_export($want, true)); }
}
function post_types_left() {
    $out = array();
    foreach ($GLOBALS['posts'] as $id => $p) { $out[] = $p['post_type']; }
    sort($out);
    return $out;
}
function sql_matching($needle) {
    $n = 0;
    foreach ($GLOBALS['wpdb']->queries as $q) {
        if (stripos($q, $needle) !== false) { $n++; }
    }
    return $n;
}

// ===========================================================================
echo "\n=== A. default site: the option was never set ===\n";
// ===========================================================================
reset_world(false);
$base = $GLOBALS['uploads_basedir'];
require $UNINSTALL;

check('every post survives', count($GLOBALS['posts']), 10);
check('no table is dropped', sql_matching('DROP TABLE'), 0);
check('no SQL is run at all', count($GLOBALS['wpdb']->queries), 0);
check('no term row is deleted', count($GLOBALS['wpdb']->deletes), 0);
check('no option is deleted', $GLOBALS['deleted_options'], array());
check('the price defaults are still readable', get_option('ecare_default_daily_12_price'), 1700);
check('the private directory is still there', is_dir($base . '/ecare-private'), true);
check('the prescription is still on disk', is_file($base . '/ecare-private/2026/09/abc-prescription.pdf'), true);
check('get_posts was never called', count($GLOBALS['get_posts_calls']), 0);
rm_tree($base);
@unlink($base . '/../ecare-outside.txt');

// ===========================================================================
echo "\n=== B. the option is set to something else ===\n";
// ===========================================================================
reset_world(false);
$GLOBALS['options']['ecare_delete_data_on_uninstall'] = '1';   // truthy, but not 'yes'
$base = $GLOBALS['uploads_basedir'];
require $UNINSTALL;
check("only the exact string 'yes' opts in", count($GLOBALS['posts']), 10);
check('still no SQL', count($GLOBALS['wpdb']->queries), 0);
rm_tree($base);

// ===========================================================================
echo "\n=== C. opted in: the plugin's own data goes ===\n";
// ===========================================================================
reset_world(true);
$base = $GLOBALS['uploads_basedir'];
require $UNINSTALL;

check('bookings table dropped',  sql_matching('DROP TABLE IF EXISTS wp_ecare_bookings'), 1);
check('locations table dropped', sql_matching('DROP TABLE IF EXISTS wp_ecare_locations'), 1);

check('only other people\'s posts remain', post_types_left(), array('attachment', 'page', 'product', 'shop_order'));
check('a trashed provider does not outlive the plugin', isset($GLOBALS['posts'][3]), false);
check('an auto-draft lab test goes too', isset($GLOBALS['posts'][4]), false);

// ===========================================================================
echo "\n=== D. caregiver types ===\n";
// ===========================================================================
$tables = array();
foreach ($GLOBALS['wpdb']->deletes as $d) { $tables[] = $d[0]; }
check('all four term tables are cleaned', array_values(array_unique($tables)), array(
    'wp_term_relationships', 'wp_term_taxonomy', 'wp_termmeta', 'wp_terms',
));
check('once per term', count($GLOBALS['wpdb']->deletes), 8);
check('the lookup is scoped to our taxonomy', sql_matching("taxonomy = 'ecare_caregiver_type'"), 1);
check('relationships go by term_taxonomy_id', $GLOBALS['wpdb']->deletes[0][1], array('term_taxonomy_id' => 31));
check('term meta goes by term_id', $GLOBALS['wpdb']->deletes[2][1], array('term_id' => 21));
check('ids are cast, never interpolated as strings', $GLOBALS['wpdb']->deletes[4][1], array('term_taxonomy_id' => 32));

// ===========================================================================
echo "\n=== E. options and transients ===\n";
// ===========================================================================
sort($GLOBALS['deleted_options']);
check('the eight plugin options plus the flag', $GLOBALS['deleted_options'], array(
    'ecare_activation_date',
    'ecare_default_daily_12_price',
    'ecare_default_daily_24_price',
    'ecare_default_monthly_12_price',
    'ecare_default_monthly_24_price',
    'ecare_default_physio_premium_price',
    'ecare_default_physio_regular_price',
    'ecare_delete_data_on_uninstall',
    'ecare_rewrite_version',
));
check("WooCommerce's option is untouched", get_option('woocommerce_currency'), 'BDT');
check('the opt-in flag clears itself', array_key_exists('ecare_delete_data_on_uninstall', $GLOBALS['options']), false);
// esc_like() escapes the underscores, so the pattern matches that literal
// prefix and not, say, '_transientXecareYulZ'. The escaped form is what must
// reach the database.
check('rate-limit transients are swept', sql_matching('LIKE \'\_transient\_ecare\_ul\_%\''), 1);
check('and their timeout rows with them', sql_matching('LIKE \'\_transient\_timeout\_ecare\_ul\_%\''), 1);
check('one statement, not a wildcard delete', sql_matching('DELETE FROM wp_options'), 1);

// ===========================================================================
echo "\n=== F. the private document store ===\n";
// ===========================================================================
check('the private tree is gone', file_exists($base . '/ecare-private'), false);
check('an ordinary media file is untouched', is_file($base . '/2026/09/logo.png'), true);
check('nothing above the uploads dir is touched', is_file($base . '/../ecare-outside.txt'), true);
check('the uploads dir itself survives', is_dir($base), true);
if ($GLOBALS['symlinked']) {
    check('a symlink out of the tree was unlinked, not followed', is_file($base . '/2026/09/logo.png'), true);
} else {
    echo "  SKIP  symlink test (this filesystem would not create one)\n";
}
rm_tree($base);
@unlink($base . '/../ecare-outside.txt');

// ===========================================================================
echo "\n=== G. a large catalogue, and a post that will not die ===\n";
// ===========================================================================
reset_world(true, 250);
$base = $GLOBALS['uploads_basedir'];
require $UNINSTALL;
check('all 251 lab tests are deleted', count(array_filter($GLOBALS['posts'], function ($p) {
    return $p['post_type'] === 'ecare_lab_test';
})), 0);
$biggest = 0;
foreach ($GLOBALS['get_posts_calls'] as $c) { $biggest = max($biggest, $c['returned']); }
check('read in batches, never all at once', $biggest, 100);
rm_tree($base);
@unlink($base . '/../ecare-outside.txt');

reset_world(true);
$base = $GLOBALS['uploads_basedir'];
$GLOBALS['undeletable'][1] = true;   // a provider something else is holding on to
$before = microtime(true);
require $UNINSTALL;
$elapsed = microtime(true) - $before;
check('a post that refuses to delete does not loop for ever', $elapsed < 5.0, true);
check('it is left in place rather than lost', isset($GLOBALS['posts'][1]), true);
check('the rest of the sweep still ran', isset($GLOBALS['posts'][5]), false);
rm_tree($base);
@unlink($base . '/../ecare-outside.txt');

printf("\n---------------------------------------\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
