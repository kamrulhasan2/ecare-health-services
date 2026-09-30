<?php
defined('ABSPATH') || exit;

/**
 * What a health package contains.
 *
 * A package is a lab test with type "package" (ECare_Lab_Test_Info). Its price
 * per lab lives in the offerings table like any test. This class adds the
 * contents shown under "Package Includes" on the detail page:
 *
 *   _ecare_package_tests  ordered single-test ids from the catalogue
 *   _ecare_package_extra  names of items that are not in the catalogue
 *
 * Only single tests can be included - no package inside a package, and never
 * the package itself.
 */
class ECare_Lab_Packages {

    const POST_TYPE = 'ecare_lab_test';
    const MAX_ITEMS = 50;

    public static function init() {
        add_action('add_meta_boxes_' . self::POST_TYPE, array(__CLASS__, 'add_meta_box'));
        add_action('save_post_' . self::POST_TYPE, array(__CLASS__, 'save'), 10, 2);
    }

    // -----------------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------------

    /** Included single tests that still exist, in the admin's order. */
    public static function included_test_ids($package_id) {
        $raw = get_post_meta((int) $package_id, '_ecare_package_tests', true);
        $out = array();
        foreach ((array) $raw as $id) {
            $p = get_post((int) $id);
            // A test turned into a package later drops out rather than nesting.
            if ($p && $p->post_type === self::POST_TYPE && $p->post_status !== 'trash' && !ECare_Lab_Test_Info::is_package((int) $id)) {
                $out[] = (int) $id;
            }
        }
        return $out;
    }

    /** @return string[] */
    public static function extra_items($package_id) {
        $raw = get_post_meta((int) $package_id, '_ecare_package_extra', true);
        return is_array($raw) ? array_values($raw) : array();
    }

    /** "Includes N tests" on cards. */
    public static function item_count($package_id) {
        return count(self::included_test_ids($package_id)) + count(self::extra_items($package_id));
    }

    /**
     * What the included tests would cost at one lab if booked one by one:
     * the sum of that lab's MRP for each, and how many of them the lab does
     * not offer (or has switched off).
     *
     * @return array{0: float, 1: int} (sum of MRP, missing count)
     */
    public static function lab_sum($package_id, $provider_id) {
        $sum     = 0.0;
        $missing = 0;
        foreach (self::included_test_ids($package_id) as $test_id) {
            $found = null;
            foreach (ECare_Lab_Offerings::for_test($test_id) as $row) {
                if ((int) $row->provider_id === (int) $provider_id && $row->status === ECare_Lab_Offerings::STATUS_ACTIVE) {
                    $found = $row;
                    break;
                }
            }
            if ($found) {
                $sum += (float) $found->mrp;
            } else {
                $missing++;
            }
        }
        return array(round($sum, 2), $missing);
    }

    // -----------------------------------------------------------------------
    // Cleaning and saving
    // -----------------------------------------------------------------------

    /**
     * Posted ids -> clean ids: single lab tests only, never the package
     * itself, no repeats, order kept, capped.
     */
    public static function clean_test_ids($package_id, $ids) {
        $out = array();
        foreach ((array) $ids as $id) {
            $id = (int) $id;
            if ($id <= 0 || $id === (int) $package_id || in_array($id, $out, true)) {
                continue;
            }
            $p = get_post($id);
            if (!$p || $p->post_type !== self::POST_TYPE || $p->post_status === 'trash') {
                continue;
            }
            if (ECare_Lab_Test_Info::is_package($id)) {
                continue;
            }
            $out[] = $id;
            if (count($out) >= self::MAX_ITEMS) {
                break;
            }
        }
        return $out;
    }

    /** One item per line; blanks and case-insensitive repeats dropped. */
    public static function clean_extra($text) {
        $out  = array();
        $seen = array();
        foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $line) {
            $line = sanitize_text_field($line);
            $key  = function_exists('mb_strtolower') ? mb_strtolower($line, 'UTF-8') : strtolower($line);
            if ($line === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[]      = $line;
            if (count($out) >= self::MAX_ITEMS) {
                break;
            }
        }
        return $out;
    }

    public static function save($post_id, $post) {
        if (!isset($_POST['ecare_lab_package_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ecare_lab_package_nonce'])), 'ecare_lab_package')) {
            return;
        }
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($post_id)) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        $in = wp_unslash($_POST);
        update_post_meta($post_id, '_ecare_package_tests', self::clean_test_ids($post_id, $in['ecare_package_tests'] ?? array()));
        update_post_meta($post_id, '_ecare_package_extra', self::clean_extra($in['ecare_package_extra'] ?? ''));
    }

    // -----------------------------------------------------------------------
    // Edit screen
    // -----------------------------------------------------------------------

    public static function add_meta_box() {
        add_meta_box('ecare_lab_package', __('Package Contents', 'ecare-health-services'), array(__CLASS__, 'render_box'), self::POST_TYPE, 'normal', 'high');
    }

    public static function render_box($post) {
        wp_nonce_field('ecare_lab_package', 'ecare_lab_package_nonce');
        $chosen = self::included_test_ids($post->ID);
        $tests  = get_posts(array(
            'post_type'      => self::POST_TYPE,
            'post_status'    => array('publish', 'draft', 'private'),
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'post__not_in'   => array((int) $post->ID),
        ));
        $choices = array();
        foreach ($tests as $t) {
            if (ECare_Lab_Test_Info::is_package($t->ID)) {
                continue;
            }
            $code = (string) get_post_meta($t->ID, '_test_code', true);
            $choices[$t->ID] = $t->post_title . ($code !== '' ? ' (' . $code . ')' : '') . ($t->post_status !== 'publish' ? ' - ' . __('draft', 'ecare-health-services') : '');
        }
        ?>
        <style>
            #ecare-pkg-list{margin:0 0 10px;padding:0;list-style:none;max-width:640px}
            #ecare-pkg-list li{display:flex;align-items:center;gap:8px;padding:6px 8px;border:1px solid #dcdcde;border-radius:4px;margin-bottom:4px;background:#fff}
            #ecare-pkg-list li span{flex:1}
            #ecare-pkg-pick{display:flex;gap:8px;align-items:center;max-width:640px}
            #ecare-pkg-pick input{flex:1}
        </style>
        <p><strong><?php esc_html_e('Tests in this package', 'ecare-health-services'); ?></strong></p>
        <ul id="ecare-pkg-list">
            <?php foreach ($chosen as $id): if (!isset($choices[$id])) { continue; } ?>
                <?php self::list_item($id, $choices[$id]); ?>
            <?php endforeach; ?>
        </ul>
        <div id="ecare-pkg-pick">
            <input type="search" list="ecare-pkg-options" id="ecare-pkg-search" placeholder="<?php esc_attr_e('Type a test name to add it…', 'ecare-health-services'); ?>" />
            <datalist id="ecare-pkg-options">
                <?php foreach ($choices as $id => $label): ?>
                    <option value="<?php echo esc_attr($label); ?>" data-id="<?php echo (int) $id; ?>"></option>
                <?php endforeach; ?>
            </datalist>
            <button type="button" class="button" id="ecare-pkg-add"><?php esc_html_e('Add', 'ecare-health-services'); ?></button>
        </div>
        <p class="description"><?php esc_html_e('Single tests from the catalogue. Their sample, fasting and report details are shown on the package page.', 'ecare-health-services'); ?></p>

        <p style="margin-top:16px;"><label for="ecare-pkg-extra"><strong><?php esc_html_e('Other items', 'ecare-health-services'); ?></strong></label></p>
        <textarea id="ecare-pkg-extra" name="ecare_package_extra" rows="3" class="large-text" placeholder="<?php esc_attr_e('One per line, for items not in the catalogue (e.g. Serum Calcium)', 'ecare-health-services'); ?>"><?php echo esc_textarea(implode("\n", self::extra_items($post->ID))); ?></textarea>
        <p><?php esc_html_e('Shown as "Includes N tests":', 'ecare-health-services'); ?> <strong id="ecare-pkg-count"><?php echo (int) self::item_count($post->ID); ?></strong></p>

        <template id="ecare-pkg-item"><?php self::list_item(0, ''); ?></template>
        <script>
        (function () {
            var list = document.getElementById('ecare-pkg-list'), search = document.getElementById('ecare-pkg-search');
            var box = document.getElementById('ecare_lab_package'), extra = document.getElementById('ecare-pkg-extra');
            function count() {
                // Same rule as the server: blanks and case-insensitive repeats do not count.
                var seen = {};
                extra.value.split(/\n/).forEach(function (s) { s = s.trim().toLowerCase(); if (s) { seen[s] = true; } });
                document.getElementById('ecare-pkg-count').textContent = list.children.length + Object.keys(seen).length;
            }
            function add() {
                var opt = Array.prototype.find.call(document.querySelectorAll('#ecare-pkg-options option'), function (o) { return o.value === search.value; });
                if (!opt) { search.focus(); return; }
                var id = opt.dataset.id;
                if (!list.querySelector('input[value="' + id + '"]')) {
                    var li = document.getElementById('ecare-pkg-item').content.firstElementChild.cloneNode(true);
                    li.querySelector('input').value = id;
                    li.querySelector('span').textContent = opt.value;
                    list.appendChild(li);
                }
                search.value = '';
                count();
            }
            document.getElementById('ecare-pkg-add').addEventListener('click', add);
            search.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); add(); } });
            search.addEventListener('change', add);
            list.addEventListener('click', function (e) {
                var li = e.target.closest('li'); if (!li) { return; }
                if (e.target.classList.contains('ecare-pkg-remove')) { li.remove(); count(); }
                if (e.target.classList.contains('ecare-pkg-up') && li.previousElementSibling) { list.insertBefore(li, li.previousElementSibling); }
                if (e.target.classList.contains('ecare-pkg-down') && li.nextElementSibling) { list.insertBefore(li.nextElementSibling, li); }
            });
            extra.addEventListener('input', count);
            // Shown only while the test type is "Health package".
            function toggle() {
                var r = document.querySelector('input[name="_ecare_test_type"]:checked');
                if (box) { box.style.display = (r && r.value === 'package') ? '' : 'none'; }
            }
            document.querySelectorAll('input[name="_ecare_test_type"]').forEach(function (r) { r.addEventListener('change', toggle); });
            toggle();
        })();
        </script>
        <?php
    }

    private static function list_item($id, $label) {
        ?>
        <li>
            <input type="hidden" name="ecare_package_tests[]" value="<?php echo (int) $id; ?>" />
            <span><?php echo esc_html($label); ?></span>
            <button type="button" class="button button-small ecare-pkg-up" aria-label="<?php esc_attr_e('Move up', 'ecare-health-services'); ?>">↑</button>
            <button type="button" class="button button-small ecare-pkg-down" aria-label="<?php esc_attr_e('Move down', 'ecare-health-services'); ?>">↓</button>
            <button type="button" class="button-link button-link-delete ecare-pkg-remove"><?php esc_html_e('Remove', 'ecare-health-services'); ?></button>
        </li>
        <?php
    }
}
