<?php
defined('ABSPATH') || exit;

/**
 * What a lab test is, as a patient reads it on the detail page:
 * single test or package, subtitle, other names, parameters, sample, fasting,
 * report time, who it is for, and an FAQ. Description is the post content and
 * the picture is the featured image.
 *
 *   _ecare_test_type      single | package
 *   _ecare_subtitle       one line under the name
 *   _ecare_also_known_as  other names
 *   _ecare_parameters     how many values the report gives
 *   _ecare_fasting        yes | no | '' (not stated)
 *   _ecare_report_min     report time, lower bound
 *   _ecare_report_max     upper bound, 0 when not a range
 *   _ecare_report_unit    hours | days
 *   _ecare_gender         all | men | women
 *   _ecare_age_min/_max   0 = no bound
 *   _ecare_faq            array of array(q, a)
 *
 * Test code, sample type and status keep their old meta keys (_test_code,
 * _sample_type, _test_status) and are still saved by ECare_CPT; this box only
 * shows them. _turnaround_days, which the current front end prints, is kept in
 * step with the report time.
 */
class ECare_Lab_Test_Info {

    const POST_TYPE = 'ecare_lab_test';

    const TYPE_SINGLE  = 'single';
    const TYPE_PACKAGE = 'package';

    const MAX_FAQ = 30;

    public static function init() {
        add_action('add_meta_boxes_' . self::POST_TYPE, array(__CLASS__, 'add_meta_boxes'));
        add_action('save_post_' . self::POST_TYPE, array(__CLASS__, 'save'), 10, 2);
        if (is_admin()) {
            add_filter('enter_title_here', array(__CLASS__, 'title_placeholder'), 10, 2);
        }
    }

    // -----------------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------------

    public static function type($id) {
        return get_post_meta((int) $id, '_ecare_test_type', true) === self::TYPE_PACKAGE ? self::TYPE_PACKAGE : self::TYPE_SINGLE;
    }

    public static function is_package($id) {
        return self::type($id) === self::TYPE_PACKAGE;
    }

    /**
     * Report time as array(min, max, unit). Tests saved before this box existed
     * fall back to their _turnaround_days.
     */
    public static function report_time($id) {
        $min  = (int) get_post_meta((int) $id, '_ecare_report_min', true);
        $max  = (int) get_post_meta((int) $id, '_ecare_report_max', true);
        $unit = get_post_meta((int) $id, '_ecare_report_unit', true) === 'hours' ? 'hours' : 'days';
        if ($min <= 0) {
            $legacy = (int) get_post_meta((int) $id, '_turnaround_days', true);
            return $legacy > 0 ? array($legacy, 0, 'days') : array(0, 0, 'days');
        }
        return array($min, $max > $min ? $max : 0, $unit);
    }

    /** "12 hours", "1 day", "5-7 days", or '' when not set. */
    public static function report_label($id) {
        list($min, $max, $unit) = self::report_time($id);
        if ($min <= 0) {
            return '';
        }
        if ($max > $min) {
            /* translators: 1: from, 2: to */
            return $unit === 'hours'
                ? sprintf(__('%1$d-%2$d hours', 'ecare-health-services'), $min, $max)
                : sprintf(__('%1$d-%2$d days', 'ecare-health-services'), $min, $max);
        }
        return $unit === 'hours'
            ? sprintf(_n('%d hour', '%d hours', $min, 'ecare-health-services'), $min)
            : sprintf(_n('%d day', '%d days', $min, 'ecare-health-services'), $min);
    }

    /** "Men & Women, 1-70 years", "Women, 18+ years", "Men", or ''. */
    public static function available_for_label($id) {
        $gender = get_post_meta((int) $id, '_ecare_gender', true);
        $min    = (int) get_post_meta((int) $id, '_ecare_age_min', true);
        $max    = (int) get_post_meta((int) $id, '_ecare_age_max', true);

        $who = array(
            'men'   => __('Men', 'ecare-health-services'),
            'women' => __('Women', 'ecare-health-services'),
        )[$gender] ?? __('Men & Women', 'ecare-health-services');

        if ($min > 0 && $max > 0) {
            $age = sprintf(__('%1$d-%2$d years', 'ecare-health-services'), $min, $max);
        } elseif ($min > 0) {
            $age = sprintf(__('%d+ years', 'ecare-health-services'), $min);
        } elseif ($max > 0) {
            $age = sprintf(__('up to %d years', 'ecare-health-services'), $max);
        } else {
            $age = '';
        }
        if ($gender === '' && $age === '') {
            return '';
        }
        return $age === '' ? $who : $who . ', ' . $age;
    }

    /** @return array<int, array{q:string,a:string}> */
    public static function faq($id) {
        $raw = get_post_meta((int) $id, '_ecare_faq', true);
        return is_array($raw) ? array_values($raw) : array();
    }

    /** Everything the front end will need, in one array. */
    public static function details($id) {
        $fasting = get_post_meta((int) $id, '_ecare_fasting', true);
        return array(
            'type'          => self::type($id),
            'code'          => (string) get_post_meta((int) $id, '_test_code', true),
            'subtitle'      => (string) get_post_meta((int) $id, '_ecare_subtitle', true),
            'also_known_as' => (string) get_post_meta((int) $id, '_ecare_also_known_as', true),
            'parameters'    => (int) get_post_meta((int) $id, '_ecare_parameters', true),
            'sample'        => (string) get_post_meta((int) $id, '_sample_type', true),
            'fasting'       => in_array($fasting, array('yes', 'no'), true) ? $fasting : '',
            'report'        => self::report_label($id),
            'available_for' => self::available_for_label($id),
            'faq'           => self::faq($id),
        );
    }

    // -----------------------------------------------------------------------
    // Cleaning input
    // -----------------------------------------------------------------------

    /** Posted report fields -> array(min, max, unit). A max not above min is dropped. */
    public static function clean_report($min, $max, $unit) {
        $min  = max(0, (int) $min);
        $max  = max(0, (int) $max);
        $unit = $unit === 'hours' ? 'hours' : 'days';
        if ($min === 0 || $max <= $min) {
            $max = 0;
        }
        return array($min, $max, $unit);
    }

    /** Whole days for the old front end: 12 hours still reads "1 day", never 0. */
    public static function turnaround_days($min, $unit) {
        if ($min <= 0) {
            return 0;
        }
        return $unit === 'hours' ? max(1, (int) ceil($min / 24)) : $min;
    }

    /** Ages 0-120; a max below the min is dropped. */
    public static function clean_ages($min, $max) {
        $min = min(120, max(0, (int) $min));
        $max = min(120, max(0, (int) $max));
        if ($max > 0 && $max < $min) {
            $max = 0;
        }
        return array($min, $max);
    }

    /** Pair up posted questions and answers; rows without a question go. */
    public static function clean_faq($questions, $answers) {
        $out = array();
        foreach (array_values((array) $questions) as $i => $q) {
            $q = sanitize_text_field((string) $q);
            if ($q === '') {
                continue;
            }
            $a     = sanitize_textarea_field((string) (array_values((array) $answers)[$i] ?? ''));
            $out[] = array('q' => $q, 'a' => $a);
            if (count($out) >= self::MAX_FAQ) {
                break;
            }
        }
        return $out;
    }

    // -----------------------------------------------------------------------
    // Saving
    // -----------------------------------------------------------------------

    public static function save($post_id, $post) {
        if (!isset($_POST['ecare_lab_test_info_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ecare_lab_test_info_nonce'])), 'ecare_lab_test_info')) {
            return;
        }
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($post_id)) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        $in = wp_unslash($_POST);

        update_post_meta($post_id, '_ecare_test_type', ($in['_ecare_test_type'] ?? '') === self::TYPE_PACKAGE ? self::TYPE_PACKAGE : self::TYPE_SINGLE);
        update_post_meta($post_id, '_ecare_subtitle', sanitize_text_field($in['_ecare_subtitle'] ?? ''));
        update_post_meta($post_id, '_ecare_also_known_as', sanitize_text_field($in['_ecare_also_known_as'] ?? ''));
        update_post_meta($post_id, '_ecare_parameters', max(0, (int) ($in['_ecare_parameters'] ?? 0)));

        $fasting = sanitize_key($in['_ecare_fasting'] ?? '');
        update_post_meta($post_id, '_ecare_fasting', in_array($fasting, array('yes', 'no'), true) ? $fasting : '');

        list($min, $max, $unit) = self::clean_report($in['_ecare_report_min'] ?? 0, $in['_ecare_report_max'] ?? 0, $in['_ecare_report_unit'] ?? 'days');
        update_post_meta($post_id, '_ecare_report_min', $min);
        update_post_meta($post_id, '_ecare_report_max', $max);
        update_post_meta($post_id, '_ecare_report_unit', $unit);
        // The form is pre-filled from _turnaround_days, so an untouched old
        // test posts its old value back; an emptied field really means none.
        if ($min > 0) {
            update_post_meta($post_id, '_turnaround_days', self::turnaround_days($min, $unit));
        } else {
            delete_post_meta($post_id, '_turnaround_days');
        }

        $gender = sanitize_key($in['_ecare_gender'] ?? 'all');
        update_post_meta($post_id, '_ecare_gender', in_array($gender, array('men', 'women'), true) ? $gender : 'all');
        list($age_min, $age_max) = self::clean_ages($in['_ecare_age_min'] ?? 0, $in['_ecare_age_max'] ?? 0);
        update_post_meta($post_id, '_ecare_age_min', $age_min);
        update_post_meta($post_id, '_ecare_age_max', $age_max);

        update_post_meta($post_id, '_ecare_faq', self::clean_faq($in['ecare_faq_q'] ?? array(), $in['ecare_faq_a'] ?? array()));
    }

    // -----------------------------------------------------------------------
    // Edit screen
    // -----------------------------------------------------------------------

    public static function title_placeholder($text, $post) {
        return ($post && $post->post_type === self::POST_TYPE)
            ? __('Test name, e.g. Dengue Antibody (IgG & IgM)', 'ecare-health-services')
            : $text;
    }

    public static function add_meta_boxes() {
        add_meta_box('ecare_lab_test_info', __('Test Information', 'ecare-health-services'), array(__CLASS__, 'render_info_box'), self::POST_TYPE, 'normal', 'high');
        add_meta_box('ecare_lab_test_faq', __('Frequently Asked Questions', 'ecare-health-services'), array(__CLASS__, 'render_faq_box'), self::POST_TYPE, 'normal', 'low');
        remove_meta_box('slugdiv', self::POST_TYPE, 'normal');
    }

    public static function render_info_box($post) {
        wp_nonce_field('ecare_lab_test_info', 'ecare_lab_test_info_nonce');
        $id = $post->ID;
        $d  = self::details($id);
        list($r_min, $r_max, $r_unit) = self::report_time($id);
        $gender  = get_post_meta($id, '_ecare_gender', true) ?: 'all';
        $age_min = (int) get_post_meta($id, '_ecare_age_min', true);
        $age_max = (int) get_post_meta($id, '_ecare_age_max', true);
        $status  = get_post_meta($id, '_test_status', true) ?: 'active';
        ?>
        <style>
            #ecare_lab_test_info .ecare-inline{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
            #ecare_lab_test_info .ecare-inline input[type=number]{width:80px}
        </style>
        <table class="form-table" role="presentation">
            <tr>
                <th><?php esc_html_e('Type', 'ecare-health-services'); ?></th>
                <td class="ecare-inline">
                    <label><input type="radio" name="_ecare_test_type" value="single" <?php checked($d['type'], self::TYPE_SINGLE); ?> /> <?php esc_html_e('Single test', 'ecare-health-services'); ?></label>
                    <label><input type="radio" name="_ecare_test_type" value="package" <?php checked($d['type'], self::TYPE_PACKAGE); ?> /> <?php esc_html_e('Health package', 'ecare-health-services'); ?></label>
                </td>
            </tr>
            <tr>
                <th><label for="ecare-test-code"><?php esc_html_e('Test code', 'ecare-health-services'); ?></label></th>
                <td><input type="text" id="ecare-test-code" name="_test_code" class="regular-text" value="<?php echo esc_attr($d['code']); ?>" /></td>
            </tr>
            <tr>
                <th><label for="ecare-test-subtitle"><?php esc_html_e('Subtitle', 'ecare-health-services'); ?></label></th>
                <td><input type="text" id="ecare-test-subtitle" name="_ecare_subtitle" class="large-text" value="<?php echo esc_attr($d['subtitle']); ?>" placeholder="<?php esc_attr_e('One line shown under the name', 'ecare-health-services'); ?>" /></td>
            </tr>
            <tr>
                <th><label for="ecare-test-aka"><?php esc_html_e('Also known as', 'ecare-health-services'); ?></label></th>
                <td><input type="text" id="ecare-test-aka" name="_ecare_also_known_as" class="large-text" value="<?php echo esc_attr($d['also_known_as']); ?>" /></td>
            </tr>
            <tr>
                <th><label for="ecare-test-params"><?php esc_html_e('Parameters', 'ecare-health-services'); ?></label></th>
                <td><input type="number" min="0" id="ecare-test-params" name="_ecare_parameters" value="<?php echo $d['parameters'] ? (int) $d['parameters'] : ''; ?>" style="width:80px" />
                    <p class="description"><?php esc_html_e('How many values the report gives, e.g. 1 for FBS, 5 for a lipid profile.', 'ecare-health-services'); ?></p></td>
            </tr>
            <tr>
                <th><label for="ecare-test-sample"><?php esc_html_e('Sample', 'ecare-health-services'); ?></label></th>
                <td>
                    <input type="text" id="ecare-test-sample" name="_sample_type" class="regular-text" list="ecare-sample-types" value="<?php echo esc_attr($d['sample']); ?>" />
                    <datalist id="ecare-sample-types">
                        <?php foreach (array('Blood', 'Serum', 'Plasma', 'Urine', 'Stool', 'Swab', 'Sputum', 'Semen', 'Tissue') as $s): ?>
                            <option value="<?php echo esc_attr($s); ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Fasting', 'ecare-health-services'); ?></th>
                <td class="ecare-inline">
                    <label><input type="radio" name="_ecare_fasting" value="yes" <?php checked($d['fasting'], 'yes'); ?> /> <?php esc_html_e('Required', 'ecare-health-services'); ?></label>
                    <label><input type="radio" name="_ecare_fasting" value="no" <?php checked($d['fasting'], 'no'); ?> /> <?php esc_html_e('Not required', 'ecare-health-services'); ?></label>
                    <label><input type="radio" name="_ecare_fasting" value="" <?php checked($d['fasting'], ''); ?> /> <?php esc_html_e('Not stated', 'ecare-health-services'); ?></label>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Report in', 'ecare-health-services'); ?></th>
                <td>
                    <div class="ecare-inline">
                        <input type="number" min="0" name="_ecare_report_min" value="<?php echo $r_min ? (int) $r_min : ''; ?>" aria-label="<?php esc_attr_e('From', 'ecare-health-services'); ?>" />
                        <span>–</span>
                        <input type="number" min="0" name="_ecare_report_max" value="<?php echo $r_max ? (int) $r_max : ''; ?>" aria-label="<?php esc_attr_e('To (optional)', 'ecare-health-services'); ?>" />
                        <select name="_ecare_report_unit">
                            <option value="hours" <?php selected($r_unit, 'hours'); ?>><?php esc_html_e('hours', 'ecare-health-services'); ?></option>
                            <option value="days" <?php selected($r_unit, 'days'); ?>><?php esc_html_e('days', 'ecare-health-services'); ?></option>
                        </select>
                    </div>
                    <p class="description"><?php esc_html_e('Leave the second box empty for a single value ("12 hours"); fill it for a range ("5-7 days").', 'ecare-health-services'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Available for', 'ecare-health-services'); ?></th>
                <td>
                    <div class="ecare-inline">
                        <select name="_ecare_gender">
                            <option value="all" <?php selected($gender, 'all'); ?>><?php esc_html_e('Men & Women', 'ecare-health-services'); ?></option>
                            <option value="men" <?php selected($gender, 'men'); ?>><?php esc_html_e('Men', 'ecare-health-services'); ?></option>
                            <option value="women" <?php selected($gender, 'women'); ?>><?php esc_html_e('Women', 'ecare-health-services'); ?></option>
                        </select>
                        <span><?php esc_html_e('age', 'ecare-health-services'); ?></span>
                        <input type="number" min="0" max="120" name="_ecare_age_min" value="<?php echo $age_min ?: ''; ?>" />
                        <span>–</span>
                        <input type="number" min="0" max="120" name="_ecare_age_max" value="<?php echo $age_max ?: ''; ?>" />
                        <span><?php esc_html_e('years', 'ecare-health-services'); ?></span>
                    </div>
                </td>
            </tr>
            <tr>
                <th><label for="ecare-test-status"><?php esc_html_e('Status', 'ecare-health-services'); ?></label></th>
                <td>
                    <select id="ecare-test-status" name="_test_status">
                        <option value="active" <?php selected($status, 'active'); ?>><?php esc_html_e('Active', 'ecare-health-services'); ?></option>
                        <option value="inactive" <?php selected($status, 'inactive'); ?>><?php esc_html_e('Inactive', 'ecare-health-services'); ?></option>
                    </select>
                </td>
            </tr>
        </table>
        <p class="description" style="margin-top:6px;"><?php esc_html_e('The description goes in the editor above; the picture is the Featured image.', 'ecare-health-services'); ?></p>
        <?php
    }

    public static function render_faq_box($post) {
        $rows = self::faq($post->ID);
        ?>
        <style>
            #ecare-faq-rows .ecare-faq-row{border:1px solid #dcdcde;border-radius:4px;padding:10px;margin-bottom:10px;background:#fff}
            #ecare-faq-rows .ecare-faq-row input,#ecare-faq-rows .ecare-faq-row textarea{width:100%}
            #ecare-faq-rows .ecare-faq-tools{display:flex;gap:6px;justify-content:flex-end;margin-top:6px}
        </style>
        <div id="ecare-faq-rows">
            <?php foreach ($rows as $row) { self::faq_row($row['q'], $row['a']); } ?>
        </div>
        <template id="ecare-faq-template"><?php self::faq_row('', ''); ?></template>
        <button type="button" class="button" id="ecare-faq-add">+ <?php esc_html_e('Add question', 'ecare-health-services'); ?></button>
        <script>
        (function () {
            var list = document.getElementById('ecare-faq-rows');
            document.getElementById('ecare-faq-add').addEventListener('click', function () {
                list.appendChild(document.getElementById('ecare-faq-template').content.cloneNode(true));
                list.lastElementChild.querySelector('input').focus();
            });
            list.addEventListener('click', function (e) {
                var row = e.target.closest('.ecare-faq-row');
                if (!row) { return; }
                if (e.target.classList.contains('ecare-faq-remove')) { row.remove(); }
                if (e.target.classList.contains('ecare-faq-up') && row.previousElementSibling) { list.insertBefore(row, row.previousElementSibling); }
                if (e.target.classList.contains('ecare-faq-down') && row.nextElementSibling) { list.insertBefore(row.nextElementSibling, row); }
            });
        })();
        </script>
        <?php
    }

    private static function faq_row($q, $a) {
        ?>
        <div class="ecare-faq-row">
            <input type="text" name="ecare_faq_q[]" value="<?php echo esc_attr($q); ?>" placeholder="<?php esc_attr_e('Question', 'ecare-health-services'); ?>" />
            <textarea name="ecare_faq_a[]" rows="3" placeholder="<?php esc_attr_e('Answer', 'ecare-health-services'); ?>" style="margin-top:6px;"><?php echo esc_textarea($a); ?></textarea>
            <div class="ecare-faq-tools">
                <button type="button" class="button button-small ecare-faq-up" aria-label="<?php esc_attr_e('Move up', 'ecare-health-services'); ?>">↑</button>
                <button type="button" class="button button-small ecare-faq-down" aria-label="<?php esc_attr_e('Move down', 'ecare-health-services'); ?>">↓</button>
                <button type="button" class="button button-small button-link-delete ecare-faq-remove"><?php esc_html_e('Remove', 'ecare-health-services'); ?></button>
            </div>
        </div>
        <?php
    }
}
