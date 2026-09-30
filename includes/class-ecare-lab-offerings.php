<?php
defined('ABSPATH') || exit;

/**
 * Lab offerings: one row per (test, lab) with that lab's price.
 *
 * This is the Shukhee model - one "Dengue Antibody" test, and in the booking
 * modal each lab that does it with its own price. A test is available wherever
 * one of its labs is active and covers the patient's area.
 *
 *   mrp            list price (the struck-through number)
 *   price          what the patient pays; 0 or anything above mrp means mrp
 *   material_cost  "External Material Cost" on the checkout summary
 *   status         active | inactive
 *
 * A custom table rather than post meta: the catalogue has to ask "which tests
 * does any lab covering area X offer, and from what price" on every page, and
 * that is one indexed join here instead of unserialising meta on every test.
 */
class ECare_Lab_Offerings {

    const DB_VERSION = '1';
    const DB_OPTION  = 'ecare_lab_db_version';

    const STATUS_ACTIVE   = 'active';
    const STATUS_INACTIVE = 'inactive';

    public static function init() {
        add_action('init', array(__CLASS__, 'maybe_install'), 5);
        add_action('before_delete_post', array(__CLASS__, 'on_delete_post'));
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'ecare_lab_offerings';
    }

    // -----------------------------------------------------------------------
    // Schema
    // -----------------------------------------------------------------------

    /** Uploading a zip does not run activation, so the version is checked on load. */
    public static function maybe_install() {
        if (get_option(self::DB_OPTION) === self::DB_VERSION) {
            return;
        }
        self::install();
        update_option(self::DB_OPTION, self::DB_VERSION, false);
    }

    public static function install() {
        global $wpdb;
        $table   = self::table();
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            test_id BIGINT UNSIGNED NOT NULL,
            provider_id BIGINT UNSIGNED NOT NULL,
            mrp DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            material_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY test_provider (test_id,provider_id),
            KEY provider_id (provider_id),
            KEY status (status)
        ) {$charset};");
    }

    // -----------------------------------------------------------------------
    // Money
    // -----------------------------------------------------------------------

    /** What the patient pays for one row. */
    public static function effective_price($row) {
        $mrp   = (float) $row->mrp;
        $price = (float) $row->price;
        return ($price > 0 && $price <= $mrp) ? $price : $mrp;
    }

    /** "You save": MRP minus what is paid. */
    public static function savings($row) {
        return round(max(0, (float) $row->mrp - self::effective_price($row)), 2);
    }

    /** Whole-number percent off MRP, 0 when there is no discount. */
    public static function discount_percent($row) {
        $mrp = (float) $row->mrp;
        if ($mrp <= 0) {
            return 0;
        }
        return (int) round(($mrp - self::effective_price($row)) / $mrp * 100);
    }

    // -----------------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------------

    /** All rows for a test, whatever their status. */
    public static function for_test($test_id) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE test_id = %d ORDER BY id ASC',
            (int) $test_id
        ));
        return is_array($rows) ? $rows : array();
    }

    /**
     * Rows a patient can book: the row is active and its lab is published and
     * active. Cheapest first.
     */
    public static function available_for_test($test_id) {
        $out = array();
        foreach (self::for_test($test_id) as $row) {
            if ($row->status === self::STATUS_ACTIVE && ECare_Lab_Providers::is_active((int) $row->provider_id)) {
                $out[] = $row;
            }
        }
        usort($out, function ($a, $b) {
            return self::effective_price($a) <=> self::effective_price($b);
        });
        return $out;
    }

    /** Cheapest bookable row, or null. */
    public static function cheapest($test_id) {
        $rows = self::available_for_test($test_id);
        return $rows ? $rows[0] : null;
    }

    public static function has_any($test_id) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::table() . ' WHERE test_id = %d', (int) $test_id)) > 0;
    }

    // -----------------------------------------------------------------------
    // Cleaning and saving
    // -----------------------------------------------------------------------

    /**
     * Posted rows -> clean rows. Drops rows without a real lab or without an
     * MRP; a price that is empty, negative or above MRP becomes MRP; the second
     * row for the same lab is dropped.
     *
     * @param array $posted list of array(provider_id, mrp, price, material_cost, active)
     * @return array list of clean arrays
     */
    public static function clean_rows($posted) {
        $out  = array();
        $seen = array();
        foreach ((array) $posted as $r) {
            $r   = (array) $r;
            $pid = (int) ($r['provider_id'] ?? 0);
            $p   = $pid ? get_post($pid) : null;
            if (!$p || $p->post_type !== ECare_Lab_Providers::POST_TYPE || isset($seen[$pid])) {
                continue;
            }
            $mrp = round(max(0, (float) ($r['mrp'] ?? 0)), 2);
            if ($mrp <= 0) {
                continue;
            }
            $price = round((float) ($r['price'] ?? 0), 2);
            if ($price <= 0 || $price > $mrp) {
                $price = $mrp;
            }
            $seen[$pid] = true;
            $out[] = array(
                'provider_id'   => $pid,
                'mrp'           => $mrp,
                'price'         => $price,
                'material_cost' => round(max(0, (float) ($r['material_cost'] ?? 0)), 2),
                'status'        => !empty($r['active']) ? self::STATUS_ACTIVE : self::STATUS_INACTIVE,
            );
        }
        return $out;
    }

    /**
     * Make the test's rows exactly $rows: update the labs that stay, insert
     * the new ones, delete the ones that were removed.
     */
    public static function replace_for_test($test_id, $rows) {
        global $wpdb;
        $test_id  = (int) $test_id;
        $table    = self::table();
        $existing = array();
        foreach (self::for_test($test_id) as $row) {
            $existing[(int) $row->provider_id] = (int) $row->id;
        }
        $keep = array();
        foreach ($rows as $r) {
            $data = array(
                'mrp'           => $r['mrp'],
                'price'         => $r['price'],
                'material_cost' => $r['material_cost'],
                'status'        => $r['status'],
            );
            if (isset($existing[$r['provider_id']])) {
                $wpdb->update($table, $data, array('id' => $existing[$r['provider_id']]), array('%f', '%f', '%f', '%s'), array('%d'));
            } else {
                $wpdb->insert($table, array('test_id' => $test_id, 'provider_id' => $r['provider_id']) + $data, array('%d', '%d', '%f', '%f', '%f', '%s'));
            }
            $keep[$r['provider_id']] = true;
        }
        foreach ($existing as $pid => $row_id) {
            if (!isset($keep[$pid])) {
                $wpdb->delete($table, array('id' => $row_id), array('%d'));
            }
        }
    }

    /** Called from ECare_CPT::save_meta_boxes, after its nonce check. */
    public static function save_from_post($test_id) {
        if (wp_is_post_revision($test_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
            return;
        }
        if (!isset($_POST['ecare_offer_present'])) {
            return;   // the table was not on the form; leave the rows alone
        }
        self::replace_for_test($test_id, self::clean_rows(self::posted_rows()));
    }

    /** The table's parallel arrays, zipped into rows. */
    public static function posted_rows() {
        $in   = wp_unslash($_POST);
        $pids = (array) ($in['ecare_offer_provider'] ?? array());
        $rows = array();
        foreach (array_values($pids) as $i => $pid) {
            $rows[] = array(
                'provider_id'   => $pid,
                'mrp'           => array_values((array) ($in['ecare_offer_mrp'] ?? array()))[$i] ?? 0,
                'price'         => array_values((array) ($in['ecare_offer_price'] ?? array()))[$i] ?? 0,
                'material_cost' => array_values((array) ($in['ecare_offer_material'] ?? array()))[$i] ?? 0,
                'active'        => (array_values((array) ($in['ecare_offer_active'] ?? array()))[$i] ?? '0') === '1',
            );
        }
        return $rows;
    }

    public static function on_delete_post($post_id) {
        $type = get_post_type($post_id);
        global $wpdb;
        if ($type === 'ecare_lab_test') {
            $wpdb->delete(self::table(), array('test_id' => (int) $post_id), array('%d'));
        } elseif ($type === ECare_Lab_Providers::POST_TYPE) {
            $wpdb->delete(self::table(), array('provider_id' => (int) $post_id), array('%d'));
        }
    }

    // -----------------------------------------------------------------------
    // Edit screen
    // -----------------------------------------------------------------------

    public static function render_box($post) {
        $rows = self::for_test($post->ID);

        // A test linked to one provider by the earlier version of this screen
        // starts with that provider and its price, so one save converts it.
        if (!$rows) {
            $old_pid = (int) get_post_meta($post->ID, '_ecare_provider_id', true);
            $old_p   = $old_pid ? get_post($old_pid) : null;
            if ($old_p && $old_p->post_type === ECare_Lab_Providers::POST_TYPE) {
                $mrp  = (float) get_post_meta($post->ID, '_price', true);
                $rows = array((object) array('provider_id' => $old_pid, 'mrp' => $mrp, 'price' => $mrp, 'material_cost' => 0, 'status' => self::STATUS_ACTIVE));
            }
        }

        $providers = get_posts(array(
            'post_type'      => ECare_Lab_Providers::POST_TYPE,
            'post_status'    => array('publish', 'draft', 'private'),
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ));
        $is_package = ECare_Lab_Test_Info::is_package($post->ID) && ECare_Lab_Packages::included_test_ids($post->ID);
        $options    = array();
        foreach ($providers as $p) {
            $flags = array();
            if ($p->post_status !== 'publish') { $flags[] = __('draft', 'ecare-health-services'); }
            if (get_post_meta($p->ID, '_ecare_provider_status', true) === ECare_Lab_Providers::STATUS_INACTIVE) { $flags[] = __('inactive', 'ecare-health-services'); }
            $reach = ECare_Lab_Providers::coverage_summary($p->ID);
            if ($is_package) {
                // What this lab would charge for the included tests one by one,
                // as a guide for the package MRP.
                list($sum, $missing) = ECare_Lab_Packages::lab_sum($post->ID, $p->ID);
                $hint = sprintf(__('Tests here one by one: ৳%s', 'ecare-health-services'), number_format_i18n($sum, 0));
                if ($missing) {
                    $hint .= ' ' . sprintf(_n('(%d test not offered by this lab)', '(%d tests not offered by this lab)', $missing, 'ecare-health-services'), $missing);
                }
                $reach .= ($reach !== '' ? ' — ' : '') . $hint;
            }
            $options[$p->ID] = array(
                'label' => $p->post_title . ($flags ? ' (' . implode(', ', $flags) . ')' : ''),
                'reach' => $reach,
            );
        }
        ?>
        <input type="hidden" name="ecare_offer_present" value="1" />
        <style>
            #ecare-offers{width:100%;border-collapse:collapse}
            #ecare-offers th{text-align:left;font-weight:600;padding:6px 8px;border-bottom:1px solid #dcdcde}
            #ecare-offers td{padding:6px 8px;vertical-align:top;border-bottom:1px solid #f0f0f1}
            #ecare-offers input[type=number]{width:100px}
            #ecare-offers select{max-width:260px}
            #ecare-offers .ecare-offer-reach{display:block;color:#646970;font-size:12px;margin-top:3px}
            #ecare-offers .ecare-offer-off{font-weight:700;color:#00a32a}
        </style>
        <table id="ecare-offers">
            <thead>
                <tr>
                    <th><?php esc_html_e('Lab', 'ecare-health-services'); ?></th>
                    <th><?php esc_html_e('MRP (৳)', 'ecare-health-services'); ?></th>
                    <th><?php esc_html_e('Price (৳)', 'ecare-health-services'); ?></th>
                    <th><?php esc_html_e('Material cost (৳)', 'ecare-health-services'); ?></th>
                    <th><?php esc_html_e('Active', 'ecare-health-services'); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row) { self::render_row($row, $options); } ?>
            </tbody>
        </table>
        <template id="ecare-offer-template"><?php self::render_row(null, $options); ?></template>
        <p>
            <button type="button" class="button" id="ecare-offer-add">+ <?php esc_html_e('Add lab', 'ecare-health-services'); ?></button>
            <a href="<?php echo esc_url(admin_url(ECare_Lab_Providers::menu_slug())); ?>" target="_blank" style="margin-left:8px;"><?php esc_html_e('Manage labs', 'ecare-health-services'); ?></a>
        </p>
        <p class="description"><?php esc_html_e('Each lab that does this test, with its own price. Leave Price empty for no discount. The test is offered wherever an active lab here covers the patient\'s area.', 'ecare-health-services'); ?></p>
        <script>
        (function () {
            var body  = document.querySelector('#ecare-offers tbody');
            var reach = <?php echo wp_json_encode(array_map(function ($o) { return $o['reach']; }, $options)); ?>;
            var noCov = <?php echo wp_json_encode(__('No coverage yet', 'ecare-health-services')); ?>;
            function refresh(tr) {
                var sel = tr.querySelector('select'), mrp = parseFloat(tr.querySelector('.ecare-offer-mrp').value) || 0;
                var price = parseFloat(tr.querySelector('.ecare-offer-price').value) || 0;
                var off = (mrp > 0 && price > 0 && price < mrp) ? Math.round((mrp - price) / mrp * 100) : 0;
                tr.querySelector('.ecare-offer-off').textContent = off ? off + '% OFF' : '';
                tr.querySelector('.ecare-offer-reach').textContent = sel.value ? (reach[sel.value] || noCov) : '';
                // One row per lab: a lab already used elsewhere is not offered again.
                var used = {};
                body.querySelectorAll('select').forEach(function (s) { if (s.value) { used[s.value] = (used[s.value] || 0) + 1; } });
                body.querySelectorAll('select').forEach(function (s) {
                    s.querySelectorAll('option').forEach(function (o) { o.disabled = !!o.value && o.value !== s.value && !!used[o.value]; });
                });
            }
            function sync(tr) {
                // An unticked checkbox posts nothing; the hidden twin keeps the arrays aligned.
                tr.querySelector('.ecare-offer-active-val').value = tr.querySelector('.ecare-offer-active').checked ? '1' : '0';
            }
            document.getElementById('ecare-offer-add').addEventListener('click', function () {
                body.appendChild(document.getElementById('ecare-offer-template').content.cloneNode(true));
                refresh(body.lastElementChild);
            });
            body.addEventListener('input', function (e) { var tr = e.target.closest('tr'); if (tr) { refresh(tr); } });
            body.addEventListener('change', function (e) { var tr = e.target.closest('tr'); if (tr) { sync(tr); refresh(tr); } });
            body.addEventListener('click', function (e) {
                if (e.target.classList.contains('ecare-offer-remove')) {
                    e.target.closest('tr').remove();
                    var any = body.querySelector('tr'); if (any) { refresh(any); }
                }
            });
            body.querySelectorAll('tr').forEach(refresh);
        })();
        </script>
        <?php
    }

    private static function render_row($row, $options) {
        $pid    = $row ? (int) $row->provider_id : 0;
        $active = !$row || $row->status === self::STATUS_ACTIVE;
        $mrp    = $row ? (float) $row->mrp : 0;
        $price  = $row ? self::effective_price($row) : 0;
        ?>
        <tr>
            <td>
                <select name="ecare_offer_provider[]">
                    <option value=""><?php esc_html_e('— Select lab —', 'ecare-health-services'); ?></option>
                    <?php foreach ($options as $id => $o): ?>
                        <option value="<?php echo (int) $id; ?>" <?php selected($pid, $id); ?>><?php echo esc_html($o['label']); ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="ecare-offer-reach"></span>
            </td>
            <td><input type="number" step="0.01" min="0" class="ecare-offer-mrp" name="ecare_offer_mrp[]" value="<?php echo $mrp > 0 ? esc_attr($mrp) : ''; ?>" /></td>
            <td>
                <input type="number" step="0.01" min="0" class="ecare-offer-price" name="ecare_offer_price[]" value="<?php echo ($price > 0 && $price < $mrp) ? esc_attr($price) : ''; ?>" />
                <span class="ecare-offer-off"></span>
            </td>
            <td><input type="number" step="0.01" min="0" name="ecare_offer_material[]" value="<?php echo ($row && (float) $row->material_cost > 0) ? esc_attr((float) $row->material_cost) : ''; ?>" /></td>
            <td>
                <input type="checkbox" class="ecare-offer-active" <?php checked($active); ?> />
                <input type="hidden" class="ecare-offer-active-val" name="ecare_offer_active[]" value="<?php echo $active ? '1' : '0'; ?>" />
            </td>
            <td><button type="button" class="button-link button-link-delete ecare-offer-remove"><?php esc_html_e('Remove', 'ecare-health-services'); ?></button></td>
        </tr>
        <?php
    }
}
