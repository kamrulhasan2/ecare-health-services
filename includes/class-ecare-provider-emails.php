<?php
defined('ABSPATH') || exit;

/**
 * Emails to caregivers and ambulance providers.
 *
 *   registered          provider: "we received it, status Pending"; admin: "new registration"
 *   approved / rejected provider: the decision (a rejection can carry a reason)
 *   booking             provider: a booking for them was approved (caregiver)
 *                       or approved / assigned / dispatched (ambulance)
 *
 * Every email goes to the address given on the registration form (_email).
 * Each one can be switched off in E-Care Health > Email Settings, and every
 * send is written to the provider's "Emails" box on its edit screen, where an
 * admin can preview them and send the current one again.
 *
 * Status changes are caught where they are stored (post meta), so the list
 * screens' ✓ / ✕ buttons and the edit screen's Status field both send.
 * Booking changes come through the ecare_booking_status_changed action fired
 * by ECare_Ajax::update_booking_status() and ECare_WooCommerce (payment).
 */
class ECare_Provider_Emails {

    const OPTION      = 'ecare_provider_emails';
    const LOG_META    = '_ecare_mail_log';
    const SENT_META   = '_ecare_status_mailed';     // the status whose email last went out
    const BOOKED_META = '_ecare_booking_mailed';    // one row per booking already announced
    const REASON_META = '_ecare_reject_reason';
    const NONCE       = 'ecare_provider_mail';

    /** Post type => the meta key holding its approval status. */
    const TYPES = array('ecare_caregiver' => '_provider_status', 'ecare_ambulance' => '_ambulance_status');

    /** Booking statuses that mean "go ahead" for the provider. */
    const GO = array('caregiver' => array('approved'), 'ambulance' => array('approved', 'assigned', 'dispatched'));

    const KINDS = array('pending', 'approved', 'rejected', 'admin');

    public static function init() {
        add_action('ecare_provider_registered', array(__CLASS__, 'on_registered'));
        add_action('added_post_meta', array(__CLASS__, 'on_meta'), 10, 4);
        add_action('updated_post_meta', array(__CLASS__, 'on_meta'), 10, 4);
        add_action('save_post', array(__CLASS__, 'flush'), 999);
        add_action('shutdown', array(__CLASS__, 'flush'));
        add_action('ecare_booking_status_changed', array(__CLASS__, 'on_booking'), 10, 3);

        add_action('admin_menu', array(__CLASS__, 'menu'), 30);   // after ECare_Admin (20) has made the E-Care Health menu
        add_action('admin_init', array(__CLASS__, 'register_setting'));
        add_action('add_meta_boxes', array(__CLASS__, 'add_box'));
        add_action('save_post', array(__CLASS__, 'save_reason'), 5);   // before the Status field is saved (priority 10)
        add_action('admin_post_ecare_provider_mail_preview', array(__CLASS__, 'handle_preview'));
        add_action('admin_post_ecare_provider_mail_send', array(__CLASS__, 'handle_send'));
    }

    // =======================================================================
    // Settings
    // =======================================================================

    public static function defaults() {
        return array(
            'reg_pending' => 1,
            'reg_admin'   => 1,
            'approved'    => 1,
            'rejected'    => 1,
            'booking'     => 1,
            'admin_to'    => '',   // empty: the site's admin email
            'hotline'     => '',   // empty: the Lab hotline, if set
        );
    }

    public static function settings() {
        $saved = get_option(self::OPTION, array());
        return array_merge(self::defaults(), is_array($saved) ? $saved : array());
    }

    /** Is this email switched on? (kind: pending | admin | approved | rejected | booking) */
    public static function enabled($kind) {
        $map = array('pending' => 'reg_pending', 'admin' => 'reg_admin', 'approved' => 'approved', 'rejected' => 'rejected', 'booking' => 'booking');
        return isset($map[$kind]) && !empty(self::settings()[$map[$kind]]);
    }

    /** A comma / space / line separated list, valid addresses only, each once (first spelling kept). */
    public static function clean_list($raw) {
        $out = array();
        foreach (preg_split('/[\s,;]+/', (string) $raw) as $e) {
            $e = sanitize_email($e);
            if ($e !== '' && is_email($e) && !isset($out[strtolower($e)])) {
                $out[strtolower($e)] = $e;
            }
        }
        return array_values($out);
    }

    public static function sanitize($in) {
        $in  = is_array($in) ? $in : array();
        $out = array();
        foreach (array('reg_pending', 'reg_admin', 'approved', 'rejected', 'booking') as $k) {
            $out[$k] = empty($in[$k]) ? 0 : 1;
        }
        $out['admin_to'] = implode(', ', self::clean_list($in['admin_to'] ?? ''));
        $out['hotline']  = substr(preg_replace('/[^0-9+\-\s()]/', '', (string) ($in['hotline'] ?? '')), 0, 30);
        $out['hotline']  = trim($out['hotline']);
        return $out;
    }

    public static function admin_recipients() {
        $list = self::clean_list(self::settings()['admin_to']);
        if (!$list) {
            $list = self::clean_list((string) get_option('admin_email'));
        }
        return $list;
    }

    public static function hotline() {
        $h = trim((string) self::settings()['hotline']);
        if ($h === '' && class_exists('ECare_Lab_Settings')) {
            $h = trim((string) ECare_Lab_Settings::get('hotline'));
        }
        return $h;
    }

    // =======================================================================
    // Who
    // =======================================================================

    /**
     * The provider behind a post, or null.
     *
     * @return array{id:int, type:string, label:string, name:string, email:string, status:string, reason:string, phone:string}|null
     */
    public static function provider($post_id) {
        $post_id = (int) $post_id;
        $type    = get_post_type($post_id);
        if (!isset(self::TYPES[$type])) {
            return null;
        }
        $amb   = $type === 'ecare_ambulance';
        $name  = $amb ? trim((string) get_post_meta($post_id, '_driver_name', true)) : '';
        $email = sanitize_email((string) get_post_meta($post_id, '_email', true));
        return array(
            'id'     => $post_id,
            'type'   => $amb ? 'ambulance' : 'caregiver',
            'label'  => $amb ? __('ambulance provider', 'ecare-health-services') : __('caregiver', 'ecare-health-services'),
            'name'   => $name !== '' ? $name : (string) get_the_title($post_id),
            'email'  => is_email($email) ? $email : '',
            'status' => (string) get_post_meta($post_id, self::TYPES[$type], true) ?: 'pending',
            'reason' => trim((string) get_post_meta($post_id, self::REASON_META, true)),
            'phone'  => (string) get_post_meta($post_id, '_phone', true),
        );
    }

    // =======================================================================
    // Triggers
    // =======================================================================

    /** A registration form was saved. */
    public static function on_registered($post_id) {
        self::send($post_id, 'pending');
        self::send($post_id, 'admin');
    }

    /** Decisions waiting to be sent: post id => status. */
    private static $queue = array();

    /**
     * The approval status was written - from a list screen button or the edit
     * screen. Sent a moment later (flush()), not here: the edit screen saves
     * Status before Email, so on a provider created in wp-admin the address
     * is not there yet at this point.
     */
    public static function on_meta($meta_id, $post_id, $key, $value) {
        $type = get_post_type($post_id);
        if (!isset(self::TYPES[$type]) || self::TYPES[$type] !== $key) {
            return;
        }
        $value = (string) $value;
        if (!in_array($value, array('approved', 'rejected'), true)) {
            return;   // a queued decision set back since is dropped by flush()
        }
        self::$queue[(int) $post_id] = $value;
    }

    /** Send the queued decisions: after the post is fully saved, or at the end of the request. */
    public static function flush() {
        $queue       = self::$queue;
        self::$queue = array();
        foreach ($queue as $post_id => $value) {
            $type = get_post_type($post_id);
            if (!isset(self::TYPES[$type]) || (string) get_post_meta($post_id, self::TYPES[$type], true) !== $value) {
                continue;   // changed again since
            }
            if ((string) get_post_meta($post_id, self::SENT_META, true) === $value) {
                continue;   // this decision was already announced
            }
            self::send($post_id, $value);
        }
    }

    /** A booking's status changed. */
    public static function on_booking($booking_id, $new, $old) {
        $row = self::booking($booking_id);
        if (!$row || !isset(self::GO[$row->booking_type])) {
            return;
        }
        if (!in_array((string) $new, self::GO[$row->booking_type], true)) {
            return;
        }
        self::send_booking($row);
    }

    public static function booking($id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ecare_bookings WHERE id = %d", (int) $id));
        return $row ?: null;
    }

    public static function booking_mailed($provider_id, $booking_id) {
        return in_array((string) (int) $booking_id, array_map('strval', (array) get_post_meta((int) $provider_id, self::BOOKED_META, false)), true);
    }

    // =======================================================================
    // Sending
    // =======================================================================

    /**
     * Send one registration / decision email.
     *
     * @return string sent | disabled | no_address | missing | failed
     */
    public static function send($post_id, $kind, $again = false) {
        $p = self::provider($post_id);
        if (!$p || !in_array($kind, self::KINDS, true)) {
            return 'missing';
        }
        if (!$again && !self::enabled($kind)) {
            return 'disabled';
        }
        $to = $kind === 'admin' ? self::admin_recipients() : ($p['email'] !== '' ? array($p['email']) : array());
        if (!$to) {
            self::log($post_id, $kind, '', 'no_address');
            return 'no_address';
        }
        $mail = self::build($p, $kind);
        $ok   = self::deliver(implode(', ', $to), $mail['subject'], $mail['heading'], $mail['html']);
        self::log($post_id, $kind, implode(', ', $to), $ok ? 'sent' : 'failed');
        if ($ok && in_array($kind, array('approved', 'rejected', 'pending'), true)) {
            update_post_meta($post_id, self::SENT_META, $kind);
        }
        return $ok ? 'sent' : 'failed';
    }

    /**
     * Tell the provider about a booking, once per booking.
     *
     * @return string sent | already | disabled | no_provider | no_address | failed
     */
    public static function send_booking($row, $again = false) {
        $pid = (int) $row->provider_id;
        $p   = $pid ? self::provider($pid) : null;
        if (!$p || $p['type'] !== $row->booking_type) {
            return 'no_provider';   // an ambulance request no vehicle was matched to, say
        }
        if (!$again && self::booking_mailed($pid, $row->id)) {
            return 'already';
        }
        if (!$again && !self::enabled('booking')) {
            return 'disabled';
        }
        if ($p['email'] === '') {
            self::log($pid, 'booking', '', 'no_address', (int) $row->id);
            return 'no_address';
        }
        $mail = self::build_booking($p, $row);
        $ok   = self::deliver($p['email'], $mail['subject'], $mail['heading'], $mail['html']);
        self::log($pid, 'booking', $p['email'], $ok ? 'sent' : 'failed', (int) $row->id);
        if ($ok && !self::booking_mailed($pid, $row->id)) {
            add_post_meta($pid, self::BOOKED_META, (int) $row->id);
        }
        return $ok ? 'sent' : 'failed';
    }

    public static function log($post_id, $kind, $to, $result, $booking = 0) {
        $log   = get_post_meta((int) $post_id, self::LOG_META, true);
        $log   = is_array($log) ? $log : array();
        $log[] = array('t' => time(), 'kind' => $kind, 'to' => $to, 'result' => $result, 'booking' => (int) $booking);
        update_post_meta((int) $post_id, self::LOG_META, array_slice($log, -30));
    }

    private static function deliver($to, $subject, $heading, $html) {
        if (function_exists('WC') && WC() && method_exists(WC(), 'mailer')) {
            $mailer = WC()->mailer();
            return (bool) $mailer->send($to, $subject, $mailer->wrap_message($heading, $html), "Content-Type: text/html\r\n");
        }
        return (bool) wp_mail($to, $subject, '<h2>' . esc_html($heading) . '</h2>' . $html, array('Content-Type: text/html; charset=UTF-8'));
    }

    // =======================================================================
    // Words
    // =======================================================================

    private static function site() {
        return wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
    }

    private static function rows($pairs) {
        $out = '';
        foreach ($pairs as $k => $v) {
            if ($v === '' || $v === null) {
                continue;
            }
            $out .= '<tr><th style="text-align:left;padding:6px 12px 6px 0;vertical-align:top;white-space:nowrap;">' . esc_html($k) . '</th>'
                . '<td style="text-align:left;padding:6px 0;">' . $v . '</td></tr>';
        }
        return '<table cellspacing="0" cellpadding="0" style="width:100%;margin:0 0 18px;">' . $out . '</table>';
    }

    private static function call_us() {
        $h = self::hotline();
        return $h !== '' ? '<p>' . esc_html(sprintf(__('Questions? Call us on %s.', 'ecare-health-services'), $h)) . '</p>' : '';
    }

    private static function hi($name) {
        $name = trim((string) $name);
        return '<p>' . esc_html(sprintf(__('Hi %s,', 'ecare-health-services'), $name !== '' ? $name : __('there', 'ecare-health-services'))) . '</p>';
    }

    /**
     * Subject, heading and HTML for a registration / decision email.
     *
     * @return array{subject:string, heading:string, html:string}
     */
    public static function build($p, $kind) {
        $d    = 'ecare-health-services';
        $site = self::site();

        if ($kind === 'admin') {
            $what = $p['type'] === 'ambulance' ? __('ambulance provider', $d) : __('caregiver', $d);
            $html  = '<p>' . esc_html(sprintf(__('A new %1$s has registered on %2$s and is waiting for approval.', $d), $what, $site)) . '</p>';
            $html .= self::rows(array(
                __('Name', $d)  => esc_html($p['name']),
                __('Email', $d) => esc_html($p['email']),
                __('Phone', $d) => esc_html($p['phone']),
                __('Type', $d)  => esc_html($p['type'] === 'ambulance' ? (string) get_post_meta($p['id'], '_ambulance_type', true) : (string) get_post_meta($p['id'], '_provider_type', true)),
            ));
            // Built by hand: get_edit_post_link() is empty for the visitor who just registered.
            $html .= '<p><a href="' . esc_url(admin_url('post.php?post=' . (int) $p['id'] . '&action=edit')) . '">' . esc_html__('Review the registration', $d) . '</a></p>';
            /* translators: 1: caregiver / ambulance provider, 2: name */
            return array('subject' => sprintf(__('New %1$s registration: %2$s', $d), $what, $p['name']), 'heading' => __('New provider registration', $d), 'html' => $html);
        }

        if ($kind === 'approved') {
            $html  = self::hi($p['name']);
            $html .= '<p>' . esc_html(sprintf(__('Good news: your registration as a %1$s with %2$s has been approved.', $d), $p['label'], $site)) . '</p>';
            $html .= self::rows(array(__('Status', $d) => '<strong style="color:#15803d;">' . esc_html__('Approved', $d) . '</strong>'));
            $html .= '<p>' . esc_html__('You can now receive bookings. Each time a booking for you is confirmed, we will email you the patient\'s details at this address.', $d) . '</p>';
            $html .= self::call_us();
            /* translators: %s: site name */
            return array('subject' => sprintf(__('Your registration is approved — %s', $d), $site), 'heading' => __('Registration approved', $d), 'html' => $html);
        }

        if ($kind === 'rejected') {
            $html  = self::hi($p['name']);
            $html .= '<p>' . esc_html(sprintf(__('Thank you for your interest in working with %1$s as a %2$s. After reviewing your registration, we are not able to approve it at this time.', $d), $site, $p['label'])) . '</p>';
            $html .= self::rows(array(
                __('Status', $d) => '<strong style="color:#b91c1c;">' . esc_html__('Not approved', $d) . '</strong>',
                __('Reason', $d) => $p['reason'] !== '' ? nl2br(esc_html($p['reason'])) : '',
            ));
            $html .= '<p>' . esc_html__('If you think this is a mistake, or your details have changed, please get in touch with us.', $d) . '</p>';
            $html .= self::call_us();
            /* translators: %s: site name */
            return array('subject' => sprintf(__('Update on your registration — %s', $d), $site), 'heading' => __('Registration not approved', $d), 'html' => $html);
        }

        // pending
        $html  = self::hi($p['name']);
        $html .= '<p>' . esc_html(sprintf(__('Thank you for registering as a %1$s with %2$s. We have received your registration.', $d), $p['label'], $site)) . '</p>';
        $html .= self::rows(array(__('Status', $d) => '<strong style="color:#b45309;">' . esc_html__('Pending review', $d) . '</strong>'));
        $html .= '<p>' . esc_html__('Our team will check your details and documents. We will email you as soon as your registration is approved.', $d) . '</p>';
        $html .= self::call_us();
        /* translators: %s: site name */
        return array('subject' => sprintf(__('We received your registration — %s', $d), $site), 'heading' => __('Registration received', $d), 'html' => $html);
    }

    /** The booking email for the provider. */
    public static function build_booking($p, $row) {
        $d    = 'ecare-health-services';
        $site = self::site();
        $tel  = function ($n) { $n = (string) $n; return $n === '' ? '' : '<a href="tel:' . esc_attr(preg_replace('/[^0-9+]/', '', $n)) . '">' . esc_html($n) . '</a>'; };
        $date = function ($v, $with_time) {
            $ts = strtotime((string) $v);
            return $ts ? wp_date($with_time ? 'l, j F Y, g:i A' : 'l, j F Y', $ts, new DateTimeZone('UTC')) : (string) $v;
        };

        if ($row->booking_type === 'ambulance') {
            $user    = $row->user_id ? get_userdata((int) $row->user_id) : null;
            $when    = $row->schedule_time ? $date($row->schedule_time, true) : '';
            $html    = self::hi($p['name']);
            $html   .= '<p>' . esc_html(sprintf(__('An ambulance booking for you has been confirmed on %s. Please be ready at the pickup time below.', $d), $site)) . '</p>';
            $html   .= self::rows(array(
                __('Booking', $d)     => '#' . (int) $row->id,
                __('Pickup time', $d) => '<strong>' . esc_html($when) . '</strong>',
                __('Ambulance', $d)   => esc_html((string) $row->ambulance_type),
                __('Priority', $d)    => $row->priority_level === 'Emergency' ? '<strong style="color:#b91c1c;">' . esc_html__('Emergency', $d) . '</strong>' : esc_html((string) $row->priority_level),
                __('Patient', $d)     => esc_html($user ? (string) $user->display_name : ''),
                __('Phone', $d)       => $tel($row->contact_phone),
                __('Pickup', $d)      => nl2br(esc_html((string) $row->pickup_address)),
                __('Destination', $d) => nl2br(esc_html((string) $row->destination)),
                __('Note', $d)        => nl2br(esc_html((string) $row->notes)),
            ));
            $heading = sprintf(__('New ambulance booking #%d', $d), $row->id);
            /* translators: 1: booking number, 2: pickup time */
            $subject = $when !== '' ? sprintf(__('New ambulance booking #%1$d — %2$s', $d), $row->id, $when) : $heading;
        } else {
            $when  = $row->required_date ? $date($row->required_date, false) : '';
            $html  = self::hi($p['name']);
            $html .= '<p>' . esc_html(sprintf(__('A caregiver booking for you has been confirmed on %s. Please contact the patient\'s family before the start date.', $d), $site)) . '</p>';
            $html .= self::rows(array(
                __('Booking', $d)       => '#' . (int) $row->id,
                __('Start date', $d)    => '<strong>' . esc_html($when) . '</strong>',
                __('Package', $d)       => esc_html((string) $row->package_type),
                __('Patient', $d)       => esc_html(trim((string) $row->patient_name . ($row->patient_type ? ' (' . $row->patient_type . ')' : ''))),
                __('Phone', $d)         => $tel($row->contact_phone),
                __('Address', $d)       => nl2br(esc_html((string) $row->address)),
                __('Condition', $d)     => nl2br(esc_html((string) $row->disease)),
                __('Diaper change', $d) => !empty($row->diaper_change) ? esc_html__('Yes', $d) : '',
            ));
            $heading = sprintf(__('New caregiver booking #%d', $d), $row->id);
            /* translators: 1: booking number, 2: start date */
            $subject = $when !== '' ? sprintf(__('New caregiver booking #%1$d — %2$s', $d), $row->id, $when) : $heading;
        }
        $html .= self::call_us();
        $html .= '<p>' . esc_html(sprintf(__('Booking #%1$d · %2$s', $d), $row->id, $site)) . '</p>';
        return array('subject' => $subject, 'heading' => $heading, 'html' => $html);
    }

    // =======================================================================
    // Admin: settings page
    // =======================================================================

    public static function menu() {
        add_submenu_page('ecare-dashboard', __('Email Settings', 'ecare-health-services'), __('Email Settings', 'ecare-health-services'), 'manage_options', 'ecare-email-settings', array(__CLASS__, 'render_settings'));
    }

    public static function register_setting() {
        register_setting('ecare_provider_emails', self::OPTION, array('type' => 'array', 'sanitize_callback' => array(__CLASS__, 'sanitize'), 'default' => self::defaults()));
    }

    public static function render_settings() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $d = 'ecare-health-services';
        $s = self::settings();
        $boxes = array(
            'reg_pending' => array(__('Registration received', $d), __('To the caregiver / ambulance provider as soon as the registration form is sent: their status is Pending.', $d)),
            'reg_admin'   => array(__('New registration (admin)', $d), __('To the admin addresses below, so a new provider is reviewed quickly.', $d)),
            'approved'    => array(__('Registration approved', $d), __('To the provider when their status is set to Approved (✓ button or the edit screen).', $d)),
            'rejected'    => array(__('Registration rejected', $d), __('To the provider when their status is set to Rejected. The "Reason" written on the provider\'s edit screen is included.', $d)),
            'booking'     => array(__('New booking (to the provider)', $d), __('To the booked caregiver when a booking is approved (by payment or by an admin), or to the ambulance provider when a booking is approved, assigned or dispatched. Once per booking.', $d)),
        );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('E-Care Email Settings', $d); ?></h1>
            <p><?php esc_html_e('Emails to caregivers and ambulance providers. They go to the email address given on the registration form. Lab emails are set in Lab > Settings.', $d); ?></p>
            <form method="post" action="options.php">
                <?php settings_fields('ecare_provider_emails'); ?>
                <table class="form-table" role="presentation">
                    <?php foreach ($boxes as $k => $b): ?>
                        <tr>
                            <th scope="row"><?php echo esc_html($b[0]); ?></th>
                            <td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION . '[' . $k . ']'); ?>" value="1" <?php checked(!empty($s[$k])); ?> /> <?php esc_html_e('Send', $d); ?></label>
                                <p class="description"><?php echo esc_html($b[1]); ?></p></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr>
                        <th scope="row"><label for="ecare-pe-admin"><?php esc_html_e('Admin addresses', $d); ?></label></th>
                        <td><input type="text" class="regular-text" id="ecare-pe-admin" name="<?php echo esc_attr(self::OPTION); ?>[admin_to]" value="<?php echo esc_attr($s['admin_to']); ?>" placeholder="<?php echo esc_attr((string) get_option('admin_email')); ?>" />
                            <p class="description"><?php esc_html_e('Who hears about new registrations. Separate several with commas. Empty: the site admin email.', $d); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ecare-pe-hotline"><?php esc_html_e('Contact number in emails', $d); ?></label></th>
                        <td><input type="text" class="regular-text" id="ecare-pe-hotline" name="<?php echo esc_attr(self::OPTION); ?>[hotline]" value="<?php echo esc_attr($s['hotline']); ?>" placeholder="<?php echo esc_attr(class_exists('ECare_Lab_Settings') ? (string) ECare_Lab_Settings::get('hotline') : ''); ?>" />
                            <p class="description"><?php esc_html_e('Shown as "Questions? Call us on …". Empty: the Lab hotline.', $d); ?></p></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    // =======================================================================
    // Admin: the "Emails" box on a provider's edit screen
    // =======================================================================

    public static function add_box() {
        foreach (array_keys(self::TYPES) as $type) {
            add_meta_box('ecare-provider-emails', __('Emails', 'ecare-health-services'), array(__CLASS__, 'render_box'), $type, 'side');
        }
    }

    public static function kind_label($kind) {
        $d = 'ecare-health-services';
        $l = array(
            'pending'  => __('Registration received', $d),
            'admin'    => __('New registration (admin)', $d),
            'approved' => __('Approved', $d),
            'rejected' => __('Rejected', $d),
            'booking'  => __('Booking', $d),
        );
        return $l[$kind] ?? $kind;
    }

    public static function result_label($r) {
        $d = 'ecare-health-services';
        $l = array(
            'sent'       => __('sent', $d),
            'failed'     => __('could not be sent (the mail server refused it)', $d),
            'no_address' => __('not sent: no email address', $d),
        );
        return $l[$r] ?? $r;
    }

    public static function preview_url($post_id, $kind) {
        return wp_nonce_url(admin_url('admin-post.php?action=ecare_provider_mail_preview&id=' . (int) $post_id . '&kind=' . rawurlencode($kind)), self::NONCE . '_' . (int) $post_id);
    }

    public static function send_url($post_id, $kind) {
        return wp_nonce_url(admin_url('admin-post.php?action=ecare_provider_mail_send&id=' . (int) $post_id . '&kind=' . rawurlencode($kind)), self::NONCE . '_' . (int) $post_id);
    }

    public static function render_box($post) {
        $d = 'ecare-health-services';
        $p = self::provider($post->ID);
        if (!$p) {
            return;
        }
        wp_nonce_field(self::NONCE . '_reason', '_ecare_pe_reason_nonce');
        if (isset($_GET['ecare_mail'])) {
            $r = sanitize_key(wp_unslash($_GET['ecare_mail']));
            echo '<div class="notice inline notice-' . ($r === 'sent' ? 'success' : 'warning') . '"><p>' . esc_html(sprintf(__('Email %s.', $d), self::result_label($r))) . '</p></div>';
        }
        echo '<p><strong>' . esc_html__('To:', $d) . '</strong> ' . ($p['email'] !== '' ? esc_html($p['email']) : '<em>' . esc_html__('no valid email on the registration', $d) . '</em>') . '</p>';

        $log = get_post_meta($post->ID, self::LOG_META, true);
        $log = is_array($log) ? array_reverse($log) : array();
        if ($log) {
            echo '<ul style="margin:0 0 10px;max-height:180px;overflow:auto;">';
            foreach (array_slice($log, 0, 12) as $e) {
                $what = self::kind_label($e['kind']) . (!empty($e['booking']) ? ' #' . (int) $e['booking'] : '');
                echo '<li style="margin:0 0 4px;"><span style="color:#646970;">' . esc_html(wp_date('j M Y, g:i A', (int) $e['t'])) . '</span><br />'
                    . esc_html($what . ': ' . self::result_label($e['result'])) . '</li>';
            }
            echo '</ul>';
        } else {
            echo '<p style="color:#646970;">' . esc_html__('No emails yet.', $d) . '</p>';
        }

        $current = in_array($p['status'], array('pending', 'approved', 'rejected'), true) ? $p['status'] : '';
        if ($current !== '') {
            echo '<p><a class="button" href="' . esc_url(self::send_url($post->ID, $current)) . '">' . esc_html(sprintf(__('Send "%s" email again', $d), self::kind_label($current))) . '</a></p>';
        }
        echo '<p>' . esc_html__('Preview:', $d) . ' ';
        $links = array();
        foreach (array('pending', 'approved', 'rejected') as $k) {
            $links[] = '<a href="' . esc_url(self::preview_url($post->ID, $k)) . '" target="_blank" rel="noopener">' . esc_html(self::kind_label($k)) . '</a>';
        }
        echo implode(' · ', $links) . '</p>'; // phpcs:ignore -- escaped above

        echo '<p><label for="ecare-pe-reason"><strong>' . esc_html__('Reason (for a rejection)', $d) . '</strong></label>'
            . '<textarea id="ecare-pe-reason" name="ecare_reject_reason" rows="3" maxlength="500" style="width:100%;">' . esc_textarea($p['reason']) . '</textarea>'
            . '<span class="description">' . esc_html__('Optional. Set Status to Rejected and Update: this text goes in the email.', $d) . '</span></p>';
    }

    /** Runs before the Status field is saved, so a rejection email already has the reason. */
    public static function save_reason($post_id) {
        if (!isset(self::TYPES[get_post_type($post_id)]) || !isset($_POST['_ecare_pe_reason_nonce'])) {
            return;
        }
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_ecare_pe_reason_nonce'])), self::NONCE . '_reason') || !current_user_can('edit_post', $post_id)) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        $reason = sanitize_textarea_field(wp_unslash($_POST['ecare_reject_reason'] ?? ''));
        $reason = function_exists('mb_substr') ? mb_substr($reason, 0, 500) : substr($reason, 0, 500);
        update_post_meta($post_id, self::REASON_META, $reason);
    }

    private static function guard($post_id) {
        if (!current_user_can('manage_options') || !check_admin_referer(self::NONCE . '_' . (int) $post_id)) {
            wp_die(esc_html__('You are not allowed to do that.', 'ecare-health-services'), 403);
        }
    }

    public static function handle_send() {
        $id   = (int) ($_GET['id'] ?? 0);
        $kind = sanitize_key((string) ($_GET['kind'] ?? ''));
        self::guard($id);
        $r = in_array($kind, array('pending', 'approved', 'rejected'), true) ? self::send($id, $kind, true) : 'missing';
        wp_safe_redirect(add_query_arg('ecare_mail', $r, get_edit_post_link($id, 'raw') ?: admin_url()));
        exit;
    }

    public static function handle_preview() {
        $id   = (int) ($_GET['id'] ?? 0);
        $kind = sanitize_key((string) ($_GET['kind'] ?? ''));
        self::guard($id);
        $p = self::provider($id);
        if (!$p || !in_array($kind, self::KINDS, true)) {
            wp_die(esc_html__('Provider not found.', 'ecare-health-services'), 404);
        }
        $mail = self::build($p, $kind);
        $body = (function_exists('WC') && WC() && method_exists(WC(), 'mailer'))
            ? WC()->mailer()->wrap_message($mail['heading'], $mail['html'])
            : '<h2>' . esc_html($mail['heading']) . '</h2>' . $mail['html'];
        if (class_exists('WC_Email')) {
            $wc   = new WC_Email();
            $body = $wc->style_inline($body);
        }
        nocache_headers();
        header('Content-Type: text/html; charset=UTF-8');
        echo '<div style="font:13px sans-serif;background:#fffbea;border-bottom:1px solid #e5d48b;padding:8px 12px;">'
            . esc_html(sprintf(__('Preview only, nothing is sent. Subject: %s', 'ecare-health-services'), $mail['subject'])) . '</div>';
        echo $body; // phpcs:ignore -- built from escaped parts
        exit;
    }
}
