<?php
defined('ABSPATH') || exit;

/**
 * The lab cart - separate from the WooCommerce cart.
 *
 * The WooCommerce cart is shared by caregiver, ambulance and EG Care bookings,
 * so the lab keeps its own, as Shukhee does with its "Home Lab" tab. It lives
 * in user meta (booking needs a login), so it follows the patient across
 * devices. At checkout (step 14) it becomes a WooCommerce order directly.
 *
 *   _ecare_lab_cart = array(
 *       'provider_id' => 12,             // one lab per cart
 *       'items'       => array(test_id => patients, ...),
 *   )
 *
 * Prices are never stored here: they are read from the offerings every time,
 * so a price change or a lab switching a test off shows up at once.
 */
class ECare_Lab_Cart {

    const META         = '_ecare_lab_cart';
    const MAX_PATIENTS = 10;
    const MAX_ITEMS    = 30;

    // -----------------------------------------------------------------------
    // Storage
    // -----------------------------------------------------------------------

    public static function get($user_id) {
        $raw = get_user_meta((int) $user_id, self::META, true);
        $out = array('provider_id' => 0, 'items' => array());
        if (is_array($raw)) {
            $out['provider_id'] = (int) ($raw['provider_id'] ?? 0);
            foreach ((array) ($raw['items'] ?? array()) as $tid => $n) {
                $out['items'][(int) $tid] = self::clamp_patients($n);
            }
        }
        if (!$out['items']) {
            $out['provider_id'] = 0;
        }
        return $out;
    }

    public static function save($user_id, $cart) {
        if (empty($cart['items'])) {
            delete_user_meta((int) $user_id, self::META);
            return;
        }
        update_user_meta((int) $user_id, self::META, array('provider_id' => (int) $cart['provider_id'], 'items' => $cart['items']));
    }

    public static function clear($user_id) {
        delete_user_meta((int) $user_id, self::META);
    }

    public static function clamp_patients($n) {
        return max(1, min(self::MAX_PATIENTS, (int) $n));
    }

    // -----------------------------------------------------------------------
    // Rules
    // -----------------------------------------------------------------------

    /** The bookable offering row for this test at this lab, or null. */
    public static function offering($test_id, $provider_id) {
        foreach (ECare_Lab_Offerings::available_for_test($test_id) as $row) {
            if ((int) $row->provider_id === (int) $provider_id) {
                return $row;
            }
        }
        return null;
    }

    /** Can this lab take every test in the list? */
    public static function lab_can_do_all($provider_id, $test_ids) {
        foreach ((array) $test_ids as $tid) {
            if (!self::offering($tid, $provider_id)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Add (or update) a test in the cart.
     *
     * @param string $on_conflict ''      refuse when the cart holds another lab
     *                            'switch' move the whole cart to the new lab (only if it can do all)
     *                            'replace' empty the cart and start again with this test
     * @return array{ok: bool, code?: string, cart?: array, current_lab?: int, can_switch?: bool}
     */
    public static function add($user_id, $test_id, $provider_id, $patients, $on_conflict = '') {
        $test_id     = (int) $test_id;
        $provider_id = (int) $provider_id;
        if (!self::offering($test_id, $provider_id)) {
            return array('ok' => false, 'code' => 'not_available');
        }

        $cart = self::get($user_id);
        if ($cart['items'] && $cart['provider_id'] !== $provider_id) {
            $others     = array_keys($cart['items']);
            $can_switch = self::lab_can_do_all($provider_id, $others);
            if ($on_conflict === 'switch' && $can_switch) {
                $cart['provider_id'] = $provider_id;
            } elseif ($on_conflict === 'replace') {
                $cart = array('provider_id' => $provider_id, 'items' => array());
            } else {
                return array('ok' => false, 'code' => 'other_lab', 'current_lab' => $cart['provider_id'], 'can_switch' => $can_switch);
            }
        }

        if (!isset($cart['items'][$test_id]) && count($cart['items']) >= self::MAX_ITEMS) {
            return array('ok' => false, 'code' => 'cart_full');
        }
        $cart['provider_id']        = $provider_id;
        $cart['items'][$test_id]    = self::clamp_patients($patients);
        self::save($user_id, $cart);
        return array('ok' => true, 'cart' => $cart);
    }

    public static function remove($user_id, $test_id) {
        $cart = self::get($user_id);
        unset($cart['items'][(int) $test_id]);
        self::save($user_id, $cart);
        return self::get($user_id);
    }

    public static function set_patients($user_id, $test_id, $patients) {
        $cart = self::get($user_id);
        if (isset($cart['items'][(int) $test_id])) {
            $cart['items'][(int) $test_id] = self::clamp_patients($patients);
            self::save($user_id, $cart);
        }
        return self::get($user_id);
    }

    // -----------------------------------------------------------------------
    // Reading with prices
    // -----------------------------------------------------------------------

    /**
     * The cart priced from the current offerings. Lines whose test the lab no
     * longer offers are returned with 'available' => false and count for nothing.
     *
     * @return array{provider_id:int, lines:array, count:int, subtotal_mrp:float, subtotal:float, savings:float, material:float}
     */
    public static function priced($user_id) {
        $cart  = self::get($user_id);
        $lines = array();
        $mrp = $sub = $mat = 0.0;
        $count = 0;
        foreach ($cart['items'] as $tid => $n) {
            $row = self::offering($tid, $cart['provider_id']);
            $line = array(
                'test_id'   => $tid,
                'title'     => get_the_title($tid),
                'patients'  => $n,
                'available' => (bool) $row,
                'price'     => $row ? ECare_Lab_Offerings::effective_price($row) : 0.0,
                'mrp'       => $row ? (float) $row->mrp : 0.0,
            );
            if ($row) {
                $line['line_total'] = round($line['price'] * $n, 2);
                $mrp   += (float) $row->mrp * $n;
                $sub   += $line['line_total'];
                $mat   += (float) $row->material_cost * $n;
                $count += 1;
            } else {
                $line['line_total'] = 0.0;
            }
            $lines[] = $line;
        }
        return array(
            'provider_id'  => $cart['provider_id'],
            'lines'        => $lines,
            'count'        => $count,
            'subtotal_mrp' => round($mrp, 2),
            'subtotal'     => round($sub, 2),
            'savings'      => round($mrp - $sub, 2),
            'material'     => round($mat, 2),
        );
    }
}
