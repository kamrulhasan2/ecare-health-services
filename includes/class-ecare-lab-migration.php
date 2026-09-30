<?php
defined('ABSPATH') || exit;

/**
 * Moves lab tests from the old typed-in lists to the new structure.
 *
 * Old: every test carried comma lists - _lab_provider, _division, _district,
 * _area - plus _price and a free-text _test_category, and the same test was
 * entered once per provider or location.
 *
 * New: one test per name, one offering (price) per lab, labs with area
 * coverage, categories as terms.
 *
 * Three phases, so it can be run on the live site after upload:
 *   plan()  - pure: reads a snapshot, returns what would happen. Nothing written.
 *   apply() - carries out a plan and records every change in a log.
 *   undo()  - reverses the logged changes.
 *
 * Nothing is deleted. Duplicate tests are set to draft (with _ecare_merged_into)
 * and each touched test keeps its old meta in _ecare_legacy_backup.
 */
class ECare_Lab_Migration {

    const LOG_OPTION = 'ecare_lab_migration_log';
    /** One-time token: an Apply or Undo form works once, so a replayed POST cannot re-run it. */
    const TOKEN_OPTION = 'ecare_lab_migration_token';
    const PAGE       = 'ecare-lab-migration';

    /** Old and alternative spellings of built-in divisions and districts. */
    const ALIASES = array(
        'chittagong' => 'Chattogram', 'ctg' => 'Chattogram', 'comilla' => 'Cumilla',
        'barisal' => 'Barishal', 'jessore' => 'Jashore', 'bogra' => 'Bogura',
        'coxsbazaar' => "Cox's Bazar", 'chapainawabgonj' => 'Chapainawabganj', 'nawabganj' => 'Chapainawabganj',
        'moulvibazaar' => 'Moulvibazar', 'maulvibazar' => 'Moulvibazar', 'jhalakathi' => 'Jhalokati', 'jhalokathi' => 'Jhalokati',
        'netrakona' => 'Netrokona', 'brahmanbaria' => 'Brahmanbaria', 'lakshmipur' => 'Lakshmipur', 'laxmipur' => 'Lakshmipur',
        'khagrachari' => 'Khagrachhari', 'kishorganj' => 'Kishoreganj', 'narsinghdi' => 'Narsingdi',
    );

    public static function init() {
        if (is_admin()) {
            add_action('admin_post_ecare_lab_migrate', array(__CLASS__, 'handle_post'));
        }
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            WP_CLI::add_command('ecare lab-migrate', array(__CLASS__, 'cli'));
        }
    }

    // =======================================================================
    // Helpers
    // =======================================================================

    private static function key($s) {
        return ECare_Locations::name_key($s);
    }

    /** Split a comma list, tidy each name, drop blanks. */
    public static function split($list) {
        $out = array();
        foreach (explode(',', (string) $list) as $part) {
            $part = ECare_Locations::clean_name($part);
            if ($part !== '') {
                $out[] = $part;
            }
        }
        return $out;
    }

    /**
     * Closest candidate by edit distance on name keys, when it is close
     * enough to be a typo (at most 2 edits, names of 4+ letters) and there is
     * exactly one such candidate.
     *
     * @param string   $name
     * @param string[] $candidates display names
     * @return string|null
     */
    public static function suggest($name, $candidates) {
        $k = self::key($name);
        if (strlen($k) < 4) {
            return null;
        }
        $best = array();
        $min  = 3;
        foreach ($candidates as $c) {
            $ck = self::key($c);
            if ($ck === $k || $ck === '') {
                continue;
            }
            // "Mirpur" and "Mirpur-1" are different places, not a typo.
            if (strpos($ck, $k) === 0 || strpos($k, $ck) === 0) {
                continue;
            }
            $d = levenshtein($k, $ck);
            if ($d > 2) {
                continue;
            }
            if ($d < $min) {
                $min  = $d;
                $best = array($c);
            } elseif ($d === $min) {
                $best[] = $c;
            }
        }
        return count(array_unique($best)) === 1 ? $best[0] : null;
    }

    /** "Rangupr => Rangpur" lines -> key => replacement. */
    public static function parse_map($text) {
        $out = array();
        foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $line) {
            if (strpos($line, '=>') === false) {
                continue;
            }
            list($from, $to) = array_map(array('ECare_Locations', 'clean_name'), explode('=>', $line, 2));
            if ($from !== '' && $to !== '') {
                $out[self::key($from)] = $to;
            }
        }
        return $out;
    }

    // =======================================================================
    // Snapshot of the site, for plan()
    // =======================================================================

    /** Everything plan() needs, read from the database. */
    public static function snapshot() {
        $tests = array();
        foreach (get_posts(array(
            'post_type'      => 'ecare_lab_test',
            'post_status'    => array('publish', 'draft', 'pending', 'private'),
            'posts_per_page' => -1,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        )) as $p) {
            if (ECare_Lab_Offerings::has_any($p->ID)) {
                continue;   // already on the new structure
            }
            $m = function ($k) use ($p) { return (string) get_post_meta($p->ID, $k, true); };
            if (trim($m('_lab_provider') . $m('_area') . $m('_district') . $m('_division')) === '') {
                continue;   // nothing to migrate
            }
            $tests[] = array(
                'id' => (int) $p->ID, 'title' => $p->post_title, 'status' => $p->post_status,
                'provider' => $m('_lab_provider'), 'division' => $m('_division'), 'district' => $m('_district'),
                'area' => $m('_area'), 'price' => $m('_price'), 'category' => $m('_test_category'),
            );
        }

        $providers = array();
        foreach (get_posts(array('post_type' => ECare_Lab_Providers::POST_TYPE, 'post_status' => array('publish', 'draft', 'private'), 'posts_per_page' => -1)) as $p) {
            $providers[(int) $p->ID] = $p->post_title;
        }

        $locations = array();
        foreach (get_terms(array('taxonomy' => ECare_Locations::TAXONOMY, 'hide_empty' => false)) as $t) {
            $locations[(int) $t->term_id] = array('name' => $t->name, 'parent' => (int) $t->parent, 'level' => ECare_Locations::get_level($t->term_id));
        }

        $categories = array();
        $cats = get_terms(array('taxonomy' => ECare_Lab_Taxonomies::CATEGORY, 'hide_empty' => false));
        if (!is_wp_error($cats)) {
            foreach ($cats as $t) {
                $categories[(int) $t->term_id] = $t->name;
            }
        }

        return compact('tests', 'providers', 'locations', 'categories');
    }

    // =======================================================================
    // plan() - pure
    // =======================================================================

    /**
     * @param array $snap        from snapshot()
     * @param array $corrections key => replacement name (any provider, place or category)
     * @param array $area_homes  area key => district name, for areas whose district is ambiguous
     * @return array the plan (see keys at the end)
     */
    public static function plan($snap, $corrections = array(), $area_homes = array()) {
        $fix = function ($name) use ($corrections) {
            $k = self::key($name);
            return isset($corrections[$k]) ? $corrections[$k] : $name;
        };

        // --- indexes -------------------------------------------------------
        $div_by_key = $dist_by_key = $areas_by_district = array();
        $div_names  = $dist_names  = array();
        foreach ($snap['locations'] as $id => $l) {
            if ($l['level'] === 'division') { $div_by_key[self::key($l['name'])] = $id; $div_names[] = $l['name']; }
            if ($l['level'] === 'district') { $dist_by_key[self::key($l['name'])] = $id; $dist_names[] = $l['name']; }
            if ($l['level'] === 'area')     { $areas_by_district[$l['parent']][self::key($l['name'])] = $id; }
        }
        $resolve_place = function ($name, $by_key, $names) use ($fix) {
            $name = $fix($name);
            $k    = self::key($name);
            if (isset($by_key[$k])) {
                return array($by_key[$k], null);
            }
            if (isset(self::ALIASES[$k]) && isset($by_key[self::key(self::ALIASES[$k])])) {
                return array($by_key[self::key(self::ALIASES[$k])], null);
            }
            return array(0, self::suggest($name, $names));
        };

        $prov_by_key = array();
        foreach ($snap['providers'] as $id => $name) {
            $prov_by_key[self::key($name)] = $id;
        }
        $cat_by_key = array();
        foreach ($snap['categories'] as $id => $name) {
            $cat_by_key[self::key($name)] = $id;
        }

        $providers   = array();   // key => [name, id|0, suggestion|null]
        $new_areas   = array();   // "district_id|key" => [district_id, name]
        $coverage    = array();   // provider key => [area ref => true]
        $issues      = array();
        $suggestions = array();   // key => [from, to]
        $groups      = array();   // title key => [...]

        foreach ($snap['tests'] as $t) {
            $label = '#' . $t['id'] . ' ' . $t['title'];

            // --- places ----------------------------------------------------
            $districts = array();
            foreach (self::split($t['district']) as $name) {
                list($id, $sugg) = $resolve_place($name, $dist_by_key, $dist_names);
                if ($id) {
                    $districts[$id] = true;
                } else {
                    $issues[] = array('place', sprintf('%s: district "%s" is not a known district', $label, $name) . ($sugg ? sprintf(' - did you mean "%s"?', $sugg) : ''));
                    if ($sugg) { $suggestions[self::key($name)] = array($name, $sugg); }
                }
            }
            foreach (self::split($t['division']) as $name) {
                list($id, $sugg) = $resolve_place($name, $div_by_key, $div_names);
                if (!$id) {
                    $issues[] = array('place', sprintf('%s: division "%s" is not a known division', $label, $name) . ($sugg ? sprintf(' - did you mean "%s"?', $sugg) : ''));
                    if ($sugg) { $suggestions[self::key($name)] = array($name, $sugg); }
                }
            }
            $districts = array_keys($districts);

            $area_refs = array();
            foreach (self::split($t['area']) as $raw) {
                $name = $fix($raw);
                $k    = self::key($name);
                // The admin may have written the area as it was typed or as corrected.
                $hk   = isset($area_homes[$k]) ? $k : self::key($raw);
                $home = 0;
                if (isset($area_homes[$hk])) {
                    list($home) = $resolve_place($area_homes[$hk], $dist_by_key, $dist_names);
                }
                // An existing area in one of the listed districts wins.
                $found = 0;
                foreach ($home ? array($home) : $districts as $d) {
                    if (isset($areas_by_district[$d][$k])) { $found = $areas_by_district[$d][$k]; break; }
                }
                if ($found) {
                    $area_refs['id:' . $found] = true;
                    continue;
                }
                // A near-miss of an existing area is a likely typo.
                $near = array();
                foreach ($home ? array($home) : $districts as $d) {
                    foreach ($areas_by_district[$d] ?? array() as $ak => $aid) {
                        $near[] = $snap['locations'][$aid]['name'];
                    }
                }
                $sugg = self::suggest($name, $near);
                if ($sugg) {
                    $suggestions[$k] = array($raw, $sugg);
                    $issues[] = array('place', sprintf('%s: area "%s" looks like "%s" - add a correction to use it', $label, $raw, $sugg));
                }
                $target = $home ?: (count($districts) === 1 ? $districts[0] : 0);
                if (!$target) {
                    $issues[] = array('area', sprintf('%s: area "%s" - which district? The test lists %d districts; add "%s => District" under area districts', $label, $raw, count($districts), $raw));
                    continue;
                }
                if ($sugg) {
                    continue;   // wait for the admin to confirm, rather than create a typo
                }
                $new_areas[$target . '|' . $k] = array($target, $name, $snap['locations'][$target]['name'] ?? '');
                $area_refs['new:' . $target . '|' . $k] = true;
            }

            // --- providers -------------------------------------------------
            $pkeys = array();
            foreach (self::split($t['provider']) as $raw) {
                $name = $fix($raw);
                $k    = self::key($name);
                if (!isset($providers[$k])) {
                    $sugg = isset($prov_by_key[$k]) ? null : self::suggest($name, array_values($snap['providers']));
                    $providers[$k] = array($name, $prov_by_key[$k] ?? 0, $sugg);
                    if ($sugg) {
                        $suggestions[$k] = array($raw, $sugg);
                        $issues[] = array('provider', sprintf('Provider "%s" is new, but looks like existing "%s" - add a correction to use it', $name, $sugg));
                    }
                }
                $pkeys[] = $k;
                foreach ($area_refs as $ref => $_) {
                    $coverage[$k][$ref] = true;
                }
            }
            if (!$pkeys) {
                $issues[] = array('test', sprintf('%s: no provider - no price can be created for it', $label));
            }

            // --- category --------------------------------------------------
            $cats = array();
            foreach (self::split($t['category']) as $raw) {
                $k = self::key($fix($raw));
                if (isset($cat_by_key[$k])) {
                    $cats[] = $cat_by_key[$k];
                } else {
                    $sugg = self::suggest($raw, array_values($snap['categories']));
                    $issues[] = array('category', sprintf('%s: category "%s" matches no category - left as it is', $label, $raw) . ($sugg ? sprintf(' (did you mean "%s"?)', $sugg) : ''));
                    if ($sugg) { $suggestions[self::key($raw)] = array($raw, $sugg); }
                }
            }

            // --- group by test name ----------------------------------------
            $gk = self::key($t['title']);
            if (!isset($groups[$gk])) {
                $groups[$gk] = array('title' => $t['title'], 'master' => 0, 'members' => array(), 'offers' => array(), 'categories' => array());
            }
            $g =& $groups[$gk];
            $g['members'][] = array('id' => $t['id'], 'status' => $t['status']);
            // The master is the first published test, else the first one.
            if (!$g['master'] || ($t['status'] === 'publish' && self::status_of($g, $g['master']) !== 'publish')) {
                $g['master'] = $t['id'];
            }
            $price = (float) $t['price'];
            foreach ($pkeys as $pk) {
                if ($price <= 0) {
                    $issues[] = array('test', sprintf('%s: no price for %s - that lab is skipped', $label, $providers[$pk][0]));
                    continue;
                }
                if (isset($g['offers'][$pk]) && abs($g['offers'][$pk] - $price) > 0.001) {
                    $issues[] = array('conflict', sprintf('"%s" at %s has two prices (%s and %s) - the lower is used', $t['title'], $providers[$pk][0], $g['offers'][$pk], $price));
                    $g['offers'][$pk] = min($g['offers'][$pk], $price);
                } else {
                    $g['offers'][$pk] = $price;
                }
            }
            $g['categories'] = array_values(array_unique(array_merge($g['categories'], $cats)));
            unset($g);
        }

        foreach ($providers as $k => $p) {
            if (empty($coverage[$k])) {
                $issues[] = array('provider', sprintf('Provider "%s" gets no areas - it will not be bookable until coverage is added', $p[0]));
            }
        }

        return array(
            'providers'   => $providers,
            'new_areas'   => $new_areas,
            'coverage'    => array_map('array_keys', $coverage),
            'groups'      => $groups,
            'issues'      => $issues,
            'suggestions' => $suggestions,
            'summary'     => array(
                'tests'          => count($snap['tests']),
                'merged_tests'   => count($groups),
                'drafted'        => array_sum(array_map(function ($g) { return count($g['members']) - 1; }, $groups)),
                'new_providers'  => count(array_filter($providers, function ($p) { return !$p[1]; })),
                'new_areas'      => count($new_areas),
                'offerings'      => array_sum(array_map(function ($g) { return count($g['offers']); }, $groups)),
                'issues'         => count($issues),
            ),
        );
    }

    private static function status_of($group, $id) {
        foreach ($group['members'] as $m) {
            if ($m['id'] === $id) {
                return $m['status'];
            }
        }
        return '';
    }

    // =======================================================================
    // apply() and undo()
    // =======================================================================

    /** Carry out a plan. Returns the log, which is also stored for undo(). */
    public static function apply($plan) {
        global $wpdb;
        $log = array('time' => current_time('mysql'), 'providers' => array(), 'coverage' => array(), 'areas' => array(),
                     'offerings' => array(), 'drafted' => array(), 'backups' => array(), 'categories' => array());

        // 1. Providers
        $pid = array();
        foreach ($plan['providers'] as $k => $p) {
            if ($p[1]) {
                $pid[$k] = (int) $p[1];
                continue;
            }
            $id = wp_insert_post(array('post_type' => ECare_Lab_Providers::POST_TYPE, 'post_status' => 'publish', 'post_title' => $p[0]), true);
            if (is_wp_error($id) || !$id) {
                continue;
            }
            update_post_meta($id, '_ecare_provider_status', ECare_Lab_Providers::STATUS_ACTIVE);
            $pid[$k] = (int) $id;
            $log['providers'][] = (int) $id;
        }

        // 2. Areas
        $area_id = array();
        foreach ($plan['new_areas'] as $ref => $a) {
            $r = wp_insert_term($a[1], ECare_Locations::TAXONOMY, array('parent' => (int) $a[0]));
            if (is_wp_error($r)) {
                $existing = ECare_Locations::find_sibling($a[1], (int) $a[0]);
                if ($existing) { $area_id[$ref] = $existing; }
                continue;
            }
            $area_id[$ref] = (int) $r['term_id'];
            $log['areas'][] = (int) $r['term_id'];
        }

        // 3. Coverage (added to whatever the provider already has)
        foreach ($plan['coverage'] as $k => $refs) {
            if (empty($pid[$k])) {
                continue;
            }
            $before = ECare_Lab_Providers::coverage_term_ids($pid[$k]);
            $add    = array();
            foreach ($refs as $ref) {
                if (strpos($ref, 'id:') === 0) { $add[] = (int) substr($ref, 3); }
                elseif (isset($area_id[substr($ref, 4)])) { $add[] = $area_id[substr($ref, 4)]; }
            }
            $after = ECare_Lab_Providers::normalize_coverage(array_merge($before, $add));
            if ($after != $before) {
                wp_set_object_terms($pid[$k], $after, ECare_Locations::TAXONOMY, false);
                $log['coverage'][$pid[$k]] = $before;
            }
        }

        // 4. Tests
        $table = ECare_Lab_Offerings::table();
        foreach ($plan['groups'] as $g) {
            foreach ($g['members'] as $m) {
                $backup = array();
                foreach (array('_lab_provider', '_division', '_district', '_area', '_price', '_test_category') as $key) {
                    $backup[$key] = get_post_meta($m['id'], $key, true);
                }
                update_post_meta($m['id'], '_ecare_legacy_backup', $backup);
                $log['backups'][] = $m['id'];

                if ($m['id'] !== $g['master']) {
                    wp_update_post(array('ID' => $m['id'], 'post_status' => 'draft'));
                    update_post_meta($m['id'], '_ecare_merged_into', $g['master']);
                    $log['drafted'][$m['id']] = $m['status'];
                }
            }
            foreach ($g['offers'] as $k => $price) {
                if (empty($pid[$k])) {
                    continue;
                }
                $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE test_id = %d AND provider_id = %d", $g['master'], $pid[$k]));
                if ($exists) {
                    continue;
                }
                $wpdb->insert($table, array('test_id' => $g['master'], 'provider_id' => $pid[$k], 'mrp' => $price, 'price' => $price, 'material_cost' => 0, 'status' => 'active'),
                              array('%d', '%d', '%f', '%f', '%f', '%s'));
                if ($wpdb->insert_id) {
                    $log['offerings'][] = (int) $wpdb->insert_id;
                }
            }
            if ($g['categories']) {
                $log['categories'][$g['master']] = wp_get_object_terms($g['master'], ECare_Lab_Taxonomies::CATEGORY, array('fields' => 'ids'));
                wp_set_object_terms($g['master'], array_map('intval', $g['categories']), ECare_Lab_Taxonomies::CATEGORY, true);
            }
            ECare_Lab_Tests::sync_legacy($g['master']);
        }

        update_option(self::LOG_OPTION, $log, false);
        do_action('ecare_lab_offerings_changed', 0);
        return $log;
    }

    /** Reverse the last apply(). */
    public static function undo() {
        global $wpdb;
        $log = get_option(self::LOG_OPTION);
        if (!is_array($log)) {
            return false;
        }
        foreach ($log['offerings'] as $id) {
            $wpdb->delete(ECare_Lab_Offerings::table(), array('id' => (int) $id), array('%d'));
        }
        foreach ($log['backups'] as $test_id) {
            $b = get_post_meta($test_id, '_ecare_legacy_backup', true);
            if (is_array($b)) {
                foreach ($b as $k => $v) {
                    update_post_meta($test_id, $k, $v);
                }
            }
            delete_post_meta($test_id, '_ecare_legacy_backup');
        }
        foreach ($log['drafted'] as $test_id => $status) {
            wp_update_post(array('ID' => (int) $test_id, 'post_status' => $status));
            delete_post_meta($test_id, '_ecare_merged_into');
        }
        foreach ($log['categories'] as $test_id => $before) {
            wp_set_object_terms((int) $test_id, array_map('intval', (array) $before), ECare_Lab_Taxonomies::CATEGORY, false);
        }
        foreach ($log['coverage'] as $provider_id => $before) {
            wp_set_object_terms((int) $provider_id, array_map('intval', (array) $before), ECare_Locations::TAXONOMY, false);
        }
        foreach ($log['providers'] as $id) {
            wp_delete_post((int) $id, true);
        }
        foreach ($log['areas'] as $id) {
            wp_delete_term((int) $id, ECare_Locations::TAXONOMY);
        }
        delete_option(self::LOG_OPTION);
        do_action('ecare_lab_offerings_changed', 0);
        return true;
    }

    // =======================================================================
    // Admin page
    // =======================================================================

    /**
     * True once for the token on the current page, then never again: the
     * stored token is spent whether or not it matched.
     */
    public static function consume_token($given) {
        $token = (string) get_option(self::TOKEN_OPTION, '');
        delete_option(self::TOKEN_OPTION);
        return $token !== '' && hash_equals($token, (string) $given);
    }

    public static function handle_post() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Not allowed.', 'ecare-health-services'), 403);
        }
        check_admin_referer('ecare_lab_migrate');
        $in   = wp_unslash($_POST);
        $back = admin_url('admin.php?page=' . self::PAGE);
        update_option('ecare_lab_migration_input', array(
            'corrections' => sanitize_textarea_field($in['corrections'] ?? ''),
            'area_homes'  => sanitize_textarea_field($in['area_homes'] ?? ''),
        ), false);

        $action = sanitize_key($in['do'] ?? 'scan');
        if (in_array($action, array('apply', 'undo'), true)) {
            // A form that was already used (back button, reload, a resubmitted
            // request) carries a spent token and is turned away. Without this,
            // replaying an Apply after an Undo would quietly apply it again.
            if (!self::consume_token((string) ($in['run_token'] ?? ''))) {
                wp_safe_redirect(add_query_arg('ecare_msg', 'stale', $back));
                exit;
            }
        }
        if ($action === 'apply') {
            if (empty($in['have_backup'])) {
                wp_safe_redirect(add_query_arg('ecare_msg', 'need_backup', $back));
                exit;
            }
            if (get_option(self::LOG_OPTION)) {
                wp_safe_redirect(add_query_arg('ecare_msg', 'already', $back));
                exit;
            }
            $inp = get_option('ecare_lab_migration_input');
            self::apply(self::plan(self::snapshot(), self::parse_map($inp['corrections']), self::parse_map($inp['area_homes'])));
            wp_safe_redirect(add_query_arg('ecare_msg', 'applied', $back));
            exit;
        }
        if ($action === 'undo') {
            self::undo();
            wp_safe_redirect(add_query_arg('ecare_msg', 'undone', $back));
            exit;
        }
        wp_safe_redirect(add_query_arg('ecare_scan', '1', $back));
        exit;
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $inp  = get_option('ecare_lab_migration_input', array('corrections' => '', 'area_homes' => ''));
        $log  = get_option(self::LOG_OPTION);
        $plan = self::plan(self::snapshot(), self::parse_map($inp['corrections'] ?? ''), self::parse_map($inp['area_homes'] ?? ''));
        $msg  = sanitize_key($_GET['ecare_msg'] ?? '');

        // Suggestions not yet in the corrections box are offered as a starting point.
        $corrections = trim((string) ($inp['corrections'] ?? ''));
        $have        = self::parse_map($corrections);
        foreach ($plan['suggestions'] as $k => $s) {
            if (!isset($have[$k])) {
                $corrections .= ($corrections !== '' ? "\n" : '') . $s[0] . ' => ' . $s[1];
            }
        }
        $messages = array(
            'applied'     => array('success', __('Migration applied. Check the tests and labs below; Undo is available until you clear the log.', 'ecare-health-services')),
            'undone'      => array('success', __('The last migration was undone.', 'ecare-health-services')),
            'need_backup' => array('error', __('Tick "I have a backup" before applying.', 'ecare-health-services')),
            'already'     => array('error', __('A migration was already applied. Undo it first, or review its result.', 'ecare-health-services')),
            'stale'       => array('error', __('That form was already used or is out of date, so nothing was done. Review the page below and try again if you still want to.', 'ecare-health-services')),
        );
        $sum = $plan['summary'];
        // A fresh token for this view of the page; any older form stops working.
        $token = wp_generate_password(24, false, false);
        update_option(self::TOKEN_OPTION, $token, false);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Lab Data Migration', 'ecare-health-services'); ?></h1>
            <?php if (isset($messages[$msg])): ?>
                <div class="notice notice-<?php echo esc_attr($messages[$msg][0]); ?>"><p><?php echo esc_html($messages[$msg][1]); ?></p></div>
            <?php endif; ?>
            <p><?php esc_html_e('Converts tests that still use the old typed-in provider and area lists. This page only shows a plan; nothing changes until you press Apply. Nothing is deleted: duplicates become drafts and every touched test keeps a copy of its old data.', 'ecare-health-services'); ?></p>

            <?php if ($log): ?>
                <div class="notice notice-info inline"><p>
                    <?php echo esc_html(sprintf(__('Applied on %1$s: %2$d prices, %3$d new labs, %4$d new areas, %5$d tests set to draft.', 'ecare-health-services'),
                        $log['time'], count($log['offerings']), count($log['providers']), count($log['areas']), count($log['drafted']))); ?>
                </p></div>
            <?php endif; ?>

            <h2><?php esc_html_e('Plan', 'ecare-health-services'); ?></h2>
            <table class="widefat striped" style="max-width:760px">
                <tbody>
                    <tr><td><?php esc_html_e('Old-format tests found', 'ecare-health-services'); ?></td><td><strong><?php echo (int) $sum['tests']; ?></strong></td></tr>
                    <tr><td><?php esc_html_e('Tests after merging same names', 'ecare-health-services'); ?></td><td><strong><?php echo (int) $sum['merged_tests']; ?></strong> (<?php echo esc_html(sprintf(__('%d duplicates set to draft', 'ecare-health-services'), $sum['drafted'])); ?>)</td></tr>
                    <tr><td><?php esc_html_e('Lab prices to create', 'ecare-health-services'); ?></td><td><strong><?php echo (int) $sum['offerings']; ?></strong></td></tr>
                    <tr><td><?php esc_html_e('New labs', 'ecare-health-services'); ?></td><td><strong><?php echo (int) $sum['new_providers']; ?></strong></td></tr>
                    <tr><td><?php esc_html_e('New areas', 'ecare-health-services'); ?></td><td><strong><?php echo (int) $sum['new_areas']; ?></strong></td></tr>
                    <tr><td><?php esc_html_e('Things to review', 'ecare-health-services'); ?></td><td><strong style="color:<?php echo $sum['issues'] ? '#b32d2e' : '#00a32a'; ?>"><?php echo (int) $sum['issues']; ?></strong></td></tr>
                </tbody>
            </table>

            <?php if ($plan['issues']): ?>
                <h3><?php esc_html_e('To review', 'ecare-health-services'); ?></h3>
                <ul style="list-style:disc;padding-left:20px;max-width:900px">
                    <?php foreach ($plan['issues'] as $i): ?><li><?php echo esc_html($i[1]); ?></li><?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if ($plan['new_areas']): ?>
                <h3><?php esc_html_e('New areas', 'ecare-health-services'); ?></h3>
                <p class="description"><?php esc_html_e('Check the spelling: a typo here becomes a real area. Fix it with a correction and scan again.', 'ecare-health-services'); ?></p>
                <p style="max-width:900px"><?php echo esc_html(implode(' · ', array_map(function ($a) { return $a[1] . ' (' . $a[2] . ')'; }, $plan['new_areas']))); ?></p>
            <?php endif; ?>

            <h3><?php esc_html_e('Labs', 'ecare-health-services'); ?></h3>
            <table class="widefat striped" style="max-width:900px">
                <thead><tr><th><?php esc_html_e('Lab', 'ecare-health-services'); ?></th><th><?php esc_html_e('Action', 'ecare-health-services'); ?></th><th><?php esc_html_e('Areas to add', 'ecare-health-services'); ?></th></tr></thead>
                <tbody>
                <?php foreach ($plan['providers'] as $k => $p): ?>
                    <tr>
                        <td><?php echo esc_html($p[0]); ?></td>
                        <td><?php echo $p[1] ? esc_html__('use existing', 'ecare-health-services') : '<strong>' . esc_html__('create', 'ecare-health-services') . '</strong>'; ?></td>
                        <td><?php echo (int) count($plan['coverage'][$k] ?? array()); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h3><?php esc_html_e('Tests', 'ecare-health-services'); ?></h3>
            <table class="widefat striped" style="max-width:900px">
                <thead><tr><th><?php esc_html_e('Test', 'ecare-health-services'); ?></th><th><?php esc_html_e('Kept', 'ecare-health-services'); ?></th><th><?php esc_html_e('Set to draft', 'ecare-health-services'); ?></th><th><?php esc_html_e('Lab prices', 'ecare-health-services'); ?></th></tr></thead>
                <tbody>
                <?php foreach ($plan['groups'] as $g): ?>
                    <tr>
                        <td><?php echo esc_html($g['title']); ?></td>
                        <td>#<?php echo (int) $g['master']; ?></td>
                        <td><?php echo esc_html(implode(', ', array_map(function ($m) { return '#' . $m['id']; }, array_filter($g['members'], function ($m) use ($g) { return $m['id'] !== $g['master']; })))) ?: '&mdash;'; ?></td>
                        <td><?php echo esc_html(implode(' · ', array_map(function ($k, $price) use ($plan) { return $plan['providers'][$k][0] . ' ৳' . $price; }, array_keys($g['offers']), $g['offers']))) ?: '&mdash;'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:24px;max-width:900px">
                <input type="hidden" name="action" value="ecare_lab_migrate" />
                <input type="hidden" name="run_token" value="<?php echo esc_attr($token); ?>" />
                <?php wp_nonce_field('ecare_lab_migrate'); ?>
                <h3><?php esc_html_e('Corrections', 'ecare-health-services'); ?></h3>
                <p class="description"><?php esc_html_e('One per line: Old name => Right name. Applies to labs, divisions, districts, areas and categories. Suggestions are pre-filled; remove any that are wrong.', 'ecare-health-services'); ?></p>
                <textarea name="corrections" rows="6" class="large-text code"><?php echo esc_textarea($corrections); ?></textarea>
                <h3><?php esc_html_e('Area districts', 'ecare-health-services'); ?></h3>
                <p class="description"><?php esc_html_e('For tests that list several districts: Area => District, one per line.', 'ecare-health-services'); ?></p>
                <textarea name="area_homes" rows="4" class="large-text code"><?php echo esc_textarea((string) ($inp['area_homes'] ?? '')); ?></textarea>
                <p>
                    <button class="button" name="do" value="scan"><?php esc_html_e('Save and scan again', 'ecare-health-services'); ?></button>
                </p>
                <?php if (!$log && $sum['tests']): ?>
                    <p style="margin-top:16px;padding:12px;background:#fff;border-left:4px solid #dba617;">
                        <label><input type="checkbox" name="have_backup" value="1" /> <?php esc_html_e('I have a fresh backup of the site database', 'ecare-health-services'); ?></label><br /><br />
                        <button class="button button-primary" name="do" value="apply"><?php esc_html_e('Apply migration', 'ecare-health-services'); ?></button>
                    </p>
                <?php endif; ?>
                <?php if ($log): ?>
                    <p style="margin-top:16px;"><button class="button" name="do" value="undo" onclick="return confirm(<?php echo esc_attr(wp_json_encode(__('Undo the last migration?', 'ecare-health-services'))); ?>);"><?php esc_html_e('Undo last migration', 'ecare-health-services'); ?></button></p>
                <?php endif; ?>
            </form>
        </div>
        <?php
    }

    // =======================================================================
    // WP-CLI: wp ecare lab-migrate [--apply] [--undo]
    // =======================================================================

    /**
     * Show the lab migration plan, or apply / undo it.
     *
     * ## OPTIONS
     * [--apply]
     * : Carry out the plan (uses the corrections saved on the admin page).
     * [--undo]
     * : Reverse the last applied migration.
     */
    public static function cli($args, $assoc) {
        if (!empty($assoc['undo'])) {
            self::undo() ? WP_CLI::success('Undone.') : WP_CLI::warning('Nothing to undo.');
            return;
        }
        $inp  = get_option('ecare_lab_migration_input', array('corrections' => '', 'area_homes' => ''));
        $plan = self::plan(self::snapshot(), self::parse_map($inp['corrections'] ?? ''), self::parse_map($inp['area_homes'] ?? ''));
        foreach ($plan['summary'] as $k => $v) {
            WP_CLI::log(str_pad($k, 16) . $v);
        }
        foreach ($plan['issues'] as $i) {
            WP_CLI::log('- ' . $i[1]);
        }
        if (!empty($assoc['apply'])) {
            if (get_option(self::LOG_OPTION)) {
                WP_CLI::error('A migration was already applied. Run with --undo first.');
            }
            $log = self::apply($plan);
            WP_CLI::success(sprintf('Applied: %d prices, %d labs, %d areas, %d drafted.', count($log['offerings']), count($log['providers']), count($log['areas']), count($log['drafted'])));
        } else {
            WP_CLI::log('Dry run. Nothing was changed. Add --apply to carry it out.');
        }
    }
}
