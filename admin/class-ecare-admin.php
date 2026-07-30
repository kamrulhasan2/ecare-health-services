<?php
defined('ABSPATH') || exit;

class ECare_Admin {

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'add_admin_menus'), 20);
        add_filter('woocommerce_admin_menu', array(__CLASS__, 'maybe_add_woo_submenu'));

        // Handle CSV export
        add_action('admin_post_ecare_export_bookings', array(__CLASS__, 'export_bookings_csv'));
    }

    public static function add_admin_menus() {
        $icon = 'dashicons-heart';

        add_menu_page(
            __('E-Care Health', 'ecare-health-services'),
            __('E-Care Health', 'ecare-health-services'),
            'manage_options',
            'ecare-dashboard',
            array(__CLASS__, 'render_dashboard'),
            $icon,
            56
        );

        add_submenu_page(
            'ecare-dashboard',
            __('Care Bookings', 'ecare-health-services'),
            __('Care Bookings', 'ecare-health-services'),
            'manage_options',
            'ecare-care-bookings',
            array(__CLASS__, 'render_care_bookings')
        );

        add_submenu_page(
            'ecare-dashboard',
            __('Care Providers', 'ecare-health-services'),
            __('Care Providers', 'ecare-health-services'),
            'manage_options',
            'ecare-care-providers',
            array(__CLASS__, 'render_care_providers')
        );

        add_submenu_page(
            'ecare-dashboard',
            __('Lab Catalog', 'ecare-health-services'),
            __('Lab Catalog', 'ecare-health-services'),
            'manage_options',
            'ecare-lab-catalog',
            array(__CLASS__, 'render_lab_catalog')
        );

        add_submenu_page(
            'ecare-dashboard',
            __('Lab Orders', 'ecare-health-services'),
            __('Lab Orders', 'ecare-health-services'),
            'manage_options',
            'ecare-lab-orders',
            array(__CLASS__, 'render_lab_orders')
        );

        add_submenu_page(
            'ecare-dashboard',
            __('Ambulance Dispatch', 'ecare-health-services'),
            __('Ambulance Dispatch', 'ecare-health-services'),
            'manage_options',
            'ecare-ambulance-dispatch',
            array(__CLASS__, 'render_ambulance_dispatch')
        );
    }

    public static function maybe_add_woo_submenu($menu) {
        return $menu;
    }

    // ---- Dashboard ----

    public static function render_dashboard() {
        global $wpdb;
        $bookings_table = $wpdb->prefix . 'ecare_bookings';

        $total_bookings = $wpdb->get_var("SELECT COUNT(*) FROM {$bookings_table}");
        $pending = $wpdb->get_var("SELECT COUNT(*) FROM {$bookings_table} WHERE status = 'pending'");
        $approved = $wpdb->get_var("SELECT COUNT(*) FROM {$bookings_table} WHERE status = 'approved'");
        $completed = $wpdb->get_var("SELECT COUNT(*) FROM {$bookings_table} WHERE status = 'completed'");
        $cancelled = $wpdb->get_var("SELECT COUNT(*) FROM {$bookings_table} WHERE status = 'cancelled'");

        $recent_bookings = $wpdb->get_results("SELECT * FROM {$bookings_table} ORDER BY created_at DESC LIMIT 10");

        ?>
        <div class="wrap ecare-admin-wrap">
            <h1><?php _e('E-Care Health Dashboard', 'ecare-health-services'); ?></h1>
            <div class="ecare-kpi-grid">
                <div class="ecare-kpi-card ecare-kpi-total"><span class="ecare-kpi-number"><?php echo intval($total_bookings); ?></span><span class="ecare-kpi-label">Total Bookings</span></div>
                <div class="ecare-kpi-card ecare-kpi-pending"><span class="ecare-kpi-number"><?php echo intval($pending); ?></span><span class="ecare-kpi-label">Pending Approvals</span></div>
                <div class="ecare-kpi-card ecare-kpi-approved"><span class="ecare-kpi-number"><?php echo intval($approved); ?></span><span class="ecare-kpi-label">Approved</span></div>
                <div class="ecare-kpi-card ecare-kpi-completed"><span class="ecare-kpi-number"><?php echo intval($completed); ?></span><span class="ecare-kpi-label">Completed</span></div>
                <div class="ecare-kpi-card ecare-kpi-cancelled"><span class="ecare-kpi-number"><?php echo intval($cancelled); ?></span><span class="ecare-kpi-label">Cancelled</span></div>
            </div>

            <h2>Recent Bookings</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th>ID</th><th>Type</th><th>Patient/Client</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead>
                <tbody>
                    <?php if ($recent_bookings): ?>
                        <?php foreach ($recent_bookings as $b): ?>
                            <tr>
                                <td><?php echo intval($b->id); ?></td>
                                <td><span class="ecare-status-badge ecare-status-<?php echo esc_attr($b->booking_type); ?>"><?php echo esc_html(ucfirst($b->booking_type)); ?></span></td>
                                <td><?php echo esc_html($b->patient_name ?: 'N/A'); ?></td>
                                <td>৳<?php echo esc_html(number_format($b->total_amount, 2)); ?></td>
                                <td><span class="ecare-status-badge ecare-status-<?php echo esc_attr($b->status); ?>"><?php echo esc_html(ucfirst($b->status)); ?></span></td>
                                <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($b->created_at))); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6">No bookings found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    // ---- Care Bookings ----

    public static function render_care_bookings() {
        global $wpdb;
        $table = $wpdb->prefix . 'ecare_bookings';

        $bookings = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE booking_type = %s ORDER BY created_at DESC", 'caregiver'));

        self::render_booking_table('Care Bookings', $bookings, 'caregiver');
    }

    // ---- Care Providers ----

    public static function render_care_providers() {
        $providers = get_posts(array(
            'post_type'      => 'ecare_caregiver',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
        ));
        ?>
        <div class="wrap ecare-admin-wrap">
            <h1><?php _e('Care Providers', 'ecare-health-services'); ?></h1>
            <a href="<?php echo esc_url(admin_url('post-new.php?post_type=ecare_caregiver')); ?>" class="page-title-action">Add New Provider</a>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th>Name</th><th>Type</th><th>Experience</th><th>Skills</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php if ($providers): ?>
                        <?php foreach ($providers as $p): ?>
                            <?php
                            $type = get_post_meta($p->ID, '_provider_type', true);
                            $exp = get_post_meta($p->ID, '_experience', true);
                            $skills = get_post_meta($p->ID, '_skills', true);
                            $status = get_post_meta($p->ID, '_provider_status', true) ?: 'pending';
                            ?>
                            <tr>
                                <td><a href="<?php echo esc_url(get_edit_post_link($p->ID)); ?>"><?php echo esc_html($p->post_title); ?></a></td>
                                <td><?php echo esc_html($type); ?></td>
                                <td><?php echo esc_html($exp ? $exp . ' yrs' : '-'); ?></td>
                                <td><?php echo esc_html(wp_trim_words($skills, 10)); ?></td>
                                <td><span class="ecare-status-badge ecare-status-<?php echo esc_attr($status); ?>"><?php echo esc_html(ucfirst($status)); ?></span></td>
                                <td>
                                    <a href="<?php echo esc_url(get_edit_post_link($p->ID)); ?>" class="button button-small">Edit</a>
                                    <?php if ($status === 'pending'): ?>
                                        <button class="button button-small ecare-approve-provider" data-id="<?php echo intval($p->ID); ?>">Approve</button>
                                        <button class="button button-small ecare-reject-provider" data-id="<?php echo intval($p->ID); ?>">Reject</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6">No providers registered yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    // ---- Lab Catalog ----

    public static function render_lab_catalog() {
        $tests = get_posts(array(
            'post_type'      => 'ecare_lab_test',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
        ));
        ?>
        <div class="wrap ecare-admin-wrap">
            <h1><?php _e('Lab Catalog', 'ecare-health-services'); ?></h1>
            <a href="<?php echo esc_url(admin_url('post-new.php?post_type=ecare_lab_test')); ?>" class="page-title-action">Add New Test</a>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th>Test Name</th><th>Code</th><th>Category</th><th>Price</th><th>Lab Provider</th><th>Division</th><th>District</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php if ($tests): ?>
                        <?php foreach ($tests as $t): ?>
                            <?php
                            $meta = array(
                                'code' => get_post_meta($t->ID, '_test_code', true),
                                'cat' => get_post_meta($t->ID, '_test_category', true),
                                'price' => get_post_meta($t->ID, '_price', true),
                                'provider' => get_post_meta($t->ID, '_lab_provider', true),
                                'div' => get_post_meta($t->ID, '_division', true),
                                'dist' => get_post_meta($t->ID, '_district', true),
                                'status' => get_post_meta($t->ID, '_test_status', true) ?: 'active',
                            );
                            ?>
                            <tr>
                                <td><a href="<?php echo esc_url(get_edit_post_link($t->ID)); ?>"><?php echo esc_html($t->post_title); ?></a></td>
                                <td><?php echo esc_html($meta['code']); ?></td>
                                <td><?php echo esc_html($meta['cat']); ?></td>
                                <td>৳<?php echo esc_html(number_format($meta['price'])); ?></td>
                                <td><?php echo esc_html($meta['provider']); ?></td>
                                <td><?php echo esc_html($meta['div']); ?></td>
                                <td><?php echo esc_html($meta['dist']); ?></td>
                                <td><span class="ecare-status-badge ecare-status-<?php echo esc_attr($meta['status']); ?>"><?php echo esc_html(ucfirst($meta['status'])); ?></span></td>
                                <td><a href="<?php echo esc_url(get_edit_post_link($t->ID)); ?>" class="button button-small">Edit</a></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="9">No lab tests added yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    // ---- Lab Orders ----

    public static function render_lab_orders() {
        global $wpdb;
        $table = $wpdb->prefix . 'ecare_bookings';

        $bookings = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE booking_type = %s ORDER BY created_at DESC", 'lab'));

        self::render_booking_table('Lab Orders', $bookings, 'lab');
    }

    // ---- Ambulance Dispatch ----

    public static function render_ambulance_dispatch() {
        global $wpdb;
        $table = $wpdb->prefix . 'ecare_bookings';

        $bookings = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE booking_type = %s ORDER BY created_at DESC", 'ambulance'));

        ?>
        <div class="wrap ecare-admin-wrap">
            <h1><?php _e('Ambulance Dispatch', 'ecare-health-services'); ?></h1>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>ID</th><th>Type</th><th>Pickup</th><th>Destination</th><th>Contact</th><th>Priority</th><th>Amount</th><th>Status</th><th>Date</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($bookings): ?>
                        <?php foreach ($bookings as $b): ?>
                            <tr>
                                <td><?php echo intval($b->id); ?></td>
                                <td><span class="ecare-status-badge ecare-status-<?php echo esc_attr(strtolower($b->ambulance_type)); ?>"><?php echo esc_html($b->ambulance_type); ?></span></td>
                                <td><?php echo esc_html(wp_trim_words($b->pickup_address, 5)); ?></td>
                                <td><?php echo esc_html(wp_trim_words($b->destination, 5)); ?></td>
                                <td><?php echo esc_html($b->contact_phone); ?></td>
                                <td><span class="ecare-status-badge ecare-status-<?php echo esc_attr(strtolower($b->priority_level)); ?>"><?php echo esc_html($b->priority_level); ?></span></td>
                                <td>৳<?php echo esc_html(number_format($b->total_amount, 2)); ?></td>
                                <td><span class="ecare-status-badge ecare-status-<?php echo esc_attr($b->status); ?>"><?php echo esc_html(ucfirst($b->status)); ?></span></td>
                                <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($b->created_at))); ?></td>
                                <td>
                                    <select class="ecare-status-select" data-booking-id="<?php echo intval($b->id); ?>">
                                        <option value="pending" <?php selected($b->status, 'pending'); ?>>Pending Dispatch</option>
                                        <option value="dispatched" <?php selected($b->status, 'dispatched'); ?>>Dispatched</option>
                                        <option value="assigned" <?php selected($b->status, 'assigned'); ?>>Assigned</option>
                                        <option value="completed" <?php selected($b->status, 'completed'); ?>>Completed</option>
                                        <option value="cancelled" <?php selected($b->status, 'cancelled'); ?>>Cancelled</option>
                                    </select>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="10">No ambulance requests found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    // ---- Shared ----

    private static function render_booking_table($title, $bookings, $type) {
        ?>
        <div class="wrap ecare-admin-wrap">
            <h1><?php echo esc_html($title); ?></h1>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>ID</th><th>Patient/Client</th><th>Contact</th><th>Package/Type</th><th>Amount</th><th>Status</th><th>Date</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($bookings): ?>
                        <?php foreach ($bookings as $b): ?>
                            <tr>
                                <td><?php echo intval($b->id); ?></td>
                                <td><?php echo esc_html($b->patient_name ?: 'N/A'); ?></td>
                                <td><?php echo esc_html($b->contact_phone); ?></td>
                                <td><?php echo esc_html($b->package_type ?: $b->ambulance_type ?: '-'); ?></td>
                                <td>৳<?php echo esc_html(number_format($b->total_amount, 2)); ?></td>
                                <td><span class="ecare-status-badge ecare-status-<?php echo esc_attr($b->status); ?>"><?php echo esc_html(ucfirst($b->status)); ?></span></td>
                                <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($b->created_at))); ?></td>
                                <td>
                                    <select class="ecare-status-select" data-booking-id="<?php echo intval($b->id); ?>">
                                        <option value="pending" <?php selected($b->status, 'pending'); ?>>Pending</option>
                                        <option value="approved" <?php selected($b->status, 'approved'); ?>>Approved</option>
                                        <option value="completed" <?php selected($b->status, 'completed'); ?>>Completed</option>
                                        <option value="cancelled" <?php selected($b->status, 'cancelled'); ?>>Cancelled</option>
                                    </select>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="8">No <?php echo esc_html(strtolower($title)); ?> found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public static function export_bookings_csv() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ecare_bookings';
        $bookings = $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC");

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=ecare-bookings-' . date('Y-m-d') . '.csv');

        $output = fopen('php://output', 'w');
        fputcsv($output, array('ID', 'Type', 'Patient', 'Contact', 'Amount', 'Status', 'Date'));

        foreach ($bookings as $b) {
            fputcsv($output, array(
                $b->id,
                $b->booking_type,
                $b->patient_name,
                $b->contact_phone,
                $b->total_amount,
                $b->status,
                $b->created_at,
            ));
        }

        fclose($output);
        exit;
    }
}
