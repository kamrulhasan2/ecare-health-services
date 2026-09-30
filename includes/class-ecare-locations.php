<?php
defined('ABSPATH') || exit;

/**
 * Location master for the lab catalogue: Division > District > Area.
 *
 * Until now every lab test carried four free-text, comma-separated lists
 * (_division, _district, _area, _lab_provider) with nothing tying an area to
 * its district. A test listing both Dhaka and Chattogram areas therefore
 * offered Chattogram areas to someone who had picked Dhaka, "Banani" and
 * "banani" became two options, and a LIKE match on "Mirpur-1" also caught
 * "Mirpur-10".
 *
 * This class is the single source of truth that replaces those lists. The
 * eight divisions and sixty-four districts are built in and seeded; admins add
 * only areas, and only under a district. Nothing reads from it yet - the lab
 * test form, the front-end filter and the data migration move over to it in
 * later steps.
 */
class ECare_Locations {

    const TAXONOMY = 'ecare_location';

    /** Bump when the seed list changes; the next page load re-runs the seed. */
    const SEED_VERSION = '1';
    const SEED_OPTION  = 'ecare_locations_seed_version';

    const LEVEL_DIVISION = 'division';
    const LEVEL_DISTRICT = 'district';
    const LEVEL_AREA     = 'area';

    /** Set while the seeder runs, so the admin-facing guards stand aside. */
    private static $seeding = false;

    public static function init() {
        add_action('init', array(__CLASS__, 'register_taxonomy'));
        add_action('init', array(__CLASS__, 'maybe_seed'), 20);

        add_filter('pre_insert_term', array(__CLASS__, 'guard_new_term'), 10, 3);
        add_action('created_' . self::TAXONOMY, array(__CLASS__, 'mark_new_area'));
        add_filter('wp_update_term_parent', array(__CLASS__, 'guard_parent_change'), 10, 3);
        add_action('pre_delete_term', array(__CLASS__, 'guard_delete'), 10, 2);

        if (is_admin()) {
            add_action('admin_menu', array(__CLASS__, 'add_menu'), 30);
            add_filter('parent_file', array(__CLASS__, 'highlight_menu'));
            add_filter('taxonomy_parent_dropdown_args', array(__CLASS__, 'parent_dropdown_args'), 10, 2);
            add_action(self::TAXONOMY . '_pre_add_form', array(__CLASS__, 'render_add_form_help'));
            add_filter('manage_edit-' . self::TAXONOMY . '_columns', array(__CLASS__, 'columns'));
            add_filter('manage_' . self::TAXONOMY . '_custom_column', array(__CLASS__, 'column_content'), 10, 3);
            add_filter(self::TAXONOMY . '_row_actions', array(__CLASS__, 'row_actions'), 10, 2);
        }
    }

    // -----------------------------------------------------------------------
    // Registration
    // -----------------------------------------------------------------------

    public static function register_taxonomy() {
        register_taxonomy(self::TAXONOMY, array('ecare_lab_test', 'ecare_lab_provider'), array(
            'labels' => array(
                'name'              => __('Lab Locations', 'ecare-health-services'),
                'singular_name'     => __('Location', 'ecare-health-services'),
                'search_items'      => __('Search Locations', 'ecare-health-services'),
                'all_items'         => __('All Locations', 'ecare-health-services'),
                'parent_item'       => __('District', 'ecare-health-services'),
                'parent_item_colon' => __('District:', 'ecare-health-services'),
                'edit_item'         => __('Edit Location', 'ecare-health-services'),
                'update_item'       => __('Update Location', 'ecare-health-services'),
                'add_new_item'      => __('Add New Area', 'ecare-health-services'),
                'new_item_name'     => __('New Area Name', 'ecare-health-services'),
                'not_found'         => __('No locations found.', 'ecare-health-services'),
            ),
            'hierarchical'       => true,
            // Catalogue data, like the post types: no archive, no front-end URL.
            'public'             => false,
            'publicly_queryable' => false,
            'show_ui'            => true,
            'show_in_menu'       => false,
            'show_in_nav_menus'  => false,
            'show_in_rest'       => false,
            'show_tagcloud'      => false,
            'show_in_quick_edit' => false,
            'show_admin_column'  => false,
            // The lab test form gets its own picker in a later step.
            'meta_box_cb'        => false,
            'query_var'          => false,
            'rewrite'            => false,
            'capabilities'       => array(
                'manage_terms' => 'manage_options',
                'edit_terms'   => 'manage_options',
                'delete_terms' => 'manage_options',
                'assign_terms' => 'manage_options',
            ),
        ));
    }

    // -----------------------------------------------------------------------
    // Seed data
    // -----------------------------------------------------------------------

    /**
     * The eight divisions and their sixty-four districts, English => Bangla.
     */
    public static function seed_data() {
        return array(
            'Dhaka' => array('bn' => 'ঢাকা', 'districts' => array(
                'Dhaka' => 'ঢাকা', 'Faridpur' => 'ফরিদপুর', 'Gazipur' => 'গাজীপুর',
                'Gopalganj' => 'গোপালগঞ্জ', 'Kishoreganj' => 'কিশোরগঞ্জ', 'Madaripur' => 'মাদারীপুর',
                'Manikganj' => 'মানিকগঞ্জ', 'Munshiganj' => 'মুন্সিগঞ্জ', 'Narayanganj' => 'নারায়ণগঞ্জ',
                'Narsingdi' => 'নরসিংদী', 'Rajbari' => 'রাজবাড়ী', 'Shariatpur' => 'শরীয়তপুর',
                'Tangail' => 'টাঙ্গাইল',
            )),
            'Chattogram' => array('bn' => 'চট্টগ্রাম', 'districts' => array(
                'Bandarban' => 'বান্দরবান', 'Brahmanbaria' => 'ব্রাহ্মণবাড়িয়া', 'Chandpur' => 'চাঁদপুর',
                'Chattogram' => 'চট্টগ্রাম', 'Cumilla' => 'কুমিল্লা', "Cox's Bazar" => 'কক্সবাজার',
                'Feni' => 'ফেনী', 'Khagrachhari' => 'খাগড়াছড়ি', 'Lakshmipur' => 'লক্ষ্মীপুর',
                'Noakhali' => 'নোয়াখালী', 'Rangamati' => 'রাঙ্গামাটি',
            )),
            'Rajshahi' => array('bn' => 'রাজশাহী', 'districts' => array(
                'Bogura' => 'বগুড়া', 'Chapainawabganj' => 'চাঁপাইনবাবগঞ্জ', 'Joypurhat' => 'জয়পুরহাট',
                'Naogaon' => 'নওগাঁ', 'Natore' => 'নাটোর', 'Pabna' => 'পাবনা',
                'Rajshahi' => 'রাজশাহী', 'Sirajganj' => 'সিরাজগঞ্জ',
            )),
            'Khulna' => array('bn' => 'খুলনা', 'districts' => array(
                'Bagerhat' => 'বাগেরহাট', 'Chuadanga' => 'চুয়াডাঙ্গা', 'Jashore' => 'যশোর',
                'Jhenaidah' => 'ঝিনাইদহ', 'Khulna' => 'খুলনা', 'Kushtia' => 'কুষ্টিয়া',
                'Magura' => 'মাগুরা', 'Meherpur' => 'মেহেরপুর', 'Narail' => 'নড়াইল',
                'Satkhira' => 'সাতক্ষীরা',
            )),
            'Barishal' => array('bn' => 'বরিশাল', 'districts' => array(
                'Barguna' => 'বরগুনা', 'Barishal' => 'বরিশাল', 'Bhola' => 'ভোলা',
                'Jhalokati' => 'ঝালকাঠি', 'Patuakhali' => 'পটুয়াখালী', 'Pirojpur' => 'পিরোজপুর',
            )),
            'Sylhet' => array('bn' => 'সিলেট', 'districts' => array(
                'Habiganj' => 'হবিগঞ্জ', 'Moulvibazar' => 'মৌলভীবাজার', 'Sunamganj' => 'সুনামগঞ্জ',
                'Sylhet' => 'সিলেট',
            )),
            'Rangpur' => array('bn' => 'রংপুর', 'districts' => array(
                'Dinajpur' => 'দিনাজপুর', 'Gaibandha' => 'গাইবান্ধা', 'Kurigram' => 'কুড়িগ্রাম',
                'Lalmonirhat' => 'লালমনিরহাট', 'Nilphamari' => 'নীলফামারী', 'Panchagarh' => 'পঞ্চগড়',
                'Rangpur' => 'রংপুর', 'Thakurgaon' => 'ঠাকুরগাঁও',
            )),
            'Mymensingh' => array('bn' => 'ময়মনসিংহ', 'districts' => array(
                'Jamalpur' => 'জামালপুর', 'Mymensingh' => 'ময়মনসিংহ', 'Netrokona' => 'নেত্রকোনা',
                'Sherpur' => 'শেরপুর',
            )),
        );
    }

    /**
     * Seed once per SEED_VERSION. Runs on init rather than only on activation
     * because uploading a new zip over an installed plugin does not activate it.
     */
    public static function maybe_seed() {
        if (get_option(self::SEED_OPTION) === self::SEED_VERSION) {
            return;
        }
        if (!taxonomy_exists(self::TAXONOMY)) {
            return;
        }
        if (self::seed()) {
            update_option(self::SEED_OPTION, self::SEED_VERSION, false);
        }
    }

    /**
     * Idempotent: each built-in term is found by its fixed slug before it is
     * created, so a second run adds nothing. Returns false if any insert failed,
     * which leaves the version unset and retries on the next load.
     */
    public static function seed() {
        self::$seeding = true;
        $ok = true;

        foreach (self::seed_data() as $division => $info) {
            $division_id = self::ensure_builtin($division, $info['bn'], 0, self::LEVEL_DIVISION);
            if (!$division_id) {
                $ok = false;
                continue;
            }
            foreach ($info['districts'] as $district => $bn) {
                if (!self::ensure_builtin($district, $bn, $division_id, self::LEVEL_DISTRICT)) {
                    $ok = false;
                }
            }
        }

        self::$seeding = false;
        return $ok;
    }

    /**
     * Division "Dhaka" and district "Dhaka" share a name, and slugs are unique
     * across the whole taxonomy, so the level goes into the slug.
     */
    public static function builtin_slug($name, $level) {
        return sanitize_title($name) . '-' . $level;
    }

    private static function ensure_builtin($name, $bn, $parent_id, $level) {
        $slug     = self::builtin_slug($name, $level);
        $existing = get_term_by('slug', $slug, self::TAXONOMY);

        if ($existing) {
            $term_id = (int) $existing->term_id;
        } else {
            $result = wp_insert_term($name, self::TAXONOMY, array('slug' => $slug, 'parent' => (int) $parent_id));
            if (is_wp_error($result)) {
                return 0;
            }
            $term_id = (int) $result['term_id'];
        }

        update_term_meta($term_id, 'ecare_level', $level);
        update_term_meta($term_id, 'ecare_builtin', 1);
        update_term_meta($term_id, 'ecare_name_bn', $bn);
        return $term_id;
    }

    // -----------------------------------------------------------------------
    // Helpers other steps will use
    // -----------------------------------------------------------------------

    /**
     * One comparison key per place name: case, spacing and punctuation fold
     * away, so "Mirpur-1", "mirpur 1" and " Mirpur 1 " are the same place while
     * "Mirpur-10" stays different. Bangla letters survive (\p{L}, \p{M}).
     */
    public static function name_key($name) {
        $name = function_exists('mb_strtolower') ? mb_strtolower((string) $name, 'UTF-8') : strtolower((string) $name);
        return (string) preg_replace('/[^\p{L}\p{M}\p{N}]+/u', '', $name);
    }

    /** Trim and collapse inner whitespace; the stored display name. */
    public static function clean_name($name) {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $name));
    }

    public static function get_level($term_id) {
        $level = get_term_meta((int) $term_id, 'ecare_level', true);
        if ($level) {
            return $level;
        }
        // Fallback by depth, for a term created some way that bypassed the hooks.
        $depth = count(get_ancestors((int) $term_id, self::TAXONOMY, 'taxonomy'));
        return $depth === 0 ? self::LEVEL_DIVISION : ($depth === 1 ? self::LEVEL_DISTRICT : self::LEVEL_AREA);
    }

    public static function is_builtin($term_id) {
        return (bool) get_term_meta((int) $term_id, 'ecare_builtin', true);
    }

    /** Term id of a sibling with the same name_key under $parent_id, or 0. */
    public static function find_sibling($name, $parent_id, $exclude_id = 0) {
        $key = self::name_key($name);
        if ($key === '') {
            return 0;
        }
        $siblings = get_terms(array(
            'taxonomy'   => self::TAXONOMY,
            'parent'     => (int) $parent_id,
            'hide_empty' => false,
        ));
        if (is_wp_error($siblings)) {
            return 0;
        }
        foreach ($siblings as $s) {
            if ((int) $s->term_id !== (int) $exclude_id && self::name_key($s->name) === $key) {
                return (int) $s->term_id;
            }
        }
        return 0;
    }

    // -----------------------------------------------------------------------
    // Guards
    // -----------------------------------------------------------------------

    /**
     * Admins may add areas only, and only directly under a district.
     *
     * @param string|WP_Error $term
     * @param string          $taxonomy
     * @param array           $args     Passed since WP 6.1; older cores fall back to $_POST.
     */
    public static function guard_new_term($term, $taxonomy, $args = array()) {
        if ($taxonomy !== self::TAXONOMY || self::$seeding || is_wp_error($term)) {
            return $term;
        }

        $name = self::clean_name($term);
        if (self::name_key($name) === '') {
            return new WP_Error('ecare_location_empty', __('Enter an area name.', 'ecare-health-services'));
        }

        $parent = isset($args['parent']) ? (int) $args['parent'] : (int) ($_POST['parent'] ?? 0);
        if ($parent <= 0 || self::get_level($parent) !== self::LEVEL_DISTRICT) {
            return new WP_Error(
                'ecare_location_parent',
                __('Choose the district this area belongs to. Divisions and districts are built in; only areas can be added.', 'ecare-health-services')
            );
        }

        $dupe = self::find_sibling($name, $parent);
        if ($dupe) {
            $existing = get_term($dupe, self::TAXONOMY);
            return new WP_Error(
                'ecare_location_duplicate',
                sprintf(
                    /* translators: %s: existing area name */
                    __('This district already has the area "%s".', 'ecare-health-services'),
                    $existing && !is_wp_error($existing) ? $existing->name : $name
                )
            );
        }

        return $name;
    }

    public static function mark_new_area($term_id) {
        if (self::$seeding) {
            return;
        }
        update_term_meta((int) $term_id, 'ecare_level', self::LEVEL_AREA);
    }

    /**
     * wp_update_term() cannot be refused from here, so an invalid parent is
     * quietly kept as it was: built-in terms never move, and an area may only
     * move to another district.
     */
    public static function guard_parent_change($parent, $term_id, $taxonomy) {
        if ($taxonomy !== self::TAXONOMY || self::$seeding) {
            return $parent;
        }
        $current = get_term((int) $term_id, self::TAXONOMY);
        $old     = ($current && !is_wp_error($current)) ? (int) $current->parent : 0;

        if (self::is_builtin($term_id)) {
            return $old;
        }
        if ((int) $parent <= 0 || self::get_level($parent) !== self::LEVEL_DISTRICT) {
            return $old;
        }
        return $parent;
    }

    public static function guard_delete($term_id, $taxonomy) {
        if ($taxonomy !== self::TAXONOMY || !self::is_builtin($term_id)) {
            return;
        }
        wp_die(
            esc_html__('Divisions and districts are built in and cannot be deleted. Only areas can be removed.', 'ecare-health-services'),
            esc_html__('Not allowed', 'ecare-health-services'),
            array('response' => 403, 'back_link' => true)
        );
    }

    // -----------------------------------------------------------------------
    // Admin screen
    // -----------------------------------------------------------------------

    public static function menu_slug() {
        return 'edit-tags.php?taxonomy=' . self::TAXONOMY . '&post_type=ecare_lab_test';
    }

    public static function add_menu() {
        add_submenu_page(
            'ecare-dashboard',
            __('Lab Locations', 'ecare-health-services'),
            __('Lab Locations', 'ecare-health-services'),
            'manage_options',
            self::menu_slug()
        );
    }

    public static function highlight_menu($parent_file) {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && $screen->taxonomy === self::TAXONOMY) {
            $GLOBALS['submenu_file'] = self::menu_slug();
            return 'ecare-dashboard';
        }
        return $parent_file;
    }

    /** The parent picker lists divisions and districts only, never areas. */
    public static function parent_dropdown_args($args, $taxonomy) {
        if ($taxonomy === self::TAXONOMY) {
            $args['depth']            = 2;
            $args['show_option_none'] = __('— Select district —', 'ecare-health-services');
        }
        return $args;
    }

    public static function render_add_form_help() {
        echo '<div class="notice notice-info inline" style="margin:0 0 12px;"><p>'
            . esc_html__('Divisions and districts are built in. Add an area by typing its name and choosing its district as the parent. Names that differ only in case, spaces or punctuation count as the same area.', 'ecare-health-services')
            . '</p></div>';
    }

    public static function columns($columns) {
        $out = array();
        foreach ($columns as $key => $label) {
            if ($key === 'description') {
                continue;
            }
            $out[$key] = $label;
            if ($key === 'name') {
                $out['ecare_level']   = __('Level', 'ecare-health-services');
                $out['ecare_name_bn'] = __('Bangla name', 'ecare-health-services');
            }
        }
        return $out;
    }

    public static function column_content($content, $column, $term_id) {
        if ($column === 'ecare_level') {
            $labels = array(
                self::LEVEL_DIVISION => __('Division', 'ecare-health-services'),
                self::LEVEL_DISTRICT => __('District', 'ecare-health-services'),
                self::LEVEL_AREA     => __('Area', 'ecare-health-services'),
            );
            $level = self::get_level($term_id);
            return esc_html($labels[$level] ?? $level);
        }
        if ($column === 'ecare_name_bn') {
            return esc_html((string) get_term_meta((int) $term_id, 'ecare_name_bn', true));
        }
        return $content;
    }

    public static function row_actions($actions, $term) {
        if (self::is_builtin($term->term_id)) {
            unset($actions['delete']);
        }
        return $actions;
    }

    // -----------------------------------------------------------------------
    // Shared helpers for coverage (providers and tests)
    // -----------------------------------------------------------------------

    /**
     * Turn a coverage selection (district and area ids) into the area ids it
     * reaches: an area is itself, a district is every area under it.
     *
     * @return int[] sorted, unique
     */
    public static function expand_to_areas($ids) {
        $out = array();
        foreach ((array) $ids as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            $level = self::get_level($id);
            if ($level === self::LEVEL_AREA) {
                $out[$id] = true;
            } elseif ($level === self::LEVEL_DISTRICT) {
                $children = get_terms(array(
                    'taxonomy'   => self::TAXONOMY,
                    'parent'     => $id,
                    'hide_empty' => false,
                    'fields'     => 'ids',
                ));
                if (!is_wp_error($children)) {
                    foreach ($children as $child) {
                        $out[(int) $child] = true;
                    }
                }
            }
        }
        $out = array_keys($out);
        sort($out);
        return $out;
    }

    /**
     * The Division > District > Area checkbox tree used on the provider and
     * lab test screens. Collapsed by default, opened where something is ticked,
     * with a search box. Ticking "whole district" locks its areas; the server
     * folds them into the district regardless.
     *
     * @param int[]  $selected   Ticked district / area ids.
     * @param string $field_name Posted as $field_name[].
     * @param string $dom_id     Unique id for the wrapper; the script is scoped to it.
     */
    public static function render_checkbox_tree($selected, $field_name, $dom_id) {
        $terms = get_terms(array(
            'taxonomy'   => self::TAXONOMY,
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
        $selected = array_flip(array_map('intval', (array) $selected));
        $name     = $field_name . '[]';
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
            <input type="search" class="regular-text ecare-cov-search" data-tree="<?php echo esc_attr($dom_id); ?>" placeholder="<?php esc_attr_e('Search district or area…', 'ecare-health-services'); ?>" />
            <a href="<?php echo esc_url(admin_url(self::menu_slug())); ?>" target="_blank"><?php esc_html_e('Add areas', 'ecare-health-services'); ?></a>
        </div>
        <div class="ecare-cov" id="<?php echo esc_attr($dom_id); ?>">
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
                    $areas  = $children[(int) $district->term_id] ?? array();
                    $whole  = isset($selected[(int) $district->term_id]);
                    $picked = 0;
                    foreach ($areas as $a) { $picked += isset($selected[(int) $a->term_id]) ? 1 : 0; }
                    ?>
                    <details class="ecare-cov-district" data-id="<?php echo (int) $district->term_id; ?>" data-name="<?php echo esc_attr(strtolower($district->name)); ?>" <?php echo ($whole || $picked) ? 'open' : ''; ?>>
                        <summary><?php echo esc_html($district->name); ?>
                            <?php if ($whole): ?><span class="ecare-cov-count"><?php esc_html_e('whole district', 'ecare-health-services'); ?></span>
                            <?php elseif ($picked): ?><span class="ecare-cov-count">(<?php echo (int) $picked; ?>)</span><?php endif; ?>
                        </summary>
                        <label class="ecare-cov-whole">
                            <input type="checkbox" class="ecare-cov-whole-cb" name="<?php echo esc_attr($name); ?>" value="<?php echo (int) $district->term_id; ?>" <?php checked($whole); ?> />
                            <?php esc_html_e('Whole district - every area, including ones added later', 'ecare-health-services'); ?>
                        </label>
                        <?php if ($areas): ?>
                            <div class="ecare-cov-areas">
                            <?php foreach ($areas as $area): ?>
                                <label data-id="<?php echo (int) $area->term_id; ?>" data-name="<?php echo esc_attr(strtolower($area->name)); ?>" class="<?php echo $whole ? 'is-covered' : ''; ?>">
                                    <input type="checkbox" class="ecare-cov-area-cb" name="<?php echo esc_attr($name); ?>" value="<?php echo (int) $area->term_id; ?>" <?php checked(isset($selected[(int) $area->term_id]) || $whole); ?> <?php disabled($whole); ?> />
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
            var root = document.getElementById(<?php echo wp_json_encode($dom_id); ?>);
            if (!root) { return; }
            root.addEventListener('change', function (e) {
                if (!e.target.classList.contains('ecare-cov-whole-cb')) { return; }
                var box = e.target.closest('.ecare-cov-district');
                box.querySelectorAll('.ecare-cov-area-cb').forEach(function (cb) {
                    cb.disabled = e.target.checked;
                    cb.checked = e.target.checked;
                    cb.closest('label').classList.toggle('is-covered', e.target.checked);
                });
            });
            // Hidden by something else (e.g. a provider filter) stays hidden:
            // the search only narrows what is already allowed.
            var search = document.querySelector('.ecare-cov-search[data-tree="' + root.id + '"]');
            search.addEventListener('input', function () {
                var q = search.value.trim().toLowerCase();
                root.querySelectorAll('.ecare-cov-district').forEach(function (d) {
                    var hit = !q || d.dataset.name.indexOf(q) !== -1, anyArea = false;
                    d.querySelectorAll('.ecare-cov-areas label').forEach(function (l) {
                        var m = !q || hit || l.dataset.name.indexOf(q) !== -1;
                        l.classList.toggle('ecare-search-miss', !m);
                        anyArea = anyArea || (q && l.dataset.name.indexOf(q) !== -1);
                    });
                    var show = hit || anyArea;
                    d.classList.toggle('ecare-search-miss', !show);
                    if (q && show) { d.open = true; d.closest('.ecare-cov-division').open = true; }
                });
                root.querySelectorAll('.ecare-cov-division').forEach(function (dv) {
                    var visible = dv.querySelector('.ecare-cov-district:not(.ecare-search-miss):not(.ecare-scope-miss)');
                    dv.classList.toggle('ecare-search-miss', !visible);
                });
            });
        })();
        </script>
        <style>.ecare-cov .ecare-search-miss,.ecare-cov .ecare-scope-miss{display:none!important}</style>
        <?php
    }
}
