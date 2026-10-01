<?php
defined('ABSPATH') || exit;

/**
 * Lab settings: payment, report delivery, collection schedule, home page.
 *
 * Everything lives in one option, ecare_lab_settings, saved through the
 * Settings API and cleaned by sanitize(). The helpers below (advance amount,
 * delivery fee, open slots) are what the cart and checkout steps will call, so
 * the rules live here once.
 */
class ECare_Lab_Settings {

    const OPTION = 'ecare_lab_settings';
    const PAGE   = 'ecare-lab-settings';

    const DAYS = array('sat', 'sun', 'mon', 'tue', 'wed', 'thu', 'fri');

    public static function init() {
        if (is_admin()) {
            add_action('admin_init', array(__CLASS__, 'register'));
            add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'));
        }
        // Page choices changed: forget the auto-detected lab pages.
        add_action('update_option_' . self::OPTION, array(__CLASS__, 'forget_pages'));
    }

    // -----------------------------------------------------------------------
    // Defaults and reading
    // -----------------------------------------------------------------------

    public static function forget_pages() {
        foreach (array('home', 'tests', 'cart') as $which) {
            delete_transient('ecare_lab_page_' . $which);
        }
    }

    public static function defaults() {
        return array(
            'new_front'       => 0,   // the switch-over: 1 = the new lab pages replace [ecare_lab_tests]
            'page_home'       => 0,
            'page_tests'      => 0,
            'page_cart'       => 0,
            'advance_percent' => 20,
            'service_charge'  => 0,
            'hard_copy_fee'   => 200,
            'both_fee'        => 200,
            'days_ahead'      => 7,
            'open_days'       => self::DAYS,
            'slots'           => array('07:00-09:00', '09:00-11:00', '11:00-13:00', '15:00-17:00', '17:00-19:00'),
            'cutoff_hours'    => 2,
            'slot_capacity'   => 0,
            'banner_id'       => 0,
            'banner_link'     => '',
            'messenger_link'  => '',
            'whatsapp_number' => '',
            'whatsapp_message'=> 'Hello, I would like to book a lab test.',
            'hotline'         => '',
            'steps'           => array(
                array('title' => 'Sample collection at home', 'text' => 'A trained collector comes to your address at the time you choose and takes the sample safely.'),
                array('title' => 'Safe transport',            'text' => 'Samples are sealed, labelled and kept at the right temperature on the way to the lab.'),
                array('title' => 'Tested by the lab you chose', 'text' => 'Your chosen partner lab runs the tests with its own equipment and specialists.'),
                array('title' => 'Report online',             'text' => 'The report is uploaded to your account as soon as it is ready; a printed copy can follow if you asked for one.'),
            ),
        );
    }

    /** Stored settings merged over defaults. */
    public static function all() {
        $saved = get_option(self::OPTION, array());
        return array_merge(self::defaults(), is_array($saved) ? $saved : array());
    }

    /** Has the site switched to the new lab pages? */
    public static function is_live() {
        return (int) self::get('new_front') === 1;
    }

    public static function get($key) {
        $all = self::all();
        return $all[$key] ?? null;
    }

    // -----------------------------------------------------------------------
    // Money
    // -----------------------------------------------------------------------

    /** Advance payable on a total: the configured percent, rounded up to a whole taka, never above the total. */
    public static function advance_amount($total) {
        $total = max(0, (float) $total);
        $pct   = (float) self::get('advance_percent');
        return (float) min($total, ceil($total * $pct / 100));
    }

    /** Report delivery fee for soft | hard | both. */
    public static function delivery_fee($method) {
        if ($method === 'hard') {
            return (float) self::get('hard_copy_fee');
        }
        if ($method === 'both') {
            return (float) self::get('both_fee');
        }
        return 0.0;
    }

    // -----------------------------------------------------------------------
    // Collection schedule
    // -----------------------------------------------------------------------

    /**
     * "7:00 - 9:00" style input -> "07:00-09:00". Invalid lines, zero-length
     * or backwards slots and repeats are dropped; the result is sorted.
     */
    public static function parse_slots($raw) {
        $lines = is_array($raw) ? $raw : preg_split('/\r\n|\r|\n|,/', (string) $raw);
        $out   = array();
        foreach ($lines as $line) {
            if (!preg_match('/^\s*(\d{1,2}):(\d{2})\s*[-–]\s*(\d{1,2}):(\d{2})\s*$/u', (string) $line, $m)) {
                continue;
            }
            $a = (int) $m[1] * 60 + (int) $m[2];
            $b = (int) $m[3] * 60 + (int) $m[4];
            if ((int) $m[1] > 23 || (int) $m[3] > 24 || (int) $m[2] > 59 || (int) $m[4] > 59 || $b <= $a || $b > 1440) {
                continue;
            }
            $out[sprintf('%02d:%02d-%02d:%02d', $m[1], $m[2], $m[3], $m[4])] = $a;
        }
        asort($out);
        return array_keys($out);
    }

    /**
     * Bookable dates from $now: today through days_ahead, open weekdays only,
     * and only dates with at least one slot still open.
     *
     * @return string[] Y-m-d
     */
    public static function bookable_dates(DateTimeImmutable $now, $booked = array()) {
        $out = array();
        $day = $now->setTime(0, 0);
        for ($i = 0; $i <= (int) self::get('days_ahead'); $i++) {
            $date = $day->modify('+' . $i . ' days')->format('Y-m-d');
            if (self::open_slots($date, $now, $booked)) {
                $out[] = $date;
            }
        }
        return $out;
    }

    /**
     * Slots a patient can still pick on $date.
     *
     * @param string            $date   Y-m-d
     * @param DateTimeImmutable $now    in the site's timezone
     * @param array             $booked "Y-m-d|HH:MM-HH:MM" => orders already in that slot
     * @return string[]
     */
    public static function open_slots($date, DateTimeImmutable $now, $booked = array()) {
        $tz = $now->getTimezone();
        $d  = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $date, $tz);
        if (!$d || $d->format('Y-m-d') !== $date) {
            return array();
        }
        $today = $now->setTime(0, 0);
        $last  = $today->modify('+' . (int) self::get('days_ahead') . ' days');
        if ($d < $today || $d > $last) {
            return array();
        }
        // PHP's N is 1 (Mon) .. 7 (Sun); map to our sat-first keys.
        $key = array(1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun')[(int) $d->format('N')];
        if (!in_array($key, (array) self::get('open_days'), true)) {
            return array();
        }

        $cutoff   = $now->modify('+' . max(0, (int) self::get('cutoff_hours')) . ' hours');
        $capacity = (int) self::get('slot_capacity');
        $out      = array();
        foreach ((array) self::get('slots') as $slot) {
            list($start) = explode('-', $slot);
            list($h, $m) = array_map('intval', explode(':', $start));
            if ($d->setTime($h, $m) <= $cutoff) {
                continue;
            }
            if ($capacity > 0 && (int) ($booked[$date . '|' . $slot] ?? 0) >= $capacity) {
                continue;
            }
            $out[] = $slot;
        }
        return $out;
    }

    /**
     * A WhatsApp number in the international digits wa.me wants:
     * "01712-345678", "+880 1712 345678" and "8801712345678" all give
     * "8801712345678". Anything that is not 8 to 15 digits is dropped.
     */
    public static function clean_whatsapp($raw) {
        $d = preg_replace('/\D+/', '', (string) $raw);
        if (strpos($d, '00') === 0) {
            $d = substr($d, 2);                       // 00880... international prefix
        }
        if (preg_match('/^01[3-9]\d{8}$/', $d)) {
            $d = '88' . $d;                           // a Bangladeshi number written locally
        }
        return (strlen($d) >= 8 && strlen($d) <= 15) ? $d : '';
    }

    /** The "Order via WhatsApp" link, or '' when no number is set. */
    public static function whatsapp_url() {
        $num = (string) self::get('whatsapp_number');
        if ($num === '') {
            return '';
        }
        $msg = trim((string) self::get('whatsapp_message'));
        return 'https://wa.me/' . $num . ($msg !== '' ? '?text=' . rawurlencode($msg) : '');
    }

    /** "07:00-09:00" -> "7:00 AM - 9:00 AM" for display. */
    public static function slot_label($slot) {
        $parts = explode('-', (string) $slot);
        if (count($parts) !== 2) {
            return (string) $slot;
        }
        $fmt = function ($t) {
            list($h, $m) = array_map('intval', explode(':', $t));
            $h24 = $h % 24;
            return sprintf('%d:%02d %s', ($h24 % 12) ?: 12, $m, $h24 < 12 ? 'AM' : 'PM');
        };
        return $fmt($parts[0]) . ' - ' . $fmt($parts[1]);
    }

    // -----------------------------------------------------------------------
    // Saving
    // -----------------------------------------------------------------------

    public static function register() {
        register_setting(self::PAGE, self::OPTION, array(
            'type'              => 'array',
            'sanitize_callback' => array(__CLASS__, 'sanitize'),
            'default'           => self::defaults(),
            'show_in_rest'      => false,
        ));
    }

    /** Every field bounded; anything missing falls back to its default. */
    public static function sanitize($in) {
        $in  = is_array($in) ? $in : array();
        $d   = self::defaults();
        $num = function ($v, $min, $max) { return (float) min($max, max($min, round((float) $v, 2))); };

        $open = array();
        foreach ((array) ($in['open_days'] ?? array()) as $day) {
            if (in_array($day, self::DAYS, true) && !in_array($day, $open, true)) {
                $open[] = $day;
            }
        }
        // Keep the week in order however the boxes were posted.
        $open = array_values(array_intersect(self::DAYS, $open));

        $slots = self::parse_slots($in['slots'] ?? '');

        $steps = array();
        foreach (array_values((array) ($in['steps'] ?? array())) as $i => $s) {
            if ($i >= 4) {
                break;
            }
            $title = sanitize_text_field($s['title'] ?? '');
            $text  = sanitize_textarea_field($s['text'] ?? '');
            $steps[] = array(
                'title' => $title !== '' ? $title : $d['steps'][$i]['title'],
                'text'  => $text,
            );
        }
        while (count($steps) < 4) {
            $steps[] = $d['steps'][count($steps)];
        }

        $banner = (int) ($in['banner_id'] ?? 0);
        if ($banner > 0 && function_exists('wp_attachment_is_image') && !wp_attachment_is_image($banner)) {
            $banner = 0;
        }

        return array(
            'new_front'       => empty($in['new_front']) ? 0 : 1,
            'page_home'       => max(0, (int) ($in['page_home'] ?? 0)),
            'page_tests'      => max(0, (int) ($in['page_tests'] ?? 0)),
            'page_cart'       => max(0, (int) ($in['page_cart'] ?? 0)),
            'advance_percent' => $num($in['advance_percent'] ?? $d['advance_percent'], 0, 100),
            'service_charge'  => $num($in['service_charge'] ?? 0, 0, 100000),
            'hard_copy_fee'   => $num($in['hard_copy_fee'] ?? $d['hard_copy_fee'], 0, 100000),
            'both_fee'        => $num($in['both_fee'] ?? $d['both_fee'], 0, 100000),
            'days_ahead'      => (int) $num($in['days_ahead'] ?? $d['days_ahead'], 0, 60),
            // All days unticked would close the service silently; that needs a slot list anyway.
            'open_days'       => $open ?: $d['open_days'],
            'slots'           => $slots ?: $d['slots'],
            'cutoff_hours'    => (int) $num($in['cutoff_hours'] ?? $d['cutoff_hours'], 0, 72),
            'slot_capacity'   => (int) $num($in['slot_capacity'] ?? 0, 0, 1000),
            'banner_id'       => $banner,
            'banner_link'     => esc_url_raw(trim((string) ($in['banner_link'] ?? ''))),
            'messenger_link'  => esc_url_raw(trim((string) ($in['messenger_link'] ?? ''))),
            'whatsapp_number' => self::clean_whatsapp($in['whatsapp_number'] ?? ''),
            'whatsapp_message'=> function_exists('mb_substr') ? mb_substr(sanitize_text_field($in['whatsapp_message'] ?? ''), 0, 300) : substr(sanitize_text_field($in['whatsapp_message'] ?? ''), 0, 300),
            'hotline'         => sanitize_text_field($in['hotline'] ?? ''),
            'steps'           => $steps,
        );
    }

    // -----------------------------------------------------------------------
    // Page
    // -----------------------------------------------------------------------

    public static function enqueue($hook) {
        if (isset($_GET['page']) && $_GET['page'] === self::PAGE) {
            wp_enqueue_media();
        }
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $s    = self::all();
        $name = function ($k) { return self::OPTION . '[' . $k . ']'; };
        $day_labels = array(
            'sat' => __('Saturday', 'ecare-health-services'), 'sun' => __('Sunday', 'ecare-health-services'),
            'mon' => __('Monday', 'ecare-health-services'), 'tue' => __('Tuesday', 'ecare-health-services'),
            'wed' => __('Wednesday', 'ecare-health-services'), 'thu' => __('Thursday', 'ecare-health-services'),
            'fri' => __('Friday', 'ecare-health-services'),
        );
        $banner_url = $s['banner_id'] ? wp_get_attachment_image_url((int) $s['banner_id'], 'medium') : '';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Lab Settings', 'ecare-health-services'); ?></h1>
            <?php settings_errors(); ?>
            <form method="post" action="options.php">
                <?php settings_fields(self::PAGE); ?>

                <h2 class="title"><?php esc_html_e('Go live', 'ecare-health-services'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th><?php esc_html_e('New lab pages', 'ecare-health-services'); ?></th>
                        <td>
                            <input type="hidden" name="<?php echo esc_attr($name('new_front')); ?>" value="0" />
                            <label><input type="checkbox" name="<?php echo esc_attr($name('new_front')); ?>" value="1" <?php checked((int) $s['new_front'], 1); ?> />
                                <?php esc_html_e('Use the new lab pages everywhere', 'ecare-health-services'); ?></label>
                            <p class="description"><?php esc_html_e('On: the old [ecare_lab_tests] shortcode and Elementor widget show the new All Tests page, and the old lab cart buttons stop. Off: the old lab page works as before. Run Data Migration and check the Dashboard checklist before turning this on; turning it off again is safe.', 'ecare-health-services'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2 class="title"><?php esc_html_e('Pages', 'ecare-health-services'); ?></h2>
                <p class="description"><?php esc_html_e('Which pages hold the lab screens. Left on "Find automatically", the first published page with the matching shortcode or Elementor widget is used.', 'ecare-health-services'); ?></p>
                <table class="form-table" role="presentation">
                    <?php foreach (array(
                        'home'  => array(__('Lab home', 'ecare-health-services'), '[ecare_lab_home]'),
                        'tests' => array(__('All tests & test details', 'ecare-health-services'), '[ecare_lab_catalog]'),
                        'cart'  => array(__('Lab cart & checkout', 'ecare-health-services'), '[ecare_lab_cart]'),
                    ) as $which => $info): ?>
                        <tr>
                            <th><label for="ecare-page-<?php echo esc_attr($which); ?>"><?php echo esc_html($info[0]); ?></label></th>
                            <td>
                                <?php wp_dropdown_pages(array(
                                    'name'              => esc_attr($name('page_' . $which)),
                                    'id'                => 'ecare-page-' . $which,
                                    'selected'          => (int) $s['page_' . $which],
                                    'show_option_none'  => esc_html__('— Find automatically —', 'ecare-health-services'),
                                    'option_none_value' => '0',
                                )); ?>
                                <p class="description"><?php echo esc_html(sprintf(__('Shortcode: %s', 'ecare-health-services'), $info[1])); ?>
                                <?php if (class_exists('ECare_Lab_Front') && ($found = ECare_Lab_Front::page_id($which))): ?>
                                    &middot; <?php esc_html_e('In use:', 'ecare-health-services'); ?> <a href="<?php echo esc_url(get_permalink($found)); ?>" target="_blank"><?php echo esc_html(get_the_title($found)); ?></a>
                                <?php endif; ?></p>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>

                <h2 class="title"><?php esc_html_e('Payment', 'ecare-health-services'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th><label for="ecare-adv"><?php esc_html_e('Advance payable', 'ecare-health-services'); ?></label></th>
                        <td><input type="number" id="ecare-adv" min="0" max="100" step="1" name="<?php echo esc_attr($name('advance_percent')); ?>" value="<?php echo esc_attr($s['advance_percent']); ?>" style="width:80px" /> %
                            <p class="description"><?php esc_html_e('Paid online at checkout, rounded up to a whole taka. The rest is collected with the sample. 100 = pay everything online.', 'ecare-health-services'); ?></p></td>
                    </tr>
                    <tr>
                        <th><label for="ecare-svc"><?php esc_html_e('Service charge', 'ecare-health-services'); ?></label></th>
                        <td>৳ <input type="number" id="ecare-svc" min="0" step="1" name="<?php echo esc_attr($name('service_charge')); ?>" value="<?php echo esc_attr($s['service_charge']); ?>" style="width:100px" />
                            <p class="description"><?php esc_html_e('Added once per order.', 'ecare-health-services'); ?></p></td>
                    </tr>
                </table>

                <h2 class="title"><?php esc_html_e('Report delivery', 'ecare-health-services'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr><th><?php esc_html_e('Soft copy', 'ecare-health-services'); ?></th><td><?php esc_html_e('Free - the report is uploaded to the patient\'s account.', 'ecare-health-services'); ?></td></tr>
                    <tr>
                        <th><label for="ecare-hard"><?php esc_html_e('Hard copy fee', 'ecare-health-services'); ?></label></th>
                        <td>৳ <input type="number" id="ecare-hard" min="0" step="1" name="<?php echo esc_attr($name('hard_copy_fee')); ?>" value="<?php echo esc_attr($s['hard_copy_fee']); ?>" style="width:100px" /></td>
                    </tr>
                    <tr>
                        <th><label for="ecare-both"><?php esc_html_e('Both (soft + hard) fee', 'ecare-health-services'); ?></label></th>
                        <td>৳ <input type="number" id="ecare-both" min="0" step="1" name="<?php echo esc_attr($name('both_fee')); ?>" value="<?php echo esc_attr($s['both_fee']); ?>" style="width:100px" /></td>
                    </tr>
                </table>

                <h2 class="title"><?php esc_html_e('Sample collection schedule', 'ecare-health-services'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th><label for="ecare-ahead"><?php esc_html_e('Book up to', 'ecare-health-services'); ?></label></th>
                        <td><input type="number" id="ecare-ahead" min="0" max="60" name="<?php echo esc_attr($name('days_ahead')); ?>" value="<?php echo esc_attr($s['days_ahead']); ?>" style="width:80px" /> <?php esc_html_e('days ahead (0 = today only)', 'ecare-health-services'); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Open days', 'ecare-health-services'); ?></th>
                        <td>
                            <?php foreach (self::DAYS as $day): ?>
                                <label style="margin-right:12px;white-space:nowrap;"><input type="checkbox" name="<?php echo esc_attr($name('open_days')); ?>[]" value="<?php echo esc_attr($day); ?>" <?php checked(in_array($day, (array) $s['open_days'], true)); ?> /> <?php echo esc_html($day_labels[$day]); ?></label>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="ecare-slots"><?php esc_html_e('Time slots', 'ecare-health-services'); ?></label></th>
                        <td><textarea id="ecare-slots" rows="6" cols="24" name="<?php echo esc_attr($name('slots')); ?>" style="font-family:monospace"><?php echo esc_textarea(implode("\n", (array) $s['slots'])); ?></textarea>
                            <p class="description"><?php esc_html_e('One per line, 24-hour clock: 07:00-09:00. Invalid lines are dropped when saved.', 'ecare-health-services'); ?></p></td>
                    </tr>
                    <tr>
                        <th><label for="ecare-cut"><?php esc_html_e('Stop booking a slot', 'ecare-health-services'); ?></label></th>
                        <td><input type="number" id="ecare-cut" min="0" max="72" name="<?php echo esc_attr($name('cutoff_hours')); ?>" value="<?php echo esc_attr($s['cutoff_hours']); ?>" style="width:80px" /> <?php esc_html_e('hours before it starts', 'ecare-health-services'); ?></td>
                    </tr>
                    <tr>
                        <th><label for="ecare-cap"><?php esc_html_e('Orders per slot', 'ecare-health-services'); ?></label></th>
                        <td><input type="number" id="ecare-cap" min="0" name="<?php echo esc_attr($name('slot_capacity')); ?>" value="<?php echo esc_attr($s['slot_capacity']); ?>" style="width:80px" /> <?php esc_html_e('(0 = no limit)', 'ecare-health-services'); ?></td>
                    </tr>
                </table>

                <h2 class="title"><?php esc_html_e('Lab home page', 'ecare-health-services'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th><?php esc_html_e('Banner', 'ecare-health-services'); ?></th>
                        <td>
                            <input type="hidden" id="ecare-banner-id" name="<?php echo esc_attr($name('banner_id')); ?>" value="<?php echo (int) $s['banner_id']; ?>" />
                            <div id="ecare-banner-preview" style="margin-bottom:6px;"><?php if ($banner_url): ?><img src="<?php echo esc_url($banner_url); ?>" alt="" style="max-width:360px;height:auto;border-radius:6px;" /><?php endif; ?></div>
                            <button type="button" class="button" id="ecare-banner-choose"><?php esc_html_e('Choose image', 'ecare-health-services'); ?></button>
                            <button type="button" class="button-link" id="ecare-banner-remove" style="margin-left:8px;<?php echo $banner_url ? '' : 'display:none;'; ?>"><?php esc_html_e('Remove', 'ecare-health-services'); ?></button>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="ecare-banner-link"><?php esc_html_e('Banner link', 'ecare-health-services'); ?></label></th>
                        <td><input type="url" id="ecare-banner-link" class="regular-text" name="<?php echo esc_attr($name('banner_link')); ?>" value="<?php echo esc_attr($s['banner_link']); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="ecare-msgr"><?php esc_html_e('Messenger link', 'ecare-health-services'); ?></label></th>
                        <td><input type="url" id="ecare-msgr" class="regular-text" name="<?php echo esc_attr($name('messenger_link')); ?>" value="<?php echo esc_attr($s['messenger_link']); ?>" placeholder="https://m.me/…" />
                            <p class="description"><?php esc_html_e('Shows the "Order via Messenger" button when set.', 'ecare-health-services'); ?></p></td>
                    </tr>
                    <tr>
                        <th><label for="ecare-wa"><?php esc_html_e('WhatsApp number', 'ecare-health-services'); ?></label></th>
                        <td><input type="tel" id="ecare-wa" class="regular-text" name="<?php echo esc_attr($name('whatsapp_number')); ?>" value="<?php echo esc_attr($s['whatsapp_number']); ?>" placeholder="01XXXXXXXXX" />
                            <p class="description"><?php esc_html_e('Shows the "Order via WhatsApp" button when set. A Bangladeshi number can be written as 01XXXXXXXXX; it is saved with 880 in front.', 'ecare-health-services'); ?></p></td>
                    </tr>
                    <tr>
                        <th><label for="ecare-wa-msg"><?php esc_html_e('WhatsApp first message', 'ecare-health-services'); ?></label></th>
                        <td><input type="text" id="ecare-wa-msg" class="large-text" maxlength="300" name="<?php echo esc_attr($name('whatsapp_message')); ?>" value="<?php echo esc_attr($s['whatsapp_message']); ?>" />
                            <p class="description"><?php esc_html_e('Already typed in the chat when the patient opens it; they can change it before sending. Leave empty for a blank chat.', 'ecare-health-services'); ?></p></td>
                    </tr>
                    <tr>
                        <th><label for="ecare-hotline"><?php esc_html_e('Hotline', 'ecare-health-services'); ?></label></th>
                        <td><input type="text" id="ecare-hotline" class="regular-text" name="<?php echo esc_attr($name('hotline')); ?>" value="<?php echo esc_attr($s['hotline']); ?>" /></td>
                    </tr>
                    <?php foreach (array_values((array) $s['steps']) as $i => $step): ?>
                        <tr>
                            <th><?php echo esc_html(sprintf(__('How we work - step %d', 'ecare-health-services'), $i + 1)); ?></th>
                            <td>
                                <input type="text" class="regular-text" name="<?php echo esc_attr($name('steps')); ?>[<?php echo (int) $i; ?>][title]" value="<?php echo esc_attr($step['title']); ?>" /><br />
                                <textarea rows="2" class="large-text" name="<?php echo esc_attr($name('steps')); ?>[<?php echo (int) $i; ?>][text]" style="margin-top:4px;"><?php echo esc_textarea($step['text']); ?></textarea>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <script>
        (function () {
            var input = document.getElementById('ecare-banner-id'), preview = document.getElementById('ecare-banner-preview'), rm = document.getElementById('ecare-banner-remove');
            document.getElementById('ecare-banner-choose').addEventListener('click', function () {
                if (!window.wp || !wp.media) { return; }
                var f = wp.media({ title: <?php echo wp_json_encode(__('Lab banner', 'ecare-health-services')); ?>, library: { type: 'image' }, multiple: false });
                f.on('select', function () {
                    var a = f.state().get('selection').first().toJSON();
                    input.value = a.id;
                    preview.innerHTML = '';
                    var img = document.createElement('img');
                    img.src = (a.sizes && a.sizes.medium) ? a.sizes.medium.url : a.url;
                    img.style.maxWidth = '360px'; img.style.height = 'auto'; img.style.borderRadius = '6px';
                    preview.appendChild(img);
                    rm.style.display = '';
                });
                f.open();
            });
            rm.addEventListener('click', function () { input.value = 0; preview.innerHTML = ''; rm.style.display = 'none'; });
        })();
        </script>
        <?php
    }
}
