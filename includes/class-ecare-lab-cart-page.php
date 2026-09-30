<?php
defined('ABSPATH') || exit;

/**
 * The lab cart page: [ecare_lab_cart] (or its Elementor widget).
 *
 * Shukhee's "Lab Cart" screen: the chosen lab with a Change list (labs that
 * lack a test, or do not collect in the patient's area, are shown as
 * UNAVAILABLE), the tests with a patients stepper, Add More Tests, Clear Cart,
 * and a price summary with Proceed to Checkout.
 *
 * Every change is an ordinary form post to admin-post.php followed by a
 * redirect back, so the page works without JavaScript and a refresh never
 * repeats an action. ecare-lab.js only makes it smoother.
 */
class ECare_Lab_Cart_Page {

    const ACTION = 'ecare_lab_cart';

    public static function init() {
        add_shortcode('ecare_lab_cart', array(__CLASS__, 'render'));
        add_action('admin_post_' . self::ACTION, array(__CLASS__, 'handle'));
        add_action('admin_post_nopriv_' . self::ACTION, array(__CLASS__, 'handle'));
        add_action('template_redirect', array(__CLASS__, 'no_cache'));
    }

    /** A cart is personal: never let a page cache keep one copy for everybody. */
    public static function no_cache() {
        if (!self::is_cart_page()) {
            return;
        }
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        nocache_headers();
        do_action('litespeed_control_set_nocache', 'E-Care lab cart');
    }

    public static function is_cart_page() {
        $post = is_singular() ? get_post() : null;
        if (!$post) {
            return false;
        }
        $hay = (string) $post->post_content . ' ' . (string) get_post_meta($post->ID, '_elementor_data', true);
        return strpos($hay, 'ecare_lab_cart') !== false;
    }

    // =======================================================================
    // Actions
    // =======================================================================

    public static function handle() {
        $back = remove_query_arg(array('cart_msg', 'change'), ECare_Lab_Front::url('cart'));
        if (!is_user_logged_in()) {
            wp_safe_redirect(ECare_Lab_Front::login_url($back));
            exit;
        }
        $msg = self::apply(get_current_user_id(), wp_unslash($_POST));
        wp_safe_redirect($msg !== '' ? add_query_arg('cart_msg', $msg, $back) : $back);
        exit;
    }

    /**
     * Carry out one posted cart action for this user.
     *
     * @return string a message code for the page (see messages()), or ''
     */
    public static function apply($user_id, $in) {
        if (!wp_verify_nonce((string) ($in['_ecl'] ?? ''), self::ACTION)) {
            return 'expired';
        }
        $test = (int) ($in['test'] ?? 0);
        switch (sanitize_key((string) ($in['do'] ?? ''))) {
            case 'patients':
                ECare_Lab_Cart::set_patients($user_id, $test, (int) ($in['n'] ?? 1));
                return '';
            case 'remove':
                ECare_Lab_Cart::remove($user_id, $test);
                return 'removed';
            case 'clear':
                ECare_Lab_Cart::clear($user_id);
                return 'cleared';
            case 'lab':
                $r = ECare_Lab_Cart::switch_lab($user_id, (int) ($in['lab'] ?? 0), ECare_Lab_Cart::get_area($user_id));
                return $r['ok'] ? 'switched' : 'switch_' . $r['code'];
            case 'area':
                return ECare_Lab_Cart::set_area($user_id, (int) ($in['area'] ?? 0)) ? 'area' : 'bad_area';
        }
        return '';
    }

    public static function messages() {
        $d = 'ecare-health-services';
        return array(
            'removed'        => array('ok', __('The test was removed from your cart.', $d)),
            'cleared'        => array('ok', __('Your lab cart is empty now.', $d)),
            'switched'       => array('ok', __('Lab changed. Prices below are for the new lab.', $d)),
            'area'           => array('ok', __('Your area is saved.', $d)),
            'switch_missing' => array('error', __('That lab does not offer every test in your cart.', $d)),
            'switch_area'    => array('error', __('That lab does not collect samples in your area.', $d)),
            'switch_empty'   => array('error', __('Your cart is empty.', $d)),
            'bad_area'       => array('error', __('Please choose your area from the list.', $d)),
            'expired'        => array('error', __('That took too long and was not saved. Please try again.', $d)),
        );
    }

    /** Why checkout is blocked, in words, for the codes from ECare_Lab_Cart::problems(). */
    public static function problem_text($code, $lab_name = '', $area_name = '') {
        $d = 'ecare-health-services';
        switch ($code) {
            case 'unavailable':
                return __('Remove the tests marked unavailable, or change the lab.', $d);
            case 'no_area':
                return __('Choose your area so we know where to collect the sample.', $d);
            case 'lab_area':
                /* translators: 1: lab name, 2: area name */
                return sprintf(__('%1$s does not collect samples in %2$s. Change the lab or the area.', $d), $lab_name, $area_name);
        }
        return '';
    }

    // =======================================================================
    // Areas
    // =======================================================================

    /**
     * Every area, grouped by district, for the "Sample collection area"
     * dropdown: array('Dhaka' => array(12 => 'Dhanmondi', ...), ...).
     * An area is a location term two levels down (division > district > area).
     */
    public static function area_options() {
        $terms = get_terms(array('taxonomy' => ECare_Locations::TAXONOMY, 'hide_empty' => false));
        if (is_wp_error($terms) || !$terms) {
            return array();
        }
        $by = array();
        foreach ($terms as $t) {
            $by[(int) $t->term_id] = $t;
        }
        $groups = array();
        foreach ($terms as $t) {
            $parent = $by[(int) $t->parent] ?? null;
            if ($parent && (int) $parent->parent && isset($by[(int) $parent->parent])) {
                $groups[$parent->name][(int) $t->term_id] = $t->name;
            }
        }
        ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($groups as &$areas) {
            asort($areas, SORT_NATURAL | SORT_FLAG_CASE);
        }
        unset($areas);
        return $groups;
    }

    // =======================================================================
    // The page
    // =======================================================================

    private static function form_open($do, $class = 'ecl-cp-form') {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="' . esc_attr($class) . '">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '" />'
            . '<input type="hidden" name="_ecl" value="' . esc_attr(wp_create_nonce(self::ACTION)) . '" />'
            . '<input type="hidden" name="do" value="' . esc_attr($do) . '" />';
    }

    private static function lab_logo($id, $name) {
        $logo = (string) get_the_post_thumbnail_url($id, 'thumbnail');
        return $logo !== ''
            ? '<img class="ecl-cp-logo" src="' . esc_url($logo) . '" alt="" />'
            : '<span class="ecl-lab-initial ecl-cp-logo">' . esc_html(function_exists('mb_substr') ? mb_strtoupper(mb_substr($name, 0, 1)) : strtoupper(substr($name, 0, 1))) . '</span>';
    }

    private static function crumbs($d) {
        $out = '<nav class="ecl-crumbs" aria-label="' . esc_attr__('Breadcrumb', $d) . '"><a href="' . esc_url(home_url('/')) . '">' . esc_html__('Home', $d) . '</a>';
        if (ECare_Lab_Front::page_id('home')) {
            $out .= '<span>›</span><a href="' . esc_url(ECare_Lab_Front::url('home')) . '">' . esc_html__('Home Lab', $d) . '</a>';
        }
        return $out . '<span>›</span><span aria-current="page">' . esc_html__('Lab Cart', $d) . '</span></nav>';
    }

    public static function render() {
        $d = 'ecare-health-services';

        if (!is_user_logged_in()) {
            return '<div class="ecl ecl-cartp">' . self::crumbs($d) . '<div class="ecl-empty">' . ECare_Lab_Front::icon('flask')
                . '<h2>' . esc_html__('Log in to see your lab cart', $d) . '</h2>'
                . '<p>' . esc_html__('Your cart is saved to your account, so it is there on any device.', $d) . '</p>'
                . '<a class="ecl-btn" href="' . esc_url(ECare_Lab_Front::login_url(ECare_Lab_Front::url('cart'))) . '">' . esc_html__('Log in / Sign up', $d) . '</a></div></div>';
        }

        if (isset($_GET['step']) && $_GET['step'] === 'checkout') {
            return ECare_Lab_Checkout_Page::render();
        }
        if (isset($_GET['step']) && $_GET['step'] === 'orders') {
            return ECare_Lab_Orders_Page::render();
        }

        $uid    = get_current_user_id();
        $code   = isset($_GET['cart_msg']) ? sanitize_key(wp_unslash((string) $_GET['cart_msg'])) : '';
        $all    = self::messages();
        $notice = $all[$code] ?? null;
        $p      = ECare_Lab_Cart::priced($uid);
        $tests  = ECare_Lab_Front::url('tests');

        ob_start();
        echo '<div class="ecl ecl-cartp">' . self::crumbs($d); // phpcs:ignore
        if ($notice) {
            echo '<div class="ecl-notice ecl-notice-' . esc_attr($notice[0]) . '" role="' . ($notice[0] === 'error' ? 'alert' : 'status') . '">' . esc_html($notice[1]) . '</div>';
        }

        if (!$p['lines']) {
            echo '<div class="ecl-empty">' . ECare_Lab_Front::icon('flask') // phpcs:ignore
                . '<h2>' . esc_html__('Your lab cart is empty', $d) . '</h2>'
                . '<p>' . esc_html__('Find a test, choose a lab, and it will wait for you here.', $d) . '</p>'
                . '<a class="ecl-btn" href="' . esc_url($tests) . '">' . esc_html__('Browse tests', $d) . '</a>'
                . '<p class="ecl-cp-orders-link"><a class="ecl-link" href="' . esc_url(ECare_Lab_Front::url('cart', array('step' => 'orders'))) . '">' . esc_html__('My Lab Orders', $d) . '</a></p></div></div>';
            return ob_get_clean();
        }

        $lab_id    = (int) $p['provider_id'];
        $lab_name  = get_the_title($lab_id);
        $area      = ECare_Lab_Cart::get_area($uid);
        $areas     = self::area_options();
        $area_name = '';
        foreach ($areas as $district => $list) {
            if (isset($list[$area])) {
                $area_name = $list[$area] . ', ' . $district;
            }
        }
        $vendors  = ECare_Lab_Cart::vendors($uid, $area);
        $problems = ECare_Lab_Cart::problems($uid, $area);
        $n_lines  = count($p['lines']);
        $show_all = !empty($_GET['change']);
        $total    = round($p['subtotal'] + $p['material'], 2);
        ?>
        <div class="ecl-cp-titlebar">
            <h1 class="ecl-cp-title"><?php esc_html_e('Lab Cart', $d); ?> <span><?php echo esc_html(sprintf(_n('(%d test)', '(%d tests)', $n_lines, $d), $n_lines)); ?></span></h1>
            <a class="ecl-link" href="<?php echo esc_url(ECare_Lab_Front::url('cart', array('step' => 'orders'))); ?>"><?php esc_html_e('My Lab Orders', $d); ?></a>
        </div>

        <div class="ecl-cp-layout">
            <div class="ecl-cp-main">

                <?php if ($areas): ?>
                <section class="ecl-cp-box ecl-cp-area" aria-labelledby="ecl-cp-area-h">
                    <?php echo self::form_open('area', 'ecl-cp-form ecl-cp-area-form'); // phpcs:ignore ?>
                        <label id="ecl-cp-area-h" for="ecl-cp-area-sel"><?php esc_html_e('Sample collection area', $d); ?></label>
                        <div class="ecl-cp-area-row">
                            <select id="ecl-cp-area-sel" name="area" data-ecl-submit-on-change>
                                <option value=""><?php esc_html_e('— Choose your area —', $d); ?></option>
                                <?php foreach ($areas as $district => $list): ?>
                                    <optgroup label="<?php echo esc_attr($district); ?>">
                                        <?php foreach ($list as $id => $name): ?>
                                            <option value="<?php echo (int) $id; ?>"<?php selected($area, $id); ?>><?php echo esc_html($name); ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="ecl-btn ecl-btn-ghost ecl-btn-sm ecl-nojs"><?php esc_html_e('Save', $d); ?></button>
                        </div>
                    </form>
                </section>
                <?php endif; ?>

                <section class="ecl-cp-box ecl-cp-vendor" aria-labelledby="ecl-cp-vendor-h">
                    <div class="ecl-cp-vendor-row">
                        <?php echo self::lab_logo($lab_id, $lab_name); // phpcs:ignore ?>
                        <div class="ecl-cp-vendor-name">
                            <small id="ecl-cp-vendor-h"><?php esc_html_e('Selected lab', $d); ?></small>
                            <strong><?php echo esc_html($lab_name); ?></strong>
                            <?php if (in_array('lab_area', $problems, true)): ?>
                                <span class="ecl-tag ecl-tag-off"><?php esc_html_e('UNAVAILABLE in your area', $d); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if (count($vendors) > 1 || !$vendors || !$vendors[0]['current']): ?>
                            <a class="ecl-btn ecl-btn-ghost ecl-btn-sm" href="<?php echo esc_url(add_query_arg('change', '1', remove_query_arg('cart_msg', ECare_Lab_Front::url('cart')))); ?>#ecl-vendors" data-ecl-toggle="ecl-vendors" aria-controls="ecl-vendors" aria-expanded="<?php echo $show_all ? 'true' : 'false'; ?>"><?php esc_html_e('Change', $d); ?></a>
                        <?php endif; ?>
                    </div>

                    <div class="ecl-cp-vendors" id="ecl-vendors"<?php echo $show_all ? '' : ' hidden'; ?>>
                        <p class="ecl-cp-hint"><?php esc_html_e('Prices are for everything in your cart. One lab per order.', $d); ?></p>
                        <?php foreach ($vendors as $v): $vname = get_the_title($v['id']); ?>
                            <div class="ecl-cp-v<?php echo $v['available'] ? '' : ' is-off'; ?><?php echo $v['current'] ? ' is-current' : ''; ?>">
                                <?php echo self::lab_logo($v['id'], $vname); // phpcs:ignore ?>
                                <div class="ecl-cp-v-name">
                                    <strong><?php echo esc_html($vname); ?></strong>
                                    <?php if ($v['reason'] === 'missing'): ?>
                                        <small><?php echo esc_html(sprintf(__('Does not offer: %s', $d), implode(', ', array_map('get_the_title', $v['missing'])))); ?></small>
                                    <?php elseif ($v['reason'] === 'area'): ?>
                                        <small><?php echo esc_html(sprintf(__('Does not collect in %s', $d), $area_name)); ?></small>
                                    <?php elseif ($v['savings'] > 0): ?>
                                        <small class="ecl-cp-save"><?php echo esc_html(sprintf(__('You save %s', $d), ECare_Lab_Front::money($v['savings']))); ?></small>
                                    <?php endif; ?>
                                </div>
                                <div class="ecl-cp-v-price">
                                    <?php if ($v['available']): ?>
                                        <strong><?php echo esc_html(ECare_Lab_Front::money($v['total'])); ?></strong>
                                        <?php if ($v['savings'] > 0): ?><del><?php echo esc_html(ECare_Lab_Front::money($v['mrp'])); ?></del><?php endif; ?>
                                        <?php if (!empty($v['material'])): ?><small class="ecl-cp-v-mat"><?php echo esc_html(sprintf(__('+ %s material cost', $d), ECare_Lab_Front::money($v['material']))); ?></small><?php endif; ?>
                                    <?php else: ?>
                                        <span class="ecl-tag ecl-tag-off"><?php esc_html_e('UNAVAILABLE', $d); ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($v['current']): ?>
                                    <span class="ecl-cp-v-cur"><?php esc_html_e('Selected', $d); ?></span>
                                <?php elseif ($v['available']): ?>
                                    <?php echo self::form_open('lab'); // phpcs:ignore ?>
                                        <input type="hidden" name="lab" value="<?php echo (int) $v['id']; ?>" />
                                        <button type="submit" class="ecl-btn ecl-btn-sm"><?php esc_html_e('Select', $d); ?></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="ecl-cp-box ecl-cp-items" aria-labelledby="ecl-cp-items-h">
                    <h2 id="ecl-cp-items-h"><?php esc_html_e('Tests', $d); ?></h2>
                    <ul class="ecl-cp-lines">
                        <?php foreach ($p['lines'] as $line): $tid = (int) $line['test_id']; $n = (int) $line['patients']; ?>
                            <li class="ecl-cp-line<?php echo $line['available'] ? '' : ' is-off'; ?>">
                                <div class="ecl-cp-line-info">
                                    <a class="ecl-cp-line-title" href="<?php echo esc_url(ECare_Lab_Front::url('test', array('id' => $tid))); ?>"><?php echo esc_html($line['title']); ?></a>
                                    <?php if ($line['available']): ?>
                                        <span class="ecl-cp-unit">
                                            <?php echo esc_html(sprintf(__('%s per patient', $d), ECare_Lab_Front::money($line['price']))); ?>
                                            <?php if ($line['mrp'] > $line['price']): ?><del><?php echo esc_html(ECare_Lab_Front::money($line['mrp'])); ?></del><?php endif; ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="ecl-tag ecl-tag-off"><?php esc_html_e('UNAVAILABLE', $d); ?></span>
                                        <span class="ecl-cp-unit"><?php echo esc_html(sprintf(__('%s no longer offers this test.', $d), $lab_name)); ?></span>
                                    <?php endif; ?>
                                </div>

                                <?php if ($line['available']): ?>
                                    <?php echo self::form_open('patients', 'ecl-cp-form ecl-cp-stepper'); // phpcs:ignore ?>
                                        <input type="hidden" name="test" value="<?php echo $tid; ?>" />
                                        <span class="ecl-cp-step-label"><?php esc_html_e('Patients', $d); ?></span>
                                        <span class="ecl-stepper">
                                            <button type="submit" name="n" value="<?php echo $n - 1; ?>" class="ecl-step-btn" aria-label="<?php esc_attr_e('One patient fewer', $d); ?>"<?php disabled($n <= 1); ?>>−</button>
                                            <output class="ecl-step-n2"><?php echo $n; ?></output>
                                            <button type="submit" name="n" value="<?php echo $n + 1; ?>" class="ecl-step-btn" aria-label="<?php esc_attr_e('One patient more', $d); ?>"<?php disabled($n >= ECare_Lab_Cart::MAX_PATIENTS); ?>>+</button>
                                        </span>
                                    </form>
                                    <strong class="ecl-cp-line-total"><?php echo esc_html(ECare_Lab_Front::money($line['line_total'])); ?></strong>
                                <?php endif; ?>

                                <?php echo self::form_open('remove', 'ecl-cp-form ecl-cp-remove'); // phpcs:ignore ?>
                                    <input type="hidden" name="test" value="<?php echo $tid; ?>" />
                                    <button type="submit" class="ecl-cp-x" aria-label="<?php echo esc_attr(sprintf(__('Remove %s', $d), $line['title'])); ?>">×</button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <div class="ecl-cp-acts">
                        <a class="ecl-btn ecl-btn-ghost" href="<?php echo esc_url($lab_id ? ECare_Lab_Front::url('tests', array('lab' => $lab_id)) : $tests); ?>">+ <?php esc_html_e('Add More Tests', $d); ?></a>
                        <?php echo self::form_open('clear', 'ecl-cp-form ecl-cp-clear'); // phpcs:ignore ?>
                            <button type="submit" class="ecl-btn ecl-btn-text" data-ecl-confirm="<?php esc_attr_e('Tap again to clear the cart', $d); ?>"><?php esc_html_e('Clear Cart', $d); ?></button>
                        </form>
                    </div>
                </section>
            </div>

            <aside class="ecl-cp-side">
                <div class="ecl-cp-box ecl-cp-sum">
                    <h2><?php esc_html_e('Summary', $d); ?></h2>
                    <dl>
                        <div><dt><?php esc_html_e('Subtotal (MRP)', $d); ?></dt><dd><?php echo esc_html(ECare_Lab_Front::money($p['subtotal_mrp'])); ?></dd></div>
                        <?php if ($p['savings'] > 0): ?>
                            <div class="ecl-cp-save"><dt><?php esc_html_e('Special Discount', $d); ?></dt><dd>−<?php echo esc_html(ECare_Lab_Front::money($p['savings'])); ?></dd></div>
                        <?php endif; ?>
                        <?php if ($p['material'] > 0): ?>
                            <div><dt><?php esc_html_e('External Material Cost', $d); ?></dt><dd><?php echo esc_html(ECare_Lab_Front::money($p['material'])); ?></dd></div>
                        <?php endif; ?>
                        <div class="ecl-cp-total"><dt><?php esc_html_e('Total', $d); ?></dt><dd><?php echo esc_html(ECare_Lab_Front::money($total)); ?></dd></div>
                    </dl>
                    <p class="ecl-cp-hint"><?php esc_html_e('Report delivery, service charge and coupons are added at checkout.', $d); ?></p>

                    <?php if ($problems): ?>
                        <ul class="ecl-cp-problems">
                            <?php foreach ($problems as $code): $txt = self::problem_text($code, $lab_name, $area_name); if ($txt === '') { continue; } ?>
                                <li><?php echo esc_html($txt); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <button type="button" class="ecl-btn ecl-btn-lg ecl-cp-go" disabled><?php esc_html_e('Proceed to Checkout', $d); ?></button>
                    <?php else: ?>
                        <a class="ecl-btn ecl-btn-lg ecl-cp-go" href="<?php echo esc_url(ECare_Lab_Front::url('cart', array('step' => 'checkout'))); ?>"><?php esc_html_e('Proceed to Checkout', $d); ?></a>
                    <?php endif; ?>
                </div>
            </aside>
        </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
