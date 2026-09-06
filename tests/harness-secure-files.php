<?php
/**
 * Stubbed harness for ECare_Secure_Files.
 * Replaces the WordPress functions the class touches, then exercises the
 * security-critical paths: containment, upload redirection, naming, and the
 * authorisation branches of serve().
 */

$SANDBOX = sys_get_temp_dir() . '/ecare-harness-' . getmypid();
@mkdir($SANDBOX . '/uploads', 0777, true);

define('ABSPATH', $SANDBOX . '/wp/');

// ---------------- WP stubs ----------------
$GLOBALS['ecare_test'] = array(
    'logged_in'   => true,
    'user_id'     => 5,
    'is_admin'    => false,
    'nonce_ok'    => true,
    'posts'       => array(),
    'post_meta'   => array(),
    'booking_own' => array(),
    'filters'     => array(),
    'died'        => null,
);

class WP_Error {
    public $code, $message;
    public function __construct($c = '', $m = '') { $this->code = $c; $this->message = $m; }
    public function get_error_message() { return $this->message; }
}
class ECare_Died extends Exception { public $status; }

function is_wp_error($t) { return $t instanceof WP_Error; }
function __($s, $d = null) { return $s; }
function esc_html__($s, $d = null) { return $s; }
function esc_html($s) { return htmlspecialchars($s, ENT_QUOTES); }
function absint($n) { return abs((int) $n); }
function sanitize_key($k) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($k)); }
function wp_unslash($v) { return is_string($v) ? stripslashes($v) : $v; }
function untrailingslashit($s) { return rtrim($s, '/\\'); }
function trailingslashit($s) { return untrailingslashit($s) . '/'; }
function wp_normalize_path($p) { return str_replace('\\', '/', $p); }
function wp_mkdir_p($d) { return is_dir($d) || @mkdir($d, 0777, true); }
/**
 * Close enough to WordPress's sanitize_file_name() for these tests. The point
 * that matters: it strips a fixed list of special characters and collapses
 * whitespace, but it does NOT strip non-ASCII. The stub that used to live here
 * replaced every non-ASCII byte with a dash, which quietly made the Bengali
 * filenames this site actually receives impossible to test - and hid #27.
 */
function sanitize_file_name($n) {
    $special = array('?', '[', ']', '/', '\\', '=', '<', '>', ':', ';', ',', "'", '"', '&',
                     '$', '#', '*', '(', ')', '|', '~', '`', '!', '{', '}', '%', '+', chr(0));
    $n = str_replace($special, '', $n);
    $n = preg_replace('/[\r\n\t -]+/', '-', $n);
    return trim($n, '.-_');
}
function wp_generate_password($len, $special = true, $extra = false) {
    return substr(bin2hex(random_bytes($len)), 0, $len);
}
function add_filter($h, $cb, $p = 10, $a = 1) { $GLOBALS['ecare_test']['filters'][$h][] = $cb; }
function remove_filter($h, $cb, $p = 10) {
    if (!isset($GLOBALS['ecare_test']['filters'][$h])) return;
    $GLOBALS['ecare_test']['filters'][$h] = array_values(array_filter(
        $GLOBALS['ecare_test']['filters'][$h],
        function ($x) use ($cb) { return $x !== $cb; }
    ));
}
function add_action($h, $cb, $p = 10, $a = 1) { $GLOBALS['ecare_test']['filters'][$h][] = $cb; }
function has_filter($h) { return !empty($GLOBALS['ecare_test']['filters'][$h]); }

function wp_upload_dir() {
    $base = $GLOBALS['SANDBOX'] . '/uploads';
    return array(
        'basedir' => $base,
        'baseurl' => 'https://example.test/wp-content/uploads',
        'subdir'  => '',
        'path'    => $base,
        'url'     => 'https://example.test/wp-content/uploads',
    );
}
function admin_url($p = '') { return 'https://example.test/wp-admin/' . $p; }
function add_query_arg($args, $url) {
    return $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($args);
}
function wp_nonce_url($url, $action) {
    return add_query_arg(array('_wpnonce' => substr(md5($action), 0, 10)), $url);
}
function wp_verify_nonce($n, $action) {
    if (!$GLOBALS['ecare_test']['nonce_ok']) return false;
    return $n === substr(md5($action), 0, 10) ? 1 : false;
}
function is_user_logged_in() { return $GLOBALS['ecare_test']['logged_in']; }
function auth_redirect() { $e = new ECare_Died('auth_redirect'); $e->status = 401; throw $e; }
function current_user_can($c) { return $GLOBALS['ecare_test']['is_admin'] && $c === 'manage_options'; }
function get_current_user_id() { return $GLOBALS['ecare_test']['logged_in'] ? $GLOBALS['ecare_test']['user_id'] : 0; }
function get_post($id) { return isset($GLOBALS['ecare_test']['posts'][$id]) ? $GLOBALS['ecare_test']['posts'][$id] : null; }
function get_post_meta($id, $k, $single = false) {
    return isset($GLOBALS['ecare_test']['post_meta'][$id][$k]) ? $GLOBALS['ecare_test']['post_meta'][$id][$k] : '';
}
function wp_check_filetype($f) {
    $map = array('pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg',
                 'svg' => 'image/svg+xml', 'html' => 'text/html', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
    return array('ext' => $ext, 'type' => isset($map[$ext]) ? $map[$ext] : false);
}
function nocache_headers() {}
function wp_die($msg, $status = 500) { $e = new ECare_Died($msg); $e->status = $status; throw $e; }

define('HOUR_IN_SECONDS', 3600);
$GLOBALS['ecare_transients'] = array();
$GLOBALS['ecare_filter_ret'] = array();

function apply_filters($hook, $value) {
    return array_key_exists($hook, $GLOBALS['ecare_filter_ret']) ? $GLOBALS['ecare_filter_ret'][$hook] : $value;
}
function wp_max_upload_size() {
    return isset($GLOBALS['ecare_max_upload']) ? $GLOBALS['ecare_max_upload'] : 67108864; // 64 MB
}
function size_format($b, $dp = 0) {
    foreach (array('GB' => 1073741824, 'MB' => 1048576, 'KB' => 1024) as $u => $n) {
        if ($b >= $n) return round($b / $n, $dp) . ' ' . $u;
    }
    return $b . ' B';
}
function get_transient($k) { return isset($GLOBALS['ecare_transients'][$k]) ? $GLOBALS['ecare_transients'][$k] : false; }
function set_transient($k, $v, $t = 0) { $GLOBALS['ecare_transients'][$k] = $v; return true; }

/** Faithful enough copy of WP's extension + real-content check. */
function wp_check_filetype_and_ext($file, $filename, $mimes = null) {
    $miss = array('ext' => false, 'type' => false, 'proper_filename' => false);
    $ext  = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $type = false; $matched = false;

    if ($mimes) {
        foreach ($mimes as $pattern => $mime) {
            if (preg_match('!^(' . $pattern . ')$!i', $ext)) { $type = $mime; $matched = $ext; break; }
        }
    }
    if (!$type) return $miss;

    // WP verifies image contents with getimagesize()
    if (strpos($type, 'image/') === 0) {
        $info = @getimagesize($file);
        if (!$info || empty($info['mime']) || $info['mime'] !== $type) return $miss;
    }
    // and non-images with finfo; magic bytes are enough here
    if ($type === 'application/pdf') {
        if (@file_get_contents($file, false, null, 0, 5) !== '%PDF-') return $miss;
    }
    return array('ext' => $matched, 'type' => $type, 'proper_filename' => false);
}

/** Minimal $wpdb for the booking-ownership branch. */
class ECare_Test_WPDB {
    public $prefix = 'wp_';
    public function prepare($q, ...$a) { return vsprintf(str_replace('%d', '%d', $q), $a); }
    public function get_var($q) {
        if (preg_match('/id = (\d+)/', $q, $m)) {
            $id = (int) $m[1];
            return isset($GLOBALS['ecare_test']['booking_own'][$id]) ? $GLOBALS['ecare_test']['booking_own'][$id] : null;
        }
        return null;
    }
}
$GLOBALS['wpdb'] = new ECare_Test_WPDB();

/** wp_handle_upload stub: honours the upload_dir + prefilter hooks like WP does. */
function wp_handle_upload($file, $overrides = array()) {
    foreach ($GLOBALS['ecare_test']['filters']['wp_handle_upload_prefilter'] ?? array() as $cb) {
        $file = call_user_func($cb, $file);
    }
    $dirs = wp_upload_dir();
    foreach ($GLOBALS['ecare_test']['filters']['upload_dir'] ?? array() as $cb) {
        $dirs = call_user_func($cb, $dirs);
    }
    // WP rejects anything outside the allowed mime list.
    $mimes = isset($overrides['mimes']) ? $overrides['mimes'] : null;
    $ck = wp_check_filetype_and_ext($file['tmp_name'], $file['name'], $mimes);
    if (empty($ck['type'])) {
        return array('error' => 'Sorry, you are not allowed to upload this file type.');
    }
    wp_mkdir_p($dirs['path']);
    $dest = $dirs['path'] . '/' . $file['name'];
    copy($file['tmp_name'], $dest);
    return array('file' => $dest, 'url' => $dirs['url'] . '/' . $file['name'], 'type' => 'application/pdf');
}

$target = $argv[1] ?? (__DIR__ . '/../includes/class-ecare-secure-files.php');
require_once $target;

/**
 * is_uploaded_file() is always false outside a real POST, so the harness
 * overrides just that seam and leaves every other code path untouched.
 */
class ECare_TF extends ECare_Secure_Files {
    protected static function is_real_upload($path) { return is_file($path); }
}

function ecare_reset_rate() {
    $r = new ReflectionProperty('ECare_Secure_Files', 'rate_result');
    $r->setAccessible(true);
    $r->setValue(null, null);
    $GLOBALS['ecare_transients'] = array();
}

/** Write a fixture and register it as $_FILES[$field]. */
function fixture($field, $name, $bytes, $err = UPLOAD_ERR_OK, $size = null) {
    $t = tempnam(sys_get_temp_dir(), 'fx');
    file_put_contents($t, $bytes);
    $_FILES[$field] = array('name' => $name, 'tmp_name' => $t, 'error' => $err,
                            'size' => $size === null ? strlen($bytes) : $size);
    return $t;
}
$PNG = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

// ---------------- subprocess allow-path mode ----------------
if (getenv('SERVE_MODE')) {
    // Rebuild the same sandbox file this process's parent created is not possible
    // (fresh pid = fresh sandbox), so recreate one file and serve it.
    ECare_Secure_Files::ensure_dir();
    $body = "%PDF-1.4\nPRESCRIPTION BODY\n%%EOF\n";
    $t = tempnam(sys_get_temp_dir(), 'up'); file_put_contents($t, $body);
    $_FILES['booking_file'] = array('name' => 'p.pdf', 'tmp_name' => $t, 'error' => 0, 'size' => strlen($body));
    $r = ECare_TF::upload('booking_file');

    $mode = getenv('SERVE_MODE');
    $GLOBALS['ecare_test']['logged_in'] = true;
    if ($mode === 'admin') {
        $GLOBALS['ecare_test']['is_admin'] = true;
    } else {
        $GLOBALS['ecare_test']['booking_own'][(int) getenv('SERVE_ID')] = 5;
    }
    $_GET = array('ref' => $r, 'ctx' => 'booking', 'id' => (int) getenv('SERVE_ID'),
                  '_wpnonce' => substr(md5(ECare_Secure_Files::ACTION . '_' . $r), 0, 10));
    ECare_Secure_Files::serve();
    exit;
}

// ---------------- test runner ----------------
$pass = 0; $fail = 0;
function check($label, $got, $want) {
    global $pass, $fail;
    $ok = ($got === $want);
    if ($ok) { $pass++; printf("  PASS  %s\n", $label); }
    else { $fail++; printf("  FAIL  %s\n          got:  %s\n          want: %s\n", $label, var_export($got, true), var_export($want, true)); }
}

echo "\n=== A. ensure_dir() writes the access guards ===\n";
ECare_Secure_Files::ensure_dir();
$dir = ECare_Secure_Files::base_dir();
check('private directory created', is_dir($dir), true);
check('.htaccess present', file_exists("$dir/.htaccess"), true);
check('.htaccess denies (Apache 2.4)', strpos(file_get_contents("$dir/.htaccess"), 'Require all denied') !== false, true);
check('.htaccess denies (Apache 2.2)', strpos(file_get_contents("$dir/.htaccess"), 'Deny from all') !== false, true);
check('index.php guard present', file_exists("$dir/index.php"), true);

echo "\n=== B. upload() lands in private storage with an unguessable name ===\n";
$BODY = "%PDF-1.4\nPRESCRIPTION BODY\n%%EOF\n";
$tmp = tempnam(sys_get_temp_dir(), 'up'); file_put_contents($tmp, $BODY);
$_FILES['booking_file'] = array('name' => 'my prescription.pdf', 'tmp_name' => $tmp, 'error' => 0, 'size' => strlen($BODY));
$ref = ECare_TF::upload('booking_file');
check('returns a string reference, not WP_Error', is_string($ref), true);
check('reference is relative (no scheme)', (bool) preg_match('#^ecare-private/\d{4}/\d{2}/#', (string) $ref), true);
check('reference is not a URL', ECare_Secure_Files::is_legacy($ref), false);
check('filename carries 32 random chars', (bool) preg_match('#/[0-9a-f]{32}-my-prescription\.pdf$#', (string) $ref), true);
check('file really exists on disk', is_file(ECare_Secure_Files::reference_to_path($ref)), true);
check('stored outside the year/month media tree', strpos($ref, 'ecare-private/') === 0, true);
check('upload_dir filter removed afterwards', has_filter('upload_dir'), false);
check('prefilter removed afterwards', has_filter('wp_handle_upload_prefilter'), false);

echo "\n=== C. executable upload still refused ===\n";
$tmp2 = tempnam(sys_get_temp_dir(), 'up'); file_put_contents($tmp2, '<?php evil();');
$_FILES['booking_file'] = array('name' => 'shell.php', 'tmp_name' => $tmp2, 'error' => 0, 'size' => 13);
check('.php upload rejected', is_wp_error(ECare_TF::upload('booking_file')), true);

echo "\n=== D. reference_to_path() containment ===\n";
$evil = array(
    'traversal to wp-config'      => 'ecare-private/../../../wp-config.php',
    'plain traversal'             => '../../wp-config.php',
    'absolute unix path'          => '/etc/passwd',
    'absolute windows path'       => 'C:/xampp/htdocs/wp-config.php',
    'backslash root'              => '\\\\etc\\\\passwd',
    'remote url'                  => 'https://evil.test/x.pdf',
    'file scheme'                 => 'file:///etc/passwd',
    'null byte'                   => "ecare-private/ok.pdf\0.png",
    'sibling uploads dir'         => '2026/09/public-file.png',
    'prefix look-alike'           => 'ecare-private-evil/x.pdf',
    'empty'                       => '',
);
foreach ($evil as $label => $bad) {
    check("rejected: $label", ECare_Secure_Files::reference_to_path($bad), false);
}
check('accepted: legitimate reference', is_string(ECare_Secure_Files::reference_to_path($ref)), true);

echo "\n=== E. reference classification ===\n";
check('old absolute URL flagged legacy', ECare_Secure_Files::is_legacy('https://tech.meditaj.com/wp-content/uploads/2026/09/x.png'), true);
check('private ref not flagged legacy', ECare_Secure_Files::is_legacy($ref), false);
check('private ref is a reference', ECare_Secure_Files::is_reference($ref), true);
check('old URL is not a reference', ECare_Secure_Files::is_reference('https://tech.meditaj.com/a.png'), false);

echo "\n=== F. get_view_url() ===\n";
$link = ECare_Secure_Files::get_view_url($ref, ECare_Secure_Files::CTX_BOOKING, 21);
check('link goes through admin-post.php', strpos($link, '/wp-admin/admin-post.php') !== false, true);
check('link carries a nonce', strpos($link, '_wpnonce=') !== false, true);
check('empty value yields no link', ECare_Secure_Files::get_view_url('', 'booking', 1), '');
check('legacy URL passed through unchanged', ECare_Secure_Files::get_view_url('https://x.test/a.png', 'provider', 1), 'https://x.test/a.png');

echo "\n=== G. serve() authorisation ===\n";
$nonce = substr(md5(ECare_Secure_Files::ACTION . '_' . $ref), 0, 10);
function attempt($ref, $ctx, $id, $nonce) {
    $_GET = array('ref' => $ref, 'ctx' => $ctx, 'id' => $id, '_wpnonce' => $nonce);
    try { ECare_Secure_Files::serve(); return 'SERVED'; }
    catch (ECare_Died $e) { return $e->status; }
}
$GLOBALS['ecare_test']['booking_own'][21] = 5;   // booking 21 belongs to user 5
$GLOBALS['ecare_test']['booking_own'][22] = 99;  // booking 22 belongs to someone else

$GLOBALS['ecare_test']['logged_in'] = false;
check('logged-out visitor blocked', attempt($ref, 'booking', 21, $nonce), 401);

$GLOBALS['ecare_test']['logged_in'] = true;
check('bad nonce blocked', attempt($ref, 'booking', 21, 'deadbeef00'), 403);
check("another user's booking blocked (IDOR)", attempt($ref, 'booking', 22, $nonce), 403);
check('unknown context blocked', attempt($ref, 'nonsense', 21, $nonce), 403);
// A rejected path and a missing file deliberately look identical (404), so the
// response never confirms whether a probed path exists. What matters: not SERVED.
$trav = attempt('ecare-private/../../../wp-config.php', 'booking', 21,
    substr(md5(ECare_Secure_Files::ACTION . '_ecare-private/../../../wp-config.php'), 0, 10));
check('traversal blocked even when authorised (never served)', $trav !== 'SERVED', true);
check('traversal answers 404, leaking nothing', $trav, 404);

$GLOBALS['ecare_test']['posts'][931] = (object) array('ID' => 931, 'post_type' => 'ecare_caregiver', 'post_author' => 77);
check("another provider's document blocked (IDOR)", attempt($ref, 'provider', 931, $nonce), 403);
$GLOBALS['ecare_test']['posts'][934] = (object) array('ID' => 934, 'post_type' => 'ecare_caregiver', 'post_author' => 5);
// The allow-path calls exit(), so it is exercised in a subprocess (see SERVE_MODE).

echo "\n=== H. allow-path actually streams the file (subprocess) ===\n";
$self = __FILE__; $cls = $target;
foreach (array(
    'owner of the booking' => array('booking', 21),
    'site administrator'   => array('admin',   21),
) as $who => $cfg) {
    $cmd = sprintf('SERVE_MODE=%s SERVE_REF=%s SERVE_ID=%d php %s %s 2>&1',
        escapeshellarg($cfg[0]), escapeshellarg($ref), $cfg[1], escapeshellarg($self), escapeshellarg($cls));
    $out = shell_exec($cmd);
    check("$who receives the file body", strpos((string) $out, 'PRESCRIPTION BODY') !== false, true);
}

echo "\n=== I. #5 - size cap, type whitelist, throttle ===\n";
$err = function ($r) { return is_wp_error($r) ? $r->code : 'ACCEPTED'; };
$DOC = ECare_Secure_Files::KIND_DOCUMENT;
$IMG = ECare_Secure_Files::KIND_IMAGE;

// -- caps --
check('document cap is 8 MB', ECare_Secure_Files::max_bytes($DOC), 8388608);
check('image cap is 5 MB',    ECare_Secure_Files::max_bytes($IMG), 5242880);
$GLOBALS['ecare_max_upload'] = 2097152; // host allows only 2 MB
check('cap clamped down to what the host accepts', ECare_Secure_Files::max_bytes($DOC), 2097152);
unset($GLOBALS['ecare_max_upload']);
$GLOBALS['ecare_filter_ret']['ecare_upload_max_bytes'] = 1048576;
check('cap is filterable', ECare_Secure_Files::max_bytes($DOC), 1048576);
unset($GLOBALS['ecare_filter_ret']['ecare_upload_max_bytes']);

// -- whitelist --
check('PDF allowed for documents', in_array('application/pdf', array_values(ECare_Secure_Files::allowed_mimes($DOC)), true), true);
check('PDF NOT allowed for photos', in_array('application/pdf', array_values(ECare_Secure_Files::allowed_mimes($IMG)), true), false);
check('extension label reads sensibly', ECare_Secure_Files::allowed_extensions_label($DOC), 'JPG, PNG, WEBP, PDF');

// -- validation --
ecare_reset_rate();
fixture('f', 'scan.pdf', $PDF);
check('valid PDF accepted', $err(ECare_TF::validate_upload('f', $DOC)), 'ACCEPTED');
fixture('f', 'photo.png', $PNG);
check('valid PNG accepted', $err(ECare_TF::validate_upload('f', $DOC)), 'ACCEPTED');
fixture('f', 'photo.png', $PNG);
check('PNG accepted as a profile photo too', $err(ECare_TF::validate_upload('f', $IMG)), 'ACCEPTED');
fixture('f', 'scan.pdf', $PDF);
check('PDF refused as a profile photo', $err(ECare_TF::validate_upload('f', $IMG)), 'ecare_bad_type');

fixture('f', 'big.pdf', $PDF, UPLOAD_ERR_OK, 9 * 1024 * 1024);
check('oversized file refused', $err(ECare_TF::validate_upload('f', $DOC)), 'ecare_too_large');
fixture('f', 'empty.pdf', '');
check('empty file refused', $err(ECare_TF::validate_upload('f', $DOC)), 'ecare_empty_file');
fixture('f', 'x.pdf', $PDF, UPLOAD_ERR_INI_SIZE);
$r = ECare_TF::validate_upload('f', $DOC);
check('php.ini overflow refused', $err($r), 'ecare_too_large');
check('php.ini overflow explains the limit', strpos($r->get_error_message(), 'MB') !== false, true);
fixture('f', 'x.pdf', $PDF, UPLOAD_ERR_PARTIAL);
check('interrupted upload refused', $err(ECare_TF::validate_upload('f', $DOC)), 'ecare_partial');

foreach (array('shell.php', 'run.phtml', 'sheet.xlsx', 'note.txt', 'logo.svg', 'page.html', 'archive.zip') as $bad) {
    fixture('f', $bad, 'whatever');
    check("extension refused: $bad", $err(ECare_TF::validate_upload('f', $DOC)), 'ecare_bad_type');
}

// content, not just the extension
fixture('f', 'innocent.png', '<?php system($_GET["c"]); ?>');
check('PHP source renamed to .png refused', $err(ECare_TF::validate_upload('f', $DOC)), 'ecare_bad_type');
fixture('f', 'innocent.pdf', '<html><script>alert(1)</script></html>');
check('HTML renamed to .pdf refused', $err(ECare_TF::validate_upload('f', $DOC)), 'ecare_bad_type');
fixture('f', 'innocent.jpg', $PNG);
check('PNG bytes renamed to .jpg refused', $err(ECare_TF::validate_upload('f', $DOC)), 'ecare_bad_type');

// -- end to end through upload() --
ecare_reset_rate();
fixture('booking_file', 'shell.php', '<?php ?>');
check('upload() refuses a .php', $err(ECare_TF::upload('booking_file', $DOC)), 'ecare_bad_type');
fixture('booking_file', 'huge.pdf', $PDF, UPLOAD_ERR_OK, 20 * 1024 * 1024);
check('upload() refuses an oversized file', $err(ECare_TF::upload('booking_file', $DOC)), 'ecare_too_large');
fixture('booking_file', 'good.pdf', $PDF);
check('upload() still accepts a good file', is_string(ECare_TF::upload('booking_file', $DOC)), true);

// -- throttle --
ecare_reset_rate();
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
check('first upload passes the throttle', ECare_Secure_Files::check_rate_limit(), true);
ECare_Secure_Files::check_rate_limit();
ECare_Secure_Files::check_rate_limit();
$key = 'ecare_ul_' . md5('203.0.113.9');
check('one request is charged once, not per file', (int) get_transient($key), 1);

ecare_reset_rate();
$GLOBALS['ecare_transients'][$key] = 20;
check('21st upload in the hour refused', $err(ECare_Secure_Files::check_rate_limit()), 'ecare_rate_limited');
ecare_reset_rate();
$GLOBALS['ecare_transients'][$key] = 20;
$GLOBALS['ecare_filter_ret']['ecare_upload_rate_limit'] = 100;
check('throttle is filterable (clinic on one IP)', ECare_Secure_Files::check_rate_limit(), true);
unset($GLOBALS['ecare_filter_ret']['ecare_upload_rate_limit']);

echo "\n=== J. #27 - multi-byte filenames survive the length cap ===\n";
ecare_reset_rate();

// The real filename from booking #12 on tech.meditaj.com. Bengali runs three
// bytes to the character, so this is 23 characters in 61 bytes. The old code
// did substr($base, 0, 40), which lands inside a character; $wpdb then refuses
// the reference and returns false with no error, leaving the file on disk and
// the booking with no document attached.
$BN = 'তারিখঃ-১৮-জুলাই-২০২৬-ইং';
check('fixture is the case that bit us: over 40 bytes, under 40 characters',
      strlen($BN) > 40 && preg_match_all('/./u', $BN) < 40, true);
check('a naive byte cut of it is not valid UTF-8',
      (bool) preg_match('//u', substr($BN, 0, 40)), false);

fixture('booking_file', $BN . '.pdf', $BODY);
$ref_bn = ECare_TF::upload('booking_file');
check('the upload succeeds', is_string($ref_bn), true);
check('the stored reference is valid UTF-8', (bool) preg_match('//u', (string) $ref_bn), true);
check('it still carries the 32-character token',
      (bool) preg_match('#/[0-9a-f]{32}-#', (string) $ref_bn), true);
check('the extension survives', substr((string) $ref_bn, -4), '.pdf');
check('the file is really on disk under that name',
      is_file((string) ECare_Secure_Files::reference_to_path($ref_bn)), true);

$readable = preg_replace('#^.*/[0-9a-f]{32}-#', '', (string) $ref_bn);
$readable = preg_replace('/\.pdf$/', '', $readable);
check('the readable part stays within the 40-byte cap', strlen($readable) <= 40, true);
check('the readable part is itself valid UTF-8', (bool) preg_match('//u', $readable), true);
check('it is a whole-character prefix of the original name', strpos($BN, $readable) === 0, true);
check('and it did not collapse to nothing', $readable !== '', true);

// A pure-ASCII name must still use the full budget - the fix must not shorten
// names that were never a problem.
ecare_reset_rate();
fixture('booking_file', str_repeat('a', 100) . '.pdf', $BODY);
$ref_ascii = ECare_TF::upload('booking_file');
check('an ASCII name still uses all 40 bytes',
      preg_replace('#^.*/[0-9a-f]{32}-#', '', (string) $ref_ascii),
      str_repeat('a', 40) . '.pdf');

// A short multi-byte name is left alone entirely.
ecare_reset_rate();
fixture('booking_file', 'রিপোর্ট.pdf', $BODY);
$ref_short = ECare_TF::upload('booking_file');
check('a short Bengali name is not truncated at all',
      preg_replace('#^.*/[0-9a-f]{32}-#', '', (string) $ref_short), 'রিপোর্ট.pdf');

// Nothing readable left after sanitising.
ecare_reset_rate();
fixture('booking_file', '---.pdf', $BODY);
$ref_doc = ECare_TF::upload('booking_file');
check('a name that sanitises away falls back to "document"',
      (bool) preg_match('#/[0-9a-f]{32}-document\.pdf$#', (string) $ref_doc), true);

// Extensions are ASCII. A non-ASCII one is not smuggled into the filename.
ecare_reset_rate();
fixture('booking_file', 'report.পিডিএফ', $BODY);
$ref_ext = ECare_TF::upload('booking_file');
check('a non-ASCII extension is refused rather than stored', is_string($ref_ext), false);

printf("\n---------------------------------------\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
