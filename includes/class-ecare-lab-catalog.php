<?php
defined('ABSPATH') || exit;

/**
 * Read side of the lab catalogue: what the home page, the tests page, the
 * detail page, the booking modal and the cart ask for.
 *
 * A test is listed only if a patient could book it right now: the test is
 * published and active, and at least one of its labs is bookable (the price
 * row is on, the lab is published and active). That set, with each test's
 * cheapest price and its labs, is the "bookable map", built once and cached
 * until anything that affects it changes.
 */
class ECare_Lab_Catalog {

    const CACHE_VERSION_OPTION = 'ecare_lab_cache_version';
    const CACHE_TTL            = 43200;   // 12 hours; any relevant edit clears it sooner

    const MAX_PER_PAGE = 48;
    const CARD_LOGOS   = 3;

    public static function init() {
        // Anything that can change what is bookable, or its price.
        add_action('save_post_ecare_lab_test', array(__CLASS__, 'bump'));
        add_action('save_post_' . ECare_Lab_Providers::POST_TYPE, array(__CLASS__, 'bump'));
        add_action('deleted_post', array(__CLASS__, 'bump'));
        add_action('trashed_post', array(__CLASS__, 'bump'));
        add_action('untrashed_post', array(__CLASS__, 'bump'));
        add_action('set_object_terms', array(__CLASS__, 'bump_on_terms'), 10, 4);
        add_action('ecare_lab_offerings_changed', array(__CLASS__, 'bump'));
    }

    // =======================================================================
    // Cache
    // =======================================================================

    /** Invalidate every cached catalogue answer. */
    public static function bump() {
        update_option(self::CACHE_VERSION_OPTION, (int) get_option(self::CACHE_VERSION_OPTION, 0) + 1, false);
    }

    public static function bump_on_terms($object_id, $terms, $tt_ids, $taxonomy) {
        if (in_array($taxonomy, array(ECare_Locations::TAXONOMY, ECare_Lab_Taxonomies::CATEGORY, ECare_Lab_Taxonomies::COLLECTION), true)) {
            self::bump();
        }
    }

    private static function cache_key($name) {
        return 'ecare_lab_' . $name . '_v' . (int) get_option(self::CACHE_VERSION_OPTION, 0);
    }

    // =======================================================================
    // The bookable map
    // =======================================================================

    /**
     * test id => array(
     *   'min'       => cheapest price a patient pays,
     *   'mrp'       => the MRP of that cheapest row (for the struck-through price),
     *   'providers' => provider id => price, cheapest first,
     * )
     * Only published, active tests with at least one bookable lab.
     */
    public static function bookable_map() {
        $key    = self::cache_key('bookable');
        $cached = get_transient($key);
        if (is_array($cached)) {
            return $cached;
        }

        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT test_id, provider_id, mrp, price FROM ' . ECare_Lab_Offerings::table() . ' WHERE status = %s',
            ECare_Lab_Offerings::STATUS_ACTIVE
        ));

        $lab_ok  = array();
        $test_ok = array();
        $map     = array();
        foreach ((array) $rows as $r) {
            $tid = (int) $r->test_id;
            $pid = (int) $r->provider_id;
            if (!isset($lab_ok[$pid])) {
                $lab_ok[$pid] = ECare_Lab_Providers::is_active($pid);
            }
            if (!isset($test_ok[$tid])) {
                $test_ok[$tid] = self::test_is_live($tid);
            }
            if (!$lab_ok[$pid] || !$test_ok[$tid]) {
                continue;
            }
            $price = ECare_Lab_Offerings::effective_price($r);
            $map[$tid]['providers'][$pid] = $price;
            if (!isset($map[$tid]['min']) || $price < $map[$tid]['min']) {
                $map[$tid]['min'] = $price;
                $map[$tid]['mrp'] = (float) $r->mrp;
            }
        }
        foreach ($map as &$m) {
            asort($m['providers']);
        }
        unset($m);

        set_transient($key, $map, self::CACHE_TTL);
        return $map;
    }

    private static function test_is_live($test_id) {
        $p = get_post($test_id);
        return $p && $p->post_type === 'ecare_lab_test' && $p->post_status === 'publish'
            && get_post_meta($test_id, '_test_status', true) !== 'inactive';
    }

    // =======================================================================
    // Search
    // =======================================================================

    /**
     * @param array $args {
     *   s          string   words in the name
     *   category   int|string  term id or slug
     *   collection int|string  term id or slug; results keep the collection's order
     *   provider   int      only tests this lab can do
     *   type       string   single | package
     *   orderby    string   name (default) | price_asc | price_desc
     *   page       int      1-based
     *   per_page   int      default 12, at most 48
     * }
     * @return array{items: array, total: int, page: int, pages: int}
     */
    public static function search($args = array()) {
        $args = array_merge(array('s' => '', 'category' => 0, 'collection' => 0, 'provider' => 0, 'type' => '', 'orderby' => 'name', 'page' => 1, 'per_page' => 12), (array) $args);
        $map  = self::bookable_map();
        $ids  = array_keys($map);

        $provider = (int) $args['provider'];
        if ($provider > 0) {
            $ids = array_values(array_filter($ids, function ($id) use ($map, $provider) {
                return isset($map[$id]['providers'][$provider]);
            }));
        }
        if (in_array($args['type'], array('single', 'package'), true)) {
            $type = $args['type'];
            $ids  = array_values(array_filter($ids, function ($id) use ($type) {
                return ECare_Lab_Test_Info::type($id) === $type;
            }));
        }

        $order = array();   // collection order, when filtering by one
        $col   = self::term_id($args['collection'], ECare_Lab_Taxonomies::COLLECTION);
        if ($args['collection'] && !$col) {
            $ids = array();   // unknown collection: nothing, rather than everything
        } elseif ($col) {
            $order = ECare_Lab_Taxonomies::collection_test_ids($col, 0);
            $ids   = array_values(array_intersect($order, $ids));
        }

        if ($ids) {
            $q = array(
                'post_type'        => 'ecare_lab_test',
                'post_status'      => 'publish',
                'post__in'         => $ids,
                'posts_per_page'   => -1,
                'fields'           => 'ids',
                'orderby'          => 'title',
                'order'            => 'ASC',
                'suppress_filters' => true,
            );
            $s = trim((string) $args['s']);
            if ($s !== '') {
                $q['s'] = $s;
            }
            $cat = self::term_id($args['category'], ECare_Lab_Taxonomies::CATEGORY);
            if ($args['category'] && !$cat) {
                $ids = array();
            } else {
                if ($cat) {
                    $q['tax_query'] = array(array('taxonomy' => ECare_Lab_Taxonomies::CATEGORY, 'field' => 'term_id', 'terms' => array($cat)));
                }
                $ids = array_map('intval', (array) get_posts($q));
            }
        }

        // Ordering.
        if ($args['orderby'] === 'price_asc' || $args['orderby'] === 'price_desc') {
            $dir = $args['orderby'] === 'price_asc' ? 1 : -1;
            usort($ids, function ($a, $b) use ($map, $dir) {
                $c = ($map[$a]['min'] <=> $map[$b]['min']) * $dir;
                return $c ?: $a <=> $b;
            });
        } elseif ($order) {
            $pos = array_flip($order);
            usort($ids, function ($a, $b) use ($pos) { return $pos[$a] <=> $pos[$b]; });
        }

        $per   = max(1, min(self::MAX_PER_PAGE, (int) $args['per_page']));
        $total = count($ids);
        $pages = max(1, (int) ceil($total / $per));
        $page  = max(1, min($pages, (int) $args['page']));
        $slice = array_slice($ids, ($page - 1) * $per, $per);

        return array(
            'items' => array_map(array(__CLASS__, 'card'), $slice),
            'total' => $total,
            'page'  => $page,
            'pages' => $pages,
        );
    }

    /** A collection's tests as cards, for a home page row. */
    public static function collection_cards($collection) {
        $col = self::term_id($collection, ECare_Lab_Taxonomies::COLLECTION);
        if (!$col) {
            return array();
        }
        $map = self::bookable_map();
        $ids = array_values(array_filter(ECare_Lab_Taxonomies::collection_test_ids($col), function ($id) use ($map) { return isset($map[$id]); }));
        return array_map(array(__CLASS__, 'card'), $ids);
    }

    private static function term_id($value, $taxonomy) {
        if (!$value) {
            return 0;
        }
        $t = is_numeric($value) ? get_term((int) $value, $taxonomy) : get_term_by('slug', (string) $value, $taxonomy);
        return ($t && !is_wp_error($t) && ($t->taxonomy ?? $taxonomy) === $taxonomy) ? (int) $t->term_id : 0;
    }

    // =======================================================================
    // Cards
    // =======================================================================

    /** Everything a test card shows. Assumes the test is in the bookable map. */
    public static function card($test_id) {
        $test_id = (int) $test_id;
        $map     = self::bookable_map();
        $m       = $map[$test_id] ?? array('min' => 0, 'mrp' => 0, 'providers' => array());
        $mrp     = (float) $m['mrp'];
        $min     = (float) $m['min'];
        $pids    = array_keys($m['providers']);

        $labs = array();
        foreach (array_slice($pids, 0, self::CARD_LOGOS) as $pid) {
            $labs[] = self::lab_badge($pid);
        }

        $package = ECare_Lab_Test_Info::is_package($test_id);
        return array(
            'id'         => $test_id,
            'title'      => get_the_title($test_id),
            'image'      => (string) get_the_post_thumbnail_url($test_id, 'medium'),
            'type'       => $package ? 'package' : 'single',
            'items'      => $package ? ECare_Lab_Packages::item_count($test_id) : 0,
            'report'     => ECare_Lab_Test_Info::report_label($test_id),
            'price'      => $min,
            'mrp'        => $mrp > $min ? $mrp : 0.0,
            'discount'   => ($mrp > $min && $mrp > 0) ? (int) round(($mrp - $min) / $mrp * 100) : 0,
            'labs'       => $labs,
            'more_labs'  => max(0, count($pids) - self::CARD_LOGOS),
        );
    }

    private static function lab_badge($provider_id) {
        return array(
            'id'   => (int) $provider_id,
            'name' => get_the_title($provider_id),
            'logo' => (string) get_the_post_thumbnail_url($provider_id, 'thumbnail'),
        );
    }

    // =======================================================================
    // Labs for a test / for a cart
    // =======================================================================

    /**
     * "Choose a lab" in the booking modal: bookable labs for one test, cheapest
     * first. With an area, each says whether it serves it, and those that do
     * come first.
     */
    public static function labs_for_test($test_id, $area_id = 0) {
        $out = array();
        foreach (ECare_Lab_Offerings::available_for_test($test_id) as $row) {
            $pid   = (int) $row->provider_id;
            $out[] = self::lab_badge($pid) + array(
                'offering_id'   => (int) $row->id,
                'price'         => ECare_Lab_Offerings::effective_price($row),
                'mrp'           => (float) $row->mrp,
                'savings'       => ECare_Lab_Offerings::savings($row),
                'discount'      => ECare_Lab_Offerings::discount_percent($row),
                'material_cost' => (float) $row->material_cost,
                'serves_area'   => $area_id ? ECare_Lab_Providers::covers_area($pid, $area_id) : null,
            );
        }
        if ($area_id) {
            // Stable: serving labs first, each group still cheapest first.
            $serving = array_values(array_filter($out, function ($l) { return $l['serves_area']; }));
            $others  = array_values(array_filter($out, function ($l) { return !$l['serves_area']; }));
            $out     = array_merge($serving, $others);
        }
        return $out;
    }

    /**
     * Labs that can take a whole cart: they offer every test in it. Used by the
     * cart's "Change" vendor list. Cheapest total first; with an area, labs
     * serving it first.
     *
     * @param int[] $test_ids
     */
    public static function labs_for_cart($test_ids, $area_id = 0) {
        $test_ids = array_values(array_unique(array_map('intval', (array) $test_ids)));
        if (!$test_ids) {
            return array();
        }
        $map    = self::bookable_map();
        $common = null;
        foreach ($test_ids as $tid) {
            $pids   = array_keys($map[$tid]['providers'] ?? array());
            $common = $common === null ? $pids : array_values(array_intersect($common, $pids));
        }

        $out = array();
        foreach ((array) $common as $pid) {
            $total = 0.0;
            $mrp   = 0.0;
            foreach ($test_ids as $tid) {
                foreach (ECare_Lab_Offerings::available_for_test($tid) as $row) {
                    if ((int) $row->provider_id === (int) $pid) {
                        $total += ECare_Lab_Offerings::effective_price($row);
                        $mrp   += (float) $row->mrp;
                        break;
                    }
                }
            }
            $out[] = self::lab_badge($pid) + array(
                'total'       => round($total, 2),
                'mrp'         => round($mrp, 2),
                'serves_area' => $area_id ? ECare_Lab_Providers::covers_area($pid, $area_id) : null,
            );
        }
        usort($out, function ($a, $b) use ($area_id) {
            if ($area_id && $a['serves_area'] !== $b['serves_area']) {
                return $a['serves_area'] ? -1 : 1;
            }
            return ($a['total'] <=> $b['total']) ?: ($a['id'] <=> $b['id']);
        });
        return $out;
    }

    // =======================================================================
    // Filters and related tests
    // =======================================================================

    /**
     * The tests page sidebar: categories and labs with how many bookable
     * tests each has (empty ones left out), and the collections.
     */
    public static function filters() {
        $key    = self::cache_key('filters');
        $cached = get_transient($key);
        if (is_array($cached)) {
            return $cached;
        }
        $map = self::bookable_map();

        $cat_count = array();
        $lab_count = array();
        foreach ($map as $tid => $m) {
            $cats = wp_get_object_terms($tid, ECare_Lab_Taxonomies::CATEGORY, array('fields' => 'ids'));
            foreach (is_wp_error($cats) ? array() : $cats as $cid) {
                $cat_count[(int) $cid] = ($cat_count[(int) $cid] ?? 0) + 1;
            }
            foreach (array_keys($m['providers']) as $pid) {
                $lab_count[$pid] = ($lab_count[$pid] ?? 0) + 1;
            }
        }

        $categories = array();
        foreach (ECare_Lab_Taxonomies::categories() as $t) {
            if (!empty($cat_count[$t->term_id])) {
                $categories[] = array('id' => (int) $t->term_id, 'slug' => $t->slug, 'name' => $t->name, 'icon' => ECare_Lab_Taxonomies::icon_url($t->term_id), 'count' => $cat_count[$t->term_id]);
            }
        }
        $collections = array();
        foreach (ECare_Lab_Taxonomies::collections() as $t) {
            $collections[] = array('id' => (int) $t->term_id, 'slug' => $t->slug, 'name' => $t->name);
        }
        $labs = array();
        foreach ($lab_count as $pid => $n) {
            $labs[] = self::lab_badge($pid) + array('count' => $n);
        }
        usort($labs, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });

        $out = compact('categories', 'collections', 'labs');
        set_transient($key, $out, self::CACHE_TTL);
        return $out;
    }

    /** Bookable tests sharing the most categories with this one. */
    public static function related($test_id, $limit = 6) {
        $test_id = (int) $test_id;
        $mine    = wp_get_object_terms($test_id, ECare_Lab_Taxonomies::CATEGORY, array('fields' => 'ids'));
        $mine    = is_wp_error($mine) ? array() : array_map('intval', $mine);
        if (!$mine) {
            return array();
        }
        $scores = array();
        foreach (array_keys(self::bookable_map()) as $tid) {
            if ($tid === $test_id) {
                continue;
            }
            $theirs = wp_get_object_terms($tid, ECare_Lab_Taxonomies::CATEGORY, array('fields' => 'ids'));
            $shared = count(array_intersect($mine, is_wp_error($theirs) ? array() : array_map('intval', $theirs)));
            if ($shared) {
                $scores[$tid] = $shared;
            }
        }
        uksort($scores, function ($a, $b) use ($scores) {
            return ($scores[$b] <=> $scores[$a]) ?: strcasecmp(get_the_title($a), get_the_title($b));
        });
        return array_map(array(__CLASS__, 'card'), array_slice(array_keys($scores), 0, $limit));
    }
}
