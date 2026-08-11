<?php
defined('ABSPATH') || exit;

class ECare_Admin {

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'add_admin_menus'), 20);
        add_action('admin_post_ecare_export_bookings', array(__CLASS__, 'export_bookings_csv'));

        // Caregiver Type Term Image meta hooks
        add_action('ecare_caregiver_type_add_form_fields', array(__CLASS__, 'add_caregiver_type_image_field'), 10, 2);
        add_action('ecare_caregiver_type_edit_form_fields', array(__CLASS__, 'edit_caregiver_type_image_field'), 10, 2);
        add_action('created_ecare_caregiver_type', array(__CLASS__, 'save_caregiver_type_image'));
        add_action('edited_ecare_caregiver_type', array(__CLASS__, 'save_caregiver_type_image'));
        add_filter('manage_edit-ecare_caregiver_type_columns', array(__CLASS__, 'add_caregiver_type_columns'));
        add_filter('manage_ecare_caregiver_type_custom_column', array(__CLASS__, 'render_caregiver_type_column_content'), 10, 3);
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

        add_submenu_page(
            'ecare-dashboard',
            __('Ambulance Providers', 'ecare-health-services'),
            __('Ambulance Providers', 'ecare-health-services'),
            'manage_options',
            'ecare-ambulance-providers',
            array(__CLASS__, 'render_ambulance_registry')
        );
    }

    /**
     * Helper to render custom styles for WP Admin head to override defaults
     */
    private static function admin_style_overrides() {
        ?>
        <style>
            #wpbody-content { background-color: #F8FAFC !important; }
            .update-nag, .notice, #message { margin-left: 20px !important; margin-right: 20px !important; }
        </style>
        <?php
    }

    // ---- Dashboard (Overview) ----

    public static function render_dashboard() {
        self::admin_style_overrides();
        global $wpdb;
        $bookings_table = $wpdb->prefix . 'ecare_bookings';

        // Fetch metrics
        $total_bookings = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$bookings_table}");
        $pending       = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$bookings_table} WHERE status = 'pending'");
        $completed     = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$bookings_table} WHERE status = 'completed'");
        $cancelled     = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$bookings_table} WHERE status = 'cancelled'");

        $recent_bookings = $wpdb->get_results("SELECT * FROM {$bookings_table} ORDER BY created_at DESC LIMIT 10");
        ?>
        <div class="ecare-admin-wrap">
            <h1 style="font-weight:800;font-size:24px;margin-bottom:20px;color:var(--text-dark);"><?php _e('E-Care Overview Dashboard', 'ecare-health-services'); ?></h1>
            
            <!-- KPI Metric Cards Grid -->
            <div class="ecare-admin-kpi-grid">
                <div class="ecare-admin-kpi-card">
                    <div class="ecare-admin-kpi-icon teal">📈</div>
                    <div class="ecare-admin-kpi-details">
                        <span class="ecare-admin-kpi-label"><?php _e('TOTAL BOOKINGS', 'ecare-health-services'); ?></span>
                        <span class="ecare-admin-kpi-value"><?php echo $total_bookings; ?></span>
                    </div>
                </div>
                <div class="ecare-admin-kpi-card">
                    <div class="ecare-admin-kpi-icon yellow">⏳</div>
                    <div class="ecare-admin-kpi-details">
                        <span class="ecare-admin-kpi-label"><?php _e('PENDING APPROVALS', 'ecare-health-services'); ?></span>
                        <span class="ecare-admin-kpi-value"><?php echo $pending; ?></span>
                    </div>
                </div>
                <div class="ecare-admin-kpi-card">
                    <div class="ecare-admin-kpi-icon green">✓</div>
                    <div class="ecare-admin-kpi-details">
                        <span class="ecare-admin-kpi-label"><?php _e('COMPLETED CARE', 'ecare-health-services'); ?></span>
                        <span class="ecare-admin-kpi-value"><?php echo $completed; ?></span>
                    </div>
                </div>
                <div class="ecare-admin-kpi-card">
                    <div class="ecare-admin-kpi-icon red">✕</div>
                    <div class="ecare-admin-kpi-details">
                        <span class="ecare-admin-kpi-label"><?php _e('CANCELLED', 'ecare-health-services'); ?></span>
                        <span class="ecare-admin-kpi-value"><?php echo $cancelled; ?></span>
                    </div>
                </div>
            </div>

            <!-- Action Header -->
            <div class="ecare-admin-action-header">
                <div class="ecare-admin-title-area">
                    <h2><?php _e('Recent Services Dispatch', 'ecare-health-services'); ?></h2>
                    <span class="ecare-admin-badge-count"><?php echo count($recent_bookings); ?></span>
                </div>
                <div class="ecare-admin-controls">
                    <input type="text" class="ecare-search-input" placeholder="<?php esc_attr_e('Search bookings...', 'ecare-health-services'); ?>" />
                    <a href="<?php echo esc_url(admin_url('admin-post.php?action=ecare_export_bookings')); ?>" class="ecare-admin-btn-outline">📊 <?php _e('Export', 'ecare-health-services'); ?></a>
                </div>
            </div>

            <!-- Modern Table -->
            <div class="ecare-admin-table-container">
                <table class="ecare-admin-table">
                    <thead>
                        <tr>
                            <th><?php _e('ID', 'ecare-health-services'); ?></th>
                            <th><?php _e('Type', 'ecare-health-services'); ?></th>
                            <th><?php _e('Patient Name', 'ecare-health-services'); ?></th>
                            <th><?php _e('Amount', 'ecare-health-services'); ?></th>
                            <th><?php _e('Status', 'ecare-health-services'); ?></th>
                            <th><?php _e('Date Requested', 'ecare-health-services'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($recent_bookings): ?>
                            <?php foreach ($recent_bookings as $b): ?>
                                <tr>
                                    <td>#<?php echo intval($b->id); ?></td>
                                    <td><span class="ecare-status-pill dispatched"><?php echo esc_html(ucfirst($b->booking_type)); ?></span></td>
                                    <td><strong><?php echo esc_html($b->patient_name ?: 'N/A'); ?></strong></td>
                                    <td style="font-weight:700;color:var(--brand-teal);">৳ <?php echo esc_html(number_format($b->total_amount, 2)); ?></td>
                                    <td><span class="ecare-status-pill <?php echo esc_attr($b->status); ?>"><?php echo esc_html(ucfirst($b->status)); ?></span></td>
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
        self::admin_style_overrides();
        global $wpdb;
        $table = $wpdb->prefix . 'ecare_bookings';

        // Calculate dynamic care metrics
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE booking_type = %s", 'caregiver'));
        $pending = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE booking_type = %s AND status = %s", 'caregiver', 'pending'));
        $completed = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE booking_type = %s AND status = %s", 'caregiver', 'completed'));
        $cancelled = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE booking_type = %s AND status = %s", 'caregiver', 'cancelled'));

        $bookings = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE booking_type = %s ORDER BY created_at DESC", 'caregiver'));
        
        ?>
        <div class="ecare-admin-wrap">
            <h1 style="font-weight:800;font-size:24px;margin-bottom:20px;color:var(--text-dark);"><?php _e('Caregiver Bookings Management', 'ecare-health-services'); ?></h1>
            
            <div class="ecare-admin-kpi-grid">
                <div class="ecare-admin-kpi-card">
                    <div class="ecare-admin-kpi-icon teal">🗓️</div>
                    <div class="ecare-admin-kpi-details">
                        <span class="ecare-admin-kpi-label"><?php _e('Total Bookings', 'ecare-health-services'); ?></span>
                        <span class="ecare-admin-kpi-value"><?php echo $total; ?></span>
                    </div>
                </div>
                <div class="ecare-admin-kpi-card">
                    <div class="ecare-admin-kpi-icon yellow">⏳</div>
                    <div class="ecare-admin-kpi-details">
                        <span class="ecare-admin-kpi-label"><?php _e('Pending Approvals', 'ecare-health-services'); ?></span>
                        <span class="ecare-admin-kpi-value"><?php echo $pending; ?></span>
                    </div>
                </div>
                <div class="ecare-admin-kpi-card">
                    <div class="ecare-admin-kpi-icon green">✓</div>
                    <div class="ecare-admin-kpi-details">
                        <span class="ecare-admin-kpi-label"><?php _e('Completed Care', 'ecare-health-services'); ?></span>
                        <span class="ecare-admin-kpi-value"><?php echo $completed; ?></span>
                    </div>
                </div>
                <div class="ecare-admin-kpi-card">
                    <div class="ecare-admin-kpi-icon red">✕</div>
                    <div class="ecare-admin-kpi-details">
                        <span class="ecare-admin-kpi-label"><?php _e('Cancelled', 'ecare-health-services'); ?></span>
                        <span class="ecare-admin-kpi-value"><?php echo $cancelled; ?></span>
                    </div>
                </div>
            </div>

            <!-- Action Bar -->
            <div class="ecare-admin-action-header">
                <div class="ecare-admin-title-area">
                    <h2><?php _e('Bookings Management', 'ecare-health-services'); ?></h2>
                    <span class="ecare-admin-badge-count"><?php echo count($bookings); ?></span>
                </div>
                <div class="ecare-admin-controls">
                    <input type="text" class="ecare-search-input" placeholder="<?php esc_attr_e('Search bookings...', 'ecare-health-services'); ?>" />
                    <button class="ecare-admin-btn-outline">🔍 <?php _e('Filters', 'ecare-health-services'); ?></button>
                    <a href="<?php echo esc_url(admin_url('admin-post.php?action=ecare_export_bookings')); ?>" class="ecare-admin-btn-outline">📊 <?php _e('Export', 'ecare-health-services'); ?></a>
                    <a href="<?php echo esc_url(admin_url('post-new.php?post_type=ecare_caregiver')); ?>" class="ecare-admin-btn-green">+ <?php _e('Add Booking', 'ecare-health-services'); ?></a>
                </div>
            </div>

            <!-- Table -->
            <div class="ecare-admin-table-container">
                <table class="ecare-admin-table">
                    <thead>
                        <tr>
                            <th><?php _e('ID', 'ecare-health-services'); ?></th>
                            <th><?php _e('Care Provider', 'ecare-health-services'); ?></th>
                            <th><?php _e('Patient', 'ecare-health-services'); ?></th>
                            <th><?php _e('Service Package', 'ecare-health-services'); ?></th>
                            <th><?php _e('Required Date', 'ecare-health-services'); ?></th>
                            <th><?php _e('Amount', 'ecare-health-services'); ?></th>
                            <th><?php _e('Status', 'ecare-health-services'); ?></th>
                            <th><?php _e('Payment', 'ecare-health-services'); ?></th>
                            <th><?php _e('Actions', 'ecare-health-services'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($bookings): ?>
                            <?php foreach ($bookings as $b):
                                $provider_name = $b->provider_id ? get_the_title($b->provider_id) : 'Any Provider';
                                $pkg_label = str_replace('_', ' ', $b->package_type);
                            ?>
                                <tr>
                                    <td>#<?php echo intval($b->id); ?></td>
                                    <td><strong><?php echo esc_html($provider_name); ?></strong></td>
                                    <td><?php echo esc_html($b->patient_name); ?></td>
                                    <td><span class="ecare-status-pill assigned"><?php echo esc_html(ucfirst($pkg_label)); ?></span></td>
                                    <td><?php echo esc_html($b->required_date); ?></td>
                                    <td style="font-weight:700;color:var(--brand-teal);">৳ <?php echo esc_html(number_format($b->total_amount, 2)); ?></td>
                                    <td><span class="ecare-status-pill <?php echo esc_attr($b->status); ?>"><?php echo esc_html(ucfirst($b->status)); ?></span></td>
                                    <td>
                                        <?php if ($b->order_id): ?>
                                            <a href="<?php echo esc_url(get_edit_post_link($b->order_id)); ?>" target="_blank" style="font-weight:600;color:#2563EB;text-decoration:none;">Order #<?php echo $b->order_id; ?></a>
                                        <?php else: ?>
                                            <span style="color:var(--text-muted);font-style:italic;">No Order</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <select class="ecare-status-select" data-booking-id="<?php echo intval($b->id); ?>" style="padding:5px;font-size:12px;border-radius:4px;border:1px solid var(--border-light);">
                                            <option value="pending" <?php selected($b->status, 'pending'); ?>>Pending</option>
                                            <option value="approved" <?php selected($b->status, 'approved'); ?>>Approved</option>
                                            <option value="completed" <?php selected($b->status, 'completed'); ?>>Completed</option>
                                            <option value="cancelled" <?php selected($b->status, 'cancelled'); ?>>Cancelled</option>
                                        </select>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="9" style="text-align:center;padding:30px;color:var(--text-muted);"><?php _e('No bookings found.', 'ecare-health-services'); ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    // ---- Care Providers ----

    public static function render_care_providers() {
        self::admin_style_overrides();
        
        // Fetch providers CPT
        $providers = get_posts(array(
            'post_type'      => 'ecare_caregiver',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
        ));

        // Count metrics dynamically
        $active_count = 0;
        $pending_count = 0;
        foreach ($providers as $p) {
            $status = get_post_meta($p->ID, '_provider_status', true) ?: 'pending';
            if ($status === 'approved') $active_count++;
            if ($status === 'pending') $pending_count++;
        }

        ?>
        <div class="ecare-admin-wrap">
            <h1 style="font-weight:800;font-size:24px;margin-bottom:20px;color:var(--text-dark);"><?php _e('Care Providers Management', 'ecare-health-services'); ?></h1>
            
            <!-- 4 Grid Columns KPI Metrics matching PDF Page 3 -->
            <div class="ecare-admin-kpi-grid">
                <div class="ecare-admin-kpi-card">
                    <div class="ecare-admin-kpi-icon teal">👥</div>
                    <div class="ecare-admin-kpi-details">
                        <span class="ecare-admin-kpi-label"><?php _e('Active Providers', 'ecare-health-services'); ?></span>
                        <span class="ecare-admin-kpi-value"><?php echo $active_count; ?></span>
                    </div>
                </div>
                <div class="ecare-admin-kpi-card">
                    <div class="ecare-admin-kpi-icon green">📍</div>
                    <div class="ecare-admin-kpi-details">
                        <span class="ecare-admin-kpi-label"><?php _e('Total Service Points', 'ecare-health-services'); ?></span>
                        <span class="ecare-admin-kpi-value"><?php echo $active_count; ?></span>
                    </div>
                </div>
                <div class="ecare-admin-kpi-card">
                    <div class="ecare-admin-kpi-icon yellow">⏳</div>
                    <div class="ecare-admin-kpi-details">
                        <span class="ecare-admin-kpi-label"><?php _e('Pending Review', 'ecare-health-services'); ?></span>
                        <span class="ecare-admin-kpi-value"><?php echo $pending_count; ?></span>
                    </div>
                </div>
                <div class="ecare-admin-kpi-card">
                    <div class="ecare-admin-kpi-icon red">📅</div>
                    <div class="ecare-admin-kpi-details">
                        <span class="ecare-admin-kpi-label"><?php _e('Completed Shifts', 'ecare-health-services'); ?></span>
                        <span class="ecare-admin-kpi-value">0</span>
                    </div>
                </div>
            </div>

            <!-- Action Header -->
            <div class="ecare-admin-action-header">
                <div class="ecare-admin-title-area">
                    <h2><?php _e('Care Provider Registry', 'ecare-health-services'); ?></h2>
                    <span class="ecare-admin-badge-count"><?php echo count($providers); ?></span>
                </div>
                <div class="ecare-admin-controls">
                    <input type="text" class="ecare-search-input" placeholder="<?php esc_attr_e('Search providers...', 'ecare-health-services'); ?>" />
                    <button class="ecare-admin-btn-outline">📊 <?php _e('Export', 'ecare-health-services'); ?></button>
                    <button type="button" class="button ecare-admin-btn-outline" id="ecare-add-caregiver-type-btn">+ <?php _e('Caregiver Type Info', 'ecare-health-services'); ?></button>
                    <a href="<?php echo esc_url(admin_url('post-new.php?post_type=ecare_caregiver')); ?>" class="ecare-admin-btn-green">+ <?php _e('Register New', 'ecare-health-services'); ?></a>
                </div>
            </div>

            <!-- Table -->
            <div class="ecare-admin-table-container">
                <table class="ecare-admin-table">
                    <thead>
                        <tr>
                            <th>[ ]</th>
                            <th><?php _e('Provider ID', 'ecare-health-services'); ?></th>
                            <th><?php _e('Provider Info', 'ecare-health-services'); ?></th>
                            <th><?php _e('Expertise', 'ecare-health-services'); ?></th>
                            <th><?php _e('Contact Details', 'ecare-health-services'); ?></th>
                            <th><?php _e('Professional', 'ecare-health-services'); ?></th>
                            <th><?php _e('Location', 'ecare-health-services'); ?></th>
                            <th><?php _e('Status', 'ecare-health-services'); ?></th>
                            <th><?php _e('Actions', 'ecare-health-services'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($providers): ?>
                            <?php foreach ($providers as $p):
                                $type   = get_post_meta($p->ID, '_provider_type', true);
                                $exp    = get_post_meta($p->ID, '_experience', true);
                                $email  = get_post_meta($p->ID, '_email', true) ?: 'n/a';
                                $phone  = get_post_meta($p->ID, '_phone', true) ?: 'n/a';
                                $cat    = get_post_meta($p->ID, '_category', true) ?: 'Labaid';
                                $nid    = get_post_meta($p->ID, '_nid_passport', true) ?: '0987654321';
                                $loc    = 'Mirpur'; // Location placeholder matching Page 3
                                $status = get_post_meta($p->ID, '_provider_status', true) ?: 'pending';
                                
                                $first_letter = strtoupper(substr($p->post_title, 0, 1));
                            ?>
                                <tr>
                                    <td><input type="checkbox" /></td>
                                    <td style="color:#0E9F6E;font-weight:700;">#PRO-<?php echo strtoupper(substr(md5($p->ID), 0, 8)); ?></td>
                                    <td>
                                        <div class="ecare-admin-provider-info">
                                            <div class="ecare-admin-avatar-placeholder"><?php echo $first_letter; ?></div>
                                            <div>
                                                <a href="<?php echo esc_url(get_edit_post_link($p->ID)); ?>" class="provider-name-link"><?php echo esc_html($p->post_title); ?></a>
                                                <span style="display:block;font-size:11px;color:var(--text-muted);"><?php _e('General Provider', 'ecare-health-services'); ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <strong><?php echo esc_html($type); ?></strong>
                                        <span style="display:block;font-size:11px;color:var(--text-muted);"><?php echo esc_html($exp); ?> Years Exp</span>
                                    </td>
                                    <td>
                                        <span>📞 <?php echo esc_html($phone); ?></span>
                                        <span style="display:block;font-size:11px;color:var(--text-muted);">✉️ <?php echo esc_html($email); ?></span>
                                    </td>
                                    <td>
                                        <strong><?php echo esc_html($cat); ?></strong>
                                        <span style="display:block;font-size:11px;color:var(--text-muted);">ID: <?php echo esc_html($nid); ?></span>
                                    </td>
                                    <td><?php echo esc_html($loc); ?></td>
                                    <td>
                                        <span class="ecare-status-pill <?php echo esc_attr($status); ?>"><?php echo esc_html(ucfirst($status)); ?></span>
                                    </td>
                                    <td>
                                        <div style="display:flex;gap:4px;">
                                            <a href="<?php echo esc_url(get_edit_post_link($p->ID)); ?>" class="ecare-admin-btn-outline" style="padding:4px 8px;font-size:11px;" title="<?php esc_attr_e('Edit Provider', 'ecare-health-services'); ?>">✏️</a>
                                            <button type="button" class="ecare-admin-btn-outline ecare-delete-provider" data-id="<?php echo intval($p->ID); ?>" style="padding:4px 8px;font-size:11px;border-color:#EF4444;color:#EF4444;" title="<?php esc_attr_e('Delete Provider', 'ecare-health-services'); ?>">🗑️</button>
                                            <?php if ($status === 'pending'): ?>
                                                <button class="ecare-admin-btn-green ecare-approve-provider" data-id="<?php echo intval($p->ID); ?>" style="padding:4px 8px;font-size:11px;">✓</button>
                                                <button class="ecare-admin-btn-outline ecare-reject-provider" data-id="<?php echo intval($p->ID); ?>" style="padding:4px 8px;font-size:11px;border-color:#EF4444;color:#EF4444;">✕</button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="9" style="text-align:center;padding:30px;color:var(--text-muted);"><?php _e('No providers registered yet.', 'ecare-health-services'); ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Add Caregiver Type Modal -->
            <div id="ecare-add-type-modal" class="ecare-admin-modal-backdrop" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:99999; justify-content:center; align-items:center;">
                <div class="ecare-admin-modal-content" style="background:#fff; padding:30px; border-radius:12px; width:500px; max-height:85vh; overflow-y:auto; box-shadow:0 10px 25px rgba(0,0,0,0.15); position:relative;">
                    <h3 id="ecare-type-modal-title" style="margin-top:0; font-size:18px; font-weight:700; color:#1E293B; margin-bottom:20px;"><?php _e('Add New Caregiver Type Info', 'ecare-health-services'); ?></h3>
                    
                    <form id="ecare-add-type-form">
                        <!-- Hidden inputs for state management -->
                        <input type="hidden" id="ecare-edit-term-id" name="term_id" value="" />
                        <input type="hidden" id="ecare-type-remove-image" name="remove_image" value="0" />

                        <div style="margin-bottom:15px;">
                            <label style="display:block; font-weight:600; font-size:13px; color:#475569; margin-bottom:6px;"><?php _e('Caregiver Type Name', 'ecare-health-services'); ?> <span style="color:#EF4444;">*</span></label>
                            <input type="text" id="ecare-new-type-name" name="type_name" style="width:100%; padding:10px; border-radius:6px; border:1px solid #CBD5E1; font-size:14px;" placeholder="e.g. Maternity Care" required />
                        </div>

                        <div style="margin-bottom:20px;">
                            <label style="display:block; font-weight:600; font-size:13px; color:#475569; margin-bottom:6px;"><?php _e('Type Image / Icon', 'ecare-health-services'); ?></label>
                            <input type="hidden" id="ecare-new-type-image" name="image_id" value="" />
                            <div id="ecare-new-type-image-preview" style="margin-bottom:10px; width:60px; height:60px; border-radius:50%; background:#F1F5F9; border:1px dashed #CBD5E1; display:none; align-items:center; justify-content:center; overflow:hidden;"></div>
                            <p style="margin:0;">
                                <input type="button" class="button button-secondary id-upload-type-image-btn" value="<?php esc_attr_e('Upload Image', 'ecare-health-services'); ?>" />
                                <input type="button" class="button button-secondary id-remove-type-image-btn" value="<?php esc_attr_e('Remove', 'ecare-health-services'); ?>" style="display:none;" />
                            </p>
                        </div>

                        <div style="margin-bottom:20px;">
                            <label style="display:block; font-weight:600; font-size:13px; color:#475569; margin-bottom:6px;"><?php _e('Duration Packages', 'ecare-health-services'); ?></label>
                            <div id="ecare-modal-packages-list" style="margin-bottom:10px;">
                                <div class="ecare-modal-package-row" style="display:flex; gap:10px; margin-bottom:8px; align-items:center;">
                                    <input type="text" name="term_package_labels[]" placeholder="e.g. Daily (12 Hours)" style="flex:2; padding:8px; border-radius:6px; border:1px solid #CBD5E1; font-size:13px;" required />
                                    <input type="number" name="term_package_prices[]" placeholder="Price (৳)" style="flex:1; padding:8px; border-radius:6px; border:1px solid #CBD5E1; font-size:13px;" required />
                                    <button type="button" class="button ecare-remove-package-row-btn" style="background:#EF4444; color:#fff; border-color:#EF4444; padding:6px 10px; height:auto; line-height:1;">&times;</button>
                                </div>
                            </div>
                            <button type="button" id="ecare-modal-add-package-row-btn" class="button button-secondary" style="font-size:12px; padding:4px 8px; height:auto;">+ Add New Field</button>
                        </div>

                        <div style="display:flex; justify-content:flex-end; gap:10px; border-top:1px solid #E2E8F0; padding-top:15px;">
                            <button type="button" id="ecare-cancel-edit-type-btn" class="button button-secondary" style="padding: 6px 12px; height: auto; display:none; border-color:#EF4444; color:#EF4444;"><?php _e('Cancel Edit', 'ecare-health-services'); ?></button>
                            <button type="button" id="ecare-close-type-modal" class="button button-secondary" style="padding: 6px 12px; height: auto;"><?php _e('Cancel', 'ecare-health-services'); ?></button>
                            <button type="submit" id="ecare-submit-type-btn" class="button button-primary" style="padding: 6px 12px; height: auto; background:#0E9F6E; border-color:#0E9F6E; color:#fff; font-weight:600;"><?php _e('Add Type', 'ecare-health-services'); ?></button>
                        </div>
                    </form>

                    <!-- Add Type info list panel -->
                    <div style="border-top:1px solid #E2E8F0; margin-top:20px; padding-top:15px; text-align:center;">
                        <button type="button" id="ecare-toggle-manage-types-btn" class="button button-secondary" style="width:100%; font-weight:600; color:#475569; background:#F8FAFC; border-color:#CBD5E1;"><?php _e('📋 View / Manage Caregiver Types Info', 'ecare-health-services'); ?></button>
                    </div>
                    
                    <div id="ecare-manage-types-container" style="display:none; margin-top:15px; border:1px solid #E2E8F0; border-radius:8px; padding:10px; max-height:250px; overflow-y:auto; background:#F8FAFC;">
                        <div id="ecare-manage-types-list" style="display:flex; flex-direction:column; gap:8px;">
                            <!-- Loaded dynamically via AJAX -->
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    // ---- Lab Catalog ----

    public static function render_lab_catalog() {
        self::admin_style_overrides();
        
        $tests = get_posts(array(
            'post_type'      => 'ecare_lab_test',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
        ));
        
        ?>
        <div class="ecare-admin-wrap">
            <h1 style="font-weight:800;font-size:24px;margin-bottom:20px;color:var(--text-dark);"><?php _e('Lab Diagnostic Catalog', 'ecare-health-services'); ?></h1>
            
            <div class="ecare-admin-kpi-grid">
                <div class="ecare-admin-kpi-card">
                    <div class="ecare-admin-kpi-icon teal">🔬</div>
                    <div class="ecare-admin-kpi-details">
                        <span class="ecare-admin-kpi-label"><?php _e('TOTAL TESTS', 'ecare-health-services'); ?></span>
                        <span class="ecare-admin-kpi-value"><?php echo count($tests); ?></span>
                    </div>
                </div>
                <div class="ecare-admin-kpi-card">
                    <div class="ecare-admin-kpi-icon green">৳</div>
                    <div class="ecare-admin-kpi-details">
                        <span class="ecare-admin-kpi-label"><?php _e('TOTAL REVENUE', 'ecare-health-services'); ?></span>
                        <span class="ecare-admin-kpi-value">৳ 0</span>
                    </div>
                </div>
                <div class="ecare-admin-kpi-card">
                    <div class="ecare-admin-kpi-icon yellow">⏳</div>
                    <div class="ecare-admin-kpi-details">
                        <span class="ecare-admin-kpi-label"><?php _e('PENDING', 'ecare-health-services'); ?></span>
                        <span class="ecare-admin-kpi-value">0</span>
                    </div>
                </div>
                <div class="ecare-admin-kpi-card">
                    <div class="ecare-admin-kpi-icon green">✓</div>
                    <div class="ecare-admin-kpi-details">
                        <span class="ecare-admin-kpi-label"><?php _e('COMPLETED', 'ecare-health-services'); ?></span>
                        <span class="ecare-admin-kpi-value">0</span>
                    </div>
                </div>
            </div>

            <!-- Action Bar -->
            <div class="ecare-admin-action-header">
                <div class="ecare-admin-title-area">
                    <h2><?php _e('Diagnostic Lab Catalog', 'ecare-health-services'); ?></h2>
                    <span class="ecare-admin-badge-count"><?php echo count($tests); ?></span>
                </div>
                <div class="ecare-admin-controls">
                    <input type="text" class="ecare-search-input" placeholder="<?php esc_attr_e('Search tests...', 'ecare-health-services'); ?>" />
                    <button class="ecare-admin-btn-outline">🔍 <?php _e('Filters', 'ecare-health-services'); ?></button>
                    <button class="ecare-admin-btn-outline">📊 <?php _e('Export', 'ecare-health-services'); ?></button>
                    <a href="<?php echo esc_url(admin_url('post-new.php?post_type=ecare_lab_test')); ?>" class="ecare-admin-btn-green">+ <?php _e('Add New Test', 'ecare-health-services'); ?></a>
                </div>
            </div>

            <!-- Table -->
            <div class="ecare-admin-table-container">
                <table class="ecare-admin-table">
                    <thead>
                        <tr>
                            <th>[ ]</th>
                            <th><?php _e('Test ID', 'ecare-health-services'); ?></th>
                            <th><?php _e('Test Code', 'ecare-health-services'); ?></th>
                            <th><?php _e('Test Name', 'ecare-health-services'); ?></th>
                            <th><?php _e('Location Hierarchy', 'ecare-health-services'); ?></th>
                            <th><?php _e('Price', 'ecare-health-services'); ?></th>
                            <th><?php _e('Turnaround', 'ecare-health-services'); ?></th>
                            <th><?php _e('Status', 'ecare-health-services'); ?></th>
                            <th><?php _e('Actions', 'ecare-health-services'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($tests): ?>
                            <?php foreach ($tests as $t):
                                $code     = get_post_meta($t->ID, '_test_code', true);
                                $price    = get_post_meta($t->ID, '_price', true);
                                $turn     = get_post_meta($t->ID, '_turnaround_days', true);
                                $status   = get_post_meta($t->ID, '_test_status', true) ?: 'active';
                                $division = get_post_meta($t->ID, '_division', true);
                                $district = get_post_meta($t->ID, '_district', true);
                                $area     = get_post_meta($t->ID, '_area', true);
                                $provider = get_post_meta($t->ID, '_lab_provider', true);
                                
                                $location_hierarchy = implode(' > ', array_filter(array($division, $district, $area, $provider)));
                            ?>
                                <tr>
                                    <td><input type="checkbox" /></td>
                                    <td>#TST-<?php echo $t->ID; ?></td>
                                    <td style="font-weight:700;color:var(--brand-purple);"><?php echo esc_html($code); ?></td>
                                    <td><strong><?php echo esc_html($t->post_title); ?></strong></td>
                                    <td><small><?php echo esc_html($location_hierarchy ?: 'N/A'); ?></small></td>
                                    <td style="font-weight:700;color:var(--brand-teal);">৳ <?php echo esc_html(number_format($price, 2)); ?></td>
                                    <td><?php echo esc_html($turn); ?> days</td>
                                    <td><span class="ecare-status-pill <?php echo esc_attr($status); ?>"><?php echo esc_html(ucfirst($status)); ?></span></td>
                                    <td>
                                        <a href="<?php echo esc_url(get_edit_post_link($t->ID)); ?>" class="ecare-admin-btn-outline" style="padding:4px 8px;font-size:12px;"><?php _e('Edit', 'ecare-health-services'); ?></a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="9" style="text-align:center;padding:30px;color:var(--text-muted);"><?php _e('No tests registered yet.', 'ecare-health-services'); ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    // ---- Lab Orders ----

    public static function render_lab_orders() {
        self::admin_style_overrides();
        global $wpdb;
        $table = $wpdb->prefix . 'ecare_bookings';

        // Calculate dynamic lab order metrics
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE booking_type = %s", 'lab'));
        $revenue = (float) $wpdb->get_var($wpdb->prepare("SELECT SUM(total_amount) FROM {$table} WHERE booking_type = %s AND status = %s", 'lab', 'completed'));
        $pending = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE booking_type = %s AND status = %s", 'lab', 'pending'));
        $completed = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE booking_type = %s AND status = %s", 'lab', 'completed'));

        $orders = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE booking_type = %s ORDER BY created_at DESC", 'lab'));
        
        ?>
        <div class="ecare-admin-wrap">
            <h1 style="font-weight:800;font-size:24px;margin-bottom:20px;color:var(--text-dark);"><?php _e('Lab Orders Dashboard', 'ecare-health-services'); ?></h1>
            
            <div class="ecare-admin-kpi-grid">
                <div class="ecare-admin-kpi-card">
                    <div class="ecare-admin-kpi-icon teal">🩺</div>
                    <div class="ecare-admin-kpi-details">
                        <span class="ecare-admin-kpi-label"><?php _e('Total Tests Ordered', 'ecare-health-services'); ?></span>
                        <span class="ecare-admin-kpi-value"><?php echo $total; ?></span>
                    </div>
                </div>
                <div class="ecare-admin-kpi-card">
                    <div class="ecare-admin-kpi-icon green">৳</div>
                    <div class="ecare-admin-kpi-details">
                        <span class="ecare-admin-kpi-label"><?php _e('Total Revenue', 'ecare-health-services'); ?></span>
                        <span class="ecare-admin-kpi-value">৳ <?php echo number_format($revenue); ?></span>
                    </div>
                </div>
                <div class="ecare-admin-kpi-card">
                    <div class="ecare-admin-kpi-icon yellow">⏳</div>
                    <div class="ecare-admin-kpi-details">
                        <span class="ecare-admin-kpi-label"><?php _e('Pending Orders', 'ecare-health-services'); ?></span>
                        <span class="ecare-admin-kpi-value"><?php echo $pending; ?></span>
                    </div>
                </div>
                <div class="ecare-admin-kpi-card">
                    <div class="ecare-admin-kpi-icon green">✓</div>
                    <div class="ecare-admin-kpi-details">
                        <span class="ecare-admin-kpi-label"><?php _e('Completed', 'ecare-health-services'); ?></span>
                        <span class="ecare-admin-kpi-value"><?php echo $completed; ?></span>
                    </div>
                </div>
            </div>

            <!-- Action Bar -->
            <div class="ecare-admin-action-header">
                <div class="ecare-admin-title-area">
                    <h2><?php _e('Diagnostic Orders', 'ecare-health-services'); ?></h2>
                    <span class="ecare-admin-badge-count"><?php echo count($orders); ?></span>
                </div>
                <div class="ecare-admin-controls">
                    <input type="text" class="ecare-search-input" placeholder="<?php esc_attr_e('Search orders...', 'ecare-health-services'); ?>" />
                    <button class="ecare-admin-btn-outline">📊 <?php _e('Export', 'ecare-health-services'); ?></button>
                    <a href="<?php echo esc_url(admin_url('post-new.php?post_type=ecare_lab_test')); ?>" class="ecare-admin-btn-green">+ <?php _e('Place New Order', 'ecare-health-services'); ?></a>
                </div>
            </div>

            <!-- Table -->
            <div class="ecare-admin-table-container">
                <table class="ecare-admin-table">
                    <thead>
                        <tr>
                            <th>[ ]</th>
                            <th><?php _e('ID', 'ecare-health-services'); ?></th>
                            <th><?php _e('Patient Name', 'ecare-health-services'); ?></th>
                            <th><?php _e('Contact', 'ecare-health-services'); ?></th>
                            <th><?php _e('Amount', 'ecare-health-services'); ?></th>
                            <th><?php _e('Status', 'ecare-health-services'); ?></th>
                            <th><?php _e('Payment', 'ecare-health-services'); ?></th>
                            <th><?php _e('Actions', 'ecare-health-services'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($orders): ?>
                            <?php foreach ($orders as $b): ?>
                                <tr>
                                    <td><input type="checkbox" /></td>
                                    <td>#<?php echo intval($b->id); ?></td>
                                    <td><strong><?php echo esc_html($b->patient_name ?: 'N/A'); ?></strong></td>
                                    <td><?php echo esc_html($b->contact_phone); ?></td>
                                    <td style="font-weight:700;color:var(--brand-teal);">৳ <?php echo esc_html(number_format($b->total_amount, 2)); ?></td>
                                    <td><span class="ecare-status-pill <?php echo esc_attr($b->status); ?>"><?php echo esc_html(ucfirst($b->status)); ?></span></td>
                                    <td>
                                        <?php if ($b->order_id): ?>
                                            <a href="<?php echo esc_url(get_edit_post_link($b->order_id)); ?>" target="_blank" style="font-weight:600;color:#2563EB;text-decoration:none;">Order #<?php echo $b->order_id; ?></a>
                                        <?php else: ?>
                                            <span style="color:var(--text-muted);font-style:italic;">No Order</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <select class="ecare-status-select" data-booking-id="<?php echo intval($b->id); ?>" style="padding:5px;font-size:12px;border-radius:4px;border:1px solid var(--border-light);">
                                            <option value="pending" <?php selected($b->status, 'pending'); ?>>Pending</option>
                                            <option value="approved" <?php selected($b->status, 'approved'); ?>>Approved</option>
                                            <option value="completed" <?php selected($b->status, 'completed'); ?>>Completed</option>
                                            <option value="cancelled" <?php selected($b->status, 'cancelled'); ?>>Cancelled</option>
                                        </select>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="8" style="text-align:center;padding:30px;color:var(--text-muted);"><?php _e('No orders found.', 'ecare-health-services'); ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    // ---- Ambulance Dispatch ----

    public static function render_ambulance_dispatch() {
        self::admin_style_overrides();
        global $wpdb;
        $table = $wpdb->prefix . 'ecare_bookings';

        // Calculate dynamic ambulance metrics
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE booking_type = %s", 'ambulance'));
        $active_dispatched = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE booking_type = %s AND status = %s", 'ambulance', 'dispatched'));
        $completed = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE booking_type = %s AND status = %s", 'ambulance', 'completed'));
        $emergency = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE booking_type = %s AND priority_level = %s", 'ambulance', 'Emergency'));

        $bookings = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE booking_type = %s ORDER BY created_at DESC", 'ambulance'));
        
        ?>
        <div class="ecare-admin-wrap">
            <h1 style="font-weight:800;font-size:24px;margin-bottom:20px;color:var(--text-dark);"><?php _e('Ambulance Dispatch Management', 'ecare-health-services'); ?></h1>
            
            <div class="ecare-admin-kpi-grid">
                <div class="ecare-admin-kpi-card">
                    <div class="ecare-admin-kpi-icon teal">🚑</div>
                    <div class="ecare-admin-kpi-details">
                        <span class="ecare-admin-kpi-label"><?php _e('Total Missions', 'ecare-health-services'); ?></span>
                        <span class="ecare-admin-kpi-value"><?php echo $total; ?></span>
                    </div>
                </div>
                <div class="ecare-admin-kpi-card">
                    <div class="ecare-admin-kpi-icon yellow">💨</div>
                    <div class="ecare-admin-kpi-details">
                        <span class="ecare-admin-kpi-label"><?php _e('Active Now', 'ecare-health-services'); ?></span>
                        <span class="ecare-admin-kpi-value"><?php echo $active_dispatched; ?></span>
                    </div>
                </div>
                <div class="ecare-admin-kpi-card">
                    <div class="ecare-admin-kpi-icon green">✓</div>
                    <div class="ecare-admin-kpi-details">
                        <span class="ecare-admin-kpi-label"><?php _e('Completed', 'ecare-health-services'); ?></span>
                        <span class="ecare-admin-kpi-value"><?php echo $completed; ?></span>
                    </div>
                </div>
                <div class="ecare-admin-kpi-card">
                    <div class="ecare-admin-kpi-icon red">🚨</div>
                    <div class="ecare-admin-kpi-details">
                        <span class="ecare-admin-kpi-label"><?php _e('Emergency', 'ecare-health-services'); ?></span>
                        <span class="ecare-admin-kpi-value"><?php echo $emergency; ?></span>
                    </div>
                </div>
            </div>

            <!-- Action Bar -->
            <div class="ecare-admin-action-header">
                <div class="ecare-admin-title-area">
                    <h2><?php _e('Active Dispatch Requests', 'ecare-health-services'); ?></h2>
                    <span class="ecare-admin-badge-count"><?php echo count($bookings); ?></span>
                </div>
                <div class="ecare-admin-controls">
                    <input type="text" class="ecare-search-input" placeholder="<?php esc_attr_e('Search dispatch...', 'ecare-health-services'); ?>" />
                    <button class="ecare-admin-btn-outline">📊 <?php _e('Export', 'ecare-health-services'); ?></button>
                    <a href="<?php echo esc_url(admin_url('post-new.php?post_type=ecare_ambulance')); ?>" class="ecare-admin-btn-green">+ <?php _e('Create Dispatch', 'ecare-health-services'); ?></a>
                </div>
            </div>

            <!-- Table -->
            <div class="ecare-admin-table-container">
                <table class="ecare-admin-table">
                    <thead>
                        <tr>
                            <th>[ ]</th>
                            <th><?php _e('ID', 'ecare-health-services'); ?></th>
                            <th><?php _e('Patient Details', 'ecare-health-services'); ?></th>
                            <th><?php _e('Assigned Unit', 'ecare-health-services'); ?></th>
                            <th><?php _e('Route', 'ecare-health-services'); ?></th>
                            <th><?php _e('Status', 'ecare-health-services'); ?></th>
                            <th><?php _e('Issued By', 'ecare-health-services'); ?></th>
                            <th><?php _e('Actions', 'ecare-health-services'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($bookings): ?>
                            <?php foreach ($bookings as $b):
                                $route = $b->pickup_address . ' → ' . $b->destination;
                                $assigned_unit = $b->ambulance_type . ' Type';
                                $issued_by = $b->user_id ? get_the_author_meta('display_name', $b->user_id) : 'Guest Patient';
                            ?>
                                <tr>
                                    <td><input type="checkbox" /></td>
                                    <td>#<?php echo intval($b->id); ?></td>
                                    <td>
                                        <strong><?php echo esc_html($issued_by); ?></strong>
                                        <span style="display:block;font-size:11px;color:var(--text-muted);">📞 <?php echo esc_html($b->contact_phone); ?></span>
                                    </td>
                                    <td><span class="ecare-status-pill assigned"><?php echo esc_html($assigned_unit); ?></span></td>
                                    <td><small><?php echo esc_html($route); ?></small></td>
                                    <td>
                                        <span class="ecare-status-pill <?php echo esc_attr($b->status); ?> <?php echo ($b->priority_level === 'Emergency' && $b->status === 'pending') ? 'emergency' : ''; ?>">
                                            <?php echo esc_html(ucfirst($b->status)); ?>
                                        </span>
                                    </td>
                                    <td><?php echo esc_html($issued_by); ?></td>
                                    <td>
                                        <select class="ecare-status-select" data-booking-id="<?php echo intval($b->id); ?>" style="padding:5px;font-size:12px;border-radius:4px;border:1px solid var(--border-light);">
                                            <option value="pending" <?php selected($b->status, 'pending'); ?>>Pending</option>
                                            <option value="dispatched" <?php selected($b->status, 'dispatched'); ?>>Dispatched</option>
                                            <option value="assigned" <?php selected($b->status, 'assigned'); ?>>Assigned</option>
                                            <option value="completed" <?php selected($b->status, 'completed'); ?>>Completed</option>
                                            <option value="cancelled" <?php selected($b->status, 'cancelled'); ?>>Cancelled</option>
                                        </select>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="8" style="text-align:center;padding:30px;color:var(--text-muted);"><?php _e('No dispatch requests found.', 'ecare-health-services'); ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    public static function render_ambulance_registry() {
        self::admin_style_overrides();
        
        // Fetch ambulances CPT
        $ambulances = get_posts(array(
            'post_type'      => 'ecare_ambulance',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
        ));

        // Count metrics dynamically
        $active_count = 0;
        $pending_count = 0;
        foreach ($ambulances as $amb) {
            $status = get_post_meta($amb->ID, '_ambulance_status', true) ?: 'pending';
            if ($status === 'approved') $active_count++;
            if ($status === 'pending') $pending_count++;
        }

        ?>
        <div class="ecare-admin-wrap">
            <h1 style="font-weight:800;font-size:24px;margin-bottom:20px;color:var(--text-dark);"><?php _e('Ambulance Providers Management', 'ecare-health-services'); ?></h1>
            
            <div class="ecare-admin-kpi-grid">
                <div class="ecare-admin-kpi-card">
                    <div class="ecare-admin-kpi-icon teal">🚑</div>
                    <div class="ecare-admin-kpi-details">
                        <span class="ecare-admin-kpi-label"><?php _e('Active Providers', 'ecare-health-services'); ?></span>
                        <span class="ecare-admin-kpi-value"><?php echo $active_count; ?></span>
                    </div>
                </div>
                <div class="ecare-admin-kpi-card">
                    <div class="ecare-admin-kpi-icon green">❄️</div>
                    <div class="ecare-admin-kpi-details">
                        <span class="ecare-admin-kpi-label"><?php _e('Total Registered', 'ecare-health-services'); ?></span>
                        <span class="ecare-admin-kpi-value"><?php echo count($ambulances); ?></span>
                    </div>
                </div>
                <div class="ecare-admin-kpi-card">
                    <div class="ecare-admin-kpi-icon yellow">⏳</div>
                    <div class="ecare-admin-kpi-details">
                        <span class="ecare-admin-kpi-label"><?php _e('Pending Review', 'ecare-health-services'); ?></span>
                        <span class="ecare-admin-kpi-value"><?php echo $pending_count; ?></span>
                    </div>
                </div>
            </div>

            <!-- Action Bar -->
            <div class="ecare-admin-action-header">
                <div class="ecare-admin-title-area">
                    <h2><?php _e('Registered Vehicles & Providers', 'ecare-health-services'); ?></h2>
                    <span class="ecare-admin-badge-count"><?php echo count($ambulances); ?></span>
                </div>
                <div class="ecare-admin-controls">
                    <input type="text" class="ecare-search-input" placeholder="<?php esc_attr_e('Search vehicles...', 'ecare-health-services'); ?>" />
                    <a href="<?php echo esc_url(admin_url('post-new.php?post_type=ecare_ambulance')); ?>" class="ecare-admin-btn-green">+ <?php _e('Add New Vehicle', 'ecare-health-services'); ?></a>
                </div>
            </div>

            <!-- Table -->
            <div class="ecare-admin-table-container">
                <table class="ecare-admin-table">
                    <thead>
                        <tr>
                            <th>[ ]</th>
                            <th><?php _e('Provider Name', 'ecare-health-services'); ?></th>
                            <th><?php _e('Type & Model', 'ecare-health-services'); ?></th>
                            <th><?php _e('Contact Details', 'ecare-health-services'); ?></th>
                            <th><?php _e('Driver Details', 'ecare-health-services'); ?></th>
                            <th><?php _e('Verification Doc', 'ecare-health-services'); ?></th>
                            <th><?php _e('Status', 'ecare-health-services'); ?></th>
                            <th><?php _e('Actions', 'ecare-health-services'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($ambulances): ?>
                            <?php foreach ($ambulances as $amb): 
                                $type  = get_post_meta($amb->ID, '_ambulance_type', true) ?: 'Standard';
                                $model = get_post_meta($amb->ID, '_vehicle_model', true) ?: 'N/A';
                                $plate = get_post_meta($amb->ID, '_license_plate', true) ?: 'N/A';
                                $email = get_post_meta($amb->ID, '_email', true) ?: 'N/A';
                                $phone = get_post_meta($amb->ID, '_phone', true) ?: 'N/A';
                                
                                $driver_name = get_post_meta($amb->ID, '_driver_name', true) ?: 'N/A';
                                $driver_lic  = get_post_meta($amb->ID, '_driver_license', true) ?: 'N/A';
                                $driver_nid  = get_post_meta($amb->ID, '_driver_nid', true) ?: 'N/A';
                                $doc_url     = get_post_meta($amb->ID, '_verification_doc', true);
                                $status      = get_post_meta($amb->ID, '_ambulance_status', true) ?: 'pending';
                                
                                $first_letter = strtoupper(substr($amb->post_title, 0, 1));
                            ?>
                                <tr>
                                    <td><input type="checkbox" /></td>
                                    <td>
                                        <div class="ecare-admin-provider-info">
                                            <div class="ecare-admin-avatar-placeholder" style="background:var(--brand-teal-light);color:var(--brand-teal);"><?php echo $first_letter; ?></div>
                                            <div>
                                                <a href="<?php echo esc_url(get_edit_post_link($amb->ID)); ?>" class="provider-name-link"><?php echo esc_html($amb->post_title); ?></a>
                                                <span style="display:block;font-size:11px;color:var(--text-muted);"><?php _e('Ambulance Provider', 'ecare-health-services'); ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <strong><?php echo esc_html($type); ?></strong>
                                        <span style="display:block;font-size:11px;color:var(--text-muted);"><?php echo esc_html($model); ?> (<?php echo esc_html($plate); ?>)</span>
                                    </td>
                                    <td>
                                        <span>📞 <?php echo esc_html($phone); ?></span>
                                        <span style="display:block;font-size:11px;color:var(--text-muted);">✉️ <?php echo esc_html($email); ?></span>
                                    </td>
                                    <td>
                                        <strong><?php echo esc_html($driver_name); ?></strong>
                                        <span style="display:block;font-size:11px;color:var(--text-muted);">Lic: <?php echo esc_html($driver_lic); ?> / NID: <?php echo esc_html($driver_nid); ?></span>
                                    </td>
                                    <td>
                                        <?php if ($doc_url): ?>
                                            <a href="<?php echo esc_url($doc_url); ?>" target="_blank" class="ecare-admin-btn-outline" style="padding:4px 8px;font-size:11px;text-decoration:none;display:inline-flex;align-items:center;gap:4px;">
                                                📄 View Doc
                                            </a>
                                        <?php else: ?>
                                            <span style="color:var(--text-muted);font-style:italic;font-size:12px;">No Doc</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="ecare-status-pill <?php echo esc_attr($status); ?>"><?php echo esc_html(ucfirst($status)); ?></span>
                                    </td>
                                    <td>
                                        <div style="display:flex;gap:4px;">
                                            <a href="<?php echo esc_url(get_edit_post_link($amb->ID)); ?>" class="ecare-admin-btn-outline" style="padding:4px 8px;font-size:11px;" title="<?php esc_attr_e('Edit Vehicle', 'ecare-health-services'); ?>">✏️</a>
                                            <button type="button" class="ecare-admin-btn-outline ecare-delete-provider" data-id="<?php echo intval($amb->ID); ?>" style="padding:4px 8px;font-size:11px;border-color:#EF4444;color:#EF4444;" title="<?php esc_attr_e('Delete Vehicle', 'ecare-health-services'); ?>">🗑️</button>
                                            <?php if ($status === 'pending'): ?>
                                                <button class="ecare-admin-btn-green ecare-approve-provider" data-id="<?php echo intval($amb->ID); ?>" style="padding:4px 8px;font-size:11px;">✓</button>
                                                <button class="ecare-admin-btn-outline ecare-reject-provider" data-id="<?php echo intval($amb->ID); ?>" style="padding:4px 8px;font-size:11px;border-color:#EF4444;color:#EF4444;">✕</button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="8" style="text-align:center;padding:30px;color:var(--text-muted);"><?php _e('No ambulances registered yet.', 'ecare-health-services'); ?></td></tr>
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

    public static function add_caregiver_type_image_field($taxonomy) {
        ?>
        <div class="form-field term-group">
            <label for="caregiver_type_image"><?php _e('Type Image / Icon', 'ecare-health-services'); ?></label>
            <input type="hidden" id="caregiver_type_image" name="caregiver_type_image" value="" />
            <div id="caregiver_type_image_preview" style="margin-bottom: 10px;"></div>
            <p>
                <input type="button" class="button button-secondary ecare_upload_media_btn" value="<?php esc_attr_e('Upload Image', 'ecare-health-services'); ?>" />
                <input type="button" class="button button-secondary ecare_remove_media_btn" value="<?php esc_attr_e('Remove Image', 'ecare-health-services'); ?>" style="display:none;" />
            </p>
        </div>
        <div class="form-field term-group">
            <label><?php _e('Duration Packages', 'ecare-health-services'); ?></label>
            <div id="ecare-term-packages-list" style="margin-bottom: 10px;">
                <div class="ecare-term-package-row" style="display:flex; gap:10px; margin-bottom:8px; align-items:center;">
                    <input type="text" name="term_package_labels[]" placeholder="Duration (e.g. Daily (12 Hours))" style="flex:2;" required />
                    <input type="number" name="term_package_prices[]" placeholder="Price (৳)" style="flex:1;" required />
                    <button type="button" class="button ecare-remove-package-row-btn" style="background:#EF4444; color:#fff; border-color:#EF4444; padding: 4px 8px; line-height: 1.2;">&times;</button>
                </div>
            </div>
            <p>
                <button type="button" id="ecare-add-package-row-btn" class="button button-secondary">+ <?php _e('Add New Field', 'ecare-health-services'); ?></button>
            </p>
        </div>
        <?php
    }

    public static function edit_caregiver_type_image_field($term, $taxonomy) {
        $image_id = get_term_meta($term->term_id, 'caregiver_type_image', true);
        $image_url = $image_id ? wp_get_attachment_url($image_id) : '';
        $packages = get_term_meta($term->term_id, 'ecare_packages', true);
        if (!is_array($packages)) {
            $packages = array();
        }
        ?>
        <tr class="form-field term-group-wrap">
            <th scope="row"><label for="caregiver_type_image"><?php _e('Type Image / Icon', 'ecare-health-services'); ?></label></th>
            <td>
                <input type="hidden" id="caregiver_type_image" name="caregiver_type_image" value="<?php echo esc_attr($image_id); ?>" />
                <div id="caregiver_type_image_preview" style="margin-bottom: 10px;">
                    <?php if ($image_url): ?>
                        <img src="<?php echo esc_url($image_url); ?>" style="width: 80px; height: 80px; display: block; border-radius: 50%; object-fit: cover; border: 1px solid #ddd;" />
                    <?php endif; ?>
                </div>
                <p>
                    <input type="button" class="button button-secondary ecare_upload_media_btn" value="<?php esc_attr_e('Upload / Change Image', 'ecare-health-services'); ?>" />
                    <input type="button" class="button button-secondary ecare_remove_media_btn" value="<?php esc_attr_e('Remove Image', 'ecare-health-services'); ?>" <?php echo $image_url ? '' : 'style="display:none;"'; ?> />
                </p>
            </td>
        </tr>
        <tr class="form-field term-group-wrap">
            <th scope="row"><label><?php _e('Duration Packages', 'ecare-health-services'); ?></label></th>
            <td>
                <div id="ecare-term-packages-list" style="margin-bottom: 10px; max-width: 600px;">
                    <?php if (!empty($packages)): ?>
                        <?php foreach ($packages as $pkg): ?>
                            <div class="ecare-term-package-row" style="display:flex; gap:10px; margin-bottom:8px; align-items:center;">
                                <input type="text" name="term_package_labels[]" value="<?php echo esc_attr($pkg['label']); ?>" placeholder="Duration (e.g. Daily (12 Hours))" style="flex:2;" required />
                                <input type="number" name="term_package_prices[]" value="<?php echo esc_attr($pkg['price']); ?>" placeholder="Price (৳)" style="flex:1;" required />
                                <button type="button" class="button ecare-remove-package-row-btn" style="background:#EF4444; color:#fff; border-color:#EF4444; padding: 4px 8px; line-height: 1.2;">&times;</button>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="ecare-term-package-row" style="display:flex; gap:10px; margin-bottom:8px; align-items:center;">
                            <input type="text" name="term_package_labels[]" placeholder="Duration (e.g. Daily (12 Hours))" style="flex:2;" required />
                            <input type="number" name="term_package_prices[]" placeholder="Price (৳)" style="flex:1;" required />
                            <button type="button" class="button ecare-remove-package-row-btn" style="background:#EF4444; color:#fff; border-color:#EF4444; padding: 4px 8px; line-height: 1.2;">&times;</button>
                        </div>
                    <?php endif; ?>
                </div>
                <button type="button" id="ecare-add-package-row-btn" class="button button-secondary">+ <?php _e('Add New Field', 'ecare-health-services'); ?></button>
            </td>
        </tr>
        <?php
    }

    public static function save_caregiver_type_image($term_id) {
        if (isset($_POST['caregiver_type_image'])) {
            update_term_meta($term_id, 'caregiver_type_image', sanitize_text_field($_POST['caregiver_type_image']));
        }

        if (isset($_POST['term_package_labels']) && isset($_POST['term_package_prices'])) {
            $labels = $_POST['term_package_labels'];
            $prices = $_POST['term_package_prices'];
            $packages = array();

            for ($i = 0; $i < count($labels); $i++) {
                $label = sanitize_text_field($labels[$i]);
                $price = floatval($prices[$i]);
                if (!empty($label)) {
                    $packages[] = array(
                        'label' => $label,
                        'price' => $price
                    );
                }
            }
            update_term_meta($term_id, 'ecare_packages', $packages);
        } else {
            if (isset($_POST['action']) && ($_POST['action'] === 'editedtag' || $_POST['action'] === 'ecare_add_caregiver_type')) {
                update_term_meta($term_id, 'ecare_packages', array());
            }
        }
    }

    public static function add_caregiver_type_columns($columns) {
        $new_columns = array();
        foreach ($columns as $key => $value) {
            if ($key === 'name') {
                $new_columns['image'] = __('Image', 'ecare-health-services');
            }
            $new_columns[$key] = $value;
        }
        return $new_columns;
    }

    public static function render_caregiver_type_column_content($content, $column_name, $term_id) {
        if ($column_name === 'image') {
            $image_id = get_term_meta($term_id, 'caregiver_type_image', true);
            if ($image_id) {
                $image_url = wp_get_attachment_url($image_id);
                if ($image_url) {
                    return '<img src="' . esc_url($image_url) . '" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover;" />';
                }
            }
            return '<span style="font-size: 20px; color: #ccc;">👤</span>';
        }
        return $content;
    }
}
