<?php
defined('ABSPATH') || exit;

/**
 * The lab checkout screen: the cart page with ?step=checkout.
 *
 * Shukhee's checkout: collection address (from an address book), contact,
 * report delivery, date and time slot, coupon, and the payment summary with
 * the advance to pay now.
 *
 * One form. Every button posts all of it to admin-post.php, the state is kept
 * in user meta and the page is shown again, so nothing typed is lost and the
 * page works without JavaScript. Place Order runs the full server check.
 */
class ECare_Lab_Checkout_Page {

    const ACTION = 'ecare_lab_checkout';

    public static function init() {
        add_action('admin_post_' . self::ACTION, array(__CLASS__, 'handle'));
        add_action('admin_post_nopriv_' . self::ACTION, array(__CLASS__, 'handle'));
    }

    public static function url($args = array()) {
        return ECare_Lab_Front::url('cart', array('step' => 'checkout') + $args);
    }

    private static function err_key($user_id) {
        return 'ecare_lab_co_err_' . (int) $user_id;
    }

    // =======================================================================
    // Posting
    // =======================================================================

    public static function handle() {
        if (!is_user_logged_in()) {
            wp_safe_redirect(ECare_Lab_Front::login_url(self::url()));
            exit;
        }
        $uid = get_current_user_id();
        $r   = self::apply($uid, wp_unslash($_POST), new DateTimeImmutable('now', wp_timezone()));
        if ($r['errors']) {
            set_transient(self::err_key($uid), $r['errors'], 10 * MINUTE_IN_SECONDS);
        }
        $url = self::url($r['msg'] !== '' ? array('co_msg' => $r['msg']) : array());
        wp_safe_redirect($url . ($r['anchor'] !== '' ? '#' . $r['anchor'] : ''));
        exit;
    }

    /**
     * Carry out one checkout post.
     *
     * @return array{msg:string, errors:array<string,string>, anchor:string, state?:array}
     */
    public static function apply($user_id, $in, DateTimeImmutable $now) {
        if (!wp_verify_nonce((string) ($in['_ecl'] ?? ''), self::ACTION)) {
            return array('msg' => 'expired', 'errors' => array(), 'anchor' => '');
        }
        $state  = ECare_Lab_Checkout::clean_state($in);
        $errors = array();
        $msg    = '';
        $anchor = '';
        $do     = isset($in['del_address']) ? 'del_address' : sanitize_key((string) ($in['do'] ?? ''));

        switch ($do) {
            case 'add_address':
                $r = ECare_Lab_Checkout::add_address($user_id, array(
                    'label'   => (string) ($in['new_label'] ?? ''),
                    'area_id' => (int) ($in['new_area'] ?? 0),
                    'line'    => (string) ($in['new_line'] ?? ''),
                ));
                if ($r['ok']) {
                    $state['address_id'] = $r['id'];
                    $msg                 = 'address_added';
                } else {
                    $errors['new_address'] = $r['code'];
                }
                $anchor = 'ecl-co-address';
                break;

            case 'del_address':
                $id = (int) $in['del_address'];
                ECare_Lab_Checkout::delete_address($user_id, $id);
                if ($state['address_id'] === $id) {
                    $state['address_id'] = 0;
                }
                $msg    = 'address_deleted';
                $anchor = 'ecl-co-address';
                break;

            case 'coupon':
                $code = ECare_Lab_Checkout::clean_code((string) ($in['coupon_input'] ?? ''));
                $p    = ECare_Lab_Cart::priced($user_id);
                $c    = ECare_Lab_Checkout::load_coupon($code, $user_id, $p['subtotal']);
                if (!empty($c['ok'])) {
                    $state['coupon'] = $c['code'];
                    $msg             = 'coupon_applied';
                } else {
                    $errors['coupon'] = $code === '' ? 'empty' : $c['error'];
                    if (isset($c['min'])) {
                        $errors['coupon_amount'] = (string) $c['min'];
                    } elseif (isset($c['max'])) {
                        $errors['coupon_amount'] = (string) $c['max'];
                    }
                }
                $anchor = 'ecl-co-sum';
                break;

            case 'remove_coupon':
                $state['coupon'] = '';
                $anchor          = 'ecl-co-sum';
                break;

            case 'place':
                $v = ECare_Lab_Checkout::validate($user_id, $state, $now);
                if ($v['errors']) {
                    $errors = $v['errors'];
                    $order  = array('cart' => 'ecl-co-top', 'address' => 'ecl-co-address', 'name' => 'ecl-co-contact', 'phone' => 'ecl-co-contact', 'date' => 'ecl-co-time', 'slot' => 'ecl-co-time', 'coupon' => 'ecl-co-sum');
                    foreach ($order as $field => $a) {
                        if (isset($errors[$field])) {
                            $anchor = $a;
                            break;
                        }
                    }
                    $msg = 'fix';
                } else {
                    $state['phone'] = ECare_Lab_Checkout::normalize_phone($state['phone']);
                    ECare_Lab_Checkout::save_state($user_id, $state);
                    /**
                     * A checkout passed every check. Step 14 creates the
                     * WooCommerce order and sends the patient to pay.
                     */
                    do_action('ecare_lab_checkout_ready', $user_id, $state, $v);
                    $msg    = 'ready';
                    $anchor = 'ecl-co-top';
                }
                break;
        }

        // The chosen address decides the area everywhere else (cart, Book modal).
        $book = ECare_Lab_Checkout::addresses($user_id);
        if (isset($book[$state['address_id']])) {
            ECare_Lab_Cart::set_area($user_id, $book[$state['address_id']]['area_id']);
        }
        ECare_Lab_Checkout::save_state($user_id, $state);
        return array('msg' => $msg, 'errors' => $errors, 'anchor' => $anchor, 'state' => $state);
    }

    // =======================================================================
    // Words
    // =======================================================================

    public static function messages() {
        $d = 'ecare-health-services';
        return array(
            'address_added'   => array('ok', __('Address saved.', $d)),
            'address_deleted' => array('ok', __('Address removed.', $d)),
            'coupon_applied'  => array('ok', __('Coupon applied.', $d)),
            'fix'             => array('error', __('Please check the highlighted details.', $d)),
            'ready'           => array('ok', __('Everything is in order. Online payment is connected in the next update.', $d)),
            'expired'         => array('error', __('That took too long and was not saved. Please try again.', $d)),
        );
    }

    public static function error_text($field, $code, $ctx = array()) {
        $d = 'ecare-health-services';
        $t = array(
            'cart:unavailable'  => __('A test in your cart is no longer offered by this lab. Go back to the cart to fix it.', $d),
            'cart:empty'        => __('Your lab cart is empty.', $d),
            'address:missing'   => __('Choose where the sample should be collected.', $d),
            /* translators: %s: lab name */
            'address:lab_area'  => sprintf(__('%s does not collect samples at this address. Choose another address or change the lab in your cart.', $d), $ctx['lab'] ?? ''),
            'new_address:area'  => __('Choose the area.', $d),
            'new_address:line'  => __('Write the house, road and block (at least 5 characters).', $d),
            'new_address:full'  => __('You can keep up to 5 addresses. Remove one first.', $d),
            'name:missing'      => __('Write the patient\'s or contact person\'s name.', $d),
            'phone:missing'     => __('Write a mobile number.', $d),
            'phone:invalid'     => __('Write a Bangladeshi mobile number, like 01712345678.', $d),
            'date:missing'      => __('Choose a collection date.', $d),
            'slot:missing'      => __('Choose a time slot.', $d),
            'coupon:empty'      => __('Write a coupon code.', $d),
            'coupon:not_found'  => __('This coupon does not exist.', $d),
            'coupon:expired'    => __('This coupon has expired.', $d),
            'coupon:used_up'    => __('This coupon has been used up.', $d),
            'coupon:used_by_you'=> __('You have already used this coupon.', $d),
            'coupon:email'      => __('This coupon is not for your account.', $d),
            'coupon:products'   => __('This coupon cannot be used for lab tests.', $d),
            /* translators: %s: amount */
            'coupon:min'        => sprintf(__('This coupon needs tests worth at least %s.', $d), ECare_Lab_Front::money((float) ($ctx['amount'] ?? 0))),
            /* translators: %s: amount */
            'coupon:max'        => sprintf(__('This coupon is for orders up to %s.', $d), ECare_Lab_Front::money((float) ($ctx['amount'] ?? 0))),
        );
        return $t[$field . ':' . $code] ?? '';
    }

    private static function field_error($errors, $field, $ctx = array()) {
        if (!isset($errors[$field])) {
            return '';
        }
        $txt = self::error_text($field, $errors[$field], $ctx);
        return $txt === '' ? '' : '<p class="ecl-co-err" role="alert">' . esc_html($txt) . '</p>';
    }

    /** "Today", "Tomorrow", else "Fri, 3 Oct". */
    public static function date_label($date, DateTimeImmutable $now) {
        $d     = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $now->getTimezone());
        $today = $now->setTime(0, 0);
        if (!$d) {
            return $date;
        }
        $diff = (int) $today->diff($d)->format('%r%a');
        if ($diff === 0) {
            return __('Today', 'ecare-health-services');
        }
        if ($diff === 1) {
            return __('Tomorrow', 'ecare-health-services');
        }
        return wp_date('D, j M', $d->setTime(12, 0)->getTimestamp(), $now->getTimezone());
    }

    // =======================================================================
    // The page
    // =======================================================================

    /** @param ?DateTimeImmutable $now the clock (tests pass one; the site uses its own timezone) */
    public static function render($now = null) {
        $d   = 'ecare-health-services';
        $uid = get_current_user_id();
        $p   = ECare_Lab_Cart::priced($uid);
        $cart_url = ECare_Lab_Front::url('cart');
        $crumbs   = '<nav class="ecl-crumbs" aria-label="' . esc_attr__('Breadcrumb', $d) . '"><a href="' . esc_url(home_url('/')) . '">' . esc_html__('Home', $d) . '</a>'
            . '<span>›</span><a href="' . esc_url($cart_url) . '">' . esc_html__('Lab Cart', $d) . '</a>'
            . '<span>›</span><span aria-current="page">' . esc_html__('Checkout', $d) . '</span></nav>';

        if (!$p['lines']) {
            return '<div class="ecl ecl-cartp ecl-co">' . $crumbs . '<div class="ecl-empty">' . ECare_Lab_Front::icon('flask')
                . '<h2>' . esc_html__('Your lab cart is empty', $d) . '</h2>'
                . '<a class="ecl-btn" href="' . esc_url(ECare_Lab_Front::url('tests')) . '">' . esc_html__('Browse tests', $d) . '</a></div></div>';
        }

        $now    = $now instanceof DateTimeImmutable ? $now : new DateTimeImmutable('now', wp_timezone());
        $state  = ECare_Lab_Checkout::get_state($uid);
        $user   = wp_get_current_user();
        if ($state['name'] === '' && $user && $user->exists()) {
            $state['name'] = trim($user->first_name . ' ' . $user->last_name) ?: (string) $user->display_name;
        }
        if ($state['phone'] === '') {
            $state['phone'] = (string) get_user_meta($uid, 'billing_phone', true);
        }

        $lab_id   = (int) $p['provider_id'];
        $lab_name = get_the_title($lab_id);
        $book     = ECare_Lab_Checkout::addresses($uid);
        $covers   = array();
        foreach ($book as $id => $a) {
            $covers[$id] = (bool) ECare_Lab_Providers::covers_area($lab_id, $a['area_id']);
        }
        // Keep the chosen address; else one in the cart's area this lab serves; else any it serves.
        if (!isset($book[$state['address_id']])) {
            $state['address_id'] = 0;
            $area = ECare_Lab_Cart::get_area($uid);
            foreach ($book as $id => $a) {
                if ($covers[$id] && ($a['area_id'] === $area || !$state['address_id'])) {
                    $state['address_id'] = $id;
                }
            }
        }

        $errors = get_transient(self::err_key($uid));
        $errors = is_array($errors) ? $errors : array();
        delete_transient(self::err_key($uid));

        $v     = ECare_Lab_Checkout::validate($uid, $state, $now);
        $q     = $v['quote'];
        if ($state['coupon'] !== '' && !$v['coupon'] && isset($v['errors']['coupon'])) {
            $errors['coupon'] = $v['errors']['coupon'];   // a coupon that stopped working since it was applied
        }
        $sched = ECare_Lab_Checkout::schedule($now);
        $date  = isset($sched[$state['date']]) ? $state['date'] : (string) (array_key_first($sched) ?? '');
        $slots = $sched[$date] ?? array();

        $code   = isset($_GET['co_msg']) ? sanitize_key(wp_unslash((string) $_GET['co_msg'])) : '';
        $notice = self::messages()[$code] ?? null;
        if ($code === 'ready' && $v['errors']) {
            $notice = null;   // something changed since; do not claim it is ready
        }
        $fees = array(
            'soft' => 0.0,
            'hard' => ECare_Lab_Settings::delivery_fee('hard'),
            'both' => ECare_Lab_Settings::delivery_fee('both'),
        );
        $delivery_opts = array(
            'soft' => array(__('Soft copy', $d), __('The report is uploaded to your account.', $d)),
            'hard' => array(__('Hard copy', $d), __('A printed report is delivered to your address.', $d)),
            'both' => array(__('Both', $d), __('Online report, and a printed copy delivered to you.', $d)),
        );
        $labels = array('home' => __('Home', $d), 'office' => __('Office', $d), 'other' => __('Other', $d));

        ob_start();
        ?>
        <div class="ecl ecl-cartp ecl-co" id="ecl-co-top">
            <?php echo $crumbs; // phpcs:ignore ?>
            <h1 class="ecl-cp-title"><?php esc_html_e('Checkout', $d); ?></h1>
            <?php if ($notice): ?>
                <div class="ecl-notice ecl-notice-<?php echo esc_attr($notice[0]); ?>" role="<?php echo $notice[0] === 'error' ? 'alert' : 'status'; ?>"><?php echo esc_html($notice[1]); ?></div>
            <?php endif; ?>
            <?php echo self::field_error($errors + $v['errors'], 'cart'); // phpcs:ignore ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ecl-co-form" id="ecl-co-form">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>" />
                <input type="hidden" name="_ecl" value="<?php echo esc_attr(wp_create_nonce(self::ACTION)); ?>" />
                <input type="hidden" name="coupon" value="<?php echo esc_attr($q['coupon_code']); ?>" />
                <?php /* Enter in a text field presses the form's first button. Make that a harmless "save", never the × that deletes an address. */ ?>
                <button type="submit" name="do" value="update" class="ecl-co-default" tabindex="-1" aria-hidden="true" formnovalidate></button>

                <div class="ecl-cp-layout">
                    <div class="ecl-cp-main">

                        <section class="ecl-cp-box<?php echo isset($errors['address']) ? ' has-err' : ''; ?>" id="ecl-co-address" aria-labelledby="ecl-co-address-h">
                            <h2 id="ecl-co-address-h"><span class="ecl-co-n">1</span><?php esc_html_e('Sample collection address', $d); ?></h2>
                            <?php echo self::field_error($errors, 'address', array('lab' => $lab_name)); // phpcs:ignore ?>
                            <?php if ($book): ?>
                                <div class="ecl-co-addrs" role="radiogroup" aria-labelledby="ecl-co-address-h">
                                    <?php foreach ($book as $id => $a): $off = !$covers[$id]; ?>
                                        <div class="ecl-co-addr<?php echo $off ? ' is-off' : ''; ?>">
                                            <label>
                                                <input type="radio" name="address_id" value="<?php echo (int) $id; ?>"<?php checked($state['address_id'], $id); ?><?php disabled($off); ?> />
                                                <span class="ecl-co-addr-body">
                                                    <strong><?php echo esc_html($labels[$a['label']]); ?></strong>
                                                    <span><?php echo esc_html(ECare_Lab_Checkout::address_text($a)); ?></span>
                                                    <?php if ($off): ?><span class="ecl-tag ecl-tag-off"><?php echo esc_html(sprintf(__('UNAVAILABLE for %s', $d), $lab_name)); ?></span><?php endif; ?>
                                                </span>
                                            </label>
                                            <button type="submit" name="del_address" value="<?php echo (int) $id; ?>" class="ecl-cp-x" formnovalidate aria-label="<?php echo esc_attr(sprintf(__('Remove address: %s', $d), ECare_Lab_Checkout::address_text($a))); ?>">×</button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <?php if (count($book) < ECare_Lab_Checkout::MAX_ADDRESSES): ?>
                            <details class="ecl-co-new"<?php echo (!$book || isset($errors['new_address'])) ? ' open' : ''; ?>>
                                <summary><?php echo $book ? esc_html__('+ Add a new address', $d) : esc_html__('Add your address', $d); ?></summary>
                                <?php echo self::field_error($errors, 'new_address'); // phpcs:ignore ?>
                                <div class="ecl-co-labels" role="radiogroup" aria-label="<?php esc_attr_e('Address type', $d); ?>">
                                    <?php foreach ($labels as $k => $txt): ?>
                                        <label class="ecl-co-chip"><input type="radio" name="new_label" value="<?php echo esc_attr($k); ?>"<?php checked($k, 'home'); ?> /> <span><?php echo esc_html($txt); ?></span></label>
                                    <?php endforeach; ?>
                                </div>
                                <label class="ecl-co-lbl" for="ecl-co-new-area"><?php esc_html_e('Area', $d); ?></label>
                                <select id="ecl-co-new-area" name="new_area">
                                    <option value=""><?php esc_html_e('— Choose your area —', $d); ?></option>
                                    <?php foreach (ECare_Lab_Cart_Page::area_options() as $district => $list): ?>
                                        <optgroup label="<?php echo esc_attr($district); ?>">
                                            <?php foreach ($list as $aid => $aname): ?>
                                                <option value="<?php echo (int) $aid; ?>"<?php selected(ECare_Lab_Cart::get_area($uid), $aid); ?>><?php echo esc_html($aname); ?></option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                </select>
                                <label class="ecl-co-lbl" for="ecl-co-new-line"><?php esc_html_e('House, road, block, landmark', $d); ?></label>
                                <input type="text" id="ecl-co-new-line" name="new_line" maxlength="200" autocomplete="street-address" />
                                <button type="submit" name="do" value="add_address" class="ecl-btn ecl-btn-ghost" formnovalidate><?php esc_html_e('Save address', $d); ?></button>
                            </details>
                            <?php endif; ?>
                        </section>

                        <section class="ecl-cp-box<?php echo (isset($errors['name']) || isset($errors['phone'])) ? ' has-err' : ''; ?>" id="ecl-co-contact" aria-labelledby="ecl-co-contact-h">
                            <h2 id="ecl-co-contact-h"><span class="ecl-co-n">2</span><?php esc_html_e('Contact', $d); ?></h2>
                            <div class="ecl-co-grid">
                                <div>
                                    <label class="ecl-co-lbl" for="ecl-co-name"><?php esc_html_e('Name', $d); ?></label>
                                    <input type="text" id="ecl-co-name" name="name" value="<?php echo esc_attr($state['name']); ?>" maxlength="100" autocomplete="name" required />
                                    <?php echo self::field_error($errors, 'name'); // phpcs:ignore ?>
                                </div>
                                <div>
                                    <label class="ecl-co-lbl" for="ecl-co-phone"><?php esc_html_e('Mobile number', $d); ?></label>
                                    <input type="tel" id="ecl-co-phone" name="phone" value="<?php echo esc_attr($state['phone']); ?>" placeholder="01XXXXXXXXX" inputmode="tel" autocomplete="tel" maxlength="20" required />
                                    <?php echo self::field_error($errors, 'phone'); // phpcs:ignore ?>
                                </div>
                            </div>
                            <label class="ecl-co-lbl" for="ecl-co-note"><?php esc_html_e('Note for the collector (optional)', $d); ?></label>
                            <textarea id="ecl-co-note" name="note" rows="2" maxlength="500"><?php echo esc_textarea($state['note']); ?></textarea>
                        </section>

                        <section class="ecl-cp-box" id="ecl-co-delivery" aria-labelledby="ecl-co-delivery-h">
                            <h2 id="ecl-co-delivery-h"><span class="ecl-co-n">3</span><?php esc_html_e('Report delivery', $d); ?></h2>
                            <div class="ecl-co-opts">
                                <?php foreach ($delivery_opts as $k => $o): ?>
                                    <label class="ecl-co-opt">
                                        <input type="radio" name="delivery" value="<?php echo esc_attr($k); ?>" data-fee="<?php echo esc_attr((string) $fees[$k]); ?>"<?php checked($state['delivery'], $k); ?> />
                                        <span class="ecl-co-opt-body"><strong><?php echo esc_html($o[0]); ?></strong><small><?php echo esc_html($o[1]); ?></small></span>
                                        <span class="ecl-co-opt-fee"><?php echo $fees[$k] > 0 ? esc_html('+' . ECare_Lab_Front::money($fees[$k])) : esc_html__('Free', $d); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </section>

                        <section class="ecl-cp-box<?php echo (isset($errors['date']) || isset($errors['slot'])) ? ' has-err' : ''; ?>" id="ecl-co-time" aria-labelledby="ecl-co-time-h"
                                 data-slots="<?php echo esc_attr(wp_json_encode(array_map(function ($list) { return array_map(function ($s) { return array($s, ECare_Lab_Settings::slot_label($s)); }, $list); }, $sched))); ?>">
                            <h2 id="ecl-co-time-h"><span class="ecl-co-n">4</span><?php esc_html_e('Collection date and time', $d); ?></h2>
                            <?php echo self::field_error($errors, 'date') . self::field_error($errors, 'slot'); // phpcs:ignore ?>
                            <?php if (!$sched): ?>
                                <p class="ecl-co-none"><?php esc_html_e('No collection times are open right now. Please check again later.', $d); ?></p>
                            <?php else: ?>
                                <div class="ecl-co-dates" role="radiogroup" aria-label="<?php esc_attr_e('Date', $d); ?>">
                                    <?php foreach (array_keys($sched) as $day): ?>
                                        <label class="ecl-co-chip"><input type="radio" name="date" value="<?php echo esc_attr($day); ?>"<?php checked($date, $day); ?> /> <span><?php echo esc_html(self::date_label($day, $now)); ?></span></label>
                                    <?php endforeach; ?>
                                </div>
                                <button type="submit" name="do" value="update" class="ecl-btn ecl-btn-ghost ecl-btn-sm ecl-nojs" formnovalidate><?php esc_html_e('Show times for this date', $d); ?></button>
                                <div class="ecl-co-slots" role="radiogroup" aria-label="<?php esc_attr_e('Time slot', $d); ?>">
                                    <?php foreach ($slots as $s): ?>
                                        <label class="ecl-co-chip"><input type="radio" name="slot" value="<?php echo esc_attr($s); ?>"<?php checked($state['date'] === $date ? $state['slot'] : '', $s); ?> /> <span><?php echo esc_html(ECare_Lab_Settings::slot_label($s)); ?></span></label>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </section>
                    </div>

                    <aside class="ecl-cp-side">
                        <div class="ecl-cp-box ecl-cp-sum" id="ecl-co-sum" data-base="<?php echo esc_attr((string) ($q['total'] - $q['delivery'])); ?>" data-pct="<?php echo esc_attr((string) $q['percent']); ?>">
                            <h2><?php esc_html_e('Order summary', $d); ?></h2>
                            <p class="ecl-co-lab"><?php echo esc_html($lab_name); ?> · <a class="ecl-link" href="<?php echo esc_url($cart_url); ?>"><?php esc_html_e('Edit cart', $d); ?></a></p>
                            <ul class="ecl-co-items">
                                <?php foreach ($p['lines'] as $l): if (!$l['available']) { continue; } ?>
                                    <li><span><?php echo esc_html($l['title']); ?><?php echo $l['patients'] > 1 ? esc_html(' × ' . $l['patients']) : ''; ?></span><span><?php echo esc_html(ECare_Lab_Front::money($l['line_total'])); ?></span></li>
                                <?php endforeach; ?>
                            </ul>

                            <div class="ecl-co-coupon">
                                <?php if ($q['coupon_code'] !== ''): ?>
                                    <div class="ecl-co-coupon-on">
                                        <span><?php esc_html_e('Coupon', $d); ?> <strong><?php echo esc_html(strtoupper($q['coupon_code'])); ?></strong></span>
                                        <button type="submit" name="do" value="remove_coupon" class="ecl-btn ecl-btn-text ecl-btn-sm" formnovalidate><?php esc_html_e('Remove', $d); ?></button>
                                    </div>
                                <?php else: ?>
                                    <label class="screen-reader-text" for="ecl-co-coupon"><?php esc_html_e('Coupon code', $d); ?></label>
                                    <div class="ecl-co-coupon-row">
                                        <input type="text" id="ecl-co-coupon" name="coupon_input" placeholder="<?php esc_attr_e('Coupon code', $d); ?>" maxlength="50" autocomplete="off" />
                                        <button type="submit" name="do" value="coupon" class="ecl-btn ecl-btn-ghost ecl-btn-sm" formnovalidate><?php esc_html_e('Apply', $d); ?></button>
                                    </div>
                                <?php endif; ?>
                                <?php echo self::field_error($errors, 'coupon', array('amount' => $errors['coupon_amount'] ?? 0)); // phpcs:ignore ?>
                            </div>

                            <dl>
                                <div><dt><?php esc_html_e('Subtotal (MRP)', $d); ?></dt><dd><?php echo esc_html(ECare_Lab_Front::money($q['subtotal_mrp'])); ?></dd></div>
                                <?php if ($q['special'] > 0): ?><div class="ecl-cp-save"><dt><?php esc_html_e('Special Discount', $d); ?></dt><dd>−<?php echo esc_html(ECare_Lab_Front::money($q['special'])); ?></dd></div><?php endif; ?>
                                <?php if ($q['material'] > 0): ?><div><dt><?php esc_html_e('External Material Cost', $d); ?></dt><dd><?php echo esc_html(ECare_Lab_Front::money($q['material'])); ?></dd></div><?php endif; ?>
                                <?php if ($q['coupon'] > 0): ?><div class="ecl-cp-save"><dt><?php esc_html_e('Coupon Discount', $d); ?></dt><dd>−<?php echo esc_html(ECare_Lab_Front::money($q['coupon'])); ?></dd></div><?php endif; ?>
                                <div><dt><?php esc_html_e('Report Delivery Cost', $d); ?></dt><dd data-ecl-sum="delivery"><?php echo esc_html(ECare_Lab_Front::money($q['delivery'])); ?></dd></div>
                                <?php if ($q['service'] > 0): ?><div><dt><?php esc_html_e('Service Charge', $d); ?></dt><dd><?php echo esc_html(ECare_Lab_Front::money($q['service'])); ?></dd></div><?php endif; ?>
                                <div class="ecl-cp-total"><dt><?php esc_html_e('Total', $d); ?></dt><dd data-ecl-sum="total"><?php echo esc_html(ECare_Lab_Front::money($q['total'])); ?></dd></div>
                                <div class="ecl-co-adv"><dt><?php echo esc_html(sprintf(__('Advance Payable (%s%%)', $d), rtrim(rtrim(number_format((float) $q['percent'], 2, '.', ''), '0'), '.'))); ?></dt><dd data-ecl-sum="advance"><?php echo esc_html(ECare_Lab_Front::money($q['advance'])); ?></dd></div>
                                <div class="ecl-co-later"><dt><?php esc_html_e('Pay at sample collection', $d); ?></dt><dd data-ecl-sum="later"><?php echo esc_html(ECare_Lab_Front::money($q['later'])); ?></dd></div>
                            </dl>

                            <button type="submit" name="do" value="place" class="ecl-btn ecl-btn-lg ecl-cp-go"<?php disabled(!$sched || isset($v['errors']['cart'])); ?>><?php esc_html_e('Place Order', $d); ?></button>
                            <p class="ecl-cp-hint"><?php esc_html_e('You pay the advance online to confirm the booking; the rest is paid when the sample is collected.', $d); ?></p>
                        </div>
                    </aside>
                </div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
}
