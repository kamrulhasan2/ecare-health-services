<?php
defined('ABSPATH') || exit;

/**
 * Lab checkout rules: the patient's address book, the checkout form state,
 * WooCommerce coupons, the price quote and the final validation.
 *
 * Nothing here prints anything; ECare_Lab_Checkout_Page renders and posts.
 * Step 14 turns a validated checkout into a WooCommerce order.
 */
class ECare_Lab_Checkout {

    const ADDR_META     = '_ecare_lab_addresses';
    const STATE_META    = '_ecare_lab_checkout';
    const MAX_ADDRESSES = 5;
    const LABELS        = array('home', 'office', 'other');
    const DELIVERY      = array('soft', 'hard', 'both');

    // =======================================================================
    // Address book
    // =======================================================================

    /**
     * Stored as array('seq' => last id handed out, 'list' => id => address).
     * Ids only ever go up, so an id a form still remembers can never point at
     * a different, newer address.
     */
    private static function raw_book($user_id) {
        $raw = get_user_meta((int) $user_id, self::ADDR_META, true);
        return array(
            'seq'  => is_array($raw) ? max(0, (int) ($raw['seq'] ?? 0)) : 0,
            'list' => (is_array($raw) && is_array($raw['list'] ?? null)) ? $raw['list'] : array(),
        );
    }

    /**
     * Saved addresses, id => array(id, label, area_id, line). An address whose
     * area has since been deleted is left out.
     */
    public static function addresses($user_id) {
        $out = array();
        foreach (self::raw_book($user_id)['list'] as $id => $a) {
            if (!is_array($a) || !ECare_Lab_Cart::is_area((int) ($a['area_id'] ?? 0))) {
                continue;
            }
            $out[(int) $id] = array(
                'id'      => (int) $id,
                'label'   => in_array($a['label'] ?? '', self::LABELS, true) ? $a['label'] : 'other',
                'area_id' => (int) $a['area_id'],
                'line'    => (string) ($a['line'] ?? ''),
            );
        }
        return $out;
    }

    /**
     * @param array $in label, area_id, line
     * @return array{ok:bool, id?:int, code?:string} codes: area, line, full
     */
    public static function add_address($user_id, $in) {
        $area = (int) ($in['area_id'] ?? 0);
        $line = trim((string) preg_replace('/\s+/u', ' ', sanitize_text_field((string) ($in['line'] ?? ''))));
        if (!ECare_Lab_Cart::is_area($area)) {
            return array('ok' => false, 'code' => 'area');
        }
        $len = function_exists('mb_strlen') ? mb_strlen($line) : strlen($line);
        if ($len < 5) {
            return array('ok' => false, 'code' => 'line');
        }
        if ($len > 200) {
            $line = function_exists('mb_substr') ? mb_substr($line, 0, 200) : substr($line, 0, 200);
        }
        $book = self::addresses($user_id);
        if (count($book) >= self::MAX_ADDRESSES) {
            return array('ok' => false, 'code' => 'full');
        }
        $seq       = max(self::raw_book($user_id)['seq'], $book ? max(array_keys($book)) : 0);
        $id        = $seq + 1;
        $book[$id] = array(
            'id'      => $id,
            'label'   => in_array($in['label'] ?? '', self::LABELS, true) ? $in['label'] : 'home',
            'area_id' => $area,
            'line'    => $line,
        );
        update_user_meta((int) $user_id, self::ADDR_META, array('seq' => $id, 'list' => $book));
        return array('ok' => true, 'id' => $id);
    }

    public static function delete_address($user_id, $id) {
        $raw  = self::raw_book($user_id);
        $book = self::addresses($user_id);
        unset($book[(int) $id]);
        update_user_meta((int) $user_id, self::ADDR_META, array('seq' => $raw['seq'], 'list' => $book));
    }

    /** "House 12, Road 5, Dhanmondi, Dhaka" */
    public static function address_text($addr) {
        $parts = array($addr['line']);
        $area  = get_term((int) $addr['area_id'], ECare_Locations::TAXONOMY);
        if ($area && !is_wp_error($area)) {
            $parts[]  = $area->name;
            $district = get_term((int) $area->parent, ECare_Locations::TAXONOMY);
            if ($district && !is_wp_error($district)) {
                $parts[] = $district->name;
            }
        }
        return implode(', ', array_filter($parts, 'strlen'));
    }

    // =======================================================================
    // Form state (kept between visits, so nothing typed is lost)
    // =======================================================================

    public static function clean_state($in) {
        $in    = is_array($in) ? $in : array();
        $name  = trim(sanitize_text_field((string) ($in['name'] ?? '')));
        $note  = trim(sanitize_textarea_field((string) ($in['note'] ?? '')));
        $date  = (string) ($in['date'] ?? '');
        $slot  = (string) ($in['slot'] ?? '');
        $code  = (string) ($in['coupon'] ?? '');
        return array(
            'address_id' => max(0, (int) ($in['address_id'] ?? 0)),
            'delivery'   => in_array($in['delivery'] ?? '', self::DELIVERY, true) ? $in['delivery'] : 'soft',
            'date'       => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : '',
            'slot'       => preg_match('/^\d{2}:\d{2}-\d{2}:\d{2}$/', $slot) ? $slot : '',
            'name'       => function_exists('mb_substr') ? mb_substr($name, 0, 100) : substr($name, 0, 100),
            'phone'      => substr(preg_replace('/[^\d+\s-]/', '', (string) ($in['phone'] ?? '')), 0, 20),
            'note'       => function_exists('mb_substr') ? mb_substr($note, 0, 500) : substr($note, 0, 500),
            'coupon'     => self::clean_code($code),
        );
    }

    /** WooCommerce codes are case-insensitive; keep letters, digits, - and _. */
    public static function clean_code($code) {
        $code = (string) preg_replace('/[^\p{L}\p{N}_\-]/u', '', (string) $code);
        $code = function_exists('mb_strtolower') ? mb_strtolower($code, 'UTF-8') : strtolower($code);
        return function_exists('mb_substr') ? mb_substr($code, 0, 50) : substr($code, 0, 50);
    }

    public static function get_state($user_id) {
        return self::clean_state(get_user_meta((int) $user_id, self::STATE_META, true));
    }

    public static function save_state($user_id, $state) {
        update_user_meta((int) $user_id, self::STATE_META, self::clean_state($state));
    }

    /** A Bangladeshi mobile number as 01XXXXXXXXX, or '' when it is not one. */
    public static function normalize_phone($raw) {
        $d = preg_replace('/\D+/', '', (string) $raw);
        if (strpos($d, '880') === 0) {
            $d = '0' . substr($d, 3);
        } elseif (strlen($d) === 10 && $d[0] === '1') {
            $d = '0' . $d;
        }
        return preg_match('/^01[3-9]\d{8}$/', $d) ? $d : '';
    }

    // =======================================================================
    // Coupons (WooCommerce's own)
    // =======================================================================

    /**
     * Read a WooCommerce coupon for a lab order. Only the rules that make sense
     * without products are honoured; a coupon tied to products or categories
     * belongs to the shop and is refused.
     *
     * @param object $c        WC_Coupon (or anything with the same getters)
     * @param float  $subtotal tests at their sale price
     * @return array{ok:bool, code?:string, error?:string, type?:string, amount?:float, exclude_sale?:bool, min?:float}
     */
    public static function coupon_rules($c, $user_id, $email, $subtotal) {
        if (!$c || !$c->get_id() || get_post_status($c->get_id()) !== 'publish') {
            return array('ok' => false, 'error' => 'not_found');
        }
        $type = $c->get_discount_type();
        if (!in_array($type, array('percent', 'fixed_cart'), true)) {
            return array('ok' => false, 'error' => 'products');
        }
        if ($c->get_product_ids() || $c->get_product_categories()) {
            return array('ok' => false, 'error' => 'products');
        }
        $exp = $c->get_date_expires();
        if ($exp && $exp->getTimestamp() < time()) {
            return array('ok' => false, 'error' => 'expired');
        }
        $limit = (int) $c->get_usage_limit();
        if ($limit > 0 && (int) $c->get_usage_count() >= $limit) {
            return array('ok' => false, 'error' => 'used_up');
        }
        $per = (int) $c->get_usage_limit_per_user();
        if ($per > 0) {
            $mine = 0;
            foreach ((array) $c->get_used_by() as $who) {
                if ((string) $who === (string) $user_id || ($email !== '' && strtolower((string) $who) === strtolower($email))) {
                    $mine++;
                }
            }
            if ($mine >= $per) {
                return array('ok' => false, 'error' => 'used_by_you');
            }
        }
        $allowed = array_filter((array) $c->get_email_restrictions());
        if ($allowed) {
            $ok = false;
            foreach ($allowed as $pattern) {
                $re = '/^' . str_replace('\*', '.*', preg_quote(strtolower(trim($pattern)), '/')) . '$/';
                if ($email !== '' && preg_match($re, strtolower($email))) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                return array('ok' => false, 'error' => 'email');
            }
        }
        $min = (float) $c->get_minimum_amount();
        if ($min > 0 && $subtotal < $min) {
            return array('ok' => false, 'error' => 'min', 'min' => $min);
        }
        $max = (float) $c->get_maximum_amount();
        if ($max > 0 && $subtotal > $max) {
            return array('ok' => false, 'error' => 'max', 'max' => $max);
        }
        return array(
            'ok'           => true,
            'code'         => self::clean_code($c->get_code()),
            'type'         => $type,
            'amount'       => max(0, (float) $c->get_amount()),
            'exclude_sale' => (bool) $c->get_exclude_sale_items(),
        );
    }

    /** Look the code up in WooCommerce and apply coupon_rules(). */
    public static function load_coupon($code, $user_id, $subtotal) {
        $code = self::clean_code($code);
        if ($code === '' || !class_exists('WC_Coupon') || !function_exists('wc_get_coupon_id_by_code')) {
            return array('ok' => false, 'error' => 'not_found');
        }
        if (!wc_get_coupon_id_by_code($code)) {
            return array('ok' => false, 'error' => 'not_found');
        }
        $user = get_userdata((int) $user_id);
        return self::coupon_rules(new WC_Coupon($code), $user_id, $user ? (string) $user->user_email : '', $subtotal);
    }

    /**
     * The coupon's taka off the tests. With "exclude sale items", tests that
     * already carry a special discount do not count towards it.
     */
    public static function coupon_discount($spec, $lines) {
        if (!$spec || empty($spec['ok'])) {
            return 0.0;
        }
        $base = 0.0;
        foreach ($lines as $l) {
            if (empty($l['available'])) {
                continue;
            }
            if (!empty($spec['exclude_sale']) && $l['mrp'] > $l['price']) {
                continue;
            }
            $base += (float) $l['line_total'];
        }
        $off = $spec['type'] === 'percent' ? $base * $spec['amount'] / 100 : $spec['amount'];
        return round(min($base, $off), 2);
    }

    // =======================================================================
    // Money
    // =======================================================================

    /**
     * The payment summary.
     *
     * @param array  $priced   ECare_Lab_Cart::priced()
     * @param string $delivery soft | hard | both
     * @param ?array $coupon   coupon_rules() result, or null
     */
    public static function quote($priced, $delivery, $coupon = null) {
        $coupon_off = self::coupon_discount($coupon, $priced['lines']);
        $fee        = ECare_Lab_Settings::delivery_fee($delivery);
        $service    = max(0.0, (float) ECare_Lab_Settings::get('service_charge'));
        $total      = round($priced['subtotal'] + $priced['material'] - $coupon_off + $fee + $service, 2);
        $advance    = ECare_Lab_Settings::advance_amount($total);
        return array(
            'subtotal_mrp' => (float) $priced['subtotal_mrp'],
            'special'      => (float) $priced['savings'],
            'material'     => (float) $priced['material'],
            'subtotal'     => (float) $priced['subtotal'],
            'coupon'       => $coupon_off,
            'coupon_code'  => ($coupon && !empty($coupon['ok'])) ? $coupon['code'] : '',
            'delivery'     => $fee,
            'service'      => $service,
            'total'        => $total,
            'advance'      => $advance,
            'later'        => round($total - $advance, 2),
            'percent'      => (float) ECare_Lab_Settings::get('advance_percent'),
        );
    }

    // =======================================================================
    // Schedule
    // =======================================================================

    /** Orders already in each slot, "Y-m-d|HH:MM-HH:MM" => count. Filled by step 14. */
    public static function booked_slots() {
        return (array) apply_filters('ecare_lab_booked_slots', array());
    }

    /** Every bookable date with its open slots, from $now. */
    public static function schedule(DateTimeImmutable $now) {
        $booked = self::booked_slots();
        $out    = array();
        foreach (ECare_Lab_Settings::bookable_dates($now, $booked) as $date) {
            $out[$date] = ECare_Lab_Settings::open_slots($date, $now, $booked);
        }
        return $out;
    }

    // =======================================================================
    // The final check
    // =======================================================================

    /**
     * Everything that must hold before an order can be placed. The browser's
     * checks are only a courtesy; this is the one that counts.
     *
     * @return array{errors: array<string,string>, quote: ?array, coupon: ?array, address: ?array}
     */
    public static function validate($user_id, $state, DateTimeImmutable $now) {
        $errors = array();
        $p      = ECare_Lab_Cart::priced($user_id);
        if (!$p['lines']) {
            return array('errors' => array('cart' => 'empty'), 'quote' => null, 'coupon' => null, 'address' => null);
        }
        foreach ($p['lines'] as $l) {
            if (!$l['available']) {
                $errors['cart'] = 'unavailable';
                break;
            }
        }

        $book    = self::addresses($user_id);
        $address = $book[$state['address_id']] ?? null;
        if (!$address) {
            $errors['address'] = 'missing';
        } elseif (!ECare_Lab_Providers::covers_area($p['provider_id'], $address['area_id'])) {
            $errors['address'] = 'lab_area';
        }

        if ($state['name'] === '') {
            $errors['name'] = 'missing';
        }
        if (self::normalize_phone($state['phone']) === '') {
            $errors['phone'] = $state['phone'] === '' ? 'missing' : 'invalid';
        }

        $slots = ECare_Lab_Settings::open_slots($state['date'], $now, self::booked_slots());
        if ($state['date'] === '' || !$slots) {
            $errors['date'] = 'missing';
        } elseif (!in_array($state['slot'], $slots, true)) {
            $errors['slot'] = 'missing';
        }

        $coupon = null;
        if ($state['coupon'] !== '') {
            $coupon = self::load_coupon($state['coupon'], $user_id, $p['subtotal']);
            if (empty($coupon['ok'])) {
                $errors['coupon'] = $coupon['error'];
                $coupon           = null;
            }
        }

        return array(
            'errors'  => $errors,
            'quote'   => self::quote($p, $state['delivery'], $coupon),
            'coupon'  => $coupon,
            'address' => $address,
        );
    }
}
