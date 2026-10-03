<?php
defined('ABSPATH') || exit;

/**
 * Site-wide search: one results page for lab tests, doctors, caregivers and
 * ambulances, above the site's ordinary page/post results.
 *
 * The lab tests, caregivers and ambulances are deliberately not public post
 * types (no single pages, excluded from WordPress search), so WordPress's own
 * search can never find them. This page looks them up itself and shows only
 * what their own screens already show in public: names, types, prices -
 * never phone numbers, emails, NID, bank details or vehicle numbers.
 *
 * Doctors belong to the EG Care plugin. They are only read here (approved,
 * published), with their profile and booking links; EG Care's code is not
 * touched. EG Care sends a search that matches a doctor straight to its doctor
 * list; when this page has more to show it keeps the visitor here instead
 * (through EG Care's own eg_care_search_redirect filter).
 */
class ECare_Search {

    /** Most cards shown per section; "See all" goes to the full list. */
    const PER_SECTION = 8;

    /** Words that only join a query ("CBC with ESR"). */
    const STOP = array('with', 'and', 'the', 'of', 'for', 'a', 'an', 'in', 'to', '&', '+', '-');

    private static $cache = array();

    public static function init() {
        add_filter('template_include', array(__CLASS__, 'template'), 99);
        add_filter('eg_care_search_redirect', array(__CLASS__, 'egcare_redirect'), 10, 2);
        add_filter('ecare_lab_front_on_page', array(__CLASS__, 'lab_assets'), 10, 2);
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue'), 30);
        add_action('save_post', array(__CLASS__, 'forget_pages'));
    }

    // =======================================================================
    // When
    // =======================================================================

    /** A front-end site search that is not scoped to one post type. */
    public static function active() {
        return !is_admin() && is_search() && empty($_GET['post_type']); // phpcs:ignore WordPress.Security.NonceVerification
    }

    public static function term() {
        $s = trim((string) get_search_query(false));
        return function_exists('mb_substr') ? mb_substr($s, 0, 100) : substr($s, 0, 100);
    }

    public static function template($template) {
        if (!self::active()) {
            return $template;
        }
        $ours = ECARE_PLUGIN_DIR . 'templates/ecare-search.php';
        return file_exists($ours) ? $ours : $template;
    }

    /** Keep a doctor search here when the page also has tests, caregivers or ambulances to show. */
    public static function egcare_redirect($target, $term) {
        $r = self::results($term);
        return ($r['lab'] || $r['caregiver'] || $r['ambulance']) ? '' : $target;
    }

    /** The lab cards and their Book modal need the lab CSS/JS. */
    public static function lab_assets($found, $post = null) {
        return $found || self::active();
    }

    // =======================================================================
    // Matching
    // =======================================================================

    public static function lower($s) {
        $s = (string) $s;
        return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    }

    /** "CBC with ESR!" -> ["cbc", "esr"]; letters of any script are kept, with their vowel signs (Bangla). */
    public static function tokens($s) {
        $s     = self::lower(preg_replace('/[^\p{L}\p{M}\p{N}]+/u', ' ', (string) $s));
        $parts = preg_split('/\s+/', trim($s));
        return array_values(array_unique(array_filter($parts, function ($w) {
            return $w !== '' && !in_array($w, ECare_Search::STOP, true);
        })));
    }

    /**
     * How well a thing matches: 0 = not at all (some word missing), 3 = its
     * name starts with the query, 2 = every word is in the name, 1 = the words
     * are found somewhere in what it is known by.
     */
    public static function score($tokens, $name, $also = '') {
        if (!$tokens) {
            return 0;
        }
        $n   = ' ' . self::lower(preg_replace('/[^\p{L}\p{M}\p{N}]+/u', ' ', (string) $name)) . ' ';
        $hay = $n . ' ' . self::lower(preg_replace('/[^\p{L}\p{M}\p{N}]+/u', ' ', (string) $also));
        foreach ($tokens as $t) {
            if (strpos($hay, $t) === false) {
                return 0;
            }
        }
        // The name starts with the query, word for word ("cbc esr" -> "CBC with ESR", "fate" -> "Fatema ...").
        $words = self::tokens($name);
        $start = count($words) >= count($tokens);
        foreach ($tokens as $i => $t) {
            if (!$start || strpos($words[$i], $t) !== 0) {
                $start = false;
                break;
            }
        }
        if ($start) {
            return 3;
        }
        foreach ($tokens as $t) {
            if (strpos($n, $t) === false) {
                return 1;
            }
        }
        return 2;
    }

    /** Best first, then by name. Each row: ['score' => int, 'name' => string, ...]. */
    public static function rank($rows) {
        usort($rows, function ($a, $b) {
            return $b['score'] <=> $a['score'] ?: strcasecmp($a['name'], $b['name']);
        });
        return $rows;
    }

    /**
     * Everything that matches, per section (each list ranked, uncut).
     *
     * @return array{lab:array, doctor:array, caregiver:array, ambulance:array}
     */
    public static function results($term) {
        $key = self::lower(trim((string) $term));
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        $t = self::tokens($term);
        $r = array(
            'lab'       => $t ? self::labs($t) : array(),
            'doctor'    => $t ? self::doctors($t) : array(),
            'caregiver' => $t ? self::caregivers($t) : array(),
            'ambulance' => $t ? self::ambulances($t) : array(),
        );
        return self::$cache[$key] = $r;
    }

    // =======================================================================
    // Sections
    // =======================================================================

    /** Bookable lab tests and packages: name, other names, subtitle, categories. */
    public static function labs($tokens) {
        if (!class_exists('ECare_Lab_Catalog') || !class_exists('ECare_Lab_Front') || !ECare_Lab_Front::page_id('tests')) {
            return array();   // no tests page to send anyone to
        }
        $ids = array_keys(ECare_Lab_Catalog::bookable_map());
        if (!$ids) {
            return array();
        }
        // One query primes the post, meta and term caches for the loop below.
        $posts = get_posts(array('post_type' => 'ecare_lab_test', 'post_status' => 'publish', 'post__in' => $ids, 'posts_per_page' => -1, 'suppress_filters' => true));
        $rows  = array();
        foreach ($posts as $p) {
            $info = ECare_Lab_Test_Info::details($p->ID);
            $cats = get_the_terms($p->ID, ECare_Lab_Taxonomies::CATEGORY);   // cached by the query above
            $cats = (is_array($cats)) ? wp_list_pluck($cats, 'name') : array();
            $also = implode(' ', array_merge(array((string) $info['also_known_as'], (string) $info['subtitle']), $cats));
            $s    = self::score($tokens, $p->post_title, $also);
            if ($s) {
                $rows[] = array('score' => $s, 'name' => $p->post_title, 'id' => (int) $p->ID);
            }
        }
        return self::rank($rows);
    }

    /** Approved caregivers: name, provider type (Nurse, Nanny...). */
    public static function caregivers($tokens) {
        $posts = get_posts(array(
            'post_type'        => 'ecare_caregiver',
            'post_status'      => 'publish',
            'posts_per_page'   => -1,
            'suppress_filters' => true,
            'meta_query'       => array(array('key' => '_provider_status', 'value' => 'approved')),
        ));
        $rows = array();
        foreach ($posts as $p) {
            $type = (string) get_post_meta($p->ID, '_provider_type', true);
            $s    = self::score($tokens, $p->post_title, $type . ' caregiver care provider');
            if ($s) {
                $photo = has_post_thumbnail($p->ID) ? (string) get_the_post_thumbnail_url($p->ID, 'thumbnail') : (string) get_post_meta($p->ID, '_photo_url', true);
                $years = (int) get_post_meta($p->ID, '_experience', true);
                $rows[] = array('score' => $s, 'name' => $p->post_title, 'id' => (int) $p->ID, 'type' => $type, 'photo' => $photo, 'years' => $years);
            }
        }
        return self::rank($rows);
    }

    public static function ambulance_types() {
        return array(
            'Standard' => __('Standard (Non-AC)', 'ecare-health-services'),
            'ICU'      => __('ICU (AC)', 'ecare-health-services'),
            'Freezer'  => __('Freezer Van', 'ecare-health-services'),
        );
    }

    /** Approved ambulances: provider and driver name, type. */
    public static function ambulances($tokens) {
        $posts = get_posts(array(
            'post_type'        => 'ecare_ambulance',
            'post_status'      => 'publish',
            'posts_per_page'   => -1,
            'suppress_filters' => true,
            'meta_query'       => array(array('key' => '_ambulance_status', 'value' => 'approved')),
        ));
        $types = self::ambulance_types();
        $rows  = array();
        foreach ($posts as $p) {
            $driver = trim((string) get_post_meta($p->ID, '_driver_name', true));
            $type   = (string) get_post_meta($p->ID, '_ambulance_type', true);
            $label  = $types[$type] ?? $type;
            $name   = $driver !== '' ? $driver : $p->post_title;
            $s      = self::score($tokens, $name, $p->post_title . ' ' . $type . ' ' . $label . ' ambulance');
            if ($s) {
                $rows[] = array('score' => $s, 'name' => $name, 'company' => $p->post_title !== $name ? $p->post_title : '', 'id' => (int) $p->ID, 'type' => $type, 'type_label' => $label);
            }
        }
        return self::rank($rows);
    }

    /** EG Care doctors, approved and published, read only: name, degree, designation, specialties. */
    public static function doctors($tokens) {
        if (!post_type_exists('doctors') || !class_exists('\EGCare\DB')) {
            return array();
        }
        global $wpdb;
        $meta = \EGCare\DB::get_table('doctors_meta');
        if (!$meta) {
            return array();
        }
        $list = $wpdb->get_results(
            "SELECT p.ID, p.post_title, m.degree, m.designation, m.consultation_fee
               FROM {$meta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id
              WHERE p.post_type = 'doctors' AND p.post_status = 'publish' AND m.verification_status = 'approved'"
        );
        $list = (array) $list;
        if ($list) {
            update_object_term_cache(array_map('intval', wp_list_pluck($list, 'ID')), 'doctors');   // one query for every doctor's specialties
        }
        $rows = array();
        foreach ($list as $d) {
            $spec = get_the_terms((int) $d->ID, 'specialty');
            $spec = is_array($spec) ? wp_list_pluck($spec, 'name') : array();
            $s    = self::score($tokens, $d->post_title, implode(' ', $spec) . ' ' . $d->degree . ' ' . $d->designation . ' doctor');
            if ($s) {
                $rows[] = array(
                    'score' => $s, 'name' => $d->post_title, 'id' => (int) $d->ID,
                    'specialties' => $spec, 'degree' => (string) $d->degree, 'designation' => (string) $d->designation,
                    'fee' => (float) $d->consultation_fee,
                    'photo' => (string) get_the_post_thumbnail_url((int) $d->ID, 'thumbnail'),
                );
            }
        }
        return self::rank($rows);
    }

    // =======================================================================
    // Where things are
    // =======================================================================

    /** The first published page holding a shortcode or its Elementor widget (same name). */
    public static function page_with($tag) {
        $key = 'ecare_page_with_' . $tag;
        $hit = get_transient($key);
        if ($hit !== false) {
            return (int) $hit;
        }
        global $wpdb;
        $like = '%' . $wpdb->esc_like($tag) . '%';
        $id   = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_elementor_data'
             WHERE p.post_type = 'page' AND p.post_status = 'publish' AND (p.post_content LIKE %s OR m.meta_value LIKE %s)
             ORDER BY p.ID ASC LIMIT 1",
            $like, $like
        ));
        if ($id) {
            set_transient($key, $id, HOUR_IN_SECONDS);
        }
        return $id;
    }

    public static function forget_pages() {
        delete_transient('ecare_page_with_ecare_caregiver_booking');
        delete_transient('ecare_page_with_ecare_ambulance_request');
    }

    public static function caregiver_url($id = 0) {
        $page = self::page_with('ecare_caregiver_booking');
        if (!$page) {
            return '';
        }
        return $id ? add_query_arg('ecare_caregiver', (int) $id, get_permalink($page)) : get_permalink($page);
    }

    public static function ambulance_url($type = '') {
        $page = self::page_with('ecare_ambulance_request');
        if (!$page) {
            return '';
        }
        return $type !== '' ? add_query_arg('ecare_amb_type', rawurlencode($type), get_permalink($page)) : get_permalink($page);
    }

    /** EG Care's doctor list with the search applied, or '' without one. */
    public static function doctor_list_url($term) {
        if (!class_exists('\EGCare\Shortcodes') || !method_exists('\EGCare\Shortcodes', 'page_with_shortcode')) {
            return '';
        }
        $list = (string) \EGCare\Shortcodes::page_with_shortcode('eg_care_booking_flow');
        return $list !== '' ? add_query_arg('eg_search', rawurlencode($term), $list) : '';
    }

    // =======================================================================
    // Deep links from a result: open that caregiver, choose that ambulance type
    // =======================================================================

    public static function enqueue() {
        if (empty($_GET['ecare_caregiver']) && empty($_GET['ecare_amb_type'])) { // phpcs:ignore WordPress.Security.NonceVerification
            return;
        }
        if (!wp_script_is('ecare-frontend-script', 'enqueued')) {
            return;   // not a caregiver / ambulance page
        }
        $js = ECARE_PLUGIN_DIR . 'assets/js/ecare-deeplink.js';
        wp_enqueue_script('ecare-deeplink', ECARE_PLUGIN_URL . 'assets/js/ecare-deeplink.js', array('jquery', 'ecare-frontend-script'), file_exists($js) ? filemtime($js) : ECARE_VERSION, true);
    }

    // =======================================================================
    // The page
    // =======================================================================

    private static function initials($name) {
        $name = trim((string) $name);
        return $name === '' ? '?' : (function_exists('mb_substr') ? mb_strtoupper(mb_substr($name, 0, 1)) : strtoupper(substr($name, 0, 1)));
    }

    private static function avatar($photo, $name) {
        return $photo !== ''
            ? '<img class="ecl-sr-photo" src="' . esc_url($photo) . '" alt="" loading="lazy" />'
            : '<span class="ecl-sr-photo ecl-sr-initial" aria-hidden="true">' . esc_html(self::initials($name)) . '</span>';
    }

    private static function section_head($id, $title, $count, $all_url) {
        $d = 'ecare-health-services';
        $html = '<div class="ecl-sec-head"><h2 id="' . esc_attr($id) . '">' . esc_html($title) . ' <span class="ecl-sr-count">' . (int) $count . '</span></h2>';
        if ($all_url !== '' && $count > self::PER_SECTION) {
            $html .= '<a class="ecl-link" href="' . esc_url($all_url) . '">' . esc_html__('See all', $d) . ' →</a>';
        }
        return $html . '</div>';
    }

    /** The other matches WordPress found (pages, posts, products); doctors have their own section. */
    private static function other_posts() {
        global $wp_query;
        $out = array();
        foreach ((array) $wp_query->posts as $p) {
            if ($p->post_type === 'doctors') {
                continue;
            }
            $out[] = $p;
        }
        return $out;
    }

    public static function render() {
        $d    = 'ecare-health-services';
        $term = self::term();
        $r    = self::results($term);
        $other = self::other_posts();
        $sections = array(
            'lab'       => array(__('Lab Tests & Packages', $d), class_exists('ECare_Lab_Front') ? ECare_Lab_Front::catalog_url(array('q' => $term)) : ''),
            'doctor'    => array(__('Doctors', $d), self::doctor_list_url($term)),
            'caregiver' => array(__('Caregivers', $d), self::caregiver_url()),
            'ambulance' => array(__('Ambulance', $d), self::ambulance_url()),
        );
        $total = count($r['lab']) + count($r['doctor']) + count($r['caregiver']) + count($r['ambulance']) + count($other);

        ob_start();
        ?>
        <div id="primary" class="content-area primary ecl-sr-primary">
        <main id="main" class="site-main">
        <div class="ecl ecl-searchpage">
            <header class="ecl-sr-head">
                <h1 class="ecl-cp-title">
                    <?php if ($term !== ''): ?>
                        <?php echo esc_html(sprintf(__('Search results for "%s"', $d), $term)); ?>
                    <?php else: ?>
                        <?php esc_html_e('Search', $d); ?>
                    <?php endif; ?>
                </h1>
                <form class="ecl-search ecl-sr-form" role="search" method="get" action="<?php echo esc_url(home_url('/')); ?>">
                    <?php echo ECare_Lab_Front::icon('search'); // phpcs:ignore ?>
                    <label class="screen-reader-text" for="ecl-sr-q"><?php esc_html_e('Search', $d); ?></label>
                    <input type="search" id="ecl-sr-q" name="s" value="<?php echo esc_attr($term); ?>" placeholder="<?php esc_attr_e('Search tests, doctors, caregivers, ambulance…', $d); ?>" />
                    <button type="submit" class="ecl-btn"><?php esc_html_e('Search', $d); ?></button>
                </form>
                <?php if ($term !== '' && $total): ?>
                    <nav class="ecl-sr-jump" aria-label="<?php esc_attr_e('Result sections', $d); ?>">
                        <?php foreach ($sections as $k => $s): if (!$r[$k]) { continue; } ?>
                            <a href="#ecl-sr-<?php echo esc_attr($k); ?>"><?php echo esc_html($s[0]); ?> <span><?php echo count($r[$k]); ?></span></a>
                        <?php endforeach; ?>
                        <?php if ($other): ?><a href="#ecl-sr-other"><?php esc_html_e('Pages & articles', $d); ?> <span><?php echo count($other); ?></span></a><?php endif; ?>
                    </nav>
                <?php endif; ?>
            </header>

            <?php if ($r['lab']): ?>
                <section class="ecl-section" aria-labelledby="ecl-sr-lab">
                    <?php echo self::section_head('ecl-sr-lab', $sections['lab'][0], count($r['lab']), $sections['lab'][1]); // phpcs:ignore ?>
                    <div class="ecl-grid">
                        <?php foreach (array_slice($r['lab'], 0, self::PER_SECTION) as $row) { echo ECare_Lab_Front::render_card(ECare_Lab_Catalog::card($row['id'])); } // phpcs:ignore ?>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($r['doctor']): ?>
                <section class="ecl-section" aria-labelledby="ecl-sr-doctor">
                    <?php echo self::section_head('ecl-sr-doctor', $sections['doctor'][0], count($r['doctor']), $sections['doctor'][1]); // phpcs:ignore ?>
                    <div class="ecl-sr-list">
                        <?php foreach (array_slice($r['doctor'], 0, self::PER_SECTION) as $doc):
                            $profile = (string) get_permalink($doc['id']);
                            $book    = self::doctor_list_url($doc['name']) ?: $profile; ?>
                            <article class="ecl-sr-item">
                                <?php echo self::avatar($doc['photo'], $doc['name']); // phpcs:ignore ?>
                                <div class="ecl-sr-body">
                                    <h3><a href="<?php echo esc_url($profile); ?>"><?php echo esc_html($doc['name']); ?></a></h3>
                                    <?php if ($doc['specialties']): ?><p class="ecl-sr-tag"><?php echo esc_html(implode(', ', $doc['specialties'])); ?></p><?php endif; ?>
                                    <p class="ecl-sr-meta"><?php echo esc_html(trim($doc['degree'] . ($doc['designation'] !== '' ? ' · ' . $doc['designation'] : ''), ' ·')); ?></p>
                                    <?php if ($doc['fee'] > 0): ?><p class="ecl-sr-meta"><?php echo esc_html(sprintf(__('Consultation fee %s', $d), ECare_Lab_Front::money($doc['fee']))); ?></p><?php endif; ?>
                                </div>
                                <a class="ecl-btn ecl-btn-sm" href="<?php echo esc_url($book); ?>"><?php esc_html_e('Book Appointment', $d); ?></a>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($r['caregiver']): ?>
                <section class="ecl-section" aria-labelledby="ecl-sr-caregiver">
                    <?php echo self::section_head('ecl-sr-caregiver', $sections['caregiver'][0], count($r['caregiver']), $sections['caregiver'][1]); // phpcs:ignore ?>
                    <div class="ecl-sr-list">
                        <?php foreach (array_slice($r['caregiver'], 0, self::PER_SECTION) as $cg):
                            $url = self::caregiver_url($cg['id']); ?>
                            <article class="ecl-sr-item">
                                <?php echo self::avatar($cg['photo'], $cg['name']); // phpcs:ignore ?>
                                <div class="ecl-sr-body">
                                    <h3><?php if ($url): ?><a href="<?php echo esc_url($url); ?>"><?php echo esc_html($cg['name']); ?></a><?php else: echo esc_html($cg['name']); endif; ?></h3>
                                    <?php if ($cg['type'] !== ''): ?><p class="ecl-sr-tag"><?php echo esc_html($cg['type']); ?></p><?php endif; ?>
                                    <?php if ($cg['years'] > 0): ?><p class="ecl-sr-meta"><?php echo esc_html(sprintf(_n('%d year experience', '%d years experience', $cg['years'], $d), $cg['years'])); ?></p><?php endif; ?>
                                </div>
                                <?php if ($url): ?><a class="ecl-btn ecl-btn-sm" href="<?php echo esc_url($url); ?>"><?php esc_html_e('View & Book', $d); ?></a><?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($r['ambulance']): ?>
                <section class="ecl-section" aria-labelledby="ecl-sr-ambulance">
                    <?php echo self::section_head('ecl-sr-ambulance', $sections['ambulance'][0], count($r['ambulance']), $sections['ambulance'][1]); // phpcs:ignore ?>
                    <div class="ecl-sr-list">
                        <?php foreach (array_slice($r['ambulance'], 0, self::PER_SECTION) as $amb):
                            $url = self::ambulance_url($amb['type']); ?>
                            <article class="ecl-sr-item">
                                <span class="ecl-sr-photo ecl-sr-initial ecl-sr-amb" aria-hidden="true">🚑</span>
                                <div class="ecl-sr-body">
                                    <h3><?php echo esc_html($amb['name']); ?></h3>
                                    <p class="ecl-sr-tag"><?php echo esc_html($amb['type_label']); ?></p>
                                    <?php if ($amb['company'] !== ''): ?><p class="ecl-sr-meta"><?php echo esc_html($amb['company']); ?></p><?php endif; ?>
                                </div>
                                <?php if ($url): ?><a class="ecl-btn ecl-btn-sm" href="<?php echo esc_url($url); ?>"><?php esc_html_e('Book Ambulance', $d); ?></a><?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($other): ?>
                <section class="ecl-section" aria-labelledby="ecl-sr-other">
                    <div class="ecl-sec-head"><h2 id="ecl-sr-other"><?php esc_html_e('Pages & articles', $d); ?> <span class="ecl-sr-count"><?php echo count($other); ?></span></h2></div>
                    <ul class="ecl-sr-posts">
                        <?php foreach ($other as $p): ?>
                            <li>
                                <a href="<?php echo esc_url(get_permalink($p)); ?>"><?php echo esc_html(get_the_title($p)); ?></a>
                                <?php $ex = wp_trim_words(wp_strip_all_tags(get_the_excerpt($p)), 24); if ($ex !== ''): ?><p><?php echo esc_html($ex); ?></p><?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php
                    $links = paginate_links(array('type' => 'list', 'prev_text' => '‹', 'next_text' => '›'));
                    if ($links) { echo '<nav class="ecl-sr-pages">' . $links . '</nav>'; } // phpcs:ignore
                    ?>
                </section>
            <?php endif; ?>

            <?php if (!$total): ?>
                <div class="ecl-empty">
                    <?php echo ECare_Lab_Front::icon('search'); // phpcs:ignore ?>
                    <h2><?php echo $term !== '' ? esc_html(sprintf(__('Nothing found for "%s"', $d), $term)) : esc_html__('What are you looking for?', $d); ?></h2>
                    <p><?php esc_html_e('Try another spelling or a shorter name, or browse:', $d); ?></p>
                    <p class="ecl-sr-browse">
                        <?php
                        $browse = array(
                            array(class_exists('ECare_Lab_Front') && ECare_Lab_Front::page_id('home') ? ECare_Lab_Front::url('home') : '', __('Home Lab Test', $d)),
                            array(self::doctor_list_url(''), __('Doctors', $d)),
                            array(self::caregiver_url(), __('Find a Caregiver', $d)),
                            array(self::ambulance_url(), __('Ambulance', $d)),
                        );
                        foreach ($browse as list($u, $label)) {
                            if ($u !== '') {
                                echo '<a class="ecl-btn ecl-btn-ghost ecl-btn-sm" href="' . esc_url(remove_query_arg('eg_search', $u)) . '">' . esc_html($label) . '</a> ';
                            }
                        }
                        ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>
        </main>
        </div>
        <?php
        return ob_get_clean();
    }
}
