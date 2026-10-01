<?php
defined('ABSPATH') || exit;

/**
 * Ambulance Dispatch: which ambulance goes on which request.
 *
 * When a patient books, ECare_Ajax picks the least busy approved ambulance of
 * the requested type (a suggestion; it may find none). On the Dispatch screen
 * an admin can keep it, pick another (any approved ambulance, the requested
 * type listed first) or clear it. Picking one sets the booking to Assigned
 * and emails that provider straight away (ECare_Provider_Emails); a new
 * provider after a change gets their own email.
 *
 * Status order: Pending (not paid) → Approved (paid) → Assigned (unit chosen)
 * → Dispatched (on the way) → Completed, or Cancelled.
 */
class ECare_Ambulance_Dispatch {

    const PAGE = 'ecare-ambulance-dispatch';

    /** Booking statuses, in the order a request moves through them. */
    const STATUSES = array('pending', 'approved', 'assigned', 'dispatched', 'completed', 'cancelled');

    /** A booking in one of these is still being worked on. */
    const OPEN = array('pending', 'approved', 'assigned', 'dispatched');

    /** These need an ambulance attached. */
    const NEEDS_UNIT = array('assigned', 'dispatched');

    public static function init() {
        add_action('wp_ajax_ecare_assign_ambulance', array(__CLASS__, 'ajax_assign'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'), 20);
    }

    public static function status_label($s) {
        $d = 'ecare-health-services';
        $l = array(
            'pending'    => __('Pending', $d),
            'approved'   => __('Approved', $d),
            'assigned'   => __('Assigned', $d),
            'dispatched' => __('Dispatched', $d),
            'completed'  => __('Completed', $d),
            'cancelled'  => __('Cancelled', $d),
        );
        return $l[$s] ?? ucfirst((string) $s);
    }

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'ecare_bookings';
    }

    // =======================================================================
    // Units (approved ambulances)
    // =======================================================================

    /** Open bookings per ambulance: provider_id => count. */
    public static function loads() {
        global $wpdb;
        $in   = implode(', ', array_fill(0, count(self::OPEN), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT provider_id, COUNT(*) AS n FROM " . self::table() . " WHERE booking_type = 'ambulance' AND provider_id > 0 AND status IN ({$in}) GROUP BY provider_id",
            self::OPEN
        ));
        $out = array();
        foreach ((array) $rows as $r) {
            $out[(int) $r->provider_id] = (int) $r->n;
        }
        return $out;
    }

    /** What an ambulance is called in the lists: the driver, else the provider name. */
    public static function unit_name($id) {
        $driver = trim((string) get_post_meta((int) $id, '_driver_name', true));
        return $driver !== '' ? $driver : (string) get_the_title((int) $id);
    }

    /**
     * Every approved ambulance.
     *
     * @return array<int, array{id:int, name:string, plate:string, type:string, load:int}>
     */
    public static function units() {
        $ids = get_posts(array(
            'post_type'      => 'ecare_ambulance',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'fields'         => 'ids',
            'meta_query'     => array(array('key' => '_ambulance_status', 'value' => 'approved')),
        ));
        $loads = self::loads();
        $out   = array();
        foreach ((array) $ids as $id) {
            $id = (int) $id;
            $out[$id] = array(
                'id'    => $id,
                'name'  => self::unit_name($id),
                'plate' => trim((string) get_post_meta($id, '_license_plate', true)),
                'type'  => (string) get_post_meta($id, '_ambulance_type', true),
                'load'  => $loads[$id] ?? 0,
            );
        }
        return $out;
    }

    /** "Karim · DHA-1234 · 2 active" */
    public static function unit_label($u, $with_type = false) {
        $d     = 'ecare-health-services';
        $parts = array($u['name']);
        if ($u['plate'] !== '') {
            $parts[] = $u['plate'];
        }
        if ($with_type && $u['type'] !== '') {
            $parts[] = $u['type'];
        }
        $parts[] = $u['load'] ? sprintf(_n('%d active', '%d active', $u['load'], $d), $u['load']) : __('free', $d);
        return implode(' · ', $parts);
    }

    /**
     * The options of one row's "Assign ambulance" list: the requested type
     * first (least busy first), then the others. The booking's current unit
     * stays listed even if it is no longer approved, so the list never lies.
     */
    public static function options_html($booking, $units) {
        $d       = 'ecare-health-services';
        $current = (int) $booking->provider_id;
        $type    = (string) $booking->ambulance_type;
        $match   = array_filter($units, function ($u) use ($type) { return $u['type'] === $type; });
        $other   = array_filter($units, function ($u) use ($type) { return $u['type'] !== $type; });
        $by_load = function ($a, $b) { return $a['load'] === $b['load'] ? strcmp($a['name'], $b['name']) : $a['load'] - $b['load']; };
        uasort($match, $by_load);
        uasort($other, $by_load);

        $html = '<option value="0"' . ($current ? '' : ' selected') . '>' . esc_html__('— Not assigned —', $d) . '</option>';
        if ($current && !isset($units[$current])) {
            $html .= '<option value="' . $current . '" selected>' . esc_html(sprintf(__('%s (no longer approved)', $d), self::unit_name($current))) . '</option>';
        }
        $groups = array(
            /* translators: %s: ambulance type, e.g. ICU */
            sprintf(__('%s ambulances', $d), $type !== '' ? $type : __('Requested', $d)) => array($match, false),
            __('Other types', $d) => array($other, true),
        );
        foreach ($groups as $label => $g) {
            if (!$g[0]) {
                continue;
            }
            $html .= '<optgroup label="' . esc_attr($label) . '">';
            foreach ($g[0] as $u) {
                $html .= '<option value="' . (int) $u['id'] . '"' . ($u['id'] === $current ? ' selected' : '') . '>' . esc_html(self::unit_label($u, $g[1])) . '</option>';
            }
            $html .= '</optgroup>';
        }
        return $html;
    }

    // =======================================================================
    // Counts and times for the screen
    // =======================================================================

    /** @return array{total:int, active:int, completed:int, emergency:int, unassigned:int} */
    public static function counts() {
        global $wpdb;
        $t    = self::table();
        $open = "'" . implode("', '", self::OPEN) . "'";
        $one  = function ($where) use ($wpdb, $t) { return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE booking_type = 'ambulance' AND {$where}"); };
        return array(
            'total'      => $one('1 = 1'),
            'active'     => $one("status IN ('approved', 'assigned', 'dispatched')"),
            'completed'  => $one("status = 'completed'"),
            'emergency'  => $one("priority_level = 'Emergency'"),
            'unassigned' => $one("(provider_id IS NULL OR provider_id = 0) AND status IN ({$open})"),
        );
    }

    /**
     * The database clock's distance from real UTC, in seconds (rounded to the
     * quarter hour). created_at is written by the database, whose clock need
     * not be in UTC or in the site's zone.
     */
    public static function db_offset() {
        static $off = null;
        if ($off === null) {
            global $wpdb;
            $now = strtotime((string) $wpdb->get_var('SELECT NOW()') . ' UTC');
            $off = $now ? (int) (round(($now - time()) / 900) * 900) : 0;
        }
        return $off;
    }

    /** When the booking was made, in the site's time zone ("2 Oct, 3:15 PM"). */
    public static function booked_at($created_at) {
        $created_at = trim((string) $created_at);
        if ($created_at === '' || strpos($created_at, '0000') === 0) {
            return '';
        }
        $ts = strtotime($created_at . ' UTC');
        return $ts ? wp_date('j M Y, g:i A', $ts - self::db_offset()) : '';
    }

    // =======================================================================
    // Assigning
    // =======================================================================

    /**
     * Put an ambulance on a booking (or take it off with $unit_id = 0).
     *
     * @return array{ok:bool, code:string, status?:string, unit?:int, mail?:string}
     */
    public static function assign($booking_id, $unit_id) {
        global $wpdb;
        $t   = self::table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", (int) $booking_id));
        if (!$row || $row->booking_type !== 'ambulance') {
            return array('ok' => false, 'code' => 'missing');
        }
        if (!in_array($row->status, self::OPEN, true)) {
            return array('ok' => false, 'code' => 'closed');
        }
        $unit_id = (int) $unit_id;
        $old_id  = (int) $row->provider_id;
        if ($unit_id === $old_id) {
            return array('ok' => true, 'code' => 'same', 'status' => $row->status, 'unit' => $old_id);
        }

        if ($unit_id === 0) {
            if ($row->status === 'dispatched') {
                return array('ok' => false, 'code' => 'on_the_way');   // change the status first
            }
            $status = $row->status === 'assigned' ? (self::is_paid($row) ? 'approved' : 'pending') : $row->status;
            $wpdb->update($t, array('provider_id' => null, 'status' => $status), array('id' => (int) $row->id));
            self::changed($row, $status, 0);
            return array('ok' => true, 'code' => 'cleared', 'status' => $status, 'unit' => 0);
        }

        if (get_post_type($unit_id) !== 'ecare_ambulance' || get_post_meta($unit_id, '_ambulance_status', true) !== 'approved') {
            return array('ok' => false, 'code' => 'bad_unit');
        }
        $status = in_array($row->status, array('pending', 'approved'), true) ? 'assigned' : $row->status;
        $wpdb->update($t, array('provider_id' => $unit_id, 'status' => $status), array('id' => (int) $row->id));

        // Email the provider now, and report how it went.
        $mail = '';
        if (class_exists('ECare_Provider_Emails')) {
            $fresh = clone $row;
            $fresh->provider_id = $unit_id;
            $fresh->status      = $status;
            $mail = ECare_Provider_Emails::send_booking($fresh);
        }
        self::changed($row, $status, $unit_id);
        return array('ok' => true, 'code' => 'assigned', 'status' => $status, 'unit' => $unit_id, 'mail' => $mail);
    }

    /** Was the booking's WooCommerce order paid? (No order: treat as paid, it was arranged by hand.) */
    private static function is_paid($row) {
        if (empty($row->order_id) || !function_exists('wc_get_order')) {
            return true;
        }
        $o = wc_get_order((int) $row->order_id);
        return !$o || $o->is_paid();
    }

    private static function changed($row, $status, $unit_id) {
        if ((int) $row->provider_id !== (int) $unit_id) {
            /** The ambulance on a booking changed: booking id, new provider id (0 = none), old provider id. */
            do_action('ecare_booking_provider_changed', (int) $row->id, (int) $unit_id, (int) $row->provider_id);
        }
        if ($status !== $row->status) {
            do_action('ecare_booking_status_changed', (int) $row->id, $status, $row->status);
        }
    }

    /** What the admin is told after an assignment. */
    public static function message($r, $unit_id) {
        $d = 'ecare-health-services';
        $codes = array(
            'missing'    => __('That booking could not be found.', $d),
            'closed'     => __('This booking is completed or cancelled; its ambulance can no longer change.', $d),
            'on_the_way' => __('This ambulance is already dispatched. Change the status first, then remove it.', $d),
            'bad_unit'   => __('That ambulance is not approved. Approve it in Ambulance Providers first.', $d),
            'cleared'    => __('Ambulance removed from this booking.', $d),
            'same'       => __('No change.', $d),
        );
        if ($r['code'] !== 'assigned') {
            return $codes[$r['code']] ?? __('Could not save.', $d);
        }
        $email = (string) get_post_meta((int) $unit_id, '_email', true);
        $mail  = array(
            'sent'        => sprintf(__('Assigned. Booking details emailed to %s.', $d), $email),
            'already'     => __('Assigned. This provider already had the booking email.', $d),
            'disabled'    => __('Assigned. (Booking emails are switched off in Email Settings.)', $d),
            'no_address'  => __('Assigned, but the provider has no valid email address: please call them.', $d),
            'failed'      => __('Assigned, but the email could not be sent: please call the provider.', $d),
        );
        return $mail[$r['mail'] ?? ''] ?? __('Assigned.', $d);
    }

    public static function ajax_assign() {
        check_ajax_referer('ecare_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized.', 'ecare-health-services')), 403);
        }
        $booking = (int) ($_POST['booking_id'] ?? 0);
        $unit    = (int) ($_POST['unit_id'] ?? 0);
        $r       = self::assign($booking, $unit);
        $out     = array('message' => self::message($r, $unit), 'code' => $r['code']);
        if (!$r['ok']) {
            wp_send_json_error($out, 409);
        }
        $out['status']       = $r['status'];
        $out['status_label'] = self::status_label($r['status']);
        $out['unit']         = $r['unit'];
        $out['unassigned']   = self::counts()['unassigned'];
        $out['mail']         = $r['mail'] ?? '';
        wp_send_json_success($out);
    }

    // =======================================================================
    // Script
    // =======================================================================

    public static function enqueue() {
        if (($_GET['page'] ?? '') !== self::PAGE) {
            return;
        }
        $js = ECARE_PLUGIN_DIR . 'assets/js/ecare-dispatch.js';
        wp_enqueue_script('ecare-dispatch', ECARE_PLUGIN_URL . 'assets/js/ecare-dispatch.js', array('jquery', 'ecare-admin-script'), file_exists($js) ? filemtime($js) : ECARE_VERSION, true);
        wp_localize_script('ecare-dispatch', 'ecareDispatch', array(
            'labels'   => array_combine(self::STATUSES, array_map(array(__CLASS__, 'status_label'), self::STATUSES)),
            'needUnit' => __('Assign an ambulance first, then mark it Assigned or Dispatched.', 'ecare-health-services'),
            'failed'   => __('Could not save. Please try again.', 'ecare-health-services'),
            'saving'   => __('Saving…', 'ecare-health-services'),
        ));
    }
}
