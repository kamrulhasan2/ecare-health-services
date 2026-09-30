<?php
defined('ABSPATH') || exit;

/**
 * Lab -> Lab Orders: the list, one order in full, status changes with a
 * note, and the report upload.
 *
 * Orders from the new lab cart carry everything (tests, patients, prices,
 * slot, address). The old lab's one-row-per-test bookings are listed too,
 * marked "Old", with what they have.
 */
class ECare_Lab_Orders_Admin {

    const PAGE     = 'ecare-lab-orders';
    const PER_PAGE = 20;

    public static function init() {
        add_action('admin_post_ecare_lab_order_update', array(__CLASS__, 'handle_update'));
        add_action('admin_post_ecare_lab_order_report', array(__CLASS__, 'handle_report'));
    }

    public static function url($args = array()) {
        return add_query_arg(array('page' => self::PAGE) + $args, admin_url('admin.php'));
    }

    // =======================================================================
    // Posts
    // =======================================================================

    private static function guard($id) {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do that.', 'ecare-health-services'), 403);
        }
        check_admin_referer('ecare_lab_order_' . $id);
    }

    public static function handle_update() {
        $id = (int) ($_POST['id'] ?? 0);
        self::guard($id);
        $msg = self::apply_update($id, wp_unslash($_POST), get_current_user_id());
        wp_safe_redirect(self::url(array('order' => $id, 'msg' => $msg)));
        exit;
    }

    /** @return string message code */
    public static function apply_update($id, $in, $by) {
        $row = ECare_Lab_Orders::get($id);
        if (!$row) {
            return 'missing';
        }
        $status = sanitize_key((string) ($in['status'] ?? ''));
        $note   = trim(sanitize_textarea_field((string) ($in['note'] ?? '')));
        if ($status !== '' && $status !== $row->status) {
            return ECare_Lab_Orders::set_status($id, $status, $note, $by) ? 'status' : 'bad_status';
        }
        if ($note !== '') {
            ECare_Lab_Orders::add_note($id, $note, $by);
            return 'note';
        }
        return '';
    }

    public static function handle_report() {
        $id = (int) ($_POST['id'] ?? 0);
        self::guard($id);
        $msg = 'report_missing';
        if (ECare_Lab_Orders::get($id)) {
            // An operator uploading a day's reports must not trip the per-IP
            // limit meant for the public forms.
            $no_limit = function () { return 0; };
            add_filter('ecare_upload_rate_limit', $no_limit);
            $ref = ECare_Secure_Files::upload('report', ECare_Secure_Files::KIND_DOCUMENT);
            remove_filter('ecare_upload_rate_limit', $no_limit);
            if (is_wp_error($ref)) {
                set_transient('ecare_lab_report_err_' . get_current_user_id(), $ref->get_error_message(), 5 * MINUTE_IN_SECONDS);
                $msg = 'report_error';
            } else {
                ECare_Lab_Orders::attach_report($id, $ref, get_current_user_id());
                $msg = 'report';
            }
        }
        wp_safe_redirect(self::url(array('order' => $id, 'msg' => $msg)));
        exit;
    }

    // =======================================================================
    // Reading
    // =======================================================================

    /** @return array{0: object[], 1: int} rows, total */
    public static function query($status, $search, $page) {
        global $wpdb;
        $t      = ECare_Lab_Orders::table();
        $where  = "booking_type = 'lab'";
        $params = array();
        if ($status !== '' && isset(ECare_Lab_Orders::statuses()[$status])) {
            $where   .= ' AND status = %s';
            $params[] = $status;
        }
        if ($search !== '') {
            $like     = '%' . $wpdb->esc_like($search) . '%';
            $where   .= ' AND (patient_name LIKE %s OR contact_phone LIKE %s OR id = %d OR order_id = %d)';
            array_push($params, $like, $like, (int) ltrim($search, '#'), (int) ltrim($search, '#'));
        }
        $count_sql = "SELECT COUNT(*) FROM {$t} WHERE {$where}";
        $total     = (int) $wpdb->get_var($params ? $wpdb->prepare($count_sql, $params) : $count_sql);
        $list_sql  = "SELECT * FROM {$t} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
        $rows      = $wpdb->get_results($wpdb->prepare($list_sql, array_merge($params, array(self::PER_PAGE, max(0, ($page - 1) * self::PER_PAGE)))));
        return array(array_map(array('ECare_Lab_Orders', 'decode'), (array) $rows), $total);
    }

    public static function counts() {
        global $wpdb;
        $out = array();
        foreach ((array) $wpdb->get_results('SELECT status, COUNT(*) AS n FROM ' . ECare_Lab_Orders::table() . " WHERE booking_type = 'lab' GROUP BY status") as $r) {
            $out[$r->status] = (int) $r->n;
        }
        return $out;
    }

    private static function money($n) {
        return '৳' . number_format_i18n((float) $n, floor((float) $n) == (float) $n ? 0 : 2);
    }

    private static function tests_line($row) {
        if ($row->is_new) {
            return implode(', ', array_map(function ($i) { return $i['title'] . ($i['patients'] > 1 ? ' ×' . $i['patients'] : ''); }, $row->details['items'] ?? array()));
        }
        return implode(', ', array_map('get_the_title', array_filter(array_map('intval', explode(',', (string) $row->lab_test_ids)))));
    }

    private static function status_select($current, $name = 'status') {
        $out = '<select name="' . esc_attr($name) . '">';
        foreach (ECare_Lab_Orders::statuses() as $k => $label) {
            $out .= '<option value="' . esc_attr($k) . '"' . selected($current, $k, false) . '>' . esc_html($label) . '</option>';
        }
        return $out . '</select>';
    }

    // =======================================================================
    // Screens
    // =======================================================================

    public static function render() {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (!empty($_GET['order'])) {
            self::render_one((int) $_GET['order']);
            return;
        }
        $d      = 'ecare-health-services';
        $status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';
        $search = isset($_GET['s']) ? trim(sanitize_text_field(wp_unslash($_GET['s']))) : '';
        $page   = max(1, (int) ($_GET['paged'] ?? 1));
        list($rows, $total) = self::query($status, $search, $page);
        $counts = self::counts();
        ?>
        <div class="wrap ecare-lab-orders">
            <h1 class="wp-heading-inline"><?php esc_html_e('Lab Orders', $d); ?></h1>
            <hr class="wp-header-end" />

            <ul class="subsubsub">
                <li><a href="<?php echo esc_url(self::url()); ?>"<?php echo $status === '' ? ' class="current" aria-current="page"' : ''; ?>><?php esc_html_e('All', $d); ?> <span class="count">(<?php echo (int) array_sum($counts); ?>)</span></a></li>
                <?php foreach (ECare_Lab_Orders::statuses() as $k => $label): if (empty($counts[$k])) { continue; } ?>
                    <li>| <a href="<?php echo esc_url(self::url(array('status' => $k))); ?>"<?php echo $status === $k ? ' class="current" aria-current="page"' : ''; ?>><?php echo esc_html($label); ?> <span class="count">(<?php echo (int) $counts[$k]; ?>)</span></a></li>
                <?php endforeach; ?>
            </ul>

            <form method="get" class="search-form" style="float:right;margin:6px 0">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE); ?>" />
                <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?php echo esc_attr($status); ?>" /><?php endif; ?>
                <label class="screen-reader-text" for="ecare-lo-s"><?php esc_html_e('Search orders', $d); ?></label>
                <input type="search" id="ecare-lo-s" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Order #, name or phone', $d); ?>" />
                <button class="button"><?php esc_html_e('Search', $d); ?></button>
            </form>

            <table class="wp-list-table widefat fixed striped" style="clear:both">
                <thead><tr>
                    <th style="width:90px"><?php esc_html_e('Order', $d); ?></th>
                    <th><?php esc_html_e('Patient', $d); ?></th>
                    <th><?php esc_html_e('Lab / tests', $d); ?></th>
                    <th style="width:170px"><?php esc_html_e('Collection', $d); ?></th>
                    <th style="width:150px"><?php esc_html_e('Amount', $d); ?></th>
                    <th style="width:130px"><?php esc_html_e('Status', $d); ?></th>
                </tr></thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="6"><?php esc_html_e('No lab orders found.', $d); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r): $q = $r->details['quote'] ?? array(); ?>
                    <tr>
                        <td>
                            <a href="<?php echo esc_url(self::url(array('order' => $r->id))); ?>"><strong>#<?php echo (int) $r->id; ?></strong></a>
                            <?php if (!$r->is_new): ?><br /><span class="ecare-lo-old"><?php esc_html_e('Old', $d); ?></span><?php endif; ?>
                            <br /><small><?php echo esc_html(wp_date('j M Y', ECare_Lab_Orders::placed_at($r))); ?></small>
                        </td>
                        <td><?php echo esc_html($r->patient_name ?: '—'); ?><br /><small><?php echo esc_html((string) $r->contact_phone); ?></small></td>
                        <td>
                            <?php if ($r->is_new): ?><strong><?php echo esc_html($r->details['lab']['name'] ?? get_the_title((int) $r->lab_provider_id)); ?></strong><br /><?php endif; ?>
                            <small><?php echo esc_html(self::tests_line($r)); ?></small>
                        </td>
                        <td>
                            <?php if ($r->is_new): ?>
                                <?php echo esc_html(mysql2date('D, j M', (string) $r->required_date)); ?><br /><small><?php echo esc_html(ECare_Lab_Settings::slot_label((string) $r->collection_slot)); ?></small>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td>
                            <?php echo esc_html(self::money($r->total_amount)); ?>
                            <?php if ($r->is_new && isset($q['advance'])): ?><br /><small><?php echo esc_html(sprintf(__('Advance %s', $d), self::money($q['advance']))); ?></small><?php endif; ?>
                        </td>
                        <td><span class="ecare-lo-st ecare-lo-st-<?php echo esc_attr($r->status); ?>"><?php echo esc_html(ECare_Lab_Orders::status_label($r->status)); ?></span>
                            <?php if ((string) $r->file_urls !== '' && ECare_Lab_Orders::report_url($r)): ?><br /><small>📄 <?php esc_html_e('Report', $d); ?></small><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php $pages = (int) ceil($total / self::PER_PAGE); if ($pages > 1): ?>
                <div class="tablenav"><div class="tablenav-pages">
                    <?php echo paginate_links(array('base' => add_query_arg('paged', '%#%'), 'format' => '', 'current' => $page, 'total' => $pages)); // phpcs:ignore ?>
                </div></div>
            <?php endif; ?>
        </div>
        <?php self::styles(); ?>
        <?php
    }

    public static function render_one($id) {
        $d   = 'ecare-health-services';
        $row = ECare_Lab_Orders::get($id);
        if (!$row) {
            echo '<div class="wrap"><h1>' . esc_html__('Lab order not found', $d) . '</h1><p><a href="' . esc_url(self::url()) . '">' . esc_html__('Back to Lab Orders', $d) . '</a></p></div>';
            return;
        }
        $det   = $row->details;
        $q     = $det['quote'] ?? array();
        $msgs  = array(
            'status'         => array('success', __('Status updated.', $d)),
            'note'           => array('success', __('Note added.', $d)),
            'report'         => array('success', __('Report uploaded. The patient can see it in My Lab Orders.', $d)),
            'report_missing' => array('error', __('Order not found.', $d)),
            'bad_status'     => array('error', __('Unknown status.', $d)),
        );
        $code = isset($_GET['msg']) ? sanitize_key(wp_unslash($_GET['msg'])) : '';
        $err  = get_transient('ecare_lab_report_err_' . get_current_user_id());
        if ($code === 'report_error' && $err) {
            $msgs['report_error'] = array('error', (string) $err);
            delete_transient('ecare_lab_report_err_' . get_current_user_id());
        }
        $wc        = ($row->order_id && function_exists('wc_get_order')) ? wc_get_order((int) $row->order_id) : null;
        $report    = ECare_Lab_Orders::report_url($row);
        $delivery  = array('soft' => __('Soft copy', $d), 'hard' => __('Hard copy', $d), 'both' => __('Soft and hard copy', $d));
        ?>
        <div class="wrap ecare-lab-orders">
            <p><a href="<?php echo esc_url(self::url()); ?>">← <?php esc_html_e('All lab orders', $d); ?></a></p>
            <h1><?php echo esc_html(sprintf(__('Lab order #%d', $d), $row->id)); ?>
                <span class="ecare-lo-st ecare-lo-st-<?php echo esc_attr($row->status); ?>"><?php echo esc_html(ECare_Lab_Orders::status_label($row->status)); ?></span>
                <?php if (!$row->is_new): ?><span class="ecare-lo-old"><?php esc_html_e('Old booking', $d); ?></span><?php endif; ?>
            </h1>
            <?php if (isset($msgs[$code])): ?><div class="notice notice-<?php echo esc_attr($msgs[$code][0]); ?> is-dismissible"><p><?php echo esc_html($msgs[$code][1]); ?></p></div><?php endif; ?>

            <div class="ecare-lo-grid">
                <div>
                    <div class="postbox"><div class="inside">
                        <h2><?php esc_html_e('Tests', $d); ?><?php if ($row->is_new): ?> — <?php echo esc_html($det['lab']['name'] ?? ''); ?><?php endif; ?></h2>
                        <?php if ($row->is_new): ?>
                            <table class="widefat striped">
                                <thead><tr><th><?php esc_html_e('Test', $d); ?></th><th><?php esc_html_e('Patients', $d); ?></th><th><?php esc_html_e('Price', $d); ?></th><th><?php esc_html_e('MRP', $d); ?></th><th><?php esc_html_e('Total', $d); ?></th></tr></thead>
                                <tbody>
                                <?php foreach ($det['items'] ?? array() as $i): ?>
                                    <tr><td><?php echo esc_html($i['title']); ?></td><td><?php echo (int) $i['patients']; ?></td><td><?php echo esc_html(self::money($i['price'])); ?></td><td><?php echo esc_html(self::money($i['mrp'])); ?></td><td><?php echo esc_html(self::money($i['line_total'])); ?></td></tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            <table class="ecare-lo-sum">
                                <?php foreach (array(
                                    'subtotal_mrp' => __('Subtotal (MRP)', $d), 'special' => __('Special Discount', $d), 'material' => __('External Material Cost', $d),
                                    'coupon' => __('Coupon Discount', $d), 'delivery' => __('Report Delivery Cost', $d), 'service' => __('Service Charge', $d),
                                    'total' => __('Total', $d), 'advance' => __('Advance (online)', $d), 'later' => __('Collect at sample collection', $d),
                                ) as $k => $label): if (!isset($q[$k]) || ((float) $q[$k] == 0 && !in_array($k, array('total', 'advance', 'later'), true))) { continue; } ?>
                                    <tr class="<?php echo in_array($k, array('total', 'later'), true) ? 'is-strong' : ''; ?>"><th><?php echo esc_html($label); ?><?php echo ($k === 'coupon' && !empty($q['coupon_code'])) ? ' (' . esc_html(strtoupper($q['coupon_code'])) . ')' : ''; ?></th>
                                        <td><?php echo in_array($k, array('special', 'coupon'), true) ? '−' : ''; ?><?php echo esc_html(self::money($q[$k])); ?></td></tr>
                                <?php endforeach; ?>
                            </table>
                        <?php else: ?>
                            <p><?php echo esc_html(self::tests_line($row)); ?></p>
                            <p><?php echo esc_html(sprintf(__('Amount: %s', $d), self::money($row->total_amount))); ?></p>
                        <?php endif; ?>
                    </div></div>

                    <div class="postbox"><div class="inside">
                        <h2><?php esc_html_e('History', $d); ?></h2>
                        <?php if (empty($det['log'])): ?><p><?php esc_html_e('Nothing recorded.', $d); ?></p><?php else: ?>
                            <ul class="ecare-lo-log">
                                <?php foreach (array_reverse($det['log']) as $l): $who = !empty($l['by']) ? get_userdata((int) $l['by']) : null; ?>
                                    <li><time><?php echo esc_html(wp_date('j M Y, g:i A', (int) $l['t'])); ?></time>
                                        <?php if (($l['status'] ?? '') !== ''): ?><strong><?php echo esc_html(ECare_Lab_Orders::status_label($l['status'])); ?></strong><?php endif; ?>
                                        <?php if (($l['note'] ?? '') !== ''): ?> — <?php echo esc_html($l['note']); ?><?php endif; ?>
                                        <small><?php echo $who ? esc_html($who->display_name) : esc_html__('system', $d); ?></small></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div></div>
                </div>

                <div>
                    <div class="postbox"><div class="inside">
                        <h2><?php esc_html_e('Patient and collection', $d); ?></h2>
                        <p><strong><?php echo esc_html($row->patient_name); ?></strong><br />
                           <a href="tel:<?php echo esc_attr((string) $row->contact_phone); ?>"><?php echo esc_html((string) $row->contact_phone); ?></a></p>
                        <p><?php echo esc_html((string) $row->address); ?></p>
                        <?php if ($row->is_new): ?>
                            <p><strong><?php echo esc_html(mysql2date('l, j F Y', (string) $row->required_date)); ?></strong><br /><?php echo esc_html(ECare_Lab_Settings::slot_label((string) $row->collection_slot)); ?></p>
                            <p><?php esc_html_e('Report delivery:', $d); ?> <?php echo esc_html($delivery[$det['delivery'] ?? 'soft'] ?? ''); ?></p>
                        <?php endif; ?>
                        <?php if ((string) $row->notes !== ''): ?><p><em><?php echo esc_html((string) $row->notes); ?></em></p><?php endif; ?>
                        <?php if ($wc): ?><p><a href="<?php echo esc_url($wc->get_edit_order_url()); ?>"><?php echo esc_html(sprintf(__('WooCommerce order #%1$d (%2$s)', $d), $wc->get_id(), wc_get_order_status_name($wc->get_status()))); ?></a></p><?php endif; ?>
                    </div></div>

                    <div class="postbox"><div class="inside">
                        <h2><?php esc_html_e('Status', $d); ?></h2>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="ecare_lab_order_update" />
                            <input type="hidden" name="id" value="<?php echo (int) $row->id; ?>" />
                            <?php wp_nonce_field('ecare_lab_order_' . $row->id); ?>
                            <p><?php echo self::status_select($row->status); // phpcs:ignore ?></p>
                            <p><label for="ecare-lo-note"><?php esc_html_e('Note (optional, only admins see it)', $d); ?></label><br />
                               <textarea id="ecare-lo-note" name="note" rows="2" class="widefat"></textarea></p>
                            <p><button class="button button-primary"><?php esc_html_e('Save', $d); ?></button></p>
                        </form>
                    </div></div>

                    <div class="postbox"><div class="inside">
                        <h2><?php esc_html_e('Report', $d); ?></h2>
                        <?php if ($report !== ''): ?><p><a class="button" href="<?php echo esc_url($report); ?>" target="_blank" rel="noopener"><?php esc_html_e('Open current report', $d); ?></a></p><?php endif; ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="ecare_lab_order_report" />
                            <input type="hidden" name="id" value="<?php echo (int) $row->id; ?>" />
                            <?php wp_nonce_field('ecare_lab_order_' . $row->id); ?>
                            <p><input type="file" name="report" accept=".pdf,.jpg,.jpeg,.png,.webp" required /></p>
                            <p class="description"><?php echo esc_html(sprintf(__('%1$s, up to %2$s. Kept private: only this patient and admins can open it.', $d), ECare_Secure_Files::allowed_extensions_label(), size_format(ECare_Secure_Files::max_bytes()))); ?></p>
                            <p><button class="button"><?php echo $report !== '' ? esc_html__('Replace report', $d) : esc_html__('Upload report', $d); ?></button></p>
                        </form>
                    </div></div>
                </div>
            </div>
        </div>
        <?php self::styles(); ?>
        <?php
    }

    private static function styles() {
        ?>
        <style>
            .ecare-lab-orders .ecare-lo-grid { display: grid; grid-template-columns: minmax(0, 2fr) minmax(280px, 1fr); gap: 16px; align-items: start; }
            @media (max-width: 960px) { .ecare-lab-orders .ecare-lo-grid { grid-template-columns: 1fr; } }
            .ecare-lab-orders .postbox h2 { font-size: 14px; margin: 0 0 10px; padding: 0; }
            .ecare-lo-st { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 12px; font-weight: 600; background: #f0f0f1; color: #3c434a; vertical-align: middle; }
            .ecare-lo-st-pending { background: #fcf0e3; color: #8a4b00; }
            .ecare-lo-st-approved { background: #e5f0fa; color: #135e96; }
            .ecare-lo-st-sample_collected, .ecare-lo-st-processing { background: #efe6f7; color: #5b2a86; }
            .ecare-lo-st-report_ready { background: #e3f3ea; color: #0a6b3a; }
            .ecare-lo-st-completed { background: #d8f0e0; color: #0a5a2f; }
            .ecare-lo-st-cancelled { background: #fbe9e9; color: #8a1f1f; }
            .ecare-lo-old { display: inline-block; font-size: 11px; padding: 1px 6px; border: 1px solid #c3c4c7; border-radius: 3px; color: #646970; vertical-align: middle; }
            .ecare-lo-sum { margin-top: 12px; margin-left: auto; min-width: 280px; }
            .ecare-lo-sum th { text-align: left; font-weight: 400; padding: 3px 16px 3px 0; }
            .ecare-lo-sum td { text-align: right; }
            .ecare-lo-sum tr.is-strong th, .ecare-lo-sum tr.is-strong td { font-weight: 700; }
            .ecare-lo-log { margin: 0; }
            .ecare-lo-log li { border-bottom: 1px solid #f0f0f1; padding: 4px 0; }
            .ecare-lo-log time { color: #646970; margin-right: 6px; }
            .ecare-lo-log small { color: #8c8f94; margin-left: 6px; }
        </style>
        <?php
    }
}
