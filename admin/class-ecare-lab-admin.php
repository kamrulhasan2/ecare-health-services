<?php
defined('ABSPATH') || exit;

/**
 * The "Lab" admin menu: everything about lab tests in one place.
 *
 * Lab Catalog and Lab Orders used to sit in the E-Care Health menu between the
 * caregiver and ambulance screens, and the lab providers and locations added
 * since would have made that menu longer still. They now live here. Their page
 * slugs are unchanged (admin.php?page=ecare-lab-catalog works under any parent),
 * so bookmarks and links elsewhere in the plugin keep working.
 *
 * Settings (ECare_Lab_Settings) closes the list.
 */
class ECare_Lab_Admin {

    const MENU = 'ecare-lab';

    public static function init() {
        // After ECare_Admin (20), so both menus exist by the time either is used.
        add_action('admin_menu', array(__CLASS__, 'add_menu'), 25);
        add_filter('parent_file', array(__CLASS__, 'parent_file'));
        add_filter('submenu_file', array(__CLASS__, 'submenu_file'), 10, 2);
    }

    /**
     * The submenu, in order: slug => array(title, callback or null).
     * A null callback is a link to a core screen (post list, term list, editor).
     */
    public static function items() {
        return array(
            self::MENU                               => array(__('Dashboard', 'ecare-health-services'), array(__CLASS__, 'render_dashboard')),
            'ecare-lab-catalog'                      => array(__('All Tests', 'ecare-health-services'), array('ECare_Admin', 'render_lab_catalog')),
            'post-new.php?post_type=ecare_lab_test'  => array(__('Add New Test', 'ecare-health-services'), null),
            ECare_Lab_Taxonomies::menu_slug(ECare_Lab_Taxonomies::CATEGORY)   => array(__('Categories', 'ecare-health-services'), null),
            ECare_Lab_Taxonomies::menu_slug(ECare_Lab_Taxonomies::COLLECTION) => array(__('Collections', 'ecare-health-services'), null),
            ECare_Lab_Providers::menu_slug()         => array(__('Lab Providers', 'ecare-health-services'), null),
            ECare_Locations::menu_slug()             => array(__('Locations', 'ecare-health-services'), null),
            ECare_Lab_Orders_Admin::PAGE             => array(__('Lab Orders', 'ecare-health-services'), array('ECare_Lab_Orders_Admin', 'render')),
            ECare_Lab_Migration::PAGE                => array(__('Data Migration', 'ecare-health-services'), array('ECare_Lab_Migration', 'render_page')),
            ECare_Lab_Settings::PAGE                 => array(__('Settings', 'ecare-health-services'), array('ECare_Lab_Settings', 'render_page')),
        );
    }

    public static function add_menu() {
        add_menu_page(
            __('Lab', 'ecare-health-services'),
            __('Lab', 'ecare-health-services'),
            'manage_options',
            self::MENU,
            array(__CLASS__, 'render_dashboard'),
            'dashicons-clipboard',
            57
        );
        foreach (self::items() as $slug => $item) {
            add_submenu_page(self::MENU, $item[0], $item[0], 'manage_options', $slug, $item[1] ?: '');
        }
    }

    /**
     * Which submenu entry a screen belongs to, or '' if it is not a lab screen.
     *
     * @param object|null $screen WP_Screen (only post_type, taxonomy, base, action are read).
     */
    public static function submenu_for_screen($screen) {
        if (!$screen) {
            return '';
        }
        if (in_array($screen->taxonomy ?? '', array(ECare_Lab_Taxonomies::CATEGORY, ECare_Lab_Taxonomies::COLLECTION), true)) {
            return ECare_Lab_Taxonomies::menu_slug($screen->taxonomy);
        }
        if (($screen->taxonomy ?? '') === ECare_Locations::TAXONOMY) {
            return ECare_Locations::menu_slug();
        }
        if (($screen->post_type ?? '') === ECare_Lab_Providers::POST_TYPE) {
            return ECare_Lab_Providers::menu_slug();
        }
        if (($screen->post_type ?? '') === 'ecare_lab_test' && ($screen->base ?? '') === 'post') {
            return ($screen->action ?? '') === 'add' ? 'post-new.php?post_type=ecare_lab_test' : 'ecare-lab-catalog';
        }
        return '';
    }

    private static function current_screen() {
        return function_exists('get_current_screen') ? get_current_screen() : null;
    }

    public static function parent_file($parent_file) {
        return self::submenu_for_screen(self::current_screen()) !== '' ? self::MENU : $parent_file;
    }

    public static function submenu_file($submenu_file, $parent_file = '') {
        $slug = self::submenu_for_screen(self::current_screen());
        return $slug !== '' ? $slug : $submenu_file;
    }

    // -----------------------------------------------------------------------
    // Dashboard
    // -----------------------------------------------------------------------

    /** Numbers for the dashboard tiles. Kept separate so it can be tested. */
    public static function stats() {
        global $wpdb;
        $table = $wpdb->prefix . 'ecare_bookings';

        $tests     = wp_count_posts('ecare_lab_test');
        $providers = wp_count_posts(ECare_Lab_Providers::POST_TYPE);

        $areas = 0;
        $terms = get_terms(array('taxonomy' => ECare_Locations::TAXONOMY, 'hide_empty' => false, 'fields' => 'ids'));
        if (!is_wp_error($terms)) {
            foreach ($terms as $id) {
                if (ECare_Locations::get_level($id) === ECare_Locations::LEVEL_AREA) {
                    $areas++;
                }
            }
        }

        // Old-format tests: published but without a single lab row.
        $offers   = ECare_Lab_Offerings::table();
        $unlinked = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             WHERE p.post_type = %s AND p.post_status = %s
             AND NOT EXISTS (SELECT 1 FROM {$offers} o WHERE o.test_id = p.ID)",
            'ecare_lab_test', 'publish'
        ));

        return array(
            'tests'     => (int) ($tests->publish ?? 0),
            'providers' => (int) ($providers->publish ?? 0),
            'areas'     => $areas,
            'unlinked'  => $unlinked,
            'orders'    => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE booking_type = %s", 'lab')),
            // Paid and still being worked on (an unpaid order is not work yet).
            'pending'   => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE booking_type = %s AND status IN ('approved','sample_collected','processing','report_ready')", 'lab')),
            'completed' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE booking_type = %s AND status = %s", 'lab', 'completed')),
        );
    }

    /**
     * Go-live checklist: what should be true before the switch in Lab Settings
     * is turned on. Each item: ok (true / false), label, detail, link.
     *
     * @param array $s stats()
     * @return array<string, array{ok:bool, label:string, detail:string, link:string}>
     */
    public static function checklist($s) {
        $d     = 'ecare-health-services';
        $pages = array();
        foreach (array('home' => __('Lab home', $d), 'tests' => __('All tests', $d), 'cart' => __('Lab cart', $d)) as $k => $label) {
            if (!ECare_Lab_Front::page_id($k)) {
                $pages[] = $label;
            }
        }
        $online = array();
        if (function_exists('WC') && WC() && WC()->payment_gateways()) {
            foreach ((array) WC()->payment_gateways()->payment_gateways() as $id => $g) {
                if ($id !== 'cod' && isset($g->enabled) && $g->enabled === 'yes') {
                    $online[] = method_exists($g, 'get_title') ? $g->get_title() : $id;
                }
            }
        }
        $tz     = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
        $offset = $tz->getOffset(new DateTime('now', $tz));
        $live   = ECare_Lab_Settings::is_live();
        $set    = admin_url('admin.php?page=' . ECare_Lab_Settings::PAGE);

        return array(
            'migration' => array(
                'ok'     => (bool) get_option(ECare_Lab_Migration::LOG_OPTION) || $s['unlinked'] === 0,
                'label'  => __('Old data migrated', $d),
                'detail' => __('Lab -> Data Migration: scan, check the corrections, Apply.', $d),
                'link'   => admin_url('admin.php?page=' . ECare_Lab_Migration::PAGE),
            ),
            'tests' => array(
                'ok'     => $s['unlinked'] === 0,
                'label'  => __('Every published test has a lab and price', $d),
                'detail' => $s['unlinked'] ? sprintf(_n('%d test has none and will not be shown on the new pages.', '%d tests have none and will not be shown on the new pages.', $s['unlinked'], $d), $s['unlinked']) : '',
                'link'   => admin_url('admin.php?page=ecare-lab-catalog'),
            ),
            'labs' => array(
                'ok'     => $s['providers'] > 0,
                'label'  => __('At least one lab provider, with its areas', $d),
                'detail' => '',
                'link'   => admin_url(ECare_Lab_Providers::menu_slug()),
            ),
            'pages' => array(
                'ok'     => !$pages,
                'label'  => __('Lab home, All tests and Lab cart pages exist', $d),
                'detail' => $pages ? sprintf(__('Missing: %s', $d), implode(', ', $pages)) : '',
                'link'   => $set,
            ),
            'payment' => array(
                'ok'     => (bool) $online,
                'label'  => __('An online payment method for the advance', $d),
                'detail' => $online ? implode(', ', $online) : __('Only Cash on Delivery is on, so the advance would not be taken online.', $d),
                'link'   => admin_url('admin.php?page=wc-settings&tab=checkout'),
            ),
            'timezone' => array(
                'ok'     => $offset === 6 * HOUR_IN_SECONDS,
                'label'  => __('Site timezone is Dhaka', $d),
                'detail' => $offset === 6 * HOUR_IN_SECONDS ? '' : sprintf(__('Now %s. Collection slots and cut-off times follow it.', $d), $tz->getName()),
                'link'   => admin_url('options-general.php'),
            ),
            'live' => array(
                'ok'     => $live,
                'label'  => $live ? __('The new lab pages are live', $d) : __('The new lab pages are not live yet', $d),
                'detail' => $live ? '' : __('When everything above is ticked: Lab Settings -> Go live.', $d),
                'link'   => $set,
            ),
        );
    }

    public static function render_dashboard() {
        $s = self::stats();
        $checks = self::checklist($s);
        $tiles = array(
            array('🔬', 'teal',   __('TESTS', 'ecare-health-services'),          $s['tests'],     admin_url('admin.php?page=ecare-lab-catalog')),
            array('🏥', 'green',  __('LAB PROVIDERS', 'ecare-health-services'),  $s['providers'], admin_url(ECare_Lab_Providers::menu_slug())),
            array('📍', 'yellow', __('AREAS', 'ecare-health-services'),          $s['areas'],     admin_url(ECare_Locations::menu_slug())),
            array('🧾', 'teal',   __('LAB ORDERS', 'ecare-health-services'),     $s['orders'],    admin_url('admin.php?page=ecare-lab-orders')),
            array('⏳', 'yellow', __('OPEN ORDERS', 'ecare-health-services'),    $s['pending'],   admin_url('admin.php?page=ecare-lab-orders')),
            array('✓',  'green',  __('COMPLETED', 'ecare-health-services'),      $s['completed'], admin_url('admin.php?page=ecare-lab-orders')),
        );
        ?>
        <style>
            #wpbody-content { background-color: #F8FAFC !important; }
            .ecare-lab-dash a.ecare-admin-kpi-card { text-decoration:none; color:inherit; }
            .ecare-lab-dash .ecare-lab-links { display:flex; flex-wrap:wrap; gap:10px; margin-top:20px; }
            .ecare-lab-check { background:#fff; border:1px solid #E2E8F0; border-radius:12px; padding:16px 20px; margin-top:20px; }
            .ecare-lab-check h2 { margin:0 0 10px; font-size:16px; }
            .ecare-lab-check ul { margin:0; }
            .ecare-lab-check li { display:flex; flex-wrap:wrap; align-items:baseline; gap:4px 10px; padding:6px 0; border-bottom:1px solid #F1F5F9; }
            .ecare-lab-check li:last-child { border-bottom:0; }
            .ecare-lab-check small { flex-basis:100%; padding-left:32px; color:#64748B; }
            .ecare-lab-check-mark { display:inline-flex; width:22px; height:22px; border-radius:50%; align-items:center; justify-content:center; font-weight:800; font-size:12px; }
            .ecare-lab-check .is-ok .ecare-lab-check-mark { background:#DCFCE7; color:#166534; }
            .ecare-lab-check .is-todo .ecare-lab-check-mark { background:#FEF3C7; color:#92400E; }
            .ecare-lab-check a { font-weight:600; text-decoration:none; }
        </style>
        <div class="ecare-admin-wrap ecare-lab-dash">
            <h1 style="font-weight:800;font-size:24px;margin-bottom:20px;color:var(--text-dark);"><?php esc_html_e('Lab Dashboard', 'ecare-health-services'); ?></h1>

            <?php if ($s['unlinked'] > 0): ?>
                <div class="notice notice-warning inline" style="margin:0 0 16px;">
                    <p><?php echo esc_html(sprintf(
                        /* translators: %d: number of tests */
                        _n('%d published test has no lab prices yet (old format).', '%d published tests have no lab prices yet (old format).', $s['unlinked'], 'ecare-health-services'),
                        $s['unlinked']
                    )); ?></p>
                </div>
            <?php endif; ?>

            <div class="ecare-admin-kpi-grid">
                <?php foreach ($tiles as $t): ?>
                    <a class="ecare-admin-kpi-card" href="<?php echo esc_url($t[4]); ?>">
                        <div class="ecare-admin-kpi-icon <?php echo esc_attr($t[1]); ?>"><?php echo esc_html($t[0]); ?></div>
                        <div class="ecare-admin-kpi-details">
                            <span class="ecare-admin-kpi-label"><?php echo esc_html($t[2]); ?></span>
                            <span class="ecare-admin-kpi-value"><?php echo (int) $t[3]; ?></span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="ecare-lab-check">
                <h2><?php esc_html_e('Go-live checklist', 'ecare-health-services'); ?></h2>
                <ul>
                    <?php foreach ($checks as $key => $c): ?>
                        <li class="<?php echo $c['ok'] ? 'is-ok' : 'is-todo'; ?>">
                            <span class="ecare-lab-check-mark" aria-hidden="true"><?php echo $c['ok'] ? '✓' : '!'; ?></span>
                            <a href="<?php echo esc_url($c['link']); ?>"><?php echo esc_html($c['label']); ?></a>
                            <?php if ($c['detail'] !== ''): ?><small><?php echo esc_html($c['detail']); ?></small><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div class="ecare-lab-links">
                <a class="ecare-admin-btn-green" href="<?php echo esc_url(admin_url('post-new.php?post_type=ecare_lab_test')); ?>">+ <?php esc_html_e('Add New Test', 'ecare-health-services'); ?></a>
                <a class="ecare-admin-btn-outline" href="<?php echo esc_url(admin_url('post-new.php?post_type=' . ECare_Lab_Providers::POST_TYPE)); ?>">+ <?php esc_html_e('Add Lab Provider', 'ecare-health-services'); ?></a>
                <a class="ecare-admin-btn-outline" href="<?php echo esc_url(admin_url(ECare_Locations::menu_slug())); ?>">+ <?php esc_html_e('Add Area', 'ecare-health-services'); ?></a>
            </div>
        </div>
        <?php
    }
}
