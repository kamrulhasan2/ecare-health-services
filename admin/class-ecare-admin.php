<?php
defined('ABSPATH') || exit;

class ECare_Admin {

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'add_admin_menus'), 20);
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

    // ---- Dashboard ----

    public static function render_dashboard() {
        global $wpdb;
        $bookings_table = $wpdb->prefix . 'ecare_bookings';

        $total_bookings = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$bookings_table}");
        $pending       = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$bookings_table} WHERE status = 'pending'");
        $completed     = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$bookings_table} WHERE status = 'completed'");
        $cancelled     = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$bookings_table} WHERE status = 'cancelled'");

        $recent_bookings = $wpdb->get_results("SELECT * FROM {$bookings_table} ORDER BY created_at DESC LIMIT 10");
        ?>
        <div class="ecare-admin-wrap">
            <div class="ecare-kpi-row">
                <div class="ecare-kpi-card">
                    <div class="ecare-kpi-icon total">&#128200;</div>
                    <div class="ecare-kpi-info">
                        <p class="ecare-kpi-title"><?php _e('Total Bookings', 'ecare-health-services'); ?></p>
                        <span class="ecare-kpi-number"><?php echo $total_bookings; ?></span>
                    </div>
                </div>
                <div class="ecare-kpi-card">
                    <div class="ecare-kpi-icon pending">&#9203;</div>
                    <div class="ecare-kpi-info">
                        <p class="ecare-kpi-title"><?php _e('Pending Approvals', 'ecare-health-services'); ?></p>
                        <span class="ecare-kpi-number"><?php echo $pending; ?></span>
                    </div>
                </div>
                <div class="ecare-kpi-card">
                    <div class="ecare-kpi-icon completed">&#10004;</div>
                    <div class="ecare-kpi-info">
                        <p class="ecare-kpi-title"><?php _e('Completed', 'ecare-health-services'); ?></p>
                        <span class="ecare-kpi-number"><?php echo $completed; ?></span>
                    </div>
                </div>
                <div class="ecare-kpi-card">
                    <div class="ecare-kpi-icon cancelled">&#10060;</div>
                    <div class="ecare-kpi-info">
                        <p class="ecare-kpi-title"><?php _e('Cancelled', 'ecare-health-services'); ?></p>
                        <span class="ecare-kpi-number"><?php echo $cancelled; ?></span>
                    </div>
                </div>
            </div>

            <div class="ecare-action-bar">
                <div class="ecare-action-left">
                    <h2><?php _e('Recent Bookings', 'ecare-health-services'); ?></h2>
                    <span class="ecare-count-badge"><?php echo $total_bookings; ?></span>
                </div>
                <div class="ecare-action-right">
                    <input type="text" class="ecare-search-input" placeholder="<?php esc_attr_e('Search bookings...', 'ecare-health-services'); ?>" />
                    <button class="ecare-btn-outline"><?php _e('Filter', 'ecare-health-services'); ?></button>
                    <a href="<?php echo esc_url(admin_url('admin-post.php?action=ecare_export_bookings')); ?>" class="ecare-btn-outline"><?php _e('Export', 'ecare-health-services'); ?></a>
                </div>
            </div>

            <div class="ecare-table-wrap">
                <table class="ecare-table">
                    <thead>
                        <tr>
                            <th><?php _e('ID', 'ecare-health-services'); ?></th>
                            <th><?php _e('Type', 'ecare-health-services'); ?></th>
                            <th><?php _e('Patient/Client', 'ecare-health-services'); ?></th>
                            <th><?php _e('Amount', 'ecare-health-services'); ?></th>
                            <th><?php _e('Status', 'ecare-health-services'); ?></th>
                            <th><?php _e('Date', 'ecare-health-services'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($recent_bookings): ?>
                            <?php foreach ($recent_bookings as $b): ?>
                                <tr>
                                    <td>#<?php echo intval($b->id); ?></td>
                                    <td><span class="ecare-pill ecare-pill-blue"><?php echo esc_html(ucfirst($b->booking_type)); ?></span></td>
                                    <td><?php echo esc_html($b->patient_name ?: 'N/A'); ?></td>
                                    <td>&#2547;<?php echo esc_html(number_format($b->total_amount, 2)); ?></td>
                                    <td><span class="ecare-pill ecare-pill-<?php echo $b->status === 'completed' ? 'green' : ($b->status === 'pending' ? 'yellow' : ($b->status === 'cancelled' ? 'red' : 'gray')); ?>"><?php echo esc_html(ucfirst($b->status)); ?></span></td>
                                    <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($b->created_at))); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" style="text-align:center;padding:30px;color:var(--text-muted);"><?php _e('No bookings found.', 'ecare-health-services'); ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    // ---- Care Bookings ----

    public static function render_care_bookings() {
        global $wpdb;
        $table = $wpdb->prefix . 'ecare_bookings';
        $bookings = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE booking_type = %s ORDER BY created_at DESC", 'caregiver'));
        self::render_booking_table(__('Care Bookings', 'ecare-health-services'), $bookings);
    }

    // ---- Care Providers ----

    public static function render_care_providers() {
        $providers = get_posts(array(
            'post_type'      => 'ecare_caregiver',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
        ));
        ?>
        <div class="ecare-admin-wrap">
            <div class="ecare-action-bar">
                <div class="ecare-action-left">
                    <h2><?php _e('Care Providers', 'ecare-health-services'); ?></h2>
                    <span class="ecare-count-badge"><?php echo count($providers); ?></span>
                </div>
                <div class="ecare-action-right">
                    <a href="<?php echo esc_url(admin_url('post-new.php?post_type=ecare_caregiver')); ?>" class="ecare-btn-green">+ <?php _e('Add Provider', 'ecare-health-services'); ?></a>
                </div>
            </div>
            <div class="ecare-table-wrap">
                <table class="ecare-table">
                    <thead>
                        <tr>
                            <th><?php _e('Name', 'ecare-health-services'); ?></th>
                            <th><?php _e('Type', 'ecare-health-services'); ?></th>
                            <th><?php _e('Experience', 'ecare-health-services'); ?></th>
                            <th><?php _e('Skills', 'ecare-health-services'); ?></th>
                            <th><?php _e('Status', 'ecare-health-services'); ?></th>
                            <th><?php _e('Actions', 'ecare-health-services'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($providers): ?>
                            <?php foreach ($providers as $p):
                                $type   = get_post_meta($p->ID, '_provider_type', true);
                                $exp    = get_post_meta($p->ID, '_experience', true);
                                $skills = get_post_meta($p->ID, '_skills', true);
                                $status = get_post_meta($p->ID, '_provider_status', true) ?: 'pending';
                            ?>
                                <tr>
                                    <td><a href="<?php echo esc_url(get_edit_post_link($p->ID)); ?>" style="font-weight:600;text-decoration:none;color:var(--text-dark);"><?php echo esc_html($p->post_title); ?></a></td>
                                    <td><?php echo esc_html($type); ?></td>
                                    <td><?php echo esc_html($exp ? $exp . ' yrs' : '-'); ?></td>
                                    <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo esc_html(wp_trim_words($skills, 6)); ?></td>
                                    <td><span class="ecare-pill ecare-pill-<?php echo $status === 'approved' ? 'green' : ($status === 'pending' ? 'yellow' : 'red'); ?>"><?php echo esc_html(ucfirst($status)); ?></span></td>
                                    <td>
                                        <a href="<?php echo esc_url(get_edit_post_link($p->ID)); ?>" class="ecare-btn-outline" style="padding:4px 12px;font-size:12px;"><?php _e('Edit', 'ecare-health-services'); ?></a>
                                        <?php if ($status === 'pending'): ?>
                                            <button class="ecare-btn-outline ecare-approve-provider" data-id="<?php echo intval($p->ID); ?>" style="padding:4px 12px;font-size:12px;border-color:#16a34a;color:#16a34a;"><?php _e('Approve', 'ecare-health-services'); ?></button>
                                            <button class="ecare-btn-outline ecare-reject-provider" data-id="<?php echo intval($p->ID); ?>" style="padding:4px 12px;font-size:12px;border-color:#EF4444;color:#EF4444;"><?php _e('Reject', 'ecare-health-services'); ?></button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" style="text-align:center;padding:30px;color:var(--text-muted);"><?php _e('No providers registered yet.', 'ecare-health-services'); ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
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
        <div class="ecare-admin-wrap">
            <div class="ecare-action-bar">
                <div class="ecare-action-left">
                    <h2><?php _e('Lab Catalog', 'ecare-health-services'); ?></h2>
                    <span class="ecare-count-badge"><?php echo count($tests); ?></span>
                </div>
                <div class="ecare-action-right">
                    <input type="text" class="ecare-search-input" placeholder="<?php esc_attr_e('Search tests...', 'ecare-health-services'); ?>" />
                    <a href="<?php echo esc_url(admin_url('post-new.php?post_type=ecare_lab_test')); ?>" class="ecare-btn-green">+ <?php _e('Add Test', 'ecare-health-services'); ?></a>
                </div>
            </div>
            <div class="ecare-table-wrap">
                <table class="ecare-table">
                    <thead>
                        <tr>
                            <th><?php _e('Test Name', 'ecare-health-services'); ?></th>
                            <th><?php _e('Code', 'ecare-health-services'); ?></th>
                            <th><?php _e('Category', 'ecare-health-services'); ?></th>
                            <th><?php _e('Price', 'ecare-health-services'); ?></th>
                            <th><?php _e('Lab Provider', 'ecare-health-services'); ?></th>
                            <th><?php _e('Location', 'ecare-health-services'); ?></th>
                            <th><?php _e('Status', 'ecare-health-services'); ?></th>
                            <th><?php _e('Actions', 'ecare-health-services'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($tests): ?>
                            <?php foreach ($tests as $t):
                                $meta = array(
                                    'code'     => get_post_meta($t->ID, '_test_code', true),
                                    'cat'      => get_post_meta($t->ID, '_test_category', true),
                                    'price'    => get_post_meta($t->ID, '_price', true),
                                    'provider' => get_post_meta($t->ID, '_lab_provider', true),
                                    'div'      => get_post_meta($t->ID, '_division', true),
                                    'dist'     => get_post_meta($t->ID, '_district', true),
                                    'status'   => get_post_meta($t->ID, '_test_status', true) ?: 'active',
                                );
                            ?>
                                <tr>
                                    <td><a href="<?php echo esc_url(get_edit_post_link($t->ID)); ?>" style="font-weight:600;text-decoration:none;color:var(--text-dark);"><?php echo esc_html($t->post_title); ?></a></td>
                                    <td><?php echo esc_html($meta['code']); ?></td>
                                    <td><?php echo esc_html($meta['cat']); ?></td>
                                    <td>&#2547;<?php echo esc_html(number_format($meta['price'])); ?></td>
                                    <td><?php echo esc_html($meta['provider']); ?></td>
                                    <td><?php echo esc_html($meta['div'] ? $meta['div'] . ($meta['dist'] ? ' > ' . $meta['dist'] : '') : '-'); ?></td>
                                    <td><span class="ecare-pill ecare-pill-<?php echo $meta['status'] === 'active' ? 'green' : 'gray'; ?>"><?php echo esc_html(ucfirst($meta['status'])); ?></span></td>
                                    <td><a href="<?php echo esc_url(get_edit_post_link($t->ID)); ?>" class="ecare-btn-outline" style="padding:4px 12px;font-size:12px;"><?php _e('Edit', 'ecare-health-services'); ?></a></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="8" style="text-align:center;padding:30px;color:var(--text-muted);"><?php _e('No lab tests added yet.', 'ecare-health-services'); ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    // ---- Lab Orders ----

    public static function render_lab_orders() {
        global $wpdb;
        $table = $wpdb->prefix . 'ecare_bookings';
        $bookings = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE booking_type = %s ORDER BY created_at DESC", 'lab'));
        self::render_booking_table(__('Lab Orders', 'ecare-health-services'), $bookings);
    }

    // ---- Ambulance Dispatch ----

    public static function render_ambulance_dispatch() {
        global $wpdb;
        $table = $wpdb->prefix . 'ecare_bookings';
        $bookings = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE booking_type = %s ORDER BY created_at DESC", 'ambulance'));
        ?>
        <div class="ecare-admin-wrap">
            <div class="ecare-action-bar">
                <div class="ecare-action-left">
                    <h2><?php _e('Ambulance Dispatch', 'ecare-health-services'); ?></h2>
                    <span class="ecare-count-badge"><?php echo count($bookings); ?></span>
                </div>
                <div class="ecare-action-right">
                    <input type="text" class="ecare-search-input" placeholder="<?php esc_attr_e('Search requests...', 'ecare-health-services'); ?>" />
                    <button class="ecare-btn-outline"><?php _e('Filter', 'ecare-health-services'); ?></button>
                </div>
            </div>
            <div class="ecare-table-wrap">
                <table class="ecare-table">
                    <thead>
                        <tr>
                            <th><?php _e('ID', 'ecare-health-services'); ?></th>
                            <th><?php _e('Ambulance', 'ecare-health-services'); ?></th>
                            <th><?php _e('Pickup', 'ecare-health-services'); ?></th>
                            <th><?php _e('Destination', 'ecare-health-services'); ?></th>
                            <th><?php _e('Contact', 'ecare-health-services'); ?></th>
                            <th><?php _e('Priority', 'ecare-health-services'); ?></th>
                            <th><?php _e('Amount', 'ecare-health-services'); ?></th>
                            <th><?php _e('Status', 'ecare-health-services'); ?></th>
                            <th><?php _e('Date', 'ecare-health-services'); ?></th>
                            <th><?php _e('Actions', 'ecare-health-services'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($bookings): ?>
                            <?php foreach ($bookings as $b): ?>
                                <tr>
                                    <td>#<?php echo intval($b->id); ?></td>
                                    <td><span class="ecare-pill ecare-pill-blue"><?php echo esc_html($b->ambulance_type); ?></span></td>
                                    <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo esc_html($b->pickup_address); ?></td>
                                    <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo esc_html($b->destination); ?></td>
                                    <td><?php echo esc_html($b->contact_phone); ?></td>
                                    <td><span class="ecare-pill ecare-pill-<?php echo $b->priority_level === 'Emergency' ? 'red' : 'gray'; ?>"><?php echo esc_html($b->priority_level); ?></span></td>
                                    <td>&#2547;<?php echo esc_html(number_format($b->total_amount, 2)); ?></td>
                                    <td><span class="ecare-pill ecare-pill-<?php echo $b->status === 'completed' ? 'green' : ($b->status === 'pending' ? 'yellow' : ($b->status === 'dispatched' ? 'blue' : ($b->status === 'assigned' ? 'blue' : 'gray'))); ?>"><?php echo esc_html(ucfirst($b->status)); ?></span></td>
                                    <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($b->created_at))); ?></td>
                                    <td>
                                        <select class="ecare-status-select" data-booking-id="<?php echo intval($b->id); ?>" style="padding:4px 8px;border:1px solid var(--border-color);border-radius:6px;font-size:12px;">
                                            <option value="pending" <?php selected($b->status, 'pending'); ?>><?php _e('Pending', 'ecare-health-services'); ?></option>
                                            <option value="dispatched" <?php selected($b->status, 'dispatched'); ?>><?php _e('Dispatched', 'ecare-health-services'); ?></option>
                                            <option value="assigned" <?php selected($b->status, 'assigned'); ?>><?php _e('Assigned', 'ecare-health-services'); ?></option>
                                            <option value="completed" <?php selected($b->status, 'completed'); ?>><?php _e('Completed', 'ecare-health-services'); ?></option>
                                            <option value="cancelled" <?php selected($b->status, 'cancelled'); ?>><?php _e('Cancelled', 'ecare-health-services'); ?></option>
                                        </select>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="10" style="text-align:center;padding:30px;color:var(--text-muted);"><?php _e('No ambulance requests found.', 'ecare-health-services'); ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    // ---- Shared ----

    private static function render_booking_table($title, $bookings) {
        ?>
        <div class="ecare-admin-wrap">
            <div class="ecare-action-bar">
                <div class="ecare-action-left">
                    <h2><?php echo esc_html($title); ?></h2>
                    <span class="ecare-count-badge"><?php echo count($bookings); ?></span>
                </div>
                <div class="ecare-action-right">
                    <input type="text" class="ecare-search-input" placeholder="<?php esc_attr_e('Search...', 'ecare-health-services'); ?>" />
                    <a href="<?php echo esc_url(admin_url('admin-post.php?action=ecare_export_bookings')); ?>" class="ecare-btn-outline"><?php _e('Export', 'ecare-health-services'); ?></a>
                </div>
            </div>
            <div class="ecare-table-wrap">
                <table class="ecare-table">
                    <thead>
                        <tr>
                            <th><?php _e('ID', 'ecare-health-services'); ?></th>
                            <th><?php _e('Patient/Client', 'ecare-health-services'); ?></th>
                            <th><?php _e('Contact', 'ecare-health-services'); ?></th>
                            <th><?php _e('Package/Type', 'ecare-health-services'); ?></th>
                            <th><?php _e('Amount', 'ecare-health-services'); ?></th>
                            <th><?php _e('Status', 'ecare-health-services'); ?></th>
                            <th><?php _e('Date', 'ecare-health-services'); ?></th>
                            <th><?php _e('Actions', 'ecare-health-services'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($bookings): ?>
                            <?php foreach ($bookings as $b): ?>
                                <tr>
                                    <td>#<?php echo intval($b->id); ?></td>
                                    <td><?php echo esc_html($b->patient_name ?: 'N/A'); ?></td>
                                    <td><?php echo esc_html($b->contact_phone); ?></td>
                                    <td><?php echo esc_html($b->package_type ?: $b->ambulance_type ?: '-'); ?></td>
                                    <td>&#2547;<?php echo esc_html(number_format($b->total_amount, 2)); ?></td>
                                    <td><span class="ecare-pill ecare-pill-<?php echo $b->status === 'completed' ? 'green' : ($b->status === 'pending' ? 'yellow' : ($b->status === 'cancelled' ? 'red' : 'gray')); ?>"><?php echo esc_html(ucfirst($b->status)); ?></span></td>
                                    <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($b->created_at))); ?></td>
                                    <td>
                                        <select class="ecare-status-select" data-booking-id="<?php echo intval($b->id); ?>" style="padding:4px 8px;border:1px solid var(--border-color);border-radius:6px;font-size:12px;">
                                            <option value="pending" <?php selected($b->status, 'pending'); ?>><?php _e('Pending', 'ecare-health-services'); ?></option>
                                            <option value="approved" <?php selected($b->status, 'approved'); ?>><?php _e('Approved', 'ecare-health-services'); ?></option>
                                            <option value="completed" <?php selected($b->status, 'completed'); ?>><?php _e('Completed', 'ecare-health-services'); ?></option>
                                            <option value="cancelled" <?php selected($b->status, 'cancelled'); ?>><?php _e('Cancelled', 'ecare-health-services'); ?></option>
                                        </select>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="8" style="text-align:center;padding:30px;color:var(--text-muted);"><?php echo esc_html(sprintf(__('No %s found.', 'ecare-health-services'), strtolower($title))); ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
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
