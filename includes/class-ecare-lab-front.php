<?php
defined('ABSPATH') || exit;

/**
 * The new lab front end: shortcodes, page links, the shared test card, and
 * its own stylesheet and script.
 *
 * Kept apart from the rest of the plugin on purpose. Its assets load only on
 * pages that contain one of its shortcodes or Elementor widgets, all its CSS
 * sits under .ecl, and it does not touch ecare-style.css, ecare-script.js or
 * the old [ecare_lab_tests] catalogue - those keep working as they are.
 *
 *   [ecare_lab_home]     the lab landing page
 *   [ecare_lab_catalog]  all tests, with filters
 *   [ecare_lab_tests]  is the OLD catalogue and stays as it is until switch-over
 */
class ECare_Lab_Front {

    /** Shortcodes (and Elementor widget names) of the new lab front end. */
    const TAGS = array('ecare_lab_home', 'ecare_lab_catalog', 'ecare_lab_cart');

    public static function init() {
        add_shortcode('ecare_lab_home', array(__CLASS__, 'render_home'));
        add_shortcode('ecare_lab_catalog', array(__CLASS__, 'render_catalog'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue'), 20);
        add_action('elementor/widgets/register', array(__CLASS__, 'register_widgets'));
        // A page gained or lost a lab shortcode: look again.
        add_action('save_post_page', array('ECare_Lab_Settings', 'forget_pages'));
        add_action('deleted_post', array('ECare_Lab_Settings', 'forget_pages'));
    }

    // =======================================================================
    // Where things are
    // =======================================================================

    /**
     * The page that holds a given lab screen: the one chosen in Lab Settings,
     * else the first published page containing its shortcode or widget.
     *
     * @param string $which home | tests | cart
     */
    public static function page_id($which) {
        $tags = array('home' => 'ecare_lab_home', 'tests' => 'ecare_lab_catalog', 'cart' => 'ecare_lab_cart');
        if (!isset($tags[$which])) {
            return 0;
        }
        $chosen = (int) ECare_Lab_Settings::get('page_' . $which);
        if ($chosen && get_post_status($chosen) === 'publish') {
            return $chosen;
        }
        $cache = get_transient('ecare_lab_page_' . $which);
        if ($cache !== false) {
            return (int) $cache;
        }
        global $wpdb;
        $like = '%' . $wpdb->esc_like($tags[$which]) . '%';
        $id   = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_elementor_data'
             WHERE p.post_type = 'page' AND p.post_status = 'publish'
             AND (p.post_content LIKE %s OR m.meta_value LIKE %s)
             ORDER BY p.ID ASC LIMIT 1",
            $like, $like
        ));
        // Only a hit is remembered: a miss must not hide a page added a minute later.
        if ($id) {
            set_transient('ecare_lab_page_' . $which, $id, HOUR_IN_SECONDS);
        }
        return $id;
    }

    /**
     * Links between lab screens.
     *
     *   url('tests', array('category' => 'diabetes'))
     *   url('test', array('id' => 12))   the detail page (step 11 may give it a prettier path)
     */
    public static function url($which, $args = array()) {
        if ($which === 'test') {
            $post = get_post((int) ($args['id'] ?? 0));
            $base = self::url('tests');
            return $post ? add_query_arg('lab_test', $post->post_name ?: $post->ID, $base) : $base;
        }
        $id   = self::page_id($which);
        $base = $id ? get_permalink($id) : home_url('/');
        return $args ? add_query_arg(array_map('rawurlencode', $args), $base) : $base;
    }

    // =======================================================================
    // Assets
    // =======================================================================

    /** Does the page being shown contain any of our shortcodes or widgets? */
    public static function on_lab_page() {
        $post = is_singular() ? get_post() : null;
        if (!$post) {
            return (bool) apply_filters('ecare_lab_front_on_page', false, null);
        }
        $haystack = (string) $post->post_content . ' ' . (string) get_post_meta($post->ID, '_elementor_data', true);
        $found    = false;
        foreach (self::TAGS as $tag) {
            if (strpos($haystack, $tag) !== false) {
                $found = true;
                break;
            }
        }
        return (bool) apply_filters('ecare_lab_front_on_page', $found, $post);
    }

    public static function enqueue() {
        if (!self::on_lab_page()) {
            return;
        }
        $css = ECARE_PLUGIN_DIR . 'assets/css/ecare-lab.css';
        $js  = ECARE_PLUGIN_DIR . 'assets/js/ecare-lab.js';
        wp_enqueue_style('ecare-lab', ECARE_PLUGIN_URL . 'assets/css/ecare-lab.css', array(), file_exists($css) ? filemtime($css) : ECARE_VERSION);
        wp_enqueue_script('ecare-lab', ECARE_PLUGIN_URL . 'assets/js/ecare-lab.js', array(), file_exists($js) ? filemtime($js) : ECARE_VERSION, true);
        do_action('litespeed_nonce', 'ecare_lab');
        wp_localize_script('ecare-lab', 'ecareLab', array(
            'ajax'  => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ecare_lab'),
            'urls'  => array('tests' => self::url('tests'), 'cart' => self::url('cart')),
        ));
    }

    public static function register_widgets($manager) {
        require_once ECARE_PLUGIN_DIR . 'includes/class-ecare-lab-elementor-widgets.php';
        $manager->register(new ECare_Elementor_Lab_Home());
        $manager->register(new ECare_Elementor_Lab_Catalog());
    }

    // =======================================================================
    // Pieces
    // =======================================================================

    /** ৳300, ৳1,895, ৳120.50 */
    public static function money($n) {
        $n = (float) $n;
        return '৳' . number_format_i18n($n, floor($n) == $n ? 0 : 2);
    }

    /** A small inline icon, so the page needs no icon font. */
    public static function icon($name) {
        $paths = array(
            'clock'  => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'flask'  => '<path d="M9 3h6M10 3v6l-5 9a2 2 0 0 0 2 3h10a2 2 0 0 0 2-3l-5-9V3"/><path d="M7.5 15h9"/>',
            'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
            'left'   => '<path d="m15 18-6-6 6-6"/>',
            'right'  => '<path d="m9 18 6-6-6-6"/>',
            'chat'   => '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/>',
            'list'   => '<path d="M9 6h11M9 12h11M9 18h11"/><circle cx="4.5" cy="6" r="1"/><circle cx="4.5" cy="12" r="1"/><circle cx="4.5" cy="18" r="1"/>',
            'box'    => '<path d="M21 8 12 3 3 8v8l9 5 9-5z"/><path d="m3 8 9 5 9-5M12 13v8"/>',
        );
        return '<svg class="ecl-i" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
    }

    /** The test card used on every lab screen. $c is ECare_Lab_Catalog::card(). */
    public static function render_card($c) {
        $url = self::url('test', array('id' => $c['id']));
        ob_start();
        ?>
        <article class="ecl-card">
            <a class="ecl-card-img" href="<?php echo esc_url($url); ?>" tabindex="-1" aria-hidden="true">
                <?php if ($c['image']): ?>
                    <img src="<?php echo esc_url($c['image']); ?>" alt="" loading="lazy" />
                <?php else: ?>
                    <span class="ecl-card-ph"><?php echo self::icon($c['type'] === 'package' ? 'box' : 'flask'); // phpcs:ignore ?></span>
                <?php endif; ?>
            </a>
            <div class="ecl-card-body">
                <h3 class="ecl-card-title"><a href="<?php echo esc_url($url); ?>"><?php echo esc_html($c['title']); ?></a></h3>
                <?php if ($c['type'] === 'package' && $c['items']): ?>
                    <p class="ecl-card-sub"><?php echo esc_html(sprintf(_n('Includes %d test', 'Includes %d tests', $c['items'], 'ecare-health-services'), $c['items'])); ?></p>
                <?php endif; ?>
                <?php if ($c['labs']): ?>
                    <div class="ecl-card-labs">
                        <?php foreach ($c['labs'] as $lab): ?>
                            <?php if ($lab['logo']): ?>
                                <img src="<?php echo esc_url($lab['logo']); ?>" alt="<?php echo esc_attr($lab['name']); ?>" title="<?php echo esc_attr($lab['name']); ?>" loading="lazy" />
                            <?php else: ?>
                                <span class="ecl-lab-initial" title="<?php echo esc_attr($lab['name']); ?>"><?php echo esc_html(function_exists('mb_substr') ? mb_substr($lab['name'], 0, 1) : substr($lab['name'], 0, 1)); ?></span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <?php if ($c['more_labs']): ?><span class="ecl-lab-more">+<?php echo (int) $c['more_labs']; ?></span><?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php if ($c['report']): ?>
                    <p class="ecl-card-meta"><?php echo self::icon('clock'); // phpcs:ignore ?> <?php echo esc_html(sprintf(__('Report in %s', 'ecare-health-services'), $c['report'])); ?></p>
                <?php endif; ?>
            </div>
            <div class="ecl-card-foot">
                <div class="ecl-price">
                    <strong><?php echo esc_html(self::money($c['price'])); ?></strong>
                    <?php if ($c['mrp'] > 0): ?>
                        <del><?php echo esc_html(self::money($c['mrp'])); ?></del>
                        <span class="ecl-off"><?php echo esc_html(sprintf(__('%d%% OFF', 'ecare-health-services'), $c['discount'])); ?></span>
                    <?php endif; ?>
                </div>
                <a class="ecl-btn ecl-btn-sm" href="<?php echo esc_url($url . '#book'); ?>" data-ecl-book="<?php echo (int) $c['id']; ?>"><?php esc_html_e('Book Test', 'ecare-health-services'); ?></a>
            </div>
        </article>
        <?php
        return ob_get_clean();
    }

    private static function section_head($title, $link = '', $scroller = '') {
        ?>
        <div class="ecl-sec-head">
            <h2><?php echo esc_html($title); ?></h2>
            <div class="ecl-sec-tools">
                <?php if ($scroller): ?>
                    <button type="button" class="ecl-arrow" data-ecl-scroll="<?php echo esc_attr($scroller); ?>" data-dir="-1" aria-label="<?php esc_attr_e('Previous', 'ecare-health-services'); ?>"><?php echo self::icon('left'); // phpcs:ignore ?></button>
                    <button type="button" class="ecl-arrow" data-ecl-scroll="<?php echo esc_attr($scroller); ?>" data-dir="1" aria-label="<?php esc_attr_e('Next', 'ecare-health-services'); ?>"><?php echo self::icon('right'); // phpcs:ignore ?></button>
                <?php endif; ?>
                <?php if ($link): ?><a class="ecl-link" href="<?php echo esc_url($link); ?>"><?php esc_html_e('View All', 'ecare-health-services'); ?></a><?php endif; ?>
            </div>
        </div>
        <?php
    }

    // =======================================================================
    // [ecare_lab_home]
    // =======================================================================

    public static function render_home() {
        $s        = ECare_Lab_Settings::all();
        $filters  = ECare_Lab_Catalog::filters();
        $tests    = self::url('tests');
        $banner   = $s['banner_id'] ? wp_get_attachment_image_url((int) $s['banner_id'], 'full') : '';
        $counts   = array();
        foreach ($filters['categories'] as $c) {
            $counts[$c['id']] = $c;
        }

        ob_start();
        ?>
        <div class="ecl ecl-home">

            <?php if ($banner): ?>
                <div class="ecl-banner">
                    <?php if ($s['banner_link']): ?><a href="<?php echo esc_url($s['banner_link']); ?>"><?php endif; ?>
                    <img src="<?php echo esc_url($banner); ?>" alt="<?php esc_attr_e('Home lab tests', 'ecare-health-services'); ?>" />
                    <?php if ($s['banner_link']): ?></a><?php endif; ?>
                </div>
            <?php else: ?>
                <div class="ecl-hero">
                    <h1><?php esc_html_e('Lab tests at home', 'ecare-health-services'); ?></h1>
                    <p><?php esc_html_e('Book a test, choose your lab, and a collector comes to you.', 'ecare-health-services'); ?></p>
                </div>
            <?php endif; ?>

            <form class="ecl-search" action="<?php echo esc_url($tests); ?>" method="get" role="search">
                <?php echo self::icon('search'); // phpcs:ignore ?>
                <?php /* "q", not "s": ?s= is WordPress's own search and would leave the lab page. */ ?>
                <input type="search" name="q" placeholder="<?php esc_attr_e('Search lab tests or packages…', 'ecare-health-services'); ?>" aria-label="<?php esc_attr_e('Search lab tests', 'ecare-health-services'); ?>" />
                <button type="submit" class="ecl-btn"><?php esc_html_e('Search', 'ecare-health-services'); ?></button>
            </form>

            <div class="ecl-quick">
                <?php if ($s['messenger_link']): ?>
                    <a class="ecl-quick-card" href="<?php echo esc_url($s['messenger_link']); ?>" target="_blank" rel="noopener">
                        <?php echo self::icon('chat'); // phpcs:ignore ?><span><small><?php esc_html_e('Order via', 'ecare-health-services'); ?></small><?php esc_html_e('Messenger', 'ecare-health-services'); ?></span>
                    </a>
                <?php endif; ?>
                <a class="ecl-quick-card" href="<?php echo esc_url($tests); ?>">
                    <?php echo self::icon('list'); // phpcs:ignore ?><span><small><?php esc_html_e('Explore', 'ecare-health-services'); ?></small><?php esc_html_e('Lab Tests', 'ecare-health-services'); ?></span>
                </a>
                <?php if ($s['hotline']): ?>
                    <a class="ecl-quick-card" href="<?php echo esc_url('tel:' . preg_replace('/[^0-9+]/', '', $s['hotline'])); ?>">
                        <?php echo self::icon('chat'); // phpcs:ignore ?><span><small><?php esc_html_e('Hotline', 'ecare-health-services'); ?></small><?php echo esc_html($s['hotline']); ?></span>
                    </a>
                <?php endif; ?>
            </div>

            <?php foreach (ECare_Lab_Taxonomies::collections() as $col):
                $cards = ECare_Lab_Catalog::collection_cards($col->term_id);
                if (!$cards) { continue; }
                $sid = 'ecl-row-' . (int) $col->term_id;
                ?>
                <section class="ecl-sec">
                    <?php self::section_head($col->name, self::url('tests', array('collection' => $col->slug)), $sid); ?>
                    <div class="ecl-row" id="<?php echo esc_attr($sid); ?>">
                        <?php foreach ($cards as $c) { echo self::render_card($c); } // phpcs:ignore ?>
                    </div>
                </section>
            <?php endforeach; ?>

            <?php foreach (array(
                ECare_Lab_Taxonomies::GROUP_ORGAN   => __('Checkups based on vital organs', 'ecare-health-services'),
                ECare_Lab_Taxonomies::GROUP_CONCERN => __('Browse by health concerns', 'ecare-health-services'),
            ) as $group => $title):
                $cats = array_values(array_filter(ECare_Lab_Taxonomies::categories($group), function ($t) use ($counts) { return isset($counts[$t->term_id]); }));
                if (!$cats) { continue; }
                ?>
                <section class="ecl-sec">
                    <?php self::section_head($title); ?>
                    <div class="ecl-cats">
                        <?php foreach ($cats as $t): $icon = ECare_Lab_Taxonomies::icon_url($t->term_id); ?>
                            <a class="ecl-cat" href="<?php echo esc_url(self::url('tests', array('category' => $t->slug))); ?>">
                                <span class="ecl-cat-icon"><?php if ($icon): ?><img src="<?php echo esc_url($icon); ?>" alt="" loading="lazy" /><?php else: echo self::icon('flask'); endif; // phpcs:ignore ?></span>
                                <span class="ecl-cat-name"><?php echo esc_html($t->name); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>

            <?php if ($filters['labs']): ?>
                <section class="ecl-sec">
                    <?php self::section_head(__('Our trusted lab partners', 'ecare-health-services'), $tests); ?>
                    <div class="ecl-partners">
                        <?php foreach ($filters['labs'] as $lab): ?>
                            <a class="ecl-partner" href="<?php echo esc_url(self::url('tests', array('lab' => $lab['id']))); ?>">
                                <?php if ($lab['logo']): ?><img src="<?php echo esc_url($lab['logo']); ?>" alt="" loading="lazy" /><?php else: ?><span class="ecl-lab-initial"><?php echo esc_html(function_exists('mb_substr') ? mb_substr($lab['name'], 0, 1) : substr($lab['name'], 0, 1)); ?></span><?php endif; ?>
                                <span><?php echo esc_html($lab['name']); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <section class="ecl-sec">
                <div class="ecl-sec-head"><h2><?php esc_html_e('How we work', 'ecare-health-services'); ?></h2></div>
                <ol class="ecl-steps">
                    <?php foreach ((array) $s['steps'] as $i => $step): ?>
                        <li>
                            <span class="ecl-step-n"><?php echo (int) $i + 1; ?></span>
                            <h3><?php echo esc_html($step['title']); ?></h3>
                            <?php if ($step['text'] !== ''): ?><p><?php echo esc_html($step['text']); ?></p><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </section>

        </div>
        <?php
        return ob_get_clean();
    }

    // =======================================================================
    // [ecare_lab_catalog] - all tests
    // =======================================================================

    const SORTS = array('name', 'price_asc', 'price_desc');

    /**
     * The tests page's own query string, cleaned. Parameter names avoid
     * WordPress's: "q" not "s" (site search), "pg" not "page"/"paged"
     * (a page's own pagination).
     */
    public static function catalog_args($in) {
        $in   = is_array($in) ? $in : array();
        $get  = function ($k) use ($in) { return isset($in[$k]) ? wp_unslash($in[$k]) : ''; };
        $q    = sanitize_text_field((string) $get('q'));
        $sort = sanitize_key((string) $get('sort'));
        $type = sanitize_key((string) $get('type'));
        return array(
            'q'          => function_exists('mb_substr') ? mb_substr($q, 0, 100) : substr($q, 0, 100),
            'category'   => sanitize_title((string) $get('category')),
            'collection' => sanitize_title((string) $get('collection')),
            'lab'        => max(0, (int) $get('lab')),
            'type'       => in_array($type, array('single', 'package'), true) ? $type : '',
            'sort'       => in_array($sort, self::SORTS, true) ? $sort : 'name',
            'pg'         => max(1, (int) $get('pg')),
        );
    }

    /** The tests page with these arguments; empty and default values are left out. */
    public static function catalog_url($args) {
        $keep = array();
        foreach ($args as $k => $v) {
            if ($v === '' || $v === 0 || $v === null || ($k === 'sort' && $v === 'name') || ($k === 'pg' && (int) $v <= 1)) {
                continue;
            }
            $keep[$k] = $v;
        }
        return self::url('tests', $keep);
    }

    public static function render_catalog() {
        $args    = self::catalog_args($_GET);
        $filters = ECare_Lab_Catalog::filters();
        $result  = ECare_Lab_Catalog::search(array(
            's'          => $args['q'],
            'category'   => $args['category'],
            'collection' => $args['collection'],
            'provider'   => $args['lab'],
            'type'       => $args['type'],
            'orderby'    => $args['sort'],
            'page'       => $args['pg'],
            'per_page'   => 12,
        ));
        $base = self::url('tests');

        // Plain permalinks put the page in the query string (?page_id=20); a
        // GET form would drop it, so it rides along as hidden fields.
        $hidden = array();
        $qs     = (string) wp_parse_url($base, PHP_URL_QUERY);
        if ($qs !== '') {
            parse_str($qs, $hidden);
        }

        // Names for the chips.
        $names = array('category' => array(), 'collection' => array(), 'lab' => array());
        foreach ($filters['categories'] as $c) { $names['category'][$c['slug']] = $c['name']; }
        foreach ($filters['collections'] as $c) { $names['collection'][$c['slug']] = $c['name']; }
        foreach ($filters['labs'] as $l) { $names['lab'][$l['id']] = $l['name']; }
        $chips = array();
        if ($args['q'] !== '') { $chips['q'] = '"' . $args['q'] . '"'; }
        foreach (array('category', 'collection', 'lab') as $k) {
            if ($args[$k]) { $chips[$k] = $names[$k][$args[$k]] ?? (string) $args[$k]; }
        }
        if ($args['type']) { $chips['type'] = $args['type'] === 'package' ? __('Packages', 'ecare-health-services') : __('Single tests', 'ecare-health-services'); }

        $from = $result['total'] ? ($result['page'] - 1) * 12 + 1 : 0;
        $to   = $from ? $from + count($result['items']) - 1 : 0;

        ob_start();
        ?>
        <div class="ecl ecl-catalog">
            <nav class="ecl-crumbs" aria-label="<?php esc_attr_e('Breadcrumb', 'ecare-health-services'); ?>">
                <a href="<?php echo esc_url(home_url('/')); ?>"><?php esc_html_e('Home', 'ecare-health-services'); ?></a>
                <?php if (self::page_id('home')): ?><span>›</span><a href="<?php echo esc_url(self::url('home')); ?>"><?php esc_html_e('Home Lab', 'ecare-health-services'); ?></a><?php endif; ?>
                <span>›</span><span aria-current="page"><?php esc_html_e('All Lab Tests', 'ecare-health-services'); ?></span>
            </nav>

            <form class="ecl-cat-form" action="<?php echo esc_url(strtok($base, '?')); ?>" method="get" data-ecl-autosubmit>
                <?php foreach ($hidden as $k => $v): if (is_scalar($v)): ?>
                    <input type="hidden" name="<?php echo esc_attr($k); ?>" value="<?php echo esc_attr($v); ?>" />
                <?php endif; endforeach; ?>

                <div class="ecl-toolbar">
                    <div class="ecl-search">
                        <?php echo self::icon('search'); // phpcs:ignore ?>
                        <input type="search" name="q" value="<?php echo esc_attr($args['q']); ?>" placeholder="<?php esc_attr_e('Search lab tests or packages…', 'ecare-health-services'); ?>" aria-label="<?php esc_attr_e('Search lab tests', 'ecare-health-services'); ?>" />
                        <button type="submit" class="ecl-btn"><?php esc_html_e('Search', 'ecare-health-services'); ?></button>
                    </div>
                    <div class="ecl-toolbar-row">
                        <button type="button" class="ecl-filter-toggle" data-ecl-drawer="open" aria-controls="ecl-filters"><?php echo self::icon('list'); // phpcs:ignore ?> <?php esc_html_e('Filters', 'ecare-health-services'); ?><?php if ($chips): ?> <span class="ecl-badge"><?php echo count($chips); ?></span><?php endif; ?></button>
                        <label class="ecl-sort">
                            <span><?php esc_html_e('Sort', 'ecare-health-services'); ?></span>
                            <select name="sort">
                                <option value="name" <?php selected($args['sort'], 'name'); ?>><?php esc_html_e('Name', 'ecare-health-services'); ?></option>
                                <option value="price_asc" <?php selected($args['sort'], 'price_asc'); ?>><?php esc_html_e('Price: low to high', 'ecare-health-services'); ?></option>
                                <option value="price_desc" <?php selected($args['sort'], 'price_desc'); ?>><?php esc_html_e('Price: high to low', 'ecare-health-services'); ?></option>
                            </select>
                        </label>
                    </div>
                </div>

                <div class="ecl-cat-layout">
                    <aside class="ecl-filters" id="ecl-filters" aria-label="<?php esc_attr_e('Filters', 'ecare-health-services'); ?>">
                        <div class="ecl-filters-head">
                            <strong><?php esc_html_e('Filters', 'ecare-health-services'); ?></strong>
                            <?php if ($chips): ?><a class="ecl-link" href="<?php echo esc_url($base); ?>"><?php esc_html_e('Clear', 'ecare-health-services'); ?></a><?php endif; ?>
                            <button type="button" class="ecl-drawer-close" data-ecl-drawer="close" aria-label="<?php esc_attr_e('Close filters', 'ecare-health-services'); ?>">×</button>
                        </div>

                        <?php self::filter_group(__('Type', 'ecare-health-services'), 'type', $args['type'], array(
                            array('value' => 'single', 'label' => __('Single tests', 'ecare-health-services')),
                            array('value' => 'package', 'label' => __('Packages', 'ecare-health-services')),
                        )); ?>
                        <?php self::filter_group(__('Categories', 'ecare-health-services'), 'category', $args['category'], array_map(function ($c) {
                            return array('value' => $c['slug'], 'label' => $c['name'], 'count' => $c['count'], 'icon' => $c['icon']);
                        }, $filters['categories'])); ?>
                        <?php self::filter_group(__('Collections', 'ecare-health-services'), 'collection', $args['collection'], array_map(function ($c) {
                            return array('value' => $c['slug'], 'label' => $c['name']);
                        }, $filters['collections'])); ?>
                        <?php self::filter_group(__('Lab vendors', 'ecare-health-services'), 'lab', $args['lab'], array_map(function ($l) {
                            return array('value' => $l['id'], 'label' => $l['name'], 'count' => $l['count'], 'icon' => $l['logo']);
                        }, $filters['labs'])); ?>

                        <div class="ecl-filters-apply"><button type="submit" class="ecl-btn"><?php esc_html_e('Show results', 'ecare-health-services'); ?></button></div>
                    </aside>

                    <div class="ecl-results">
                        <?php if ($chips): ?>
                            <div class="ecl-chips">
                                <?php foreach ($chips as $k => $label): ?>
                                    <a class="ecl-chip" href="<?php echo esc_url(self::catalog_url(array_merge($args, array($k => ($k === 'lab' ? 0 : '')), array('pg' => 1)))); ?>" aria-label="<?php echo esc_attr(sprintf(__('Remove filter %s', 'ecare-health-services'), $label)); ?>"><?php echo esc_html($label); ?> <span aria-hidden="true">×</span></a>
                                <?php endforeach; ?>
                                <a class="ecl-link" href="<?php echo esc_url($base); ?>"><?php esc_html_e('Clear all', 'ecare-health-services'); ?></a>
                            </div>
                        <?php endif; ?>

                        <?php if ($result['items']): ?>
                            <p class="ecl-count"><?php echo esc_html(sprintf(__('Showing %1$d–%2$d of %3$d', 'ecare-health-services'), $from, $to, $result['total'])); ?></p>
                            <div class="ecl-grid">
                                <?php foreach ($result['items'] as $c) { echo self::render_card($c); } // phpcs:ignore ?>
                            </div>
                            <?php self::pagination($args, $result); ?>
                        <?php else: ?>
                            <div class="ecl-empty">
                                <?php echo self::icon('flask'); // phpcs:ignore ?>
                                <h2><?php esc_html_e('No tests match', 'ecare-health-services'); ?></h2>
                                <p><?php esc_html_e('Try a different word or remove a filter.', 'ecare-health-services'); ?></p>
                                <?php if ($chips): ?><a class="ecl-btn" href="<?php echo esc_url($base); ?>"><?php esc_html_e('Show all tests', 'ecare-health-services'); ?></a><?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /** One radio group in the sidebar, "All" first. */
    private static function filter_group($title, $name, $current, $options) {
        if (!$options) {
            return;
        }
        $id = 'ecl-f-' . $name;
        ?>
        <fieldset class="ecl-fgroup">
            <legend><?php echo esc_html($title); ?></legend>
            <div class="ecl-fopts">
                <label class="ecl-fopt">
                    <input type="radio" name="<?php echo esc_attr($name); ?>" value="" <?php checked((string) $current, ''); ?><?php checked($current === 0); ?> />
                    <span class="ecl-fname"><?php esc_html_e('All', 'ecare-health-services'); ?></span>
                </label>
                <?php foreach ($options as $o): ?>
                    <label class="ecl-fopt">
                        <input type="radio" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($o['value']); ?>" <?php checked((string) $current, (string) $o['value']); ?> />
                        <?php if (!empty($o['icon'])): ?><img src="<?php echo esc_url($o['icon']); ?>" alt="" loading="lazy" /><?php endif; ?>
                        <span class="ecl-fname"><?php echo esc_html($o['label']); ?></span>
                        <?php if (isset($o['count'])): ?><span class="ecl-fcount"><?php echo (int) $o['count']; ?></span><?php endif; ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <?php
    }

    private static function pagination($args, $result) {
        if ($result['pages'] < 2) {
            return;
        }
        $page = (int) $result['page'];
        $show = array_unique(array_filter(array(1, $page - 1, $page, $page + 1, $result['pages']), function ($p) use ($result) { return $p >= 1 && $p <= $result['pages']; }));
        sort($show);
        ?>
        <nav class="ecl-pages" aria-label="<?php esc_attr_e('Pages', 'ecare-health-services'); ?>">
            <?php if ($page > 1): ?><a href="<?php echo esc_url(self::catalog_url(array_merge($args, array('pg' => $page - 1)))); ?>" rel="prev"><?php echo self::icon('left'); // phpcs:ignore ?><span class="screen-reader-text"><?php esc_html_e('Previous', 'ecare-health-services'); ?></span></a><?php endif; ?>
            <?php $prev = 0; foreach ($show as $p): ?>
                <?php if ($prev && $p > $prev + 1): ?><span class="ecl-gap">…</span><?php endif; ?>
                <?php if ($p === $page): ?>
                    <span class="ecl-page-cur" aria-current="page"><?php echo (int) $p; ?></span>
                <?php else: ?>
                    <a href="<?php echo esc_url(self::catalog_url(array_merge($args, array('pg' => $p)))); ?>"><?php echo (int) $p; ?></a>
                <?php endif; ?>
            <?php $prev = $p; endforeach; ?>
            <?php if ($page < $result['pages']): ?><a href="<?php echo esc_url(self::catalog_url(array_merge($args, array('pg' => $page + 1)))); ?>" rel="next"><?php echo self::icon('right'); // phpcs:ignore ?><span class="screen-reader-text"><?php esc_html_e('Next', 'ecare-health-services'); ?></span></a><?php endif; ?>
        </nav>
        <?php
    }
}
