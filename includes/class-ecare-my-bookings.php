<?php
defined('ABSPATH') || exit;

/**
 * The patient's E-Care bookings - lab tests, caregivers, ambulances - inside
 * the patient dashboard ("My Dashboard", EG Care's [eg_care_patient_dashboard]).
 *
 * EG Care's dashboard offers two hooks:
 *   eg_care_patient_dashboard_after_stats  -> a tab bar: Doctor Consultations |
 *                                             Lab Tests | Caregiver | Ambulance
 *   eg_care_patient_dashboard_end          -> the three panels
 * The script moves the panels up under the tab bar and shows one tab at a
 * time; EG Care's own consultation sections are the first tab, its Family
 * Members box stays visible under every tab. Without the script everything is
 * simply shown one after another.
 *
 * [ecare_my_bookings] shows the same panels anywhere else (a site without the
 * hook, or a page of its own).
 *
 * Only the signed-in patient's own bookings are read. An ambulance's driver
 * name and phone are shown only once that ambulance is assigned to the
 * booking (or on its way).
 */
class ECare_My_Bookings {

    const TABS = array('lab', 'caregiver', 'ambulance');

    /** Open (still being worked on) statuses per kind. */
    const OPEN = array(
        'caregiver' => array('pending', 'approved'),
        'ambulance' => array('pending', 'approved', 'assigned', 'dispatched'),
        'legacy'    => array('pending', 'approved'),
    );

    /** Panels already printed on this page (the hook and the shortcode must not both print them). */
    private static $printed = false;
    private static $tabbed  = false;

    public static function init() {
        add_shortcode('ecare_my_bookings', array(__CLASS__, 'shortcode'));
        add_action('eg_care_patient_dashboard_after_stats', array(__CLASS__, 'tab_bar'));
        add_action('eg_care_patient_dashboard_end', array(__CLASS__, 'panels_at_end'));
    }

    // =======================================================================
    // Data
    // =======================================================================

    private static function rows($user_id, $type, $extra = '') {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ecare_bookings WHERE user_id = %d AND booking_type = %s{$extra} ORDER BY id DESC LIMIT 50",
            (int) $user_id, $type
        ));
    }

    /**
     * Everything the panels show, split into open and past.
     *
     * @return array{lab:array{open:array, past:array}, caregiver:array{open:array, past:array}, ambulance:array{open:array, past:array}}
     */
    public static function data($user_id) {
        static $cache = array();   // the tab bar and the panels ask in the same request
        if (isset($cache[(int) $user_id])) {
            return $cache[(int) $user_id];
        }
        $cache[(int) $user_id] = self::load((int) $user_id);
        return $cache[(int) $user_id];
    }

    private static function load($user_id) {
        $out = array();
        foreach (self::TABS as $t) {
            $out[$t] = array('open' => array(), 'past' => array());
        }
        if (!$user_id) {
            return $out;
        }

        // Lab: orders from the lab cart, then the old one-row-per-test bookings.
        if (class_exists('ECare_Lab_Orders')) {
            $lab_open = array_merge(array('pending'), ECare_Lab_Orders::open_statuses());
            foreach (ECare_Lab_Orders::for_user($user_id) as $o) {
                $out['lab'][in_array($o->status, $lab_open, true) ? 'open' : 'past'][] = array('kind' => 'lab', 'row' => $o);
            }
            $legacy = ECare_Lab_Orders::legacy_only_sql();
        } else {
            $legacy = '';
        }
        foreach (self::rows($user_id, 'lab', $legacy) as $r) {
            $out['lab'][in_array($r->status, self::OPEN['legacy'], true) ? 'open' : 'past'][] = array('kind' => 'legacy', 'row' => $r);
        }

        foreach (array('caregiver', 'ambulance') as $t) {
            foreach (self::rows($user_id, $t) as $r) {
                $out[$t][in_array($r->status, self::OPEN[$t], true) ? 'open' : 'past'][] = array('kind' => $t, 'row' => $r);
            }
        }
        return $out;
    }

    // =======================================================================
    // Words
    // =======================================================================

    /** [label, colour class] for a caregiver / ambulance / old lab status. */
    public static function status($kind, $status) {
        $d = 'ecare-health-services';
        $map = array(
            'pending'    => array(__('Pending', $d), 'wait'),
            'approved'   => array(__('Confirmed', $d), 'ok'),
            'assigned'   => array(__('Ambulance assigned', $d), 'ok'),
            'dispatched' => array(__('On the way', $d), 'go'),
            'completed'  => array(__('Completed', $d), 'done'),
            'cancelled'  => array(__('Cancelled', $d), 'off'),
        );
        return $map[$status] ?? array(ucfirst((string) $status), 'wait');
    }

    private static function money($n) {
        return class_exists('ECare_Lab_Front') ? ECare_Lab_Front::money($n) : '৳' . number_format((float) $n);
    }

    private static function day($ymd) {
        $ts = strtotime((string) $ymd);
        return $ts ? wp_date('D, j M Y', $ts, new DateTimeZone('UTC')) : '';
    }

    private static function moment($mysql) {
        $ts = strtotime((string) $mysql);
        return $ts ? wp_date('D, j M Y, g:i A', $ts, new DateTimeZone('UTC')) : '';
    }

    /** WooCommerce's pay page for a booking that is still unpaid, or ''. */
    public static function pay_url($row) {
        if ($row->status !== 'pending' || empty($row->order_id) || !function_exists('wc_get_order')) {
            return '';
        }
        $o = wc_get_order((int) $row->order_id);
        if (!$o || (int) $o->get_customer_id() !== (int) $row->user_id || !$o->needs_payment()) {
            return '';
        }
        return $o->get_checkout_payment_url();
    }

    /** Driver's name and phone - only once the ambulance is assigned to this booking. */
    public static function driver($row) {
        if (!in_array($row->status, array('assigned', 'dispatched'), true) || !(int) $row->provider_id || get_post_type((int) $row->provider_id) !== 'ecare_ambulance') {
            return null;
        }
        $id   = (int) $row->provider_id;
        $name = trim((string) get_post_meta($id, '_driver_name', true));
        return array(
            'name'  => $name !== '' ? $name : (string) get_the_title($id),
            'phone' => trim((string) get_post_meta($id, '_phone', true)),
            'plate' => trim((string) get_post_meta($id, '_license_plate', true)),
        );
    }

    private static function pill($kind, $status) {
        $s = self::status($kind, $status);
        return '<span class="ecl-mb-pill is-' . esc_attr($s[1]) . '">' . esc_html($s[0]) . '</span>';
    }

    private static function rows_html($pairs) {
        $out = '<dl class="ecl-mb-dl">';
        foreach ($pairs as $k => $v) {
            if ($v !== '' && $v !== null) {
                $out .= '<div><dt>' . esc_html($k) . '</dt><dd>' . $v . '</dd></div>';
            }
        }
        return $out . '</dl>';
    }

    // =======================================================================
    // Cards
    // =======================================================================

    public static function card($item) {
        $d = 'ecare-health-services';
        $r = $item['row'];

        if ($item['kind'] === 'lab') {
            return ECare_Lab_Orders_Page::render_order($r);   // the same card as My Lab Orders
        }

        if ($item['kind'] === 'legacy') {
            $title = get_the_title((int) $r->lab_test_ids) ?: __('Lab test', $d);
            $body  = self::rows_html(array(
                __('Date', $d)   => esc_html(self::day($r->required_date)),
                __('Amount', $d) => esc_html(self::money($r->total_amount)),
            ));
            return self::shell($r->id, $title, self::pill('legacy', $r->status), $body, self::pay_url($r));
        }

        if ($item['kind'] === 'caregiver') {
            $name  = $r->provider_id ? get_the_title((int) $r->provider_id) : '';
            $title = $name !== '' ? sprintf(__('Caregiver: %s', $d), $name) : __('Caregiver booking', $d);
            $body  = self::rows_html(array(
                __('Package', $d)    => esc_html((string) $r->package_type),
                __('Start date', $d) => esc_html(self::day($r->required_date)),
                __('Patient', $d)    => esc_html((string) $r->patient_name),
                __('Address', $d)    => esc_html((string) $r->address),
                __('Amount', $d)     => esc_html(self::money($r->total_amount)),
            ));
            return self::shell($r->id, $title, self::pill('caregiver', $r->status), $body, self::pay_url($r));
        }

        // ambulance
        $types = class_exists('ECare_Search') ? ECare_Search::ambulance_types() : array();
        $title = sprintf(__('Ambulance: %s', $d), $types[$r->ambulance_type] ?? $r->ambulance_type);
        $pairs = array(
            __('Pickup time', $d) => esc_html(self::moment($r->schedule_time)),
            __('From', $d)        => esc_html((string) $r->pickup_address),
            __('To', $d)          => esc_html((string) $r->destination),
            __('Priority', $d)    => $r->priority_level === 'Emergency' ? '<strong class="ecl-mb-emergency">' . esc_html__('Emergency', $d) . '</strong>' : esc_html((string) $r->priority_level),
            __('Amount', $d)      => esc_html(self::money($r->total_amount)),
        );
        $drv = self::driver($r);
        if ($drv) {
            $pairs[__('Driver', $d)] = esc_html($drv['name'])
                . ($drv['phone'] !== '' ? ' · <a href="tel:' . esc_attr(preg_replace('/[^0-9+]/', '', $drv['phone'])) . '">' . esc_html($drv['phone']) . '</a>' : '')
                . ($drv['plate'] !== '' ? '<br /><small>' . esc_html($drv['plate']) . '</small>' : '');
        }
        return self::shell($r->id, $title, self::pill('ambulance', $r->status), self::rows_html($pairs), self::pay_url($r));
    }

    private static function shell($id, $title, $pill, $body, $pay) {
        $d = 'ecare-health-services';
        return '<article class="ecl-cp-box ecl-mb-card">'
            . '<header class="ecl-ord-head"><div><strong class="ecl-ord-no">' . esc_html($title) . '</strong>'
            . '<small>' . esc_html(sprintf(__('Booking #%d', $d), $id)) . '</small></div>' . $pill . '</header>'
            . $body
            . ($pay !== '' ? '<p class="ecl-mb-actions"><a class="ecl-btn ecl-btn-sm" href="' . esc_url($pay) . '">' . esc_html__('Pay now', $d) . '</a></p>' : '')
            . '</article>';
    }

    // =======================================================================
    // Panels and tabs
    // =======================================================================

    public static function tab_label($tab) {
        $d = 'ecare-health-services';
        $l = array('consult' => __('Doctor Consultations', $d), 'lab' => __('Lab Tests', $d), 'caregiver' => __('Caregiver', $d), 'ambulance' => __('Ambulance', $d));
        return $l[$tab] ?? $tab;
    }

    private static function empty_cta($tab) {
        $d = 'ecare-health-services';
        $links = array(
            'lab'       => array(class_exists('ECare_Lab_Front') && ECare_Lab_Front::page_id('home') ? ECare_Lab_Front::url('home') : '', __('Book a lab test', $d), __('No lab tests yet.', $d)),
            'caregiver' => array(class_exists('ECare_Search') ? ECare_Search::caregiver_url() : '', __('Find a caregiver', $d), __('No caregiver bookings yet.', $d)),
            'ambulance' => array(class_exists('ECare_Search') ? ECare_Search::ambulance_url() : '', __('Book an ambulance', $d), __('No ambulance bookings yet.', $d)),
        );
        $l = $links[$tab];
        return '<div class="ecl-mb-empty"><p>' . esc_html($l[2]) . '</p>'
            . ($l[0] !== '' ? '<a class="ecl-btn ecl-btn-sm" href="' . esc_url($l[0]) . '">' . esc_html($l[1]) . '</a>' : '') . '</div>';
    }

    public static function panels_html($user_id, $tabbed) {
        $d    = 'ecare-health-services';
        $data = self::data($user_id);
        $html = '<div class="ecl ecl-mb' . ($tabbed ? ' is-tabbed' : '') . '" data-ecl-mb>';
        foreach (self::TABS as $t) {
            $html .= '<section class="ecl-mb-panel" id="ecl-mb-' . esc_attr($t) . '" data-ecl-panel="' . esc_attr($t) . '" aria-labelledby="ecl-mb-h-' . esc_attr($t) . '">';
            $html .= '<h3 class="ecl-mb-h" id="ecl-mb-h-' . esc_attr($t) . '">' . esc_html(self::tab_label($t)) . '</h3>';
            if (!$data[$t]['open'] && !$data[$t]['past']) {
                $html .= self::empty_cta($t);
            } else {
                foreach ($data[$t]['open'] as $item) {
                    $html .= self::card($item);
                }
                if (!$data[$t]['open']) {
                    $html .= '<p class="ecl-mb-none">' . esc_html__('Nothing in progress.', $d) . '</p>';
                }
                if ($data[$t]['past']) {
                    $html .= '<details class="ecl-mb-past"' . ($data[$t]['open'] ? '' : ' open') . '><summary>' . esc_html(sprintf(__('Earlier bookings (%d)', $d), count($data[$t]['past']))) . '</summary>';
                    foreach ($data[$t]['past'] as $item) {
                        $html .= self::card($item);
                    }
                    $html .= '</details>';
                }
            }
            $html .= '</section>';
        }
        return $html . '</div>';
    }

    public static function tab_bar_html($user_id) {
        $data = self::data($user_id);
        $html = '<nav class="ecl ecl-mb-tabs" data-ecl-mb-tabs role="tablist" aria-label="' . esc_attr__('Your bookings', 'ecare-health-services') . '">';
        foreach (array_merge(array('consult'), self::TABS) as $i => $t) {
            $n     = $t === 'consult' ? 0 : count($data[$t]['open']);
            $html .= '<button type="button" role="tab" data-ecl-tab="' . esc_attr($t) . '" aria-selected="' . ($i === 0 ? 'true' : 'false') . '">'
                . esc_html(self::tab_label($t))
                . ($n ? ' <span class="ecl-mb-badge">' . (int) $n . '</span>' : '')
                . '</button>';
        }
        return $html . '</nav>';
    }

    private static function assets() {
        $css = ECARE_PLUGIN_DIR . 'assets/css/ecare-lab.css';
        $js  = ECARE_PLUGIN_DIR . 'assets/js/ecare-mybookings.js';
        wp_enqueue_style('ecare-lab', ECARE_PLUGIN_URL . 'assets/css/ecare-lab.css', array(), file_exists($css) ? filemtime($css) : ECARE_VERSION);
        wp_enqueue_script('ecare-mybookings', ECARE_PLUGIN_URL . 'assets/js/ecare-mybookings.js', array(), file_exists($js) ? filemtime($js) : ECARE_VERSION, true);
    }

    /** eg_care_patient_dashboard_after_stats */
    public static function tab_bar($user_id) {
        if (!is_user_logged_in() || (int) $user_id !== get_current_user_id()) {
            return;
        }
        self::assets();
        self::$tabbed = true;
        echo self::tab_bar_html((int) $user_id); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside
    }

    /** eg_care_patient_dashboard_end */
    public static function panels_at_end($user_id) {
        if (self::$printed || !is_user_logged_in() || (int) $user_id !== get_current_user_id()) {
            return;
        }
        self::$printed = true;
        self::assets();
        echo self::panels_html((int) $user_id, self::$tabbed); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside
    }

    /** [ecare_my_bookings] */
    public static function shortcode() {
        if (!is_user_logged_in()) {
            return '<div class="ecl"><p class="ecl-mb-none">' . esc_html__('Please log in to see your bookings.', 'ecare-health-services') . '</p></div>';
        }
        if (self::$printed) {
            return '';   // the dashboard already shows them on this page
        }
        self::$printed = true;
        self::assets();
        return self::panels_html(get_current_user_id(), false);
    }
}
