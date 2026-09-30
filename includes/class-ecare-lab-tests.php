<?php
defined('ABSPATH') || exit;

/**
 * Where a lab test is offered: one provider per test entry, and either every
 * area that provider serves or a chosen subset of them.
 *
 *   _ecare_provider_id    the ecare_lab_provider post
 *   _ecare_coverage_mode  'provider' (default) or 'custom'
 *   ecare_location terms  the subset, only in custom mode
 *
 * The subset is always read through the provider's coverage, so a test can
 * never be offered somewhere its provider does not go - even after the
 * provider's coverage is cut back.
 *
 * Until the front-end filter moves over (step 4), saving also writes the old
 * comma-separated meta (_lab_provider, _division, _district, _area) from this
 * data, so the catalogue keeps working if these steps ship on their own.
 */
class ECare_Lab_Tests {

    const POST_TYPE = 'ecare_lab_test';

    const MODE_PROVIDER = 'provider';
    const MODE_CUSTOM   = 'custom';

    /** The free-text fields this replaces. */
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

    public static function provider_id($test_id) {
        return (int) get_post_meta((int) $test_id, '_ecare_provider_id', true);
    }

    public static function mode($test_id) {
        return get_post_meta((int) $test_id, '_ecare_coverage_mode', true) === self::MODE_CUSTOM
            ? self::MODE_CUSTOM
            : self::MODE_PROVIDER;
    }

    /** Linked to a provider, i.e. already on the new structure. */
    public static function is_linked($test_id) {
        $pid = self::provider_id($test_id);
        $p   = $pid ? get_post($pid) : null;
        return $p && $p->post_type === ECare_Lab_Providers::POST_TYPE;
    }

    /** The raw custom subset (district and area ids) stored on the test. */
    public static function custom_term_ids($test_id) {
        $ids = wp_get_object_terms((int) $test_id, ECare_Locations::TAXONOMY, array('fields' => 'ids'));
        return is_wp_error($ids) ? array() : array_map('intval', $ids);
    }

    /**
     * Every area id the test is offered in: the provider's areas, narrowed to
     * the custom subset when there is one.
     *
     * @return int[] sorted
     */
    public static function effective_area_ids($test_id) {
        $pid = self::provider_id($test_id);
        if (!$pid) {
            return array();
        }
        $areas = ECare_Locations::expand_to_areas(ECare_Lab_Providers::coverage_term_ids($pid));
        if (self::mode($test_id) === self::MODE_CUSTOM) {
            $areas = array_values(array_intersect($areas, ECare_Locations::expand_to_areas(self::custom_term_ids($test_id))));
        }
        sort($areas);
        return $areas;
    }

    /** Is the test offered in this area? */
    public static function serves_area($test_id, $area_id) {
        $pid = self::provider_id($test_id);
        if (!$pid || !ECare_Lab_Providers::covers_area($pid, $area_id)) {
            return false;
        }
        if (self::mode($test_id) !== self::MODE_CUSTOM) {
            return true;
        }
        $area   = get_term((int) $area_id, ECare_Locations::TAXONOMY);
        $custom = self::custom_term_ids($test_id);
        return $area && !is_wp_error($area)
            && (in_array((int) $area->term_id, $custom, true) || in_array((int) $area->parent, $custom, true));
    }

    /**
     * One line for the catalogue list. Linked: "Dhaka: 2 areas · Chattogram:
     * 14 areas". Not linked: empty string, the caller shows the warning.
     */
    public static function location_summary($test_id) {
        $by_district = array();
        foreach (self::effective_area_ids($test_id) as $area_id) {
            $area = get_term($area_id, ECare_Locations::TAXONOMY);
            if ($area && !is_wp_error($area)) {
                $by_district[(int) $area->parent] = ($by_district[(int) $area->parent] ?? 0) + 1;
            }
        }
        $parts = array();
        foreach ($by_district as $district_id => $n) {
            $district = get_term($district_id, ECare_Locations::TAXONOMY);
            $name     = ($district && !is_wp_error($district)) ? $district->name : '?';
            /* translators: 1: district name, 2: number of areas */
            $parts[] = sprintf(_n('%1$s: %2$d area', '%1$s: %2$d areas', $n, 'ecare-health-services'), $name, $n);
        }
        return implode(' · ', $parts);
    }

    // -----------------------------------------------------------------------
    // Old meta, kept in step with the new data until the front end moves over
    // -----------------------------------------------------------------------

    /**
     * The four legacy comma lists, derived from the provider and the areas the
     * test is effectively offered in. Names are de-duplicated by name_key.
     *
     * @return array<string,string>
     */
    public static function legacy_values($test_id) {
        $pid      = self::provider_id($test_id);
        $provider = $pid ? get_post($pid) : null;
        $lists    = array('_division' => array(), '_district' => array(), '_area' => array());

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
            '_lab_provider' => $provider ? $provider->post_title : '',
            '_division'     => implode(', ', $lists['_division']),
            '_district'     => implode(', ', $lists['_district']),
            '_area'         => implode(', ', $lists['_area']),
        );
    }

    public static function sync_legacy($test_id) {
        foreach (self::legacy_values($test_id) as $key => $value) {
            update_post_meta((int) $test_id, $key, $value);
        }
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

    // -----------------------------------------------------------------------
    // Saving (called from ECare_CPT::save_meta_boxes after its nonce check)
    // -----------------------------------------------------------------------

    public static function save_location($test_id) {
        if (wp_is_post_revision($test_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
            return;
        }
        $pid = (int) wp_unslash($_POST['_ecare_provider_id'] ?? 0);
        $p   = $pid ? get_post($pid) : null;
        if (!$p || $p->post_type !== ECare_Lab_Providers::POST_TYPE) {
            // No provider chosen. An old test keeps its old data untouched, so
            // editing its price does not take it off the site.
            delete_post_meta($test_id, '_ecare_provider_id');
            delete_post_meta($test_id, '_ecare_coverage_mode');
            wp_set_object_terms($test_id, array(), ECare_Locations::TAXONOMY, false);
            return;
        }

        $mode = sanitize_key(wp_unslash($_POST['_ecare_coverage_mode'] ?? '')) === self::MODE_CUSTOM
            ? self::MODE_CUSTOM
            : self::MODE_PROVIDER;

        update_post_meta($test_id, '_ecare_provider_id', $pid);
        update_post_meta($test_id, '_ecare_coverage_mode', $mode);

        $custom = array();
        if ($mode === self::MODE_CUSTOM) {
            $picked = isset($_POST['ecare_test_coverage']) ? array_map('intval', (array) wp_unslash($_POST['ecare_test_coverage'])) : array();
            $custom = ECare_Lab_Providers::normalize_coverage($picked);
        }
        wp_set_object_terms($test_id, $custom, ECare_Locations::TAXONOMY, false);

        self::sync_legacy($test_id);
    }

    /**
     * A new test with neither a provider nor old location data would be
     * invisible to patients, so publishing it is held as a draft.
     */
    public static function guard_publish($data, $postarr) {
        if (($data['post_type'] ?? '') !== self::POST_TYPE || ($data['post_status'] ?? '') !== 'publish') {
            return $data;
        }
        // Only the edit form; imports and code paths that never show it are left alone.
        if (!isset($_POST['ecare_lab_test_meta_nonce'])) {
            return $data;
        }
        $pid = (int) wp_unslash($_POST['_ecare_provider_id'] ?? 0);
        $p   = $pid ? get_post($pid) : null;
        if ($p && $p->post_type === ECare_Lab_Providers::POST_TYPE) {
            return $data;
        }
        $id = (int) ($postarr['ID'] ?? 0);
        if ($id && self::has_legacy($id)) {
            return $data;
        }
        $data['post_status'] = 'draft';
        if (function_exists('get_current_user_id') && get_current_user_id()) {
            set_transient('ecare_lab_test_notice_' . get_current_user_id(), 'no_provider', 60);
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
            . esc_html__('Choose a lab provider before publishing this test. It has been saved as a draft.', 'ecare-health-services')
            . '</p></div>';
    }

    // -----------------------------------------------------------------------
    // Edit screen fields (rendered inside ECare_CPT::render_lab_test_meta)
    // -----------------------------------------------------------------------

    public static function render_location_fields($post) {
        $current  = self::provider_id($post->ID);
        $mode     = self::mode($post->ID);
        $selected = $mode === self::MODE_CUSTOM ? self::custom_term_ids($post->ID) : array();

        $providers = get_posts(array(
            'post_type'      => ECare_Lab_Providers::POST_TYPE,
            'post_status'    => array('publish', 'draft', 'private'),
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ));

        // What each provider reaches, so the tree can show only those places.
        $scope = array();
        foreach ($providers as $p) {
            $areas     = ECare_Locations::expand_to_areas(ECare_Lab_Providers::coverage_term_ids($p->ID));
            $districts = array();
            foreach ($areas as $a) {
                $t = get_term($a, ECare_Locations::TAXONOMY);
                if ($t && !is_wp_error($t)) {
                    $districts[(int) $t->parent] = true;
                }
            }
            // A whole district with no areas yet still shows, with its note.
            foreach (ECare_Lab_Providers::coverage_term_ids($p->ID) as $id) {
                if (ECare_Locations::get_level($id) === ECare_Locations::LEVEL_DISTRICT) {
                    $districts[$id] = true;
                }
            }
            $scope[$p->ID] = array('areas' => $areas, 'districts' => array_keys($districts));
        }
        ?>
        <tr>
            <th><label for="ecare-test-provider"><?php esc_html_e('Lab Provider', 'ecare-health-services'); ?></label></th>
            <td>
                <select name="_ecare_provider_id" id="ecare-test-provider">
                    <option value="0"><?php esc_html_e('— Select provider —', 'ecare-health-services'); ?></option>
                    <?php foreach ($providers as $p):
                        $flags = array();
                        if ($p->post_status !== 'publish') { $flags[] = __('draft', 'ecare-health-services'); }
                        if (get_post_meta($p->ID, '_ecare_provider_status', true) === ECare_Lab_Providers::STATUS_INACTIVE) { $flags[] = __('inactive', 'ecare-health-services'); }
                        ?>
                        <option value="<?php echo (int) $p->ID; ?>" <?php selected($current, $p->ID); ?>>
                            <?php echo esc_html($p->post_title . ($flags ? ' (' . implode(', ', $flags) . ')' : '')); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <a href="<?php echo esc_url(admin_url(ECare_Lab_Providers::menu_slug())); ?>" target="_blank" style="margin-left:8px;"><?php esc_html_e('Manage providers', 'ecare-health-services'); ?></a>
                <p class="description"><?php esc_html_e('One provider per test. The same test from another provider is a separate entry with its own price.', 'ecare-health-services'); ?></p>
            </td>
        </tr>
        <tr>
            <th><?php esc_html_e('Offered in', 'ecare-health-services'); ?></th>
            <td>
                <fieldset>
                    <label style="display:block;margin-bottom:4px;">
                        <input type="radio" name="_ecare_coverage_mode" value="provider" <?php checked($mode, self::MODE_PROVIDER); ?> />
                        <?php esc_html_e('Every area this provider serves', 'ecare-health-services'); ?>
                    </label>
                    <label style="display:block;">
                        <input type="radio" name="_ecare_coverage_mode" value="custom" <?php checked($mode, self::MODE_CUSTOM); ?> />
                        <?php esc_html_e('Only some of those areas', 'ecare-health-services'); ?>
                    </label>
                </fieldset>
                <p class="description" id="ecare-test-reach"></p>
                <div id="ecare-test-custom" style="margin-top:10px;<?php echo $mode === self::MODE_CUSTOM ? '' : 'display:none;'; ?>">
                    <?php ECare_Locations::render_checkbox_tree($selected, 'ecare_test_coverage', 'ecare-test-cov'); ?>
                </div>
            </td>
        </tr>
        <?php if (!self::is_linked($post->ID) && self::has_legacy($post->ID)): ?>
        <tr>
            <th><?php esc_html_e('Old location data', 'ecare-health-services'); ?></th>
            <td>
                <div style="background:#fcf9e8;border-left:4px solid #dba617;padding:8px 12px;max-width:760px;">
                    <p style="margin:0 0 6px;"><?php esc_html_e('This test was set up with typed-in lists. They stay in use until a provider is chosen above; choosing one replaces them.', 'ecare-health-services'); ?></p>
                    <?php foreach (array('_lab_provider' => __('Provider', 'ecare-health-services'), '_division' => __('Division', 'ecare-health-services'), '_district' => __('District', 'ecare-health-services'), '_area' => __('Area', 'ecare-health-services')) as $key => $label):
                        $value = (string) get_post_meta($post->ID, $key, true);
                        if ($value === '') { continue; }
                        $short = function_exists('mb_strimwidth') ? mb_strimwidth($value, 0, 220, '…', 'UTF-8') : substr($value, 0, 220);
                        ?>
                        <div style="font-size:12px;"><strong><?php echo esc_html($label); ?>:</strong> <span title="<?php echo esc_attr($value); ?>"><?php echo esc_html($short); ?></span></div>
                    <?php endforeach; ?>
                </div>
            </td>
        </tr>
        <?php endif; ?>
        <script>
        (function () {
            var scope  = <?php echo wp_json_encode($scope); ?>;
            var select = document.getElementById('ecare-test-provider');
            var box    = document.getElementById('ecare-test-custom');
            var tree   = document.getElementById('ecare-test-cov');
            var reach  = document.getElementById('ecare-test-reach');
            var i18n   = <?php echo wp_json_encode(array(
                'none'  => __('Choose a provider to see where this test will be offered.', 'ecare-health-services'),
                'zero'  => __('This provider has no areas yet - the test will not be shown to patients.', 'ecare-health-services'),
                'reach' => __('The provider serves %d area(s).', 'ecare-health-services'),
            )); ?>;
            function mode() {
                var r = document.querySelector('input[name="_ecare_coverage_mode"]:checked');
                return r ? r.value : 'provider';
            }
            function apply() {
                var s = scope[select.value];
                box.style.display = (mode() === 'custom' && s) ? '' : 'none';
                reach.textContent = !s ? i18n.none : (s.areas.length ? i18n.reach.replace('%d', s.areas.length) : i18n.zero);
                if (!tree) { return; }
                var areas = {}, districts = {};
                (s ? s.areas : []).forEach(function (id) { areas[id] = true; });
                (s ? s.districts : []).forEach(function (id) { districts[id] = true; });
                // Only the provider's places are offered; anything else is
                // unticked so it cannot be posted by accident.
                tree.querySelectorAll('.ecare-cov-district').forEach(function (d) {
                    var inScope = !!districts[d.dataset.id];
                    d.classList.toggle('ecare-scope-miss', !inScope);
                    d.querySelectorAll('.ecare-cov-areas label').forEach(function (l) {
                        var ok = inScope && !!areas[l.dataset.id];
                        l.classList.toggle('ecare-scope-miss', !ok);
                        if (!ok) { l.querySelector('input').checked = false; }
                    });
                    if (!inScope) { d.querySelector('.ecare-cov-whole-cb').checked = false; }
                });
                tree.querySelectorAll('.ecare-cov-division').forEach(function (dv) {
                    dv.classList.toggle('ecare-scope-miss', !dv.querySelector('.ecare-cov-district:not(.ecare-scope-miss)'));
                });
            }
            select.addEventListener('change', apply);
            document.querySelectorAll('input[name="_ecare_coverage_mode"]').forEach(function (r) { r.addEventListener('change', apply); });
            apply();
        })();
        </script>
        <?php
    }
}
