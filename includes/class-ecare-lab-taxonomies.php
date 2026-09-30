<?php
defined('ABSPATH') || exit;

/**
 * Lab categories and collections.
 *
 * Categories (ecare_lab_category) are what the catalogue filters by and what
 * the lab home page shows as icon grids. Each has an icon, the grids it
 * appears in (vital-organ checkups, health concerns, or both - Shukhee lists
 * Thyroid Disorder in both) and a sort order.
 *
 * Collections (ecare_lab_collection) are the rows on the lab home page:
 * Trending, Affordable Packages, Most Booked. A collection is either manual
 * (the tests ticked on it, in title order) or "most booked", ranked from paid
 * lab orders with any ticked tests pinned first.
 *
 * The old free-text _test_category meta, which the current front end prints,
 * is rewritten from the ticked categories when a test is saved.
 */
class ECare_Lab_Taxonomies {

    const CATEGORY   = 'ecare_lab_category';
    const COLLECTION = 'ecare_lab_collection';

    const SEED_VERSION = '1';
    const SEED_OPTION  = 'ecare_lab_taxonomies_seed_version';

    const GROUP_ORGAN   = 'organ';
    const GROUP_CONCERN = 'concern';

    const MODE_MANUAL      = 'manual';
    const MODE_MOST_BOOKED = 'most_booked';

    private static $seeding = false;

    public static function init() {
        add_action('init', array(__CLASS__, 'register'));
        add_action('init', array(__CLASS__, 'maybe_seed'), 20);

        // Terms are assigned inside wp_insert_post, before save_post runs.
        add_action('save_post_ecare_lab_test', array(__CLASS__, 'sync_legacy_category'), 20);

        if (is_admin()) {
            add_action(self::CATEGORY . '_add_form_fields', array(__CLASS__, 'category_add_fields'));
            add_action(self::CATEGORY . '_edit_form_fields', array(__CLASS__, 'category_edit_fields'));
            add_action('created_' . self::CATEGORY, array(__CLASS__, 'save_category_meta'));
            add_action('edited_' . self::CATEGORY, array(__CLASS__, 'save_category_meta'));
            add_filter('manage_edit-' . self::CATEGORY . '_columns', array(__CLASS__, 'category_columns'));
            add_filter('manage_' . self::CATEGORY . '_custom_column', array(__CLASS__, 'category_column_content'), 10, 3);

            add_action(self::COLLECTION . '_add_form_fields', array(__CLASS__, 'collection_add_fields'));
            add_action(self::COLLECTION . '_edit_form_fields', array(__CLASS__, 'collection_edit_fields'));
            add_action('created_' . self::COLLECTION, array(__CLASS__, 'save_collection_meta'));
            add_action('edited_' . self::COLLECTION, array(__CLASS__, 'save_collection_meta'));
            add_filter('manage_edit-' . self::COLLECTION . '_columns', array(__CLASS__, 'collection_columns'));
            add_filter('manage_' . self::COLLECTION . '_custom_column', array(__CLASS__, 'collection_column_content'), 10, 3);

            add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_media'));
            add_action('admin_head-edit-tags.php', array(__CLASS__, 'hide_parent_field'));
            add_action('admin_head-term.php', array(__CLASS__, 'hide_parent_field'));
        }
    }

    // -----------------------------------------------------------------------
    // Registration
    // -----------------------------------------------------------------------

    public static function register() {
        $common = array(
            // Hierarchical only for the checkbox list on the test screen; the
            // parent field is hidden and nothing reads it.
            'hierarchical'       => true,
            'public'             => false,
            'publicly_queryable' => false,
            'show_ui'            => true,
            'show_in_menu'       => false,
            'show_in_nav_menus'  => false,
            'show_in_rest'       => false,
            'show_tagcloud'      => false,
            'show_in_quick_edit' => true,
            'show_admin_column'  => false,
            'query_var'          => false,
            'rewrite'            => false,
            'capabilities'       => array(
                'manage_terms' => 'manage_options',
                'edit_terms'   => 'manage_options',
                'delete_terms' => 'manage_options',
                'assign_terms' => 'edit_posts',
            ),
        );

        register_taxonomy(self::CATEGORY, 'ecare_lab_test', $common + array(
            'labels' => array(
                'name'          => __('Lab Categories', 'ecare-health-services'),
                'singular_name' => __('Lab Category', 'ecare-health-services'),
                'search_items'  => __('Search Categories', 'ecare-health-services'),
                'all_items'     => __('All Categories', 'ecare-health-services'),
                'edit_item'     => __('Edit Category', 'ecare-health-services'),
                'update_item'   => __('Update Category', 'ecare-health-services'),
                'add_new_item'  => __('Add New Category', 'ecare-health-services'),
                'new_item_name' => __('New Category Name', 'ecare-health-services'),
                'not_found'     => __('No categories found.', 'ecare-health-services'),
                'menu_name'     => __('Categories', 'ecare-health-services'),
            ),
        ));

        register_taxonomy(self::COLLECTION, 'ecare_lab_test', $common + array(
            'labels' => array(
                'name'          => __('Lab Collections', 'ecare-health-services'),
                'singular_name' => __('Lab Collection', 'ecare-health-services'),
                'search_items'  => __('Search Collections', 'ecare-health-services'),
                'all_items'     => __('All Collections', 'ecare-health-services'),
                'edit_item'     => __('Edit Collection', 'ecare-health-services'),
                'update_item'   => __('Update Collection', 'ecare-health-services'),
                'add_new_item'  => __('Add New Collection', 'ecare-health-services'),
                'new_item_name' => __('New Collection Name', 'ecare-health-services'),
                'not_found'     => __('No collections found.', 'ecare-health-services'),
                'menu_name'     => __('Collections', 'ecare-health-services'),
            ),
        ));
    }

    // -----------------------------------------------------------------------
    // Seed
    // -----------------------------------------------------------------------

    /** Name => groups. Mirrors the two grids on Shukhee's lab home page, plus common filters. */
    public static function seed_categories() {
        return array(
            'Reproductive Tests' => array(self::GROUP_ORGAN),
            'Thyroid Disorder'   => array(self::GROUP_ORGAN, self::GROUP_CONCERN),
            'Liver Disease'      => array(self::GROUP_ORGAN, self::GROUP_CONCERN),
            'Kidney Disease'     => array(self::GROUP_ORGAN, self::GROUP_CONCERN),
            'Heart Disease'      => array(self::GROUP_ORGAN, self::GROUP_CONCERN),
            'Body Checkup'       => array(self::GROUP_ORGAN),
            'Hematology'         => array(self::GROUP_ORGAN),
            'Thalassemia'        => array(self::GROUP_CONCERN),
            'Diabetes'           => array(self::GROUP_CONCERN),
            'Anemia Profile'     => array(self::GROUP_CONCERN),
            'Cancer'             => array(self::GROUP_CONCERN),
            'Infectious Disease' => array(),
            'Vitamins'           => array(),
            'Urine/Stool Sample' => array(),
            'Allergy Tests'      => array(),
        );
    }

    /** Slug => array(name, mode). */
    public static function seed_collections() {
        return array(
            'trending'    => array('Trending Health Tests', self::MODE_MANUAL),
            'affordable'  => array('Affordable Packages', self::MODE_MANUAL),
            'most-booked' => array('Most Booked Test', self::MODE_MOST_BOOKED),
        );
    }

    public static function maybe_seed() {
        if (get_option(self::SEED_OPTION) === self::SEED_VERSION) {
            return;
        }
        if (!taxonomy_exists(self::CATEGORY) || !taxonomy_exists(self::COLLECTION)) {
            return;
        }
        if (self::seed()) {
            update_option(self::SEED_OPTION, self::SEED_VERSION, false);
        }
    }

    /**
     * Idempotent. A seeded term the admin deleted is not brought back, because
     * this runs once per SEED_VERSION, not on every load.
     */
    public static function seed() {
        self::$seeding = true;
        $ok    = true;
        $order = 0;

        foreach (self::seed_categories() as $name => $groups) {
            $order += 10;
            $slug = sanitize_title($name);
            $term = get_term_by('slug', $slug, self::CATEGORY);
            if (!$term) {
                $r = wp_insert_term($name, self::CATEGORY, array('slug' => $slug));
                if (is_wp_error($r)) { $ok = false; continue; }
                update_term_meta((int) $r['term_id'], 'ecare_groups', $groups);
                update_term_meta((int) $r['term_id'], 'ecare_order', $order);
            }
        }

        $order = 0;
        foreach (self::seed_collections() as $slug => $info) {
            $order += 10;
            $term = get_term_by('slug', $slug, self::COLLECTION);
            if (!$term) {
                $r = wp_insert_term($info[0], self::COLLECTION, array('slug' => $slug));
                if (is_wp_error($r)) { $ok = false; continue; }
                update_term_meta((int) $r['term_id'], 'ecare_mode', $info[1]);
                update_term_meta((int) $r['term_id'], 'ecare_limit', 12);
                update_term_meta((int) $r['term_id'], 'ecare_order', $order);
            }
        }

        self::$seeding = false;
        return $ok;
    }

    // -----------------------------------------------------------------------
    // Reading - what the front end will call
    // -----------------------------------------------------------------------

    /** @return string[] subset of organ / concern */
    public static function category_groups($term_id) {
        return self::clean_groups(get_term_meta((int) $term_id, 'ecare_groups', true));
    }

    public static function icon_url($term_id) {
        $id = (int) get_term_meta((int) $term_id, 'ecare_icon', true);
        return $id ? (string) wp_get_attachment_image_url($id, 'thumbnail') : '';
    }

    /**
     * Categories for one grid, in their sort order (then by name).
     *
     * @param string $group organ | concern | '' for all
     */
    public static function categories($group = '') {
        $terms = get_terms(array('taxonomy' => self::CATEGORY, 'hide_empty' => false));
        if (is_wp_error($terms)) {
            return array();
        }
        if ($group !== '') {
            $terms = array_values(array_filter($terms, function ($t) use ($group) {
                return in_array($group, self::category_groups($t->term_id), true);
            }));
        }
        return self::sort_terms($terms);
    }

    /** Collections in their sort order. */
    public static function collections() {
        $terms = get_terms(array('taxonomy' => self::COLLECTION, 'hide_empty' => false));
        return is_wp_error($terms) ? array() : self::sort_terms($terms);
    }

    public static function collection_mode($term_id) {
        return get_term_meta((int) $term_id, 'ecare_mode', true) === self::MODE_MOST_BOOKED ? self::MODE_MOST_BOOKED : self::MODE_MANUAL;
    }

    public static function collection_limit($term_id) {
        $n = (int) get_term_meta((int) $term_id, 'ecare_limit', true);
        return $n > 0 ? min(50, $n) : 12;
    }

    /**
     * Test ids in a collection, in display order, capped at its limit.
     * Only published, active tests are returned.
     *
     * @param int|null $limit null = the collection's own limit (home page row),
     *                        0 = all of them (the tests page "View All").
     */
    public static function collection_test_ids($term_id, $limit = null) {
        $term_id = (int) $term_id;
        $limit   = $limit === null ? self::collection_limit($term_id) : max(0, (int) $limit);

        $ticked = get_posts(array(
            'post_type'      => 'ecare_lab_test',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'title',
            'order'          => 'ASC',
            'meta_query'     => self::active_meta_query(),
            'tax_query'      => array(array('taxonomy' => self::COLLECTION, 'field' => 'term_id', 'terms' => array($term_id))),
        ));
        $ids = array_map('intval', (array) $ticked);

        if (self::collection_mode($term_id) === self::MODE_MOST_BOOKED) {
            $valid = array_flip(array_map('intval', (array) get_posts(array(
                'post_type'      => 'ecare_lab_test',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'meta_query'     => self::active_meta_query(),
            ))));
            foreach (self::booking_counts() as $test_id => $n) {
                if (isset($valid[$test_id]) && !in_array($test_id, $ids, true)) {
                    $ids[] = $test_id;
                }
            }
        }

        return $limit > 0 ? array_slice($ids, 0, $limit) : $ids;
    }

    /**
     * Paid lab bookings per test, most booked first. One booking row is one
     * test on one paid order (see ECare_WooCommerce::create_lab_bookings_from_order).
     * Cached for an hour.
     *
     * @return array<int,int> test id => bookings
     */
    public static function booking_counts() {
        $cached = get_transient('ecare_lab_booking_counts');
        if (is_array($cached)) {
            return $cached;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'ecare_bookings';
        $rows  = $wpdb->get_results($wpdb->prepare(
            "SELECT lab_test_ids AS test_id, COUNT(*) AS n FROM {$table}
             WHERE booking_type = %s AND status <> %s AND lab_test_ids <> ''
             GROUP BY lab_test_ids ORDER BY n DESC LIMIT 200",
            'lab', 'cancelled'
        ));
        $out = array();
        foreach ((array) $rows as $r) {
            $id = (int) $r->test_id;
            if ($id > 0) {
                $out[$id] = (int) $r->n;
            }
        }
        set_transient('ecare_lab_booking_counts', $out, HOUR_IN_SECONDS);
        return $out;
    }

    private static function active_meta_query() {
        return array(
            'relation' => 'OR',
            array('key' => '_test_status', 'value' => 'inactive', 'compare' => '!='),
            array('key' => '_test_status', 'compare' => 'NOT EXISTS'),
        );
    }

    private static function sort_terms($terms) {
        usort($terms, function ($a, $b) {
            $oa = (int) get_term_meta($a->term_id, 'ecare_order', true);
            $ob = (int) get_term_meta($b->term_id, 'ecare_order', true);
            return $oa === $ob ? strcasecmp($a->name, $b->name) : $oa <=> $ob;
        });
        return array_values($terms);
    }

    // -----------------------------------------------------------------------
    // Cleaning and saving
    // -----------------------------------------------------------------------

    public static function clean_groups($raw) {
        $out = array();
        foreach ((array) $raw as $g) {
            if (in_array($g, array(self::GROUP_ORGAN, self::GROUP_CONCERN), true) && !in_array($g, $out, true)) {
                $out[] = $g;
            }
        }
        return $out;
    }

    public static function save_category_meta($term_id) {
        if (self::$seeding || !current_user_can('manage_options')) {
            return;
        }
        if (!isset($_POST['ecare_lab_term_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ecare_lab_term_nonce'])), 'ecare_lab_term')) {
            return;
        }
        $in = wp_unslash($_POST);
        update_term_meta($term_id, 'ecare_groups', self::clean_groups($in['ecare_groups'] ?? array()));
        update_term_meta($term_id, 'ecare_order', (int) ($in['ecare_order'] ?? 0));
        $icon = (int) ($in['ecare_icon'] ?? 0);
        if ($icon > 0 && function_exists('wp_attachment_is_image') && !wp_attachment_is_image($icon)) {
            $icon = 0;
        }
        update_term_meta($term_id, 'ecare_icon', $icon);
    }

    public static function save_collection_meta($term_id) {
        if (self::$seeding || !current_user_can('manage_options')) {
            return;
        }
        if (!isset($_POST['ecare_lab_term_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ecare_lab_term_nonce'])), 'ecare_lab_term')) {
            return;
        }
        $in   = wp_unslash($_POST);
        $mode = ($in['ecare_mode'] ?? '') === self::MODE_MOST_BOOKED ? self::MODE_MOST_BOOKED : self::MODE_MANUAL;
        $lim  = (int) ($in['ecare_limit'] ?? 12);
        update_term_meta($term_id, 'ecare_mode', $mode);
        update_term_meta($term_id, 'ecare_limit', $lim > 0 ? min(50, $lim) : 12);
        update_term_meta($term_id, 'ecare_order', (int) ($in['ecare_order'] ?? 0));
    }

    /**
     * Keep the old free-text category in step while the old front end reads
     * it. A test with no ticked categories keeps whatever text it had, for the
     * migration to convert.
     */
    public static function sync_legacy_category($post_id) {
        if (wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
            return;
        }
        $names = wp_get_object_terms((int) $post_id, self::CATEGORY, array('fields' => 'names'));
        if (is_wp_error($names) || !$names) {
            return;
        }
        update_post_meta((int) $post_id, '_test_category', implode(', ', $names));
    }

    // -----------------------------------------------------------------------
    // Admin screens
    // -----------------------------------------------------------------------

    public static function menu_slug($taxonomy) {
        return 'edit-tags.php?taxonomy=' . $taxonomy . '&post_type=ecare_lab_test';
    }

    private static function on_our_term_screen() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        return $screen && in_array($screen->taxonomy ?? '', array(self::CATEGORY, self::COLLECTION), true);
    }

    public static function enqueue_media() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && ($screen->taxonomy ?? '') === self::CATEGORY) {
            wp_enqueue_media();
        }
    }

    public static function hide_parent_field() {
        if (self::on_our_term_screen()) {
            echo '<style>.term-parent-wrap,.inline-edit-col .parent{display:none!important}</style>';
        }
    }

    private static function group_labels() {
        return array(
            self::GROUP_ORGAN   => __('Checkups based on vital organs', 'ecare-health-services'),
            self::GROUP_CONCERN => __('Browse by health concerns', 'ecare-health-services'),
        );
    }

    public static function category_add_fields() {
        wp_nonce_field('ecare_lab_term', 'ecare_lab_term_nonce');
        ?>
        <div class="form-field">
            <label><?php esc_html_e('Icon', 'ecare-health-services'); ?></label>
            <?php self::icon_picker(0); ?>
        </div>
        <div class="form-field">
            <label><?php esc_html_e('Show on the lab home page in', 'ecare-health-services'); ?></label>
            <?php foreach (self::group_labels() as $key => $label): ?>
                <label style="display:block;font-weight:normal;"><input type="checkbox" name="ecare_groups[]" value="<?php echo esc_attr($key); ?>" /> <?php echo esc_html($label); ?></label>
            <?php endforeach; ?>
            <p><?php esc_html_e('Every category is a filter on the tests page either way.', 'ecare-health-services'); ?></p>
        </div>
        <div class="form-field">
            <label for="ecare-order"><?php esc_html_e('Order', 'ecare-health-services'); ?></label>
            <input type="number" id="ecare-order" name="ecare_order" value="0" style="width:90px" />
            <p><?php esc_html_e('Lower numbers come first.', 'ecare-health-services'); ?></p>
        </div>
        <?php
    }

    public static function category_edit_fields($term) {
        wp_nonce_field('ecare_lab_term', 'ecare_lab_term_nonce');
        $groups = self::category_groups($term->term_id);
        ?>
        <tr class="form-field">
            <th scope="row"><?php esc_html_e('Icon', 'ecare-health-services'); ?></th>
            <td><?php self::icon_picker((int) get_term_meta($term->term_id, 'ecare_icon', true)); ?></td>
        </tr>
        <tr class="form-field">
            <th scope="row"><?php esc_html_e('Show on the lab home page in', 'ecare-health-services'); ?></th>
            <td>
                <?php foreach (self::group_labels() as $key => $label): ?>
                    <label style="display:block;"><input type="checkbox" name="ecare_groups[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $groups, true)); ?> /> <?php echo esc_html($label); ?></label>
                <?php endforeach; ?>
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="ecare-order"><?php esc_html_e('Order', 'ecare-health-services'); ?></label></th>
            <td><input type="number" id="ecare-order" name="ecare_order" value="<?php echo (int) get_term_meta($term->term_id, 'ecare_order', true); ?>" style="width:90px" /></td>
        </tr>
        <?php
    }

    private static function icon_picker($attachment_id) {
        $url = $attachment_id ? wp_get_attachment_image_url($attachment_id, 'thumbnail') : '';
        ?>
        <div class="ecare-icon-picker">
            <input type="hidden" name="ecare_icon" value="<?php echo (int) $attachment_id; ?>" />
            <div class="ecare-icon-preview" style="width:56px;height:56px;border:1px dashed #c3c4c7;border-radius:8px;display:flex;align-items:center;justify-content:center;margin-bottom:6px;background:#fff;">
                <?php if ($url): ?><img src="<?php echo esc_url($url); ?>" alt="" style="max-width:48px;max-height:48px;" /><?php endif; ?>
            </div>
            <button type="button" class="button ecare-icon-choose"><?php esc_html_e('Choose icon', 'ecare-health-services'); ?></button>
            <button type="button" class="button-link ecare-icon-remove" style="margin-left:8px;<?php echo $url ? '' : 'display:none;'; ?>"><?php esc_html_e('Remove', 'ecare-health-services'); ?></button>
        </div>
        <script>
        (function () {
            var box = document.currentScript.previousElementSibling;
            var input = box.querySelector('input'), preview = box.querySelector('.ecare-icon-preview'), rm = box.querySelector('.ecare-icon-remove');
            box.querySelector('.ecare-icon-choose').addEventListener('click', function () {
                if (!window.wp || !wp.media) { return; }
                var frame = wp.media({ title: <?php echo wp_json_encode(__('Category icon', 'ecare-health-services')); ?>, library: { type: 'image' }, multiple: false });
                frame.on('select', function () {
                    var a = frame.state().get('selection').first().toJSON();
                    var src = (a.sizes && a.sizes.thumbnail) ? a.sizes.thumbnail.url : a.url;
                    input.value = a.id;
                    preview.innerHTML = '';
                    var img = document.createElement('img'); img.src = src; img.style.maxWidth = '48px'; img.style.maxHeight = '48px';
                    preview.appendChild(img);
                    rm.style.display = '';
                });
                frame.open();
            });
            rm.addEventListener('click', function () { input.value = 0; preview.innerHTML = ''; rm.style.display = 'none'; });
            // The add-term form is submitted by AJAX and not reloaded: clear the picker after a successful add.
            if (window.jQuery) { jQuery(document).ajaxSuccess(function (e, x, s) { if (s && s.data && String(s.data).indexOf('action=add-tag') !== -1 && document.getElementById('addtag') && document.getElementById('addtag').contains(box)) { rm.click(); } }); }
        })();
        </script>
        <?php
    }

    public static function category_columns($columns) {
        $out = array();
        foreach ($columns as $key => $label) {
            if ($key === 'description' || $key === 'slug') {
                continue;
            }
            if ($key === 'name') {
                $out['ecare_icon'] = __('Icon', 'ecare-health-services');
            }
            $out[$key] = $label;
            if ($key === 'name') {
                $out['ecare_groups'] = __('Home page grids', 'ecare-health-services');
                $out['ecare_order']  = __('Order', 'ecare-health-services');
            }
        }
        return $out;
    }

    public static function category_column_content($content, $column, $term_id) {
        if ($column === 'ecare_icon') {
            $url = self::icon_url($term_id);
            return $url ? '<img src="' . esc_url($url) . '" alt="" style="width:32px;height:32px;object-fit:contain;" />' : '&mdash;';
        }
        if ($column === 'ecare_groups') {
            $labels = array(self::GROUP_ORGAN => __('Vital organs', 'ecare-health-services'), self::GROUP_CONCERN => __('Health concerns', 'ecare-health-services'));
            $names  = array_map(function ($g) use ($labels) { return $labels[$g]; }, self::category_groups($term_id));
            return $names ? esc_html(implode(', ', $names)) : '&mdash;';
        }
        if ($column === 'ecare_order') {
            return (string) (int) get_term_meta((int) $term_id, 'ecare_order', true);
        }
        return $content;
    }

    public static function collection_add_fields() {
        wp_nonce_field('ecare_lab_term', 'ecare_lab_term_nonce');
        ?>
        <div class="form-field">
            <label for="ecare-mode"><?php esc_html_e('Which tests', 'ecare-health-services'); ?></label>
            <?php self::mode_select(self::MODE_MANUAL); ?>
        </div>
        <div class="form-field">
            <label for="ecare-limit"><?php esc_html_e('Show at most', 'ecare-health-services'); ?></label>
            <input type="number" id="ecare-limit" name="ecare_limit" value="12" min="1" max="50" style="width:90px" />
        </div>
        <div class="form-field">
            <label for="ecare-order"><?php esc_html_e('Order on the home page', 'ecare-health-services'); ?></label>
            <input type="number" id="ecare-order" name="ecare_order" value="0" style="width:90px" />
        </div>
        <?php
    }

    public static function collection_edit_fields($term) {
        wp_nonce_field('ecare_lab_term', 'ecare_lab_term_nonce');
        ?>
        <tr class="form-field">
            <th scope="row"><label for="ecare-mode"><?php esc_html_e('Which tests', 'ecare-health-services'); ?></label></th>
            <td><?php self::mode_select(self::collection_mode($term->term_id)); ?></td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="ecare-limit"><?php esc_html_e('Show at most', 'ecare-health-services'); ?></label></th>
            <td><input type="number" id="ecare-limit" name="ecare_limit" value="<?php echo (int) self::collection_limit($term->term_id); ?>" min="1" max="50" style="width:90px" /></td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="ecare-order"><?php esc_html_e('Order on the home page', 'ecare-health-services'); ?></label></th>
            <td><input type="number" id="ecare-order" name="ecare_order" value="<?php echo (int) get_term_meta($term->term_id, 'ecare_order', true); ?>" style="width:90px" /></td>
        </tr>
        <?php
    }

    private static function mode_select($mode) {
        ?>
        <select id="ecare-mode" name="ecare_mode">
            <option value="manual" <?php selected($mode, self::MODE_MANUAL); ?>><?php esc_html_e('Tests ticked on this collection', 'ecare-health-services'); ?></option>
            <option value="most_booked" <?php selected($mode, self::MODE_MOST_BOOKED); ?>><?php esc_html_e('Most booked (from paid orders), ticked tests pinned first', 'ecare-health-services'); ?></option>
        </select>
        <?php
    }

    public static function collection_columns($columns) {
        $out = array();
        foreach ($columns as $key => $label) {
            if ($key === 'description' || $key === 'slug') {
                continue;
            }
            $out[$key] = $label;
            if ($key === 'name') {
                $out['ecare_mode']  = __('Which tests', 'ecare-health-services');
                $out['ecare_limit'] = __('Shows', 'ecare-health-services');
                $out['ecare_order'] = __('Order', 'ecare-health-services');
            }
        }
        return $out;
    }

    public static function collection_column_content($content, $column, $term_id) {
        if ($column === 'ecare_mode') {
            return self::collection_mode($term_id) === self::MODE_MOST_BOOKED
                ? esc_html__('Most booked (auto)', 'ecare-health-services')
                : esc_html__('Manual', 'ecare-health-services');
        }
        if ($column === 'ecare_limit') {
            return (string) self::collection_limit($term_id);
        }
        if ($column === 'ecare_order') {
            return (string) (int) get_term_meta((int) $term_id, 'ecare_order', true);
        }
        return $content;
    }
}
