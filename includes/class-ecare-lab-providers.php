<?php
defined('ABSPATH') || exit;

/**
 * Lab providers as records of their own, with the areas they serve.
 *
 * Until now a provider was only a name typed into each lab test's
 * "_lab_provider" box, so "Popular", "popular" and "Popular Diagnostic" were
 * three providers, and which areas a provider served was guessed from the
 * area list of whichever tests happened to mention it.
 *
 * Coverage is stored as ecare_location terms on the provider. A district term
 * means the whole district, including areas added later; an area term means
 * that area only. Nothing on the front end reads this yet - the lab test form
 * (step 3), the migration (step 6) and the filter (step 4) come next.
 */
class ECare_Lab_Providers {

    const POST_TYPE = 'ecare_lab_provider';

    const STATUS_ACTIVE   = 'active';
    const STATUS_INACTIVE = 'inactive';

    public static function init() {
        add_action('init', array(__CLASS__, 'register_post_type'));

        add_filter('wp_insert_post_data', array(__CLASS__, 'guard_publish'), 10, 2);
        add_action('save_post_' . self::POST_TYPE, array(__CLASS__, 'save'), 10, 2);

        if (is_admin()) {
            add_action('admin_menu', array(__CLASS__, 'add_menu'), 30);
            add_filter('parent_file', array(__CLASS__, 'highlight_menu'));
            add_action('add_meta_boxes_' . self::POST_TYPE, array(__CLASS__, 'add_meta_boxes'));
            add_action('admin_notices', array(__CLASS__, 'render_notice'));
            add_filter('manage_' . self::POST_TYPE . '_posts_columns', array(__CLASS__, 'columns'));
            add_action('manage_' . self::POST_TYPE . '_posts_custom_column', array(__CLASS__, 'column_content'), 10, 2);
            add_filter('enter_title_here', array(__CLASS__, 'title_placeholder'), 10, 2);
        }
    }

    // -----------------------------------------------------------------------
    // Registration
    // -----------------------------------------------------------------------

    public static function register_post_type() {
        register_post_type(self::POST_TYPE, array(
            'labels' => array(
                'name'               => __('Lab Providers', 'ecare-health-services'),
                'singular_name'      => __('Lab Provider', 'ecare-health-services'),
                'add_new'            => __('Add New', 'ecare-health-services'),
                'add_new_item'       => __('Add New Lab Provider', 'ecare-health-services'),
                'edit_item'          => __('Edit Lab Provider', 'ecare-health-services'),
                'search_items'       => __('Search Lab Providers', 'ecare-health-services'),
                'not_found'          => __('No lab providers found', 'ecare-health-services'),
                'not_found_in_trash' => __('No lab providers found in Trash', 'ecare-health-services'),
                'all_items'          => __('Lab Providers', 'ecare-health-services'),
            ),
            // Catalogue data, like the other post types: no single page, no archive.
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => false,
            'publicly_queryable'  => false,
            'exclude_from_search' => true,
            'has_archive'         => false,
            'rewrite'             => false,
            'show_in_rest'        => false,
            'supports'            => array('title', 'thumbnail'),
            // Coverage has its own box; the default term checklist would show
            // every division and district as a flat, unlabelled list.
            'taxonomies'          => array(),
        ));
    }

    // -----------------------------------------------------------------------
    // Reading - what later steps will call
    // -----------------------------------------------------------------------

    public static function is_active($provider_id) {
        $post = get_post((int) $provider_id);
        return $post
            && $post->post_type === self::POST_TYPE
            && $post->post_status === 'publish'
            && get_post_meta($post->ID, '_ecare_provider_status', true) !== self::STATUS_INACTIVE;
    }

    /** Raw coverage: the district and area term ids stored on the provider. */
    public static function coverage_term_ids($provider_id) {
        $ids = wp_get_object_terms((int) $provider_id, ECare_Locations::TAXONOMY, array('fields' => 'ids'));
        return is_wp_error($ids) ? array() : array_map('intval', $ids);
    }

    /**
     * Does the provider serve this area? True if the area itself is ticked or
     * its whole district is.
     */
    public static function covers_area($provider_id, $area_id) {
        $area = get_term((int) $area_id, ECare_Locations::TAXONOMY);
        if (!$area || is_wp_error($area) || ECare_Locations::get_level($area->term_id) !== ECare_Locations::LEVEL_AREA) {
            return false;
        }
        $coverage = self::coverage_term_ids($provider_id);
        return in_array((int) $area->term_id, $coverage, true) || in_array((int) $area->parent, $coverage, true);
    }

    /** Active, published providers that serve the area. */
    public static function providers_for_area($area_id) {
        $area = get_term((int) $area_id, ECare_Locations::TAXONOMY);
        if (!$area || is_wp_error($area) || ECare_Locations::get_level($area->term_id) !== ECare_Locations::LEVEL_AREA) {
            return array();
        }
        return get_posts(array(
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'meta_query'     => array(
                'relation' => 'OR',
                array('key' => '_ecare_provider_status', 'value' => self::STATUS_INACTIVE, 'compare' => '!='),
                array('key' => '_ecare_provider_status', 'compare' => 'NOT EXISTS'),
            ),
            'tax_query'      => array(array(
                'taxonomy'         => ECare_Locations::TAXONOMY,
                'field'            => 'term_id',
                'terms'            => array((int) $area->term_id, (int) $area->parent),
                'include_children' => false,
            )),
        ));
    }

    /**
     * One line for the admin list: "Dhaka (whole district) · Chattogram: 12 areas".
     */
    public static function coverage_summary($provider_id) {
        $ids = self::coverage_term_ids($provider_id);
        if (!$ids) {
            return '';
        }
        $whole = array();
        $areas = array();   // district id => count
        foreach ($ids as $id) {
            $term = get_term($id, ECare_Locations::TAXONOMY);
            if (!$term || is_wp_error($term)) {
                continue;
            }
            $level = ECare_Locations::get_level($term->term_id);
            if ($level === ECare_Locations::LEVEL_DISTRICT) {
                $whole[] = $term->name;
            } elseif ($level === ECare_Locations::LEVEL_AREA) {
                $areas[(int) $term->parent] = ($areas[(int) $term->parent] ?? 0) + 1;
            }
        }
        $parts = array();
        foreach ($whole as $name) {
            /* translators: %s: district name */
            $parts[] = sprintf(__('%s (whole district)', 'ecare-health-services'), $name);
        }
        foreach ($areas as $district_id => $n) {
            $district = get_term($district_id, ECare_Locations::TAXONOMY);
            $name     = ($district && !is_wp_error($district)) ? $district->name : '?';
            /* translators: 1: district name, 2: number of areas */
            $parts[] = sprintf(_n('%1$s: %2$d area', '%1$s: %2$d areas', $n, 'ecare-health-services'), $name, $n);
        }
        return implode(' · ', $parts);
    }

    // -----------------------------------------------------------------------
    // Cleaning input
    // -----------------------------------------------------------------------

    /**
     * Keep districts and areas only, and drop an area whose whole district is
     * already ticked - the district covers it, and keeping both would make the
     * area look like the reason.
     */
    public static function normalize_coverage($ids) {
        $districts = array();
        $areas     = array();
        foreach ((array) $ids as $id) {
            $id   = (int) $id;
            $term = $id > 0 ? get_term($id, ECare_Locations::TAXONOMY) : null;
            if (!$term || is_wp_error($term)) {
                continue;
            }
            $level = ECare_Locations::get_level($term->term_id);
            if ($level === ECare_Locations::LEVEL_DISTRICT) {
                $districts[$id] = true;
            } elseif ($level === ECare_Locations::LEVEL_AREA) {
                $areas[$id] = (int) $term->parent;
            }
        }
        $out = array_keys($districts);
        foreach ($areas as $id => $district_id) {
            if (!isset($districts[$district_id])) {
                $out[] = $id;
            }
        }
        sort($out);
        return $out;
    }

    /** Comma, semicolon or whitespace separated; invalid ones dropped, duplicates folded. */
    public static function clean_emails($raw) {
        $out = array();
        foreach (preg_split('/[\s,;]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY) as $email) {
            $email = sanitize_email($email);
            if ($email !== '' && is_email($email) && !isset($out[strtolower($email)])) {
                $out[strtolower($email)] = $email;
            }
        }
        return array_values($out);
    }

    /** Id of another provider with the same name (case, spacing, punctuation ignored), or 0. */
    public static function find_duplicate($title, $exclude_id = 0) {
        $key = ECare_Locations::name_key($title);
        if ($key === '') {
            return 0;
        }
        $others = get_posts(array(
            'post_type'        => self::POST_TYPE,
            'post_status'      => array('publish', 'draft', 'pending', 'private', 'future'),
            'posts_per_page'   => -1,
            'post__not_in'     => $exclude_id ? array((int) $exclude_id) : array(),
            'suppress_filters' => true,
        ));
        foreach ($others as $p) {
            if ((int) $p->ID !== (int) $exclude_id && ECare_Locations::name_key($p->post_title) === $key) {
                return (int) $p->ID;
            }
        }
        return 0;
    }

    // -----------------------------------------------------------------------
    // Saving
    // -----------------------------------------------------------------------

    /**
     * A provider without a name, or with another provider's name, is held as a
     * draft instead of being published, and the admin is told why.
     */
    public static function guard_publish($data, $postarr) {
        if (($data['post_type'] ?? '') !== self::POST_TYPE || ($data['post_status'] ?? '') !== 'publish') {
            return $data;
        }
        $title = ECare_Locations::clean_name(wp_unslash($data['post_title']));
        $data['post_title'] = wp_slash($title);

        $problem = '';
        if (ECare_Locations::name_key($title) === '') {
            $problem = 'empty';
        } elseif ($dupe = self::find_duplicate($title, (int) ($postarr['ID'] ?? 0))) {
            $problem = 'duplicate:' . $dupe;
        }
        if ($problem !== '') {
            $data['post_status'] = 'draft';
            if (function_exists('get_current_user_id') && get_current_user_id()) {
                set_transient('ecare_lab_provider_notice_' . get_current_user_id(), $problem, 60);
            }
        }
        return $data;
    }

    public static function save($post_id, $post) {
        if (!isset($_POST['ecare_lab_provider_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ecare_lab_provider_nonce'])), 'ecare_lab_provider_meta')) {
            return;
        }
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($post_id)) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        update_post_meta($post_id, '_ecare_phone', sanitize_text_field(wp_unslash($_POST['_ecare_phone'] ?? '')));
        update_post_meta($post_id, '_ecare_notify_emails', implode(', ', self::clean_emails(wp_unslash($_POST['_ecare_notify_emails'] ?? ''))));
        update_post_meta($post_id, '_ecare_address', sanitize_textarea_field(wp_unslash($_POST['_ecare_address'] ?? '')));

        $status = sanitize_key(wp_unslash($_POST['_ecare_provider_status'] ?? self::STATUS_ACTIVE));
        update_post_meta($post_id, '_ecare_provider_status', $status === self::STATUS_INACTIVE ? self::STATUS_INACTIVE : self::STATUS_ACTIVE);

        $ids = isset($_POST['ecare_coverage']) ? array_map('intval', (array) wp_unslash($_POST['ecare_coverage'])) : array();
        wp_set_object_terms($post_id, self::normalize_coverage($ids), ECare_Locations::TAXONOMY, false);
    }

    // -----------------------------------------------------------------------
    // Admin screens
    // -----------------------------------------------------------------------

    public static function menu_slug() {
        return 'edit.php?post_type=' . self::POST_TYPE;
    }

    public static function add_menu() {
        add_submenu_page(
            'ecare-dashboard',
            __('Lab Providers', 'ecare-health-services'),
            __('Lab Providers', 'ecare-health-services'),
            'manage_options',
            self::menu_slug()
        );
    }

    public static function highlight_menu($parent_file) {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && $screen->post_type === self::POST_TYPE) {
            $GLOBALS['submenu_file'] = self::menu_slug();
            return 'ecare-dashboard';
        }
        return $parent_file;
    }

    public static function title_placeholder($text, $post) {
        return ($post && $post->post_type === self::POST_TYPE)
            ? __('Provider name, e.g. Popular Diagnostic Centre', 'ecare-health-services')
            : $text;
    }

    public static function render_notice() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->post_type !== self::POST_TYPE) {
            return;
        }
        $key     = 'ecare_lab_provider_notice_' . get_current_user_id();
        $problem = get_transient($key);
        if (!$problem) {
            return;
        }
        delete_transient($key);

        if ($problem === 'empty') {
            $msg = __('A lab provider needs a name before it can be published. It has been saved as a draft.', 'ecare-health-services');
        } else {
            $other = get_post((int) substr($problem, strlen('duplicate:')));
            $msg   = sprintf(
                /* translators: %s: existing provider name */
                __('A lab provider named "%s" already exists, so this one was saved as a draft. Edit that provider instead, or give this one a different name.', 'ecare-health-services'),
                $other ? $other->post_title : ''
            );
        }
        echo '<div class="notice notice-error"><p>' . esc_html($msg) . '</p></div>';
    }

    public static function columns($columns) {
        $out = array();
        foreach ($columns as $key => $label) {
            if ($key === 'date') {
                continue;
            }
            $out[$key] = $label;
            if ($key === 'title') {
                $out['ecare_coverage'] = __('Coverage', 'ecare-health-services');
                $out['ecare_phone']    = __('Phone', 'ecare-health-services');
                $out['ecare_status']   = __('Status', 'ecare-health-services');
            }
        }
        return $out;
    }

    public static function column_content($column, $post_id) {
        if ($column === 'ecare_coverage') {
            $summary = self::coverage_summary($post_id);
            echo $summary !== ''
                ? esc_html($summary)
                : '<span style="color:#b32d2e;">' . esc_html__('No coverage - hidden from patients', 'ecare-health-services') . '</span>';
        } elseif ($column === 'ecare_phone') {
            echo esc_html((string) get_post_meta($post_id, '_ecare_phone', true));
        } elseif ($column === 'ecare_status') {
            $inactive = get_post_meta($post_id, '_ecare_provider_status', true) === self::STATUS_INACTIVE;
            echo $inactive ? esc_html__('Inactive', 'ecare-health-services') : esc_html__('Active', 'ecare-health-services');
        }
    }

    public static function add_meta_boxes() {
        add_meta_box('ecare_lab_provider_details', __('Provider Details', 'ecare-health-services'), array(__CLASS__, 'render_details_box'), self::POST_TYPE, 'normal', 'high');
        add_meta_box('ecare_lab_provider_coverage', __('Coverage Areas', 'ecare-health-services'), array(__CLASS__, 'render_coverage_box'), self::POST_TYPE, 'normal', 'default');
        // The slug is never used: providers have no public URL.
        remove_meta_box('slugdiv', self::POST_TYPE, 'normal');
    }

    public static function render_details_box($post) {
        wp_nonce_field('ecare_lab_provider_meta', 'ecare_lab_provider_nonce');
        $phone  = get_post_meta($post->ID, '_ecare_phone', true);
        $emails = get_post_meta($post->ID, '_ecare_notify_emails', true);
        $addr   = get_post_meta($post->ID, '_ecare_address', true);
        $status = get_post_meta($post->ID, '_ecare_provider_status', true) ?: self::STATUS_ACTIVE;
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="ecare-provider-phone"><?php esc_html_e('Phone', 'ecare-health-services'); ?></label></th>
                <td><input type="text" id="ecare-provider-phone" name="_ecare_phone" class="regular-text" value="<?php echo esc_attr($phone); ?>" placeholder="+880..." /></td>
            </tr>
            <tr>
                <th><label for="ecare-provider-emails"><?php esc_html_e('Notification email(s)', 'ecare-health-services'); ?></label></th>
                <td>
                    <input type="text" id="ecare-provider-emails" name="_ecare_notify_emails" class="large-text" value="<?php echo esc_attr($emails); ?>" placeholder="orders@example.com, lab@example.com" />
                    <p class="description"><?php esc_html_e('Separate several addresses with commas. Kept for booking notifications; nothing is sent yet.', 'ecare-health-services'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="ecare-provider-address"><?php esc_html_e('Address', 'ecare-health-services'); ?></label></th>
                <td><textarea id="ecare-provider-address" name="_ecare_address" class="large-text" rows="2"><?php echo esc_textarea($addr); ?></textarea></td>
            </tr>
            <tr>
                <th><label for="ecare-provider-status"><?php esc_html_e('Status', 'ecare-health-services'); ?></label></th>
                <td>
                    <select id="ecare-provider-status" name="_ecare_provider_status">
                        <option value="active" <?php selected($status, self::STATUS_ACTIVE); ?>><?php esc_html_e('Active', 'ecare-health-services'); ?></option>
                        <option value="inactive" <?php selected($status, self::STATUS_INACTIVE); ?>><?php esc_html_e('Inactive', 'ecare-health-services'); ?></option>
                    </select>
                    <p class="description"><?php esc_html_e('An inactive provider is hidden from patients but keeps its coverage and history.', 'ecare-health-services'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    public static function render_coverage_box($post) {
        $terms = get_terms(array(
            'taxonomy'   => ECare_Locations::TAXONOMY,
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ));
        if (is_wp_error($terms) || !$terms) {
            echo '<p>' . esc_html__('No locations yet.', 'ecare-health-services') . '</p>';
            return;
        }

        $children = array();
        foreach ($terms as $t) {
            $children[(int) $t->parent][] = $t;
        }
        $selected = array_flip(self::coverage_term_ids($post->ID));
        ?>
        <style>
            .ecare-cov-tools{display:flex;gap:10px;align-items:center;margin:4px 0 10px}
            .ecare-cov details{margin:2px 0}
            .ecare-cov summary{cursor:pointer;padding:4px 0}
            .ecare-cov .ecare-cov-division>summary{font-weight:600}
            .ecare-cov .ecare-cov-district{margin-left:18px}
            .ecare-cov .ecare-cov-areas{margin:4px 0 8px 22px;display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:2px 12px}
            .ecare-cov .ecare-cov-whole{margin-left:22px;font-style:italic}
            .ecare-cov .ecare-cov-count{color:#2271b1;font-weight:400;margin-left:6px}
            .ecare-cov .ecare-cov-empty{margin-left:22px;color:#646970}
            .ecare-cov label.is-covered{opacity:.55}
        </style>
        <div class="ecare-cov-tools">
            <input type="search" class="regular-text" id="ecare-cov-search" placeholder="<?php esc_attr_e('Search district or area…', 'ecare-health-services'); ?>" />
            <a href="<?php echo esc_url(admin_url(ECare_Locations::menu_slug())); ?>" target="_blank"><?php esc_html_e('Add areas', 'ecare-health-services'); ?></a>
        </div>
        <div class="ecare-cov" id="ecare-cov">
        <?php foreach ($children[0] ?? array() as $division):
            $div_count = 0;
            foreach ($children[(int) $division->term_id] ?? array() as $d) {
                $div_count += isset($selected[(int) $d->term_id]) ? 1 : 0;
                foreach ($children[(int) $d->term_id] ?? array() as $a) {
                    $div_count += isset($selected[(int) $a->term_id]) ? 1 : 0;
                }
            }
            ?>
            <details class="ecare-cov-division" <?php echo $div_count ? 'open' : ''; ?>>
                <summary><?php echo esc_html($division->name); ?><?php if ($div_count): ?><span class="ecare-cov-count">(<?php echo (int) $div_count; ?>)</span><?php endif; ?></summary>
                <?php foreach ($children[(int) $division->term_id] ?? array() as $district):
                    $areas     = $children[(int) $district->term_id] ?? array();
                    $whole     = isset($selected[(int) $district->term_id]);
                    $picked    = 0;
                    foreach ($areas as $a) { $picked += isset($selected[(int) $a->term_id]) ? 1 : 0; }
                    ?>
                    <details class="ecare-cov-district" data-name="<?php echo esc_attr(strtolower($district->name)); ?>" <?php echo ($whole || $picked) ? 'open' : ''; ?>>
                        <summary><?php echo esc_html($district->name); ?>
                            <?php if ($whole): ?><span class="ecare-cov-count"><?php esc_html_e('whole district', 'ecare-health-services'); ?></span>
                            <?php elseif ($picked): ?><span class="ecare-cov-count">(<?php echo (int) $picked; ?>)</span><?php endif; ?>
                        </summary>
                        <label class="ecare-cov-whole">
                            <input type="checkbox" class="ecare-cov-whole-cb" name="ecare_coverage[]" value="<?php echo (int) $district->term_id; ?>" <?php checked($whole); ?> />
                            <?php esc_html_e('Whole district - every area, including ones added later', 'ecare-health-services'); ?>
                        </label>
                        <?php if ($areas): ?>
                            <div class="ecare-cov-areas">
                            <?php foreach ($areas as $area): ?>
                                <label data-name="<?php echo esc_attr(strtolower($area->name)); ?>" class="<?php echo $whole ? 'is-covered' : ''; ?>">
                                    <input type="checkbox" class="ecare-cov-area-cb" name="ecare_coverage[]" value="<?php echo (int) $area->term_id; ?>" <?php checked(isset($selected[(int) $area->term_id]) || $whole); ?> <?php disabled($whole); ?> />
                                    <?php echo esc_html($area->name); ?>
                                </label>
                            <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="ecare-cov-empty"><?php esc_html_e('No areas added for this district yet.', 'ecare-health-services'); ?></p>
                        <?php endif; ?>
                    </details>
                <?php endforeach; ?>
            </details>
        <?php endforeach; ?>
        </div>
        <script>
        (function () {
            var root = document.getElementById('ecare-cov');
            if (!root) { return; }
            // Ticking "whole district" greys out and locks its areas; the server
            // drops them anyway, this only shows it.
            root.addEventListener('change', function (e) {
                if (!e.target.classList.contains('ecare-cov-whole-cb')) { return; }
                var box = e.target.closest('.ecare-cov-district');
                box.querySelectorAll('.ecare-cov-area-cb').forEach(function (cb) {
                    cb.disabled = e.target.checked;
                    cb.checked = e.target.checked;
                    cb.closest('label').classList.toggle('is-covered', e.target.checked);
                });
            });
            var search = document.getElementById('ecare-cov-search');
            search.addEventListener('input', function () {
                var q = search.value.trim().toLowerCase();
                root.querySelectorAll('.ecare-cov-district').forEach(function (d) {
                    var hit = !q || d.dataset.name.indexOf(q) !== -1, anyArea = false;
                    d.querySelectorAll('.ecare-cov-areas label').forEach(function (l) {
                        var m = !q || hit || l.dataset.name.indexOf(q) !== -1;
                        l.style.display = m ? '' : 'none';
                        anyArea = anyArea || (q && l.dataset.name.indexOf(q) !== -1);
                    });
                    var show = hit || anyArea;
                    d.style.display = show ? '' : 'none';
                    if (q && show) { d.open = true; d.closest('.ecare-cov-division').open = true; }
                });
                root.querySelectorAll('.ecare-cov-division').forEach(function (dv) {
                    var visible = Array.prototype.some.call(dv.querySelectorAll('.ecare-cov-district'), function (d) { return d.style.display !== 'none'; });
                    dv.style.display = visible ? '' : 'none';
                });
            });
        })();
        </script>
        <?php
    }
}
