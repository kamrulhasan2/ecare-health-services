<?php
defined('ABSPATH') || exit;

/**
 * A lab test's labs, prices and reach, and the bridge to the old front end.
 *
 * Where a test is offered now follows from its offerings (ECare_Lab_Offerings):
 * every active lab on it, in every area that lab covers. The earlier
 * one-provider-per-test link (_ecare_provider_id / _ecare_coverage_mode) is
 * read only to pre-fill the offerings table once.
 *
 * Until the new front end takes over, saving also writes the old
 * comma-separated meta (_lab_provider, _division, _district, _area) and _price
 * from the offerings, so the current catalogue keeps working.
 */
class ECare_Lab_Tests {

    const POST_TYPE = 'ecare_lab_test';

    /** The free-text fields the offerings replace. */
    const LEGACY_KEYS = array('_lab_provider', '_division', '_district', '_area');

    public static function init() {
        add_filter('wp_insert_post_data', array(__CLASS__, 'guard_publish'), 10, 2);
        if (is_admin()) {
            add_action('admin_notices', array(__CLASS__, 'render_notice'));
        }
    }

    // -----------------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------------

    /** On the new structure: it has at least one lab row. */
    public static function is_linked($test_id) {
        return ECare_Lab_Offerings::has_any($test_id);
    }

    /** Whether the test still carries old free-text location data. */
    public static function has_legacy($test_id) {
        foreach (self::LEGACY_KEYS as $key) {
            if (trim((string) get_post_meta((int) $test_id, $key, true)) !== '') {
                return true;
            }
        }
        return false;
    }

    /** Every area id reached by the test's bookable labs. */
    public static function effective_area_ids($test_id) {
        $areas = array();
        foreach (ECare_Lab_Offerings::available_for_test($test_id) as $row) {
            foreach (ECare_Locations::expand_to_areas(ECare_Lab_Providers::coverage_term_ids((int) $row->provider_id)) as $a) {
                $areas[$a] = true;
            }
        }
        $areas = array_keys($areas);
        sort($areas);
        return $areas;
    }

    /** Can a patient in this area book the test from at least one lab? */
    public static function serves_area($test_id, $area_id) {
        foreach (ECare_Lab_Offerings::available_for_test($test_id) as $row) {
            if (ECare_Lab_Providers::covers_area((int) $row->provider_id, $area_id)) {
                return true;
            }
        }
        return false;
    }

    /**
     * For the catalogue list: "Popular ৳400 · LabAid ৳350 (off)". Inactive rows
     * and rows whose lab is not bookable are marked, so the admin sees why a
     * lab is missing on the site.
     */
    public static function labs_summary($test_id) {
        $parts = array();
        foreach (ECare_Lab_Offerings::for_test($test_id) as $row) {
            $name = get_the_title((int) $row->provider_id);
            $txt  = $name . ' ৳' . number_format_i18n(ECare_Lab_Offerings::effective_price($row), 0);
            if ($row->status !== ECare_Lab_Offerings::STATUS_ACTIVE) {
                $txt .= ' ' . __('(off)', 'ecare-health-services');
            } elseif (!ECare_Lab_Providers::is_active((int) $row->provider_id)) {
                $txt .= ' ' . __('(lab inactive)', 'ecare-health-services');
            }
            $parts[] = $txt;
        }
        return implode(' · ', $parts);
    }

    // -----------------------------------------------------------------------
    // Old meta, kept in step until the front end moves over
    // -----------------------------------------------------------------------

    /**
     * The four legacy comma lists and _price, from the bookable offerings.
     * Names are de-duplicated by name_key.
     *
     * @return array<string,string>
     */
    public static function legacy_values($test_id) {
        $rows  = ECare_Lab_Offerings::available_for_test($test_id);
        $names = array();
        foreach ($rows as $row) {
            $names[] = get_the_title((int) $row->provider_id);
        }

        $lists = array('_division' => array(), '_district' => array(), '_area' => array());
        foreach (self::effective_area_ids($test_id) as $area_id) {
            $area     = get_term($area_id, ECare_Locations::TAXONOMY);
            $district = $area && !is_wp_error($area) ? get_term((int) $area->parent, ECare_Locations::TAXONOMY) : null;
            $division = $district && !is_wp_error($district) ? get_term((int) $district->parent, ECare_Locations::TAXONOMY) : null;
            foreach (array('_area' => $area, '_district' => $district, '_division' => $division) as $key => $term) {
                if ($term && !is_wp_error($term)) {
                    $lists[$key][ECare_Locations::name_key($term->name)] = $term->name;
                }
            }
        }

        return array(
            '_lab_provider' => implode(', ', $names),
            '_division'     => implode(', ', $lists['_division']),
            '_district'     => implode(', ', $lists['_district']),
            '_area'         => implode(', ', $lists['_area']),
            '_price'        => $rows ? (string) ECare_Lab_Offerings::effective_price($rows[0]) : '',
        );
    }

    /**
     * Write the legacy meta from the offerings. A test with no offering rows at
     * all keeps its old data, so editing an old test does not take it off the site.
     */
    public static function sync_legacy($test_id) {
        if (!ECare_Lab_Offerings::has_any($test_id)) {
            return;
        }
        foreach (self::legacy_values($test_id) as $key => $value) {
            update_post_meta((int) $test_id, $key, $value);
        }
        // The one-provider link from before offerings is superseded.
        delete_post_meta((int) $test_id, '_ecare_provider_id');
        delete_post_meta((int) $test_id, '_ecare_coverage_mode');
        wp_set_object_terms((int) $test_id, array(), ECare_Locations::TAXONOMY, false);
    }

    // -----------------------------------------------------------------------
    // Publishing
    // -----------------------------------------------------------------------

    /**
     * A test with no lab row and no old location data would be invisible to
     * patients, so publishing it from the edit form is held as a draft.
     */
    public static function guard_publish($data, $postarr) {
        if (($data['post_type'] ?? '') !== self::POST_TYPE || ($data['post_status'] ?? '') !== 'publish') {
            return $data;
        }
        if (!isset($_POST['ecare_offer_present'])) {
            return $data;   // not the edit form
        }
        if (ECare_Lab_Offerings::clean_rows(ECare_Lab_Offerings::posted_rows())) {
            return $data;
        }
        $id = (int) ($postarr['ID'] ?? 0);
        if ($id && self::has_legacy($id)) {
            return $data;
        }
        $data['post_status'] = 'draft';
        if (function_exists('get_current_user_id') && get_current_user_id()) {
            set_transient('ecare_lab_test_notice_' . get_current_user_id(), 'no_lab', 60);
        }
        return $data;
    }

    public static function render_notice() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->post_type !== self::POST_TYPE) {
            return;
        }
        $key = 'ecare_lab_test_notice_' . get_current_user_id();
        if (!get_transient($key)) {
            return;
        }
        delete_transient($key);
        echo '<div class="notice notice-error"><p>'
            . esc_html__('Add at least one lab with its price before publishing this test. It has been saved as a draft.', 'ecare-health-services')
            . '</p></div>';
    }

    // -----------------------------------------------------------------------
    // Edit screen
    // -----------------------------------------------------------------------

    /** The old typed-in lists, shown read-only on tests that have no lab rows yet. */
    public static function render_legacy_note($post) {
        if (self::is_linked($post->ID) || !self::has_legacy($post->ID)) {
            return;
        }
        ?>
        <div style="background:#fcf9e8;border-left:4px solid #dba617;padding:8px 12px;margin-top:12px;">
            <p style="margin:0 0 6px;"><?php esc_html_e('Old location data. These typed-in lists stay in use until labs are added above; adding them replaces the lists.', 'ecare-health-services'); ?></p>
            <?php foreach (array('_lab_provider' => __('Provider', 'ecare-health-services'), '_price' => __('Price', 'ecare-health-services'), '_division' => __('Division', 'ecare-health-services'), '_district' => __('District', 'ecare-health-services'), '_area' => __('Area', 'ecare-health-services')) as $key => $label):
                $value = (string) get_post_meta($post->ID, $key, true);
                if ($value === '') { continue; }
                $short = function_exists('mb_strimwidth') ? mb_strimwidth($value, 0, 220, '…', 'UTF-8') : substr($value, 0, 220);
                ?>
                <div style="font-size:12px;"><strong><?php echo esc_html($label); ?>:</strong> <span title="<?php echo esc_attr($value); ?>"><?php echo esc_html($short); ?></span></div>
            <?php endforeach; ?>
        </div>
        <?php
    }
}
