<?php
defined('ABSPATH') || exit;

/**
 * Paying a lab order's advance straight through SSLCommerz.
 *
 * Place Order (and every "Pay now" / "Pay advance" link) comes here and the
 * patient lands on SSLCommerz's own payment page - no WooCommerce order-pay
 * page in between.
 *
 * Why not the gateway's own route: the SSLCommerz plugin's process_payment()
 * only sends the patient to WooCommerce's receipt page, and that page builds
 * the session from the WooCommerce *cart* (product names, item count). A lab
 * order is not in the WooCommerce cart, so that route has nothing to show and
 * stalls on "Confirm Booking". Here the session is opened from the order
 * itself, with the same fields, success URL and transaction id (the order
 * number) the plugin uses, so the plugin's own success handler and IPN still
 * validate the payment and complete the order - nothing about how a payment
 * is confirmed changes.
 *
 * Without an enabled SSLCommerz gateway everything falls back to WooCommerce's
 * order-pay page, as before.
 */
class ECare_Lab_Pay {

    const ACTION  = 'ecare_lab_pay';
    const GATEWAY = 'sslcommerz';

    public static function init() {
        add_action('admin_post_' . self::ACTION, array(__CLASS__, 'handle'));
        add_action('admin_post_nopriv_' . self::ACTION, array(__CLASS__, 'handle'));
        add_action('template_redirect', array(__CLASS__, 'skip_order_pay'), 5);
    }

    /** Is this WooCommerce order a lab order's advance? */
    public static function is_lab_order($order) {
        return $order && is_object($order) && (int) $order->get_meta(ECare_Lab_Orders::ORDER_META) > 0;
    }

    /** Can the logged-in patient pay this order now? */
    public static function payable($order, $user_id) {
        return self::is_lab_order($order)
            && (int) $user_id > 0
            && (int) $order->get_customer_id() === (int) $user_id
            && $order->has_status(array('pending', 'failed'))
            && (float) $order->get_total() > 0;
    }

    /** The enabled SSLCommerz gateway, or null. */
    public static function gateway() {
        if (!function_exists('WC') || !WC() || !method_exists(WC(), 'payment_gateways')) {
            return null;
        }
        $all = WC()->payment_gateways()->payment_gateways();
        $gw  = $all[self::GATEWAY] ?? null;
        return ($gw && $gw->get_option('enabled') === 'yes' && (string) $gw->get_option('store_id') !== '') ? $gw : null;
    }

    /**
     * Where a "pay" link points.
     *
     * @param string $from checkout | orders - where a failed or cancelled payment returns
     */
    public static function url($order, $from = 'checkout') {
        if (!self::gateway()) {
            return $order->get_checkout_payment_url();
        }
        return add_query_arg(array(
            'action'   => self::ACTION,
            'order'    => $order->get_id(),
            'from'     => $from === 'orders' ? 'orders' : 'checkout',
            '_wpnonce' => wp_create_nonce(self::ACTION . '_' . $order->get_id()),
        ), admin_url('admin-post.php'));
    }

    /** Where the patient goes back to after a failed or cancelled payment. */
    public static function back_url($from, $msg) {
        return $from === 'orders'
            ? ECare_Lab_Front::url('cart', array('step' => 'orders', 'pay' => $msg))
            : ECare_Lab_Checkout_Page::url(array('co_msg' => 'pay_' . $msg));
    }

    // =======================================================================
    // admin-post: open the session and leave for SSLCommerz
    // =======================================================================

    public static function handle() {
        $from  = (isset($_GET['from']) && $_GET['from'] === 'orders') ? 'orders' : 'checkout';
        $id    = isset($_GET['order']) ? (int) $_GET['order'] : 0;
        if (!is_user_logged_in()) {
            wp_safe_redirect(ECare_Lab_Front::login_url(ECare_Lab_Front::url('cart', array('step' => 'orders'))));
            exit;
        }
        $r = self::decide($id, (string) ($_GET['_wpnonce'] ?? ''), get_current_user_id(), $from);
        if ($r['external']) {
            wp_redirect($r['url'], 303); // phpcs:ignore WordPress.Security.SafeRedirect -- host checked in is_gateway_url()
        } else {
            wp_safe_redirect($r['url']);
        }
        exit;
    }

    /**
     * What one pay request leads to.
     *
     * @return array{url:string, external:bool}
     */
    public static function decide($order_id, $nonce, $user_id, $from) {
        if (!wp_verify_nonce($nonce, self::ACTION . '_' . (int) $order_id)) {
            return array('url' => self::back_url($from, 'expired'), 'external' => false);
        }
        $order = function_exists('wc_get_order') ? wc_get_order((int) $order_id) : null;
        if (!self::is_lab_order($order) || (int) $order->get_customer_id() !== (int) $user_id) {
            return array('url' => ECare_Lab_Front::url('cart', array('step' => 'orders')), 'external' => false);
        }
        if (!self::payable($order, $user_id)) {
            // Already paid (or cancelled): show where it stands.
            return array('url' => ECare_Lab_Front::url('cart', array('step' => 'orders', 'placed' => (int) $order->get_meta(ECare_Lab_Orders::ORDER_META))), 'external' => false);
        }
        $gw = self::gateway();
        if (!$gw) {
            return array('url' => $order->get_checkout_payment_url(), 'external' => false);
        }
        $s = self::start($order, $gw, $from);
        if ($s['ok']) {
            return array('url' => $s['url'], 'external' => true);
        }
        return array('url' => self::back_url($from, 'failed'), 'external' => false);
    }

    /** Only ever leave the site for SSLCommerz itself. */
    public static function is_gateway_url($url) {
        $p = wp_parse_url((string) $url);
        if (!$p || ($p['scheme'] ?? '') !== 'https' || empty($p['host'])) {
            return false;
        }
        $host = strtolower($p['host']);
        return $host === 'sslcommerz.com' || substr($host, -15) === '.sslcommerz.com';
    }

    /** The fields SSLCommerz's v4 session API takes, built from the order. */
    public static function session_fields($order, $gw, $from) {
        $booking = (int) $order->get_meta(ECare_Lab_Orders::ORDER_META);
        $page    = (int) $gw->get_option('redirect_page_id');
        $success = add_query_arg('wc-api', get_class($gw), $page ? get_permalink($page) : get_site_url() . '/');
        $country = $order->get_billing_country() ?: 'BD';
        $names   = (function_exists('WC') && WC()->countries) ? WC()->countries->get_countries() : array();
        return array(
            'store_id'         => (string) $gw->get_option('store_id'),
            'store_passwd'     => (string) $gw->get_option('store_password'),
            'total_amount'     => wc_format_decimal($order->get_total(), 2),
            'currency'         => $order->get_currency() ?: 'BDT',
            'tran_id'          => (string) $order->get_id(),   // the plugin's success handler and IPN look the order up by this
            'success_url'      => $success,
            'fail_url'         => self::back_url($from, 'failed'),
            'cancel_url'       => self::back_url($from, 'cancelled'),
            'ipn_url'          => get_site_url() . '/easyCheckout.php?sslcommerzipn',
            'cus_name'         => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
            'cus_email'        => $order->get_billing_email(),
            'cus_phone'        => $order->get_billing_phone(),
            'cus_add1'         => $order->get_billing_address_1() ?: $order->get_billing_city(),
            'cus_city'         => $order->get_billing_city(),
            'cus_state'        => $order->get_billing_state(),
            'cus_postcode'     => $order->get_billing_postcode(),
            'cus_country'      => $names[$country] ?? 'Bangladesh',
            'shipping_method'  => 'NO',
            'num_of_item'      => 1,
            /* translators: %d: lab order number */
            'product_name'     => sprintf(__('Lab test advance - order #%d', 'ecare-health-services'), $booking),
            'product_category' => 'healthcare',
            'product_profile'  => 'general',
            'value_a'          => (string) $booking,
        );
    }

    /**
     * Open an SSLCommerz session for the order.
     *
     * @return array{ok:bool, url?:string, code?:string}
     */
    public static function start($order, $gw, $from = 'checkout') {
        $api = $gw->get_option('testmode') === 'yes'
            ? 'https://sandbox.sslcommerz.com/gwprocess/v4/api.php'
            : 'https://securepay.sslcommerz.com/gwprocess/v4/api.php';
        $res = wp_remote_post($api, array('timeout' => 30, 'body' => self::session_fields($order, $gw, $from)));
        $why = '';
        if (is_wp_error($res)) {
            $why = $res->get_error_message();
        } elseif ((int) wp_remote_retrieve_response_code($res) !== 200) {
            $why = 'HTTP ' . (int) wp_remote_retrieve_response_code($res);
        } else {
            $body = json_decode((string) wp_remote_retrieve_body($res), true);
            $url  = is_array($body) ? (string) ($body['GatewayPageURL'] ?? '') : '';
            if (is_array($body) && strtoupper((string) ($body['status'] ?? '')) === 'SUCCESS' && self::is_gateway_url($url)) {
                if ($order->get_payment_method() !== self::GATEWAY) {
                    $order->set_payment_method($gw);
                    $order->save();
                }
                return array('ok' => true, 'url' => $url);
            }
            $why = is_array($body) ? (string) ($body['failedreason'] ?? ($body['status'] ?? 'no payment page')) : 'unreadable reply';
        }
        $order->add_order_note('SSLCommerz could not open a payment page: ' . sanitize_text_field($why));
        return array('ok' => false, 'code' => 'api');
    }

    // =======================================================================
    // WooCommerce's order-pay page is skipped for lab orders
    // =======================================================================

    /**
     * A lab order reached through WooCommerce's own "Pay" links (My Account >
     * Orders, an old email) goes straight to SSLCommerz as well.
     */
    public static function skip_order_pay() {
        if (!function_exists('is_wc_endpoint_url') || !is_wc_endpoint_url('order-pay') || !is_user_logged_in()) {
            return;
        }
        $order = wc_get_order(absint(get_query_var('order-pay')));
        if (!self::payable($order, get_current_user_id()) || !self::gateway()) {
            return;
        }
        wp_safe_redirect(self::url($order, 'orders'));
        exit;
    }
}
