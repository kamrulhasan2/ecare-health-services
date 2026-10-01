<?php
defined('ABSPATH') || exit;

/**
 * Lab order emails.
 *
 * When a lab order is confirmed (the advance is paid, or nothing was due):
 *   - the patient gets "Your lab order #N is confirmed" at their account email
 *   - the lab gets "New lab order #N" at its Notification email(s)
 *
 * Sent through WooCommerce's mailer, so the shop's email design, its From
 * name and address and any SMTP plugin all apply. Each email goes once per
 * order; an operator can send it again from Lab -> Lab Orders. Every send,
 * and every reason one was not sent, is written to the order's history.
 */
class ECare_Lab_Emails {

    const WHO = array('patient', 'lab');

    public static function init() {
        add_action('ecare_lab_order_status', array(__CLASS__, 'on_status'), 10, 3);
        add_action('admin_post_ecare_lab_email', array(__CLASS__, 'handle_resend'));
        add_action('admin_post_ecare_lab_email_preview', array(__CLASS__, 'handle_preview'));
    }

    /** Pending -> Confirmed: that is the moment the booking is real. */
    public static function on_status($id, $new, $old) {
        if ($new === 'approved' && $old === 'pending') {
            foreach (self::WHO as $who) {
                self::send($id, $who);
            }
        }
    }

    public static function enabled($who) {
        return (int) ECare_Lab_Settings::get($who === 'lab' ? 'email_lab' : 'email_patient') === 1;
    }

    /** Where this email goes. A placeholder address made from a phone number is not a mailbox. */
    public static function recipients($row, $who) {
        if ($who === 'lab') {
            $raw = (string) get_post_meta((int) $row->lab_provider_id, '_ecare_notify_emails', true);
            return array_values(ECare_Lab_Providers::clean_emails($raw));
        }
        $user  = $row->user_id ? get_userdata((int) $row->user_id) : null;
        $email = $user ? (string) $user->user_email : '';
        if ($email === '' || !is_email($email) || strpos($email, '@no-email.') !== false) {
            return array();
        }
        return array($email);
    }

    // =======================================================================
    // Sending
    // =======================================================================

    /**
     * Send one of the two emails for an order.
     *
     * @param bool $again send even if it already went (the admin's Resend)
     * @return string sent | already | disabled | no_address | failed | missing
     */
    public static function send($id, $who, $again = false) {
        $row = ECare_Lab_Orders::get($id);
        if (!$row || !$row->is_new || !in_array($who, self::WHO, true)) {
            return 'missing';
        }
        if (!$again && !self::enabled($who)) {
            return 'disabled';
        }
        $mailed = (array) ($row->details['mailed'] ?? array());
        if (!$again && !empty($mailed[$who])) {
            return 'already';
        }
        $label = $who === 'lab' ? __('Lab', 'ecare-health-services') : __('Patient', 'ecare-health-services');
        $to    = self::recipients($row, $who);
        if (!$to) {
            ECare_Lab_Orders::add_note($id, $who === 'lab'
                ? __('Lab email not sent: the lab has no notification email (Lab Providers).', 'ecare-health-services')
                : __('Patient email not sent: the account has no email address.', 'ecare-health-services'));
            return 'no_address';
        }

        $mail = self::build($row, $who);
        $ok   = self::deliver($to, $mail['subject'], $mail['heading'], $mail['html'], self::copy_to());
        if ($ok) {
            $mailed[$who] = time();
            ECare_Lab_Orders::set_detail($id, 'mailed', $mailed);
            /* translators: 1: Patient or Lab, 2: addresses */
            ECare_Lab_Orders::add_note($id, sprintf(__('%1$s email sent to %2$s.', 'ecare-health-services'), $label, implode(', ', $to)));
            return 'sent';
        }
        /* translators: 1: Patient or Lab, 2: addresses */
        ECare_Lab_Orders::add_note($id, sprintf(__('%1$s email to %2$s could not be sent (the mail server refused it).', 'ecare-health-services'), $label, implode(', ', $to)));
        return 'failed';
    }

    /** The "copy to" addresses from Lab Settings. */
    public static function copy_to() {
        return array_values(ECare_Lab_Providers::clean_emails((string) ECare_Lab_Settings::get('email_copy')));
    }

    /** Through WooCommerce when it is there (design, From, inline styles), else plain wp_mail. */
    private static function deliver($to, $subject, $heading, $html, $copy) {
        $bcc = $copy ? 'Bcc: ' . implode(', ', $copy) . "\r\n" : '';
        if (function_exists('WC') && WC() && method_exists(WC(), 'mailer')) {
            $mailer = WC()->mailer();
            return (bool) $mailer->send($to, $subject, $mailer->wrap_message($heading, $html), "Content-Type: text/html\r\n" . $bcc);
        }
        $headers = array('Content-Type: text/html; charset=UTF-8');
        if ($bcc !== '') {
            $headers[] = trim($bcc);
        }
        return (bool) wp_mail($to, $subject, '<h2>' . esc_html($heading) . '</h2>' . $html, $headers);
    }

    // =======================================================================
    // The two emails
    // =======================================================================

    private static function money($n) {
        return ECare_Lab_Front::money((float) $n);
    }

    private static function when($det) {
        if (empty($det['date'])) {
            return '';
        }
        $ts = strtotime($det['date'] . ' 12:00');
        return wp_date('l, j F Y', $ts) . ', ' . ECare_Lab_Settings::slot_label((string) ($det['slot'] ?? ''));
    }

    private static function delivery_label($method) {
        $l = array(
            'soft' => __('Soft copy (online)', 'ecare-health-services'),
            'hard' => __('Hard copy (printed, delivered)', 'ecare-health-services'),
            'both' => __('Soft and hard copy', 'ecare-health-services'),
        );
        return $l[$method] ?? $l['soft'];
    }

    private static function rows($pairs) {
        $out = '';
        foreach ($pairs as $k => $v) {
            if ($v === '' || $v === null) {
                continue;
            }
            $out .= '<tr><th style="text-align:left;padding:6px 12px 6px 0;vertical-align:top;white-space:nowrap;">' . esc_html($k) . '</th>'
                . '<td style="text-align:left;padding:6px 0;">' . $v . '</td></tr>';
        }
        return '<table cellspacing="0" cellpadding="0" style="width:100%;margin:0 0 18px;">' . $out . '</table>';
    }

    private static function items_table($items, $with_prices = true) {
        $d    = 'ecare-health-services';
        $head = '<th style="text-align:left;padding:8px;border:1px solid #e5e5e5;">' . esc_html__('Test', $d) . '</th>'
              . '<th style="text-align:center;padding:8px;border:1px solid #e5e5e5;">' . esc_html__('Patients', $d) . '</th>';
        if ($with_prices) {
            $head .= '<th style="text-align:right;padding:8px;border:1px solid #e5e5e5;">' . esc_html__('Price', $d) . '</th>'
                   . '<th style="text-align:right;padding:8px;border:1px solid #e5e5e5;">' . esc_html__('Total', $d) . '</th>';
        }
        $body = '';
        foreach ((array) $items as $i) {
            $body .= '<tr><td style="padding:8px;border:1px solid #e5e5e5;">' . esc_html($i['title']) . '</td>'
                   . '<td style="text-align:center;padding:8px;border:1px solid #e5e5e5;">' . (int) $i['patients'] . '</td>';
            if ($with_prices) {
                $body .= '<td style="text-align:right;padding:8px;border:1px solid #e5e5e5;">' . esc_html(self::money($i['price'])) . '</td>'
                       . '<td style="text-align:right;padding:8px;border:1px solid #e5e5e5;">' . esc_html(self::money($i['line_total'])) . '</td>';
            }
            $body .= '</tr>';
        }
        return '<table cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;margin:0 0 18px;"><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table>';
    }

    /**
     * Subject, heading and HTML body for one order.
     *
     * @return array{subject:string, heading:string, html:string}
     */
    public static function build($row, $who) {
        $d    = 'ecare-health-services';
        $det  = $row->details;
        $q    = (array) ($det['quote'] ?? array());
        $lab  = (string) ($det['lab']['name'] ?? '');
        $site = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        $when = self::when($det);

        if ($who === 'lab') {
            $slot = !empty($det['date']) ? wp_date('D j M', strtotime($det['date'] . ' 12:00')) . ', ' . ECare_Lab_Settings::slot_label((string) ($det['slot'] ?? '')) : '';
            /* translators: 1: order number, 2: collection date and time */
            $subject = sprintf(__('New lab order #%1$d — %2$s', $d), $row->id, $slot);
            $heading = sprintf(__('New lab order #%d', $d), $row->id);
            $html  = '<p>' . esc_html(sprintf(__('A new order for %1$s has been booked on %2$s. Please arrange the sample collection.', $d), $lab, $site)) . '</p>';
            $html .= self::rows(array(
                __('Patient', $d)        => esc_html((string) $row->patient_name),
                __('Phone', $d)          => '<a href="tel:' . esc_attr((string) $row->contact_phone) . '">' . esc_html((string) $row->contact_phone) . '</a>',
                __('Address', $d)        => esc_html((string) ($det['address']['text'] ?? $row->address)),
                __('Collection', $d)     => '<strong>' . esc_html($when) . '</strong>',
                __('Report', $d)         => esc_html(self::delivery_label($det['delivery'] ?? 'soft')),
                __('Note', $d)           => esc_html((string) ($det['note'] ?? '')),
            ));
            $html .= self::items_table($det['items'] ?? array());
            $html .= self::rows(array(
                __('Order total', $d)                    => esc_html(self::money($q['total'] ?? $row->total_amount)),
                __('Paid online (advance)', $d)          => esc_html(self::money($q['advance'] ?? 0)),
                __('To collect at sample collection', $d) => '<strong>' . esc_html(self::money($q['later'] ?? 0)) . '</strong>',
            ));
            $html .= '<p>' . esc_html(sprintf(__('Order #%1$d · %2$s', $d), $row->id, $site)) . '</p>';
            return array('subject' => $subject, 'heading' => $heading, 'html' => $html);
        }

        /* translators: 1: order number, 2: site name */
        $subject = sprintf(__('Your lab order #%1$d is confirmed — %2$s', $d), $row->id, $site);
        $heading = __('Your lab order is confirmed', $d);
        $name    = trim((string) $row->patient_name);
        $html    = '<p>' . esc_html(sprintf(__('Hi %s,', $d), $name !== '' ? $name : __('there', $d))) . '</p>';
        $html   .= '<p>' . esc_html(sprintf(__('Thank you. Your order #%1$d with %2$s is confirmed. A sample collector will come to you at the time below.', $d), $row->id, $lab)) . '</p>';
        $html   .= self::rows(array(
            __('Collection', $d) => '<strong>' . esc_html($when) . '</strong>',
            __('Address', $d)    => esc_html((string) ($det['address']['text'] ?? $row->address)),
            __('Lab', $d)        => esc_html($lab),
            __('Report', $d)     => esc_html(self::delivery_label($det['delivery'] ?? 'soft')),
        ));
        $html .= self::items_table($det['items'] ?? array());

        $money = array(__('Subtotal (MRP)', $d) => esc_html(self::money($q['subtotal_mrp'] ?? 0)));
        foreach (array('special' => __('Special Discount', $d), 'coupon' => __('Coupon Discount', $d)) as $k => $label) {
            if (!empty($q[$k])) {
                $money[$label] = '−' . esc_html(self::money($q[$k]));
            }
        }
        foreach (array('material' => __('External Material Cost', $d), 'delivery' => __('Report Delivery Cost', $d), 'service' => __('Service Charge', $d)) as $k => $label) {
            if (!empty($q[$k])) {
                $money[$label] = esc_html(self::money($q[$k]));
            }
        }
        $money[__('Total', $d)]                    = '<strong>' . esc_html(self::money($q['total'] ?? $row->total_amount)) . '</strong>';
        $money[__('Advance paid', $d)]             = esc_html(self::money($q['advance'] ?? 0));
        $money[__('Pay at sample collection', $d)] = '<strong>' . esc_html(self::money($q['later'] ?? 0)) . '</strong>';
        $html .= self::rows($money);

        $tips = array();
        foreach ($det['items'] ?? array() as $i) {
            $info = class_exists('ECare_Lab_Test_Info') ? ECare_Lab_Test_Info::details((int) $i['test_id']) : array();
            if (($info['fasting'] ?? '') === 'yes') {
                $tips[] = esc_html(sprintf(__('%s needs fasting: please do not eat before the collection (water is fine).', $d), $i['title']));
            }
        }
        if ($tips) {
            $html .= '<p><strong>' . esc_html__('Before the collection', $d) . '</strong><br />' . implode('<br />', $tips) . '</p>';
        }
        $orders  = ECare_Lab_Front::url('cart', array('step' => 'orders'));
        $hotline = trim((string) ECare_Lab_Settings::get('hotline'));
        $html   .= '<p>' . esc_html__('You can follow the order and open the report when it is ready in My Lab Orders:', $d)
                 . ' <a href="' . esc_url($orders) . '">' . esc_html__('My Lab Orders', $d) . '</a></p>';
        if ($hotline !== '') {
            $html .= '<p>' . esc_html(sprintf(__('Questions? Call us on %s.', $d), $hotline)) . '</p>';
        }
        return array('subject' => $subject, 'heading' => $heading, 'html' => $html);
    }

    // =======================================================================
    // Admin: send again, preview
    // =======================================================================

    private static function guard($id) {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do that.', 'ecare-health-services'), 403);
        }
        check_admin_referer('ecare_lab_email_' . (int) $id);
    }

    public static function handle_resend() {
        $id  = (int) ($_POST['id'] ?? 0);
        $who = sanitize_key((string) ($_POST['who'] ?? ''));
        self::guard($id);
        $r = self::send($id, $who, true);
        wp_safe_redirect(ECare_Lab_Orders_Admin::url(array('order' => $id, 'msg' => 'mail_' . $r)));
        exit;
    }

    /** The email exactly as it would go, in the browser, nothing sent. */
    public static function handle_preview() {
        $id  = (int) ($_GET['id'] ?? 0);
        $who = sanitize_key((string) ($_GET['who'] ?? ''));
        self::guard($id);
        $row = ECare_Lab_Orders::get($id);
        if (!$row || !$row->is_new || !in_array($who, self::WHO, true)) {
            wp_die(esc_html__('Lab order not found.', 'ecare-health-services'), 404);
        }
        $mail = self::build($row, $who);
        $body = (function_exists('WC') && WC() && method_exists(WC(), 'mailer'))
            ? WC()->mailer()->wrap_message($mail['heading'], $mail['html'])
            : '<h2>' . esc_html($mail['heading']) . '</h2>' . $mail['html'];
        // WooCommerce styles its emails as they are sent; do the same here so
        // the preview looks like what arrives.
        if (class_exists('WC_Email')) {
            $wc   = new WC_Email();
            $body = $wc->style_inline($body);
        }
        nocache_headers();
        header('Content-Type: text/html; charset=UTF-8');
        echo '<div style="font:13px sans-serif;background:#fffbea;border-bottom:1px solid #e5d48b;padding:8px 12px;">'
            . esc_html(sprintf(__('Preview only, nothing is sent. Subject: %s', 'ecare-health-services'), $mail['subject'])) . '</div>';
        echo $body; // phpcs:ignore -- built from escaped parts above
        exit;
    }

    public static function preview_url($id, $who) {
        return wp_nonce_url(add_query_arg(array('action' => 'ecare_lab_email_preview', 'id' => (int) $id, 'who' => $who), admin_url('admin-post.php')), 'ecare_lab_email_' . (int) $id);
    }
}
