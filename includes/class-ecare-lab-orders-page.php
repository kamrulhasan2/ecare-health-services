<?php
defined('ABSPATH') || exit;

/**
 * "My Lab Orders": the cart page with ?step=orders. Also linked from
 * WooCommerce My Account (ECare_Lab_Orders::account_menu).
 */
class ECare_Lab_Orders_Page {

    /** The steps a paid order goes through, in order, for the progress line. */
    const STEPS = array('approved', 'sample_collected', 'processing', 'report_ready', 'completed');

    public static function render() {
        $d   = 'ecare-health-services';
        $uid = get_current_user_id();
        $crumbs = '<nav class="ecl-crumbs" aria-label="' . esc_attr__('Breadcrumb', $d) . '"><a href="' . esc_url(home_url('/')) . '">' . esc_html__('Home', $d) . '</a>'
            . '<span>›</span><a href="' . esc_url(ECare_Lab_Front::url('cart')) . '">' . esc_html__('Lab Cart', $d) . '</a>'
            . '<span>›</span><span aria-current="page">' . esc_html__('My Lab Orders', $d) . '</span></nav>';

        $orders = ECare_Lab_Orders::for_user($uid);
        $placed = isset($_GET['placed']) ? (int) $_GET['placed'] : 0;

        ob_start();
        echo '<div class="ecl ecl-cartp ecl-orders">' . $crumbs; // phpcs:ignore
        echo '<h1 class="ecl-cp-title">' . esc_html__('My Lab Orders', $d) . '</h1>';

        foreach ($orders as $o) {
            if ($o->id == $placed && $o->status !== 'pending') {
                echo '<div class="ecl-notice ecl-notice-ok" role="status">' . esc_html(sprintf(__('Order #%d is confirmed.', $d), $placed)) . '</div>';
            }
        }

        if (!$orders) {
            echo '<div class="ecl-empty">' . ECare_Lab_Front::icon('flask') // phpcs:ignore
                . '<h2>' . esc_html__('No lab orders yet', $d) . '</h2>'
                . '<a class="ecl-btn" href="' . esc_url(ECare_Lab_Front::url('tests')) . '">' . esc_html__('Browse tests', $d) . '</a></div></div>';
            return ob_get_clean();
        }

        foreach ($orders as $o) {
            echo self::render_order($o, $d); // phpcs:ignore
        }
        echo '</div>';
        return ob_get_clean();
    }

    public static function render_order($o, $d = 'ecare-health-services') {
        $det   = $o->details;
        $q     = $det['quote'] ?? array();
        $items = $det['items'] ?? array();
        $paid  = !in_array($o->status, array('pending', 'cancelled'), true);
        $pay   = '';
        if ($o->status === 'pending' && $o->order_id && function_exists('wc_get_order')) {
            $wc = wc_get_order((int) $o->order_id);
            if ($wc && $wc->has_status(array('pending', 'failed'))) {
                $pay = $wc->get_checkout_payment_url();
            }
        }
        $report = in_array($o->status, array('report_ready', 'completed'), true) ? ECare_Lab_Orders::report_url($o) : '';
        $when   = ($det['date'] ?? '') !== '' ? wp_date('D, j M Y', strtotime($det['date'] . ' 12:00')) . ' · ' . ECare_Lab_Settings::slot_label($det['slot'] ?? '') : '';
        $step   = array_search($o->status, self::STEPS, true);

        ob_start();
        ?>
        <article class="ecl-cp-box ecl-ord" id="lab-order-<?php echo (int) $o->id; ?>">
            <header class="ecl-ord-head">
                <div>
                    <strong class="ecl-ord-no"><?php echo esc_html(sprintf(__('Order #%d', $d), $o->id)); ?></strong>
                    <small><?php echo esc_html(wp_date('j M Y', ECare_Lab_Orders::placed_at($o))); ?> · <?php echo esc_html($det['lab']['name'] ?? ''); ?></small>
                </div>
                <span class="ecl-st ecl-st-<?php echo esc_attr($o->status); ?>"><?php echo esc_html(ECare_Lab_Orders::status_label($o->status)); ?></span>
            </header>

            <?php if ($step !== false): ?>
                <ol class="ecl-ord-steps" aria-label="<?php esc_attr_e('Progress', $d); ?>">
                    <?php foreach (self::STEPS as $i => $s): ?>
                        <li class="<?php echo $i < $step ? 'is-done' : ($i === $step ? 'is-now' : ''); ?>"<?php echo $i === $step ? ' aria-current="step"' : ''; ?>><span><?php echo esc_html(ECare_Lab_Orders::status_label($s)); ?></span></li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>

            <p class="ecl-ord-tests"><?php echo esc_html(implode(', ', array_map(function ($i) { return $i['title'] . ($i['patients'] > 1 ? ' × ' . $i['patients'] : ''); }, $items))); ?></p>
            <?php if ($when !== ''): ?><p class="ecl-ord-when"><?php echo ECare_Lab_Front::icon('clock'); // phpcs:ignore ?> <?php echo esc_html($when); ?></p><?php endif; ?>
            <?php if (!empty($det['address']['text'])): ?><p class="ecl-ord-addr"><?php echo esc_html($det['address']['text']); ?></p><?php endif; ?>

            <dl class="ecl-ord-money">
                <div><dt><?php esc_html_e('Total', $d); ?></dt><dd><?php echo esc_html(ECare_Lab_Front::money($q['total'] ?? $o->total_amount)); ?></dd></div>
                <div><dt><?php echo $paid ? esc_html__('Advance paid', $d) : esc_html__('Advance', $d); ?></dt><dd><?php echo esc_html(ECare_Lab_Front::money($q['advance'] ?? 0)); ?></dd></div>
                <div><dt><?php esc_html_e('Pay at collection', $d); ?></dt><dd><?php echo esc_html(ECare_Lab_Front::money($q['later'] ?? 0)); ?></dd></div>
            </dl>

            <div class="ecl-ord-acts">
                <?php if ($pay !== ''): ?>
                    <a class="ecl-btn" href="<?php echo esc_url($pay); ?>"><?php echo esc_html(sprintf(__('Pay advance %s', $d), ECare_Lab_Front::money($q['advance'] ?? 0))); ?></a>
                <?php endif; ?>
                <?php if ($report !== ''): ?>
                    <a class="ecl-btn" href="<?php echo esc_url($report); ?>" target="_blank" rel="noopener"><?php esc_html_e('View report', $d); ?></a>
                <?php endif; ?>
            </div>

            <details class="ecl-ord-more">
                <summary><?php esc_html_e('Details', $d); ?></summary>
                <table class="ecl-ord-items">
                    <thead><tr><th><?php esc_html_e('Test', $d); ?></th><th><?php esc_html_e('Patients', $d); ?></th><th><?php esc_html_e('Price', $d); ?></th><th><?php esc_html_e('Total', $d); ?></th></tr></thead>
                    <tbody>
                        <?php foreach ($items as $i): ?>
                            <tr><td><?php echo esc_html($i['title']); ?></td><td><?php echo (int) $i['patients']; ?></td><td><?php echo esc_html(ECare_Lab_Front::money($i['price'])); ?></td><td><?php echo esc_html(ECare_Lab_Front::money($i['line_total'])); ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <dl class="ecl-ord-money ecl-ord-money-full">
                    <?php foreach (array(
                        'subtotal_mrp' => __('Subtotal (MRP)', $d), 'special' => __('Special Discount', $d), 'material' => __('External Material Cost', $d),
                        'coupon' => __('Coupon Discount', $d), 'delivery' => __('Report Delivery Cost', $d), 'service' => __('Service Charge', $d),
                    ) as $k => $label): if (empty($q[$k])) { continue; } $neg = in_array($k, array('special', 'coupon'), true); ?>
                        <div><dt><?php echo esc_html($label); ?></dt><dd><?php echo $neg ? '−' : ''; ?><?php echo esc_html(ECare_Lab_Front::money($q[$k])); ?></dd></div>
                    <?php endforeach; ?>
                </dl>
                <?php if (!empty($det['log'])): ?>
                    <ul class="ecl-ord-log">
                        <?php foreach (array_reverse($det['log']) as $l): if (($l['status'] ?? '') === '') { continue; } ?>
                            <li><time><?php echo esc_html(wp_date('j M, g:i A', (int) $l['t'])); ?></time> <?php echo esc_html(ECare_Lab_Orders::status_label($l['status'])); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </details>
        </article>
        <?php
        return ob_get_clean();
    }
}
