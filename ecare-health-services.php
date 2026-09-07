<?php
/**
 * Plugin Name:       E-Care Health Services
 * Plugin URI:        https://github.com/kamrulhasan2/ecare-health-services
 * Description:       Comprehensive healthcare service booking and management – connects patients with caregivers, lab tests, and ambulance dispatch with WooCommerce payments. Shortcodes: [ecare_caregiver_booking] – filter & book caregivers; [ecare_caregiver_registration] – provider signup; [ecare_lab_tests] – diagnostic catalog; [ecare_ambulance_request] – ambulance dispatch; [ecare_ambulance_registration] – ambulance provider signup.
 * Version:           1.2.4
 * Author:            Md. Kamrul Hasan
 * License:           GPL v2 or later
 * Text Domain:       ecare-health-services
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 * Requires PHP:      8.1
 * Requires at least: 6.0
 */

defined('ABSPATH') || exit;

define('ECARE_VERSION', '1.2.4');
define('ECARE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ECARE_PLUGIN_URL', plugin_dir_url(__FILE__));

final class ECare_Health_Services {

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        add_action('plugins_loaded', array($this, 'init_plugin'));
        // Priority 99: after every post type and taxonomy has registered.
        add_action('init', array($this, 'maybe_flush_rewrites'), 99);
        add_action('plugins_loaded', array($this, 'init_elementor'), 20);
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        // Priority 20: WooCommerce registers selectWoo on this same hook, and
        // plugin load order would otherwise put us first.
        add_action('wp_enqueue_scripts', array($this, 'frontend_enqueue_scripts'), 20);
        // Enable multipart form for caregiver photo upload
        add_action('post_edit_form_tag', array($this, 'caregiver_form_enctype'));

        // Every order read in this plugin goes through the WooCommerce CRUD
        // layer, so High-Performance Order Storage is safe. Without this
        // declaration WooCommerce lists the plugin as incompatible and warns the
        // site owner off a feature that in fact works.
        add_action('before_woocommerce_init', array($this, 'declare_woocommerce_compatibility'));
    }

    public function declare_woocommerce_compatibility() {
        if (!class_exists('\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
            return;
        }

        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);

        // The block checkout is supported: the cart item data filter is applied
        // by the Store API's cart schema, line item meta survives because the
        // Store API hands line item creation back to WC_Checkout, and the one
        // action it does not fire - woocommerce_checkout_order_processed - now
        // has its Store API counterpart hooked alongside it.
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }

    public function activate() {
        require_once ECARE_PLUGIN_DIR . 'includes/class-ecare-secure-files.php';
        require_once ECARE_PLUGIN_DIR . 'includes/class-ecare-cpt.php';
        require_once ECARE_PLUGIN_DIR . 'includes/class-ecare-activator.php';

        // The taxonomy is normally registered on init, which has not run during
        // activation, so register it here before seeding depends on it.
        ECare_CPT::register_caregiver_type_taxonomy();

        ECare_Activator::activate();
    }

    public function deactivate() {
        // Cleanup if needed
    }

    /**
     * Flush rewrite rules once after an upgrade.
     *
     * Uploading a new zip over an installed plugin does not fire the activation
     * hook, so a release that changes post type registration would otherwise
     * leave the old rules in the database until somebody happened to re-save
     * permalinks. That matters here: the archive rules this version removes are
     * what stopped a page at /lab-test/ from resolving.
     *
     * Soft flush - the .htaccess is not touched.
     */
    public function maybe_flush_rewrites() {
        if (get_option('ecare_rewrite_version') === ECARE_VERSION) {
            return;
        }

        flush_rewrite_rules(false);
        update_option('ecare_rewrite_version', ECARE_VERSION, false);
    }

    public function init_plugin() {
        $this->load_dependencies();
    }

    public function init_elementor() {
        // Load Elementor custom widgets if Elementor is active
        if (did_action('elementor/loaded') || defined('ELEMENTOR_VERSION')) {
            require_once ECARE_PLUGIN_DIR . 'includes/class-ecare-elementor.php';
            ECare_Elementor::init();
        }
    }

    private function load_dependencies() {
        require_once ECARE_PLUGIN_DIR . 'includes/class-ecare-secure-files.php';
        require_once ECARE_PLUGIN_DIR . 'includes/class-ecare-cpt.php';
        require_once ECARE_PLUGIN_DIR . 'includes/class-ecare-ajax.php';
        require_once ECARE_PLUGIN_DIR . 'includes/class-ecare-woocommerce.php';
        require_once ECARE_PLUGIN_DIR . 'includes/class-ecare-shortcodes.php';
        require_once ECARE_PLUGIN_DIR . 'admin/class-ecare-admin.php';

        ECare_Secure_Files::init();
        ECare_CPT::init();
        ECare_Ajax::init();
        ECare_WooCommerce::init();
        ECare_Shortcodes::init();
        ECare_Admin::init();
    }

    /** The shortcodes this plugin provides. Elementor widget names match them. */
    private static function shortcode_tags() {
        return array(
            'ecare_caregiver_booking',
            'ecare_caregiver_registration',
            'ecare_lab_tests',
            'ecare_ambulance_request',
            'ecare_ambulance_registration',
        );
    }

    /**
     * Which E-Care blocks the page being rendered actually contains.
     *
     * The stylesheet, the script, Select2 and a Google Fonts request used to
     * load on every page of the site, checkout and blog posts included, for the
     * handful of pages that need them.
     *
     * Both post_content and _elementor_data are searched, because Elementor
     * keeps its layout in postmeta and has_shortcode() cannot see it. The widget
     * names are the same strings as the shortcode tags, so one search covers
     * both.
     *
     * Detection cannot see a shortcode printed from a theme template, a sidebar
     * widget, or an Elementor header, footer or popup template, since those live
     * on a different post. The ecare_blocks_on_page filter is the way out:
     *
     *     add_filter('ecare_blocks_on_page', function ($found, $post) {
     *         return is_page('help') ? array('ecare_lab_tests') : $found;
     *     }, 10, 2);
     *
     * @return string[] Empty when the page has none of our content.
     */
    private function ecare_blocks_on_page() {
        $found = array();
        $post  = is_singular() ? get_post() : null;

        if ($post instanceof WP_Post) {
            $haystack = (string) $post->post_content;

            $elementor = get_post_meta($post->ID, '_elementor_data', true);
            if (is_string($elementor) && $elementor !== '') {
                $haystack .= ' ' . $elementor;
            }

            foreach (self::shortcode_tags() as $tag) {
                if (strpos($haystack, $tag) !== false) {
                    $found[] = $tag;
                }
            }
        }

        return (array) apply_filters('ecare_blocks_on_page', $found, $post);
    }

    /**
     * Only our own admin screens, and the edit screens for our post types.
     */
    private function is_ecare_admin_screen() {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if (strpos($page, 'ecare-') === 0) {
            return true;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen) {
            return false;
        }

        if (in_array($screen->post_type, array('ecare_caregiver', 'ecare_lab_test', 'ecare_ambulance'), true)) {
            return true;
        }

        return $screen->taxonomy === 'ecare_caregiver_type';
    }

    public function admin_enqueue_scripts($hook) {
        if (!$this->is_ecare_admin_screen()) {
            return;
        }


        wp_enqueue_media();
        wp_enqueue_style('google-font-inter', 'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap', array(), null);
        
        $style_ver  = file_exists(ECARE_PLUGIN_DIR . 'assets/css/ecare-style.css') ? filemtime(ECARE_PLUGIN_DIR . 'assets/css/ecare-style.css') : ECARE_VERSION;
        $script_ver = file_exists(ECARE_PLUGIN_DIR . 'assets/js/ecare-script.js') ? filemtime(ECARE_PLUGIN_DIR . 'assets/js/ecare-script.js') : ECARE_VERSION;

        wp_enqueue_style('ecare-admin-style', ECARE_PLUGIN_URL . 'assets/css/ecare-style.css', array(), $style_ver);
        wp_enqueue_script('ecare-admin-script', ECARE_PLUGIN_URL . 'assets/js/ecare-script.js', array('jquery'), $script_ver, true);
        
        // Read only. Seeding the defaults from here meant every page view could
        // issue a database write; that now happens at activation and when a new
        // caregiver type is created.
        $type_packages = array();
        $terms = get_terms(array(
            'taxonomy'   => 'ecare_caregiver_type',
            'hide_empty' => false,
        ));
        if (!is_wp_error($terms) && !empty($terms)) {
            foreach ($terms as $term) {
                $type_packages[$term->name] = ECare_CPT::term_packages($term);
            }
        }

        do_action('litespeed_nonce', 'ecare_nonce');
        wp_localize_script('ecare-admin-script', 'ecare_ajax', array(
            'ajax_url'      => admin_url('admin-ajax.php'),
            'nonce'         => wp_create_nonce('ecare_nonce'),
            'type_packages' => $type_packages,
            'upload'        => array(
                'doc_max_bytes'   => ECare_Secure_Files::max_bytes(ECare_Secure_Files::KIND_DOCUMENT),
                'doc_max_label'   => size_format(ECare_Secure_Files::max_bytes(ECare_Secure_Files::KIND_DOCUMENT)),
                'doc_types'       => array_values(ECare_Secure_Files::allowed_mimes(ECare_Secure_Files::KIND_DOCUMENT)),
                'doc_types_label' => ECare_Secure_Files::allowed_extensions_label(ECare_Secure_Files::KIND_DOCUMENT),
                'img_max_bytes'   => ECare_Secure_Files::max_bytes(ECare_Secure_Files::KIND_IMAGE),
                'img_max_label'   => size_format(ECare_Secure_Files::max_bytes(ECare_Secure_Files::KIND_IMAGE)),
                'img_types'       => array_values(ECare_Secure_Files::allowed_mimes(ECare_Secure_Files::KIND_IMAGE)),
                'img_types_label' => ECare_Secure_Files::allowed_extensions_label(ECare_Secure_Files::KIND_IMAGE),
            ),
        ));
    }

    public function frontend_enqueue_scripts() {
        $ecare_blocks = $this->ecare_blocks_on_page();
        if (empty($ecare_blocks)) {
            return;
        }

        wp_enqueue_style('google-font-inter', 'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap', array(), null);
        
        $style_ver  = file_exists(ECARE_PLUGIN_DIR . 'assets/css/ecare-style.css') ? filemtime(ECARE_PLUGIN_DIR . 'assets/css/ecare-style.css') : ECARE_VERSION;
        $script_ver = file_exists(ECARE_PLUGIN_DIR . 'assets/js/ecare-script.js') ? filemtime(ECARE_PLUGIN_DIR . 'assets/js/ecare-script.js') : ECARE_VERSION;

        // Select2 drives the cascading location dropdowns, which only the lab
        // test catalogue has. WooCommerce is a hard dependency of this plugin and
        // already ships selectWoo, a select2 fork exposing the same $.fn.select2,
        // so prefer that over a third-party CDN: one less external request, one
        // less thing to be offline or to watch a visitor.
        $script_deps = array('jquery');

        if (in_array('ecare_lab_tests', $ecare_blocks, true)) {
            if (wp_script_is('selectWoo', 'registered')) {
                wp_enqueue_script('selectWoo');
                $script_deps[] = 'selectWoo';
            } else {
                wp_enqueue_style('ecare-select2-css', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css', array(), '4.1.0');
                wp_enqueue_script('ecare-select2-js', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', array('jquery'), '4.1.0', true);
                $script_deps[] = 'ecare-select2-js';
            }

            if (wp_style_is('select2', 'registered')) {
                wp_enqueue_style('select2');
            }
        }

        wp_enqueue_style('ecare-frontend-style', ECARE_PLUGIN_URL . 'assets/css/ecare-style.css', array(), $style_ver);
        wp_enqueue_script('ecare-frontend-script', ECARE_PLUGIN_URL . 'assets/js/ecare-script.js', $script_deps, $script_ver, true);
        
        // Read only. Seeding the defaults from here meant every page view could
        // issue a database write; that now happens at activation and when a new
        // caregiver type is created.
        $type_packages = array();
        $terms = get_terms(array(
            'taxonomy'   => 'ecare_caregiver_type',
            'hide_empty' => false,
        ));
        if (!is_wp_error($terms) && !empty($terms)) {
            foreach ($terms as $term) {
                $type_packages[$term->name] = ECare_CPT::term_packages($term);
            }
        }

        do_action('litespeed_nonce', 'ecare_nonce');
        wp_localize_script('ecare-frontend-script', 'ecare_ajax', array(
            'ajax_url'      => admin_url('admin-ajax.php'),
            'nonce'         => wp_create_nonce('ecare_nonce'),
            'type_packages' => $type_packages,
            'upload'        => array(
                'doc_max_bytes'   => ECare_Secure_Files::max_bytes(ECare_Secure_Files::KIND_DOCUMENT),
                'doc_max_label'   => size_format(ECare_Secure_Files::max_bytes(ECare_Secure_Files::KIND_DOCUMENT)),
                'doc_types'       => array_values(ECare_Secure_Files::allowed_mimes(ECare_Secure_Files::KIND_DOCUMENT)),
                'doc_types_label' => ECare_Secure_Files::allowed_extensions_label(ECare_Secure_Files::KIND_DOCUMENT),
                'img_max_bytes'   => ECare_Secure_Files::max_bytes(ECare_Secure_Files::KIND_IMAGE),
                'img_max_label'   => size_format(ECare_Secure_Files::max_bytes(ECare_Secure_Files::KIND_IMAGE)),
                'img_types'       => array_values(ECare_Secure_Files::allowed_mimes(ECare_Secure_Files::KIND_IMAGE)),
                'img_types_label' => ECare_Secure_Files::allowed_extensions_label(ECare_Secure_Files::KIND_IMAGE),
            ),
        ));
    }

    public function caregiver_form_enctype() {
        global $post;
        if ($post && $post->post_type === 'ecare_caregiver') {
            echo ' enctype="multipart/form-data"';
        }
    }
}

function ecare_health_services() {
    return ECare_Health_Services::instance();
}

ecare_health_services();
