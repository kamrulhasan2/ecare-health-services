<?php
/**
 * Plugin Name:       E-Care Health Services
 * Plugin URI:        https://github.com/kamrulhasan2/ecare-health-services
 * Description:       Comprehensive healthcare service booking and management – connects patients with caregivers, lab tests, and ambulance dispatch with WooCommerce payments. Shortcodes: [ecare_caregiver_booking] – filter & book caregivers; [ecare_caregiver_registration] – provider signup; [ecare_lab_tests] – diagnostic catalog; [ecare_ambulance_request] – ambulance dispatch; [ecare_ambulance_registration] – ambulance provider signup.
 * Version:           1.1.0
 * Author:            Md. Kamrul Hasan
 * License:           GPL v2 or later
 * Text Domain:       ecare-health-services
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 * Requires PHP:      8.1
 * Requires at least: 6.0
 */

defined('ABSPATH') || exit;

define('ECARE_VERSION', '1.1.0');
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
        add_action('plugins_loaded', array($this, 'init_elementor'), 20);
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        add_action('wp_enqueue_scripts', array($this, 'frontend_enqueue_scripts'));
        // Enable multipart form for caregiver photo upload
        add_action('post_edit_form_tag', array($this, 'caregiver_form_enctype'));

        // Every order read in this plugin goes through the WooCommerce CRUD
        // layer, so High-Performance Order Storage is safe. Without this
        // declaration WooCommerce lists the plugin as incompatible and warns the
        // site owner off a feature that in fact works.
        add_action('before_woocommerce_init', array($this, 'declare_woocommerce_compatibility'));
    }

    public function declare_woocommerce_compatibility() {
        if (class_exists('\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        }
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

    public function admin_enqueue_scripts($hook) {
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
        wp_enqueue_style('google-font-inter', 'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap', array(), null);
        
        $style_ver  = file_exists(ECARE_PLUGIN_DIR . 'assets/css/ecare-style.css') ? filemtime(ECARE_PLUGIN_DIR . 'assets/css/ecare-style.css') : ECARE_VERSION;
        $script_ver = file_exists(ECARE_PLUGIN_DIR . 'assets/js/ecare-script.js') ? filemtime(ECARE_PLUGIN_DIR . 'assets/js/ecare-script.js') : ECARE_VERSION;

        wp_enqueue_style('ecare-select2-css', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css', array(), '4.1.0');
        wp_enqueue_script('ecare-select2-js', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', array('jquery'), '4.1.0', true);

        wp_enqueue_style('ecare-frontend-style', ECARE_PLUGIN_URL . 'assets/css/ecare-style.css', array('ecare-select2-css'), $style_ver);
        wp_enqueue_script('ecare-frontend-script', ECARE_PLUGIN_URL . 'assets/js/ecare-script.js', array('jquery', 'ecare-select2-js'), $script_ver, true);
        
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
