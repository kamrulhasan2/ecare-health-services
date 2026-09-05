<?php
/**
 * Private storage + authenticated delivery for sensitive E-Care uploads.
 *
 * Patient prescriptions and provider identity documents must never be readable
 * from a public URL, and must never appear in the Media Library (attachments are
 * listable through the public /wp-json/wp/v2/media endpoint).
 *
 * Files handled here live under wp-content/uploads/ecare-private/ and are served
 * only through admin-post.php after a capability check.
 */

defined('ABSPATH') || exit;

class ECare_Secure_Files {

    /** Directory name under the uploads basedir. */
    const DIR = 'ecare-private';

    /** admin-post.php action used for delivery. */
    const ACTION = 'ecare_view_secure_file';

    /** Contexts a stored reference can belong to. */
    const CTX_PROVIDER = 'provider';
    const CTX_BOOKING  = 'booking';

    /** Upload kinds, which decide the size cap and the accepted types. */
    const KIND_DOCUMENT = 'document';
    const KIND_IMAGE    = 'image';

    /** Ceilings before the server's own php.ini limit is applied. */
    const MAX_DOCUMENT_BYTES = 8388608; // 8 MB
    const MAX_IMAGE_BYTES    = 5242880; // 5 MB

    /** Uploads allowed from one IP per hour. */
    const RATE_LIMIT = 20;

    /** Set while our upload_dir filter is active. */
    private static $redirect_upload = false;

    /** Memoised throttle result, so one request counts once however often it asks. */
    private static $rate_result = null;

    public static function init() {
        add_action('admin_post_' . self::ACTION, array(__CLASS__, 'serve'));
        // No nopriv counterpart on purpose: these files always require a login.
    }

    // ---- Validation ----

    /**
     * Largest upload we accept, never larger than what this server can receive.
     *
     * Clamping to wp_max_upload_size() keeps the number we advertise in the UI
     * honest: promising 8 MB on a host capped at 2 MB just produces a confusing
     * failure halfway through the upload.
     */
    public static function max_bytes($kind = self::KIND_DOCUMENT) {
        $ceiling = ($kind === self::KIND_IMAGE) ? self::MAX_IMAGE_BYTES : self::MAX_DOCUMENT_BYTES;

        /**
         * Filter the size cap for E-Care uploads.
         *
         * @param int    $ceiling Bytes.
         * @param string $kind    KIND_DOCUMENT or KIND_IMAGE.
         */
        $ceiling = (int) apply_filters('ecare_upload_max_bytes', $ceiling, $kind);

        $server = (int) wp_max_upload_size();
        if ($server > 0 && $server < $ceiling) {
            $ceiling = $server;
        }

        return max(0, $ceiling);
    }

    /**
     * Accepted types, as extension => mime.
     *
     * Deliberately narrow. Anything not listed here is refused even when
     * WordPress would otherwise allow it, because these forms only ever need a
     * photo of a document.
     */
    public static function allowed_mimes($kind = self::KIND_DOCUMENT) {
        $image = array(
            'jpg|jpeg|jpe' => 'image/jpeg',
            'png'          => 'image/png',
            'webp'         => 'image/webp',
        );

        $mimes = ($kind === self::KIND_IMAGE)
            ? $image
            : array_merge($image, array('pdf' => 'application/pdf'));

        /**
         * Filter the accepted upload types.
         *
         * @param array  $mimes extension pattern => mime type.
         * @param string $kind  KIND_DOCUMENT or KIND_IMAGE.
         */
        return apply_filters('ecare_allowed_upload_mimes', $mimes, $kind);
    }

    /**
     * A human list of accepted extensions, for UI hints.
     */
    public static function allowed_extensions_label($kind = self::KIND_DOCUMENT) {
        $out = array();
        foreach (array_keys(self::allowed_mimes($kind)) as $pattern) {
            $parts = explode('|', $pattern);
            $out[] = strtoupper($parts[0]);
        }
        return implode(', ', $out);
    }

    /**
     * Validate one $_FILES entry before anything is written or created.
     *
     * Call this BEFORE creating users, posts or booking rows, so a rejected file
     * does not leave half-finished records behind.
     *
     * @return true|WP_Error
     */
    public static function validate_upload($field, $kind = self::KIND_DOCUMENT) {
        if (empty($_FILES[$field]) || !is_array($_FILES[$field])) {
            return new WP_Error('ecare_no_file', __('No file was uploaded.', 'ecare-health-services'));
        }

        $file = $_FILES[$field];
        $max  = self::max_bytes($kind);

        // PHP-level errors first: these have nothing to do with our own rules.
        $error = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
        if ($error !== UPLOAD_ERR_OK) {
            switch ($error) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    return new WP_Error('ecare_too_large', sprintf(
                        /* translators: %s: human readable size, e.g. "8 MB" */
                        __('That file is too large. The limit is %s.', 'ecare-health-services'),
                        size_format($max)
                    ));
                case UPLOAD_ERR_PARTIAL:
                    return new WP_Error('ecare_partial', __('The upload was interrupted. Please try again.', 'ecare-health-services'));
                case UPLOAD_ERR_NO_FILE:
                    return new WP_Error('ecare_no_file', __('No file was uploaded.', 'ecare-health-services'));
                default:
                    return new WP_Error('ecare_upload_error', __('The file could not be uploaded. Please try again.', 'ecare-health-services'));
            }
        }

        if (empty($file['tmp_name']) || !static::is_real_upload($file['tmp_name'])) {
            return new WP_Error('ecare_bad_upload', __('The file could not be uploaded. Please try again.', 'ecare-health-services'));
        }

        $size = isset($file['size']) ? (int) $file['size'] : 0;
        if ($size <= 0) {
            return new WP_Error('ecare_empty_file', __('That file is empty.', 'ecare-health-services'));
        }
        if ($size > $max) {
            return new WP_Error('ecare_too_large', sprintf(
                __('That file is too large. The limit is %s.', 'ecare-health-services'),
                size_format($max)
            ));
        }

        $allowed = self::allowed_mimes($kind);

        // Checks the real contents, not just the extension, so a script renamed
        // to .jpg does not get through.
        $checked = wp_check_filetype_and_ext($file['tmp_name'], $file['name'], $allowed);
        $type    = !empty($checked['type']) ? $checked['type'] : false;

        if (!$type || !in_array($type, array_values($allowed), true)) {
            return new WP_Error('ecare_bad_type', sprintf(
                /* translators: %s: list of extensions, e.g. "JPG, PNG, WEBP, PDF" */
                __('That file type is not accepted. Please upload one of: %s.', 'ecare-health-services'),
                self::allowed_extensions_label($kind)
            ));
        }

        // An image must actually decode as one.
        if (strpos($type, 'image/') === 0 && !@getimagesize($file['tmp_name'])) {
            return new WP_Error('ecare_bad_image', __('That image could not be read. Please upload a different file.', 'ecare-health-services'));
        }

        return true;
    }

    /**
     * Guards against a forged $_FILES entry pointing at a file already on disk.
     *
     * Split out as its own overridable method purely so the test harness can
     * exercise validate_upload(): is_uploaded_file() is always false outside a
     * real HTTP POST, which would otherwise make every path untestable.
     */
    protected static function is_real_upload($path) {
        return is_uploaded_file($path);
    }

    /**
     * Crude per-IP throttle, so a size cap alone cannot be turned into disk
     * exhaustion by repeating the upload.
     *
     * Keyed on IP because these endpoints are open to logged-out visitors. Shared
     * connections (carrier NAT, a clinic behind one address) share the budget, so
     * the default is deliberately loose and filterable.
     *
     * @return true|WP_Error
     */
    public static function check_rate_limit() {
        // One HTTP request may carry two files and ask twice; charge it once.
        if (self::$rate_result !== null) {
            return self::$rate_result;
        }

        $limit = (int) apply_filters('ecare_upload_rate_limit', self::RATE_LIMIT);
        if ($limit <= 0) {
            return self::$rate_result = true;
        }

        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        if ($ip === '') {
            return self::$rate_result = true;
        }

        $key   = 'ecare_ul_' . md5($ip);
        $count = (int) get_transient($key);

        if ($count >= $limit) {
            return self::$rate_result = new WP_Error('ecare_rate_limited', __('Too many uploads from this connection. Please wait an hour and try again.', 'ecare-health-services'));
        }

        set_transient($key, $count + 1, HOUR_IN_SECONDS);

        return self::$rate_result = true;
    }

    // ---- Storage ----

    /**
     * Absolute path of the private directory (no trailing slash).
     */
    public static function base_dir() {
        $uploads = wp_upload_dir();
        return untrailingslashit($uploads['basedir']) . '/' . self::DIR;
    }

    /**
     * Create the private directory and drop the access guards into it.
     *
     * The randomised filenames are the primary defence (a guard file only helps
     * on servers that read it), so this is belt and braces, not the whole belt.
     */
    public static function ensure_dir() {
        $dir = self::base_dir();

        if (!wp_mkdir_p($dir)) {
            return false;
        }

        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            $rules  = "# E-Care Health Services - deny all direct access.\n";
            $rules .= "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n";
            $rules .= "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n";
            file_put_contents($htaccess, $rules);
        }

        $index = $dir . '/index.php';
        if (!file_exists($index)) {
            file_put_contents($index, "<?php\n// Silence is golden.\n");
        }

        $webconfig = $dir . '/web.config';
        if (!file_exists($webconfig)) {
            $xml  = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
            $xml .= "<configuration><system.webServer><authorization>\n";
            $xml .= "    <deny users=\"*\" />\n";
            $xml .= "</authorization></system.webServer></configuration>\n";
            file_put_contents($webconfig, $xml);
        }

        return true;
    }

    /**
     * Move an uploaded file into private storage.
     *
     * @param string $field Key in $_FILES.
     * @param string $kind  KIND_DOCUMENT or KIND_IMAGE.
     * @return string|WP_Error Reference to persist (relative to the uploads basedir), or error.
     */
    public static function upload($field, $kind = self::KIND_DOCUMENT) {
        $valid = self::validate_upload($field, $kind);
        if (is_wp_error($valid)) {
            return $valid;
        }

        $allowed = self::check_rate_limit();
        if (is_wp_error($allowed)) {
            return $allowed;
        }

        if (!function_exists('wp_handle_upload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        if (!self::ensure_dir()) {
            return new WP_Error('ecare_dir_failed', __('Secure storage is unavailable. Please try again later.', 'ecare-health-services'));
        }

        self::$redirect_upload = true;
        add_filter('upload_dir', array(__CLASS__, 'filter_upload_dir'));
        add_filter('wp_handle_upload_prefilter', array(__CLASS__, 'filter_upload_name'));

        // test_form is false because these come from AJAX, not a rendered form.
        // The mimes override makes wp_handle_upload enforce the same narrow list
        // validate_upload() just checked, so both layers agree.
        $result = wp_handle_upload($_FILES[$field], array(
            'test_form' => false,
            'mimes'     => self::allowed_mimes($kind),
        ));

        remove_filter('wp_handle_upload_prefilter', array(__CLASS__, 'filter_upload_name'));
        remove_filter('upload_dir', array(__CLASS__, 'filter_upload_dir'));
        self::$redirect_upload = false;

        if (!is_array($result) || isset($result['error'])) {
            $message = is_array($result) && isset($result['error'])
                ? $result['error']
                : __('Upload failed.', 'ecare-health-services');
            return new WP_Error('ecare_upload_failed', $message);
        }

        @chmod($result['file'], 0644);

        $reference = self::path_to_reference($result['file']);
        if (!$reference) {
            @unlink($result['file']);
            return new WP_Error('ecare_upload_failed', __('Upload failed.', 'ecare-health-services'));
        }

        // Deliberately NOT stored: $result['url']. Nothing should ever hold a
        // public URL to one of these files.
        return $reference;
    }

    /**
     * Point wp_handle_upload at the private directory instead of the year/month tree.
     */
    public static function filter_upload_dir($dirs) {
        if (!self::$redirect_upload) {
            return $dirs;
        }

        $sub = '/' . self::DIR . date('/Y/m');

        $dirs['subdir'] = $sub;
        $dirs['path']   = $dirs['basedir'] . $sub;
        $dirs['url']    = $dirs['baseurl'] . $sub;

        return $dirs;
    }

    /**
     * Give the file an unguessable name, so the file stays unreachable even on a
     * server that ignores .htaccess.
     */
    public static function filter_upload_name($file) {
        if (!self::$redirect_upload) {
            return $file;
        }

        $name = isset($file['name']) ? $file['name'] : '';
        $ext  = pathinfo($name, PATHINFO_EXTENSION);
        $base = pathinfo($name, PATHINFO_FILENAME);
        $base = sanitize_file_name($base);

        if (strlen($base) > 40) {
            $base = substr($base, 0, 40);
        }
        if ($base === '') {
            $base = 'document';
        }

        $file['name'] = wp_generate_password(32, false, false) . '-' . $base . ($ext ? '.' . strtolower($ext) : '');

        return $file;
    }

    // ---- References ----

    /**
     * Convert an absolute path inside the private directory to a stored reference.
     */
    private static function path_to_reference($absolute) {
        $uploads = wp_upload_dir();
        $basedir = untrailingslashit($uploads['basedir']);

        $absolute = wp_normalize_path($absolute);
        $basedir  = wp_normalize_path($basedir);

        if (strpos($absolute, $basedir . '/' . self::DIR . '/') !== 0) {
            return false;
        }

        return ltrim(substr($absolute, strlen($basedir)), '/');
    }

    /**
     * True when the stored value is a legacy public URL rather than a private
     * reference. Records created before this change still hold absolute URLs;
     * they keep working until they are migrated.
     */
    public static function is_legacy($value) {
        return is_string($value) && preg_match('#^https?://#i', $value) === 1;
    }

    /**
     * True when the value looks like a reference this class can serve.
     */
    public static function is_reference($value) {
        return is_string($value)
            && $value !== ''
            && !self::is_legacy($value)
            && strpos($value, self::DIR . '/') === 0;
    }

    /**
     * Resolve a reference to an absolute path, refusing anything that escapes the
     * private directory.
     *
     * @return string|false
     */
    public static function reference_to_path($reference) {
        if (!is_string($reference) || $reference === '') {
            return false;
        }

        // Reject traversal, absolute paths, URLs and null bytes before touching disk.
        if (strpos($reference, "\0") !== false
            || strpos($reference, '..') !== false
            || strpos($reference, '://') !== false
            || $reference[0] === '/'
            || $reference[0] === '\\'
            || preg_match('#^[a-zA-Z]:#', $reference)) {
            return false;
        }

        if (strpos($reference, self::DIR . '/') !== 0) {
            return false;
        }

        $uploads  = wp_upload_dir();
        $basedir  = wp_normalize_path(untrailingslashit($uploads['basedir']));
        $base     = realpath($basedir . '/' . self::DIR);
        $resolved = realpath($basedir . '/' . $reference);

        if (!$base || !$resolved || !is_file($resolved)) {
            return false;
        }

        // Containment check on the real, symlink-resolved paths.
        $base     = wp_normalize_path($base);
        $resolved = wp_normalize_path($resolved);

        if (strpos($resolved, untrailingslashit($base) . '/') !== 0) {
            return false;
        }

        return $resolved;
    }

    // ---- Delivery ----

    /**
     * Build a signed, capability-gated view link for a stored value.
     *
     * Legacy absolute URLs are returned unchanged so existing records keep
     * rendering until they are migrated.
     *
     * @return string Empty string when there is nothing to link to.
     */
    public static function get_view_url($value, $context, $object_id) {
        if (empty($value)) {
            return '';
        }

        if (self::is_legacy($value)) {
            return $value;
        }

        if (!self::is_reference($value)) {
            return '';
        }

        $url = add_query_arg(
            array(
                'action' => self::ACTION,
                'ctx'    => $context,
                'id'     => (int) $object_id,
                'ref'    => rawurlencode($value),
            ),
            admin_url('admin-post.php')
        );

        return wp_nonce_url($url, self::ACTION . '_' . $value);
    }

    /**
     * Stream a private file to a viewer who is allowed to see it.
     */
    public static function serve() {
        $reference = isset($_GET['ref']) ? wp_unslash($_GET['ref']) : '';
        $context   = isset($_GET['ctx']) ? sanitize_key($_GET['ctx']) : '';
        $object_id = isset($_GET['id']) ? absint($_GET['id']) : 0;

        if (!is_user_logged_in()) {
            auth_redirect();
        }

        $nonce = isset($_GET['_wpnonce']) ? $_GET['_wpnonce'] : '';
        if (!wp_verify_nonce($nonce, self::ACTION . '_' . $reference)) {
            wp_die(esc_html__('This document link has expired. Reload the page and try again.', 'ecare-health-services'), 403);
        }

        if (!self::can_view($context, $object_id)) {
            wp_die(esc_html__('You are not allowed to view this document.', 'ecare-health-services'), 403);
        }

        $path = self::reference_to_path($reference);
        if (!$path) {
            wp_die(esc_html__('Document not found.', 'ecare-health-services'), 404);
        }

        self::stream($path);
    }

    /**
     * Authorisation for a document request. The nonce proves the request was not
     * forged; this decides whether the person is entitled to the file at all.
     */
    private static function can_view($context, $object_id) {
        if (current_user_can('manage_options')) {
            return true;
        }

        $user_id = get_current_user_id();
        if (!$user_id) {
            return false;
        }

        if ($context === self::CTX_PROVIDER) {
            $post = get_post($object_id);
            if (!$post || !in_array($post->post_type, array('ecare_caregiver', 'ecare_ambulance'), true)) {
                return false;
            }
            // The provider who submitted the document may review their own copy.
            if ((int) $post->post_author === $user_id) {
                return true;
            }
            return (int) get_post_meta($object_id, '_user_id', true) === $user_id;
        }

        if ($context === self::CTX_BOOKING) {
            global $wpdb;
            $table = $wpdb->prefix . 'ecare_bookings';
            $owner = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$table} WHERE id = %d", $object_id));
            return $owner && (int) $owner === $user_id;
        }

        return false;
    }

    /**
     * Send the file. Only images and PDFs render inline; everything else is
     * forced to download, so an uploaded HTML or SVG file can never execute on
     * this origin.
     */
    private static function stream($path) {
        $filetype = wp_check_filetype(basename($path));
        $mime     = $filetype['type'] ? $filetype['type'] : 'application/octet-stream';

        $inline_ok = (strpos($mime, 'image/') === 0 && $mime !== 'image/svg+xml')
            || $mime === 'application/pdf';

        $disposition = $inline_ok ? 'inline' : 'attachment';

        nocache_headers();
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: ' . $disposition . '; filename="' . basename($path) . '"');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow', true);
        header('Cache-Control: private, no-store, max-age=0');
        header('Referrer-Policy: no-referrer');

        // Clear anything a plugin may have buffered, so the body is only the file.
        while (ob_get_level()) {
            ob_end_clean();
        }

        readfile($path);
        exit;
    }
}
