<?php
/**
 * Plugin Name:       E-Care Health Services
 * Plugin URI:        https://ecarehealth.com/
 * Description:       Comprehensive healthcare service booking and management – connects patients with caregivers, lab tests, and ambulance dispatch with WooCommerce payments. Shortcodes: [ecare_caregiver_booking] – filter & book caregivers; [ecare_caregiver_registration] – provider signup; [ecare_lab_tests] – diagnostic catalog; [ecare_ambulance_request] – ambulance dispatch; [ecare_ambulance_registration] – ambulance provider signup.
 * Version:           1.0.0
 * Author:            E-Care Health
 * License:           GPL v2 or later
 * Text Domain:       ecare-health-services
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 * Requires PHP:      8.1
 * Requires at least: 6.0
 */

defined('ABSPATH') || exit;

define('ECARE_VERSION', '1.0.0');
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
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        add_action('wp_enqueue_scripts', array($this, 'frontend_enqueue_scripts'));
    }

    public function activate() {
        require_once ECARE_PLUGIN_DIR . 'includes/class-ecare-activator.php';
        ECare_Activator::activate();
    }

    public function deactivate() {
        // Cleanup if needed
    }

    public function init_plugin() {
        $this->load_dependencies();
    }

    private function load_dependencies() {
        require_once ECARE_PLUGIN_DIR . 'includes/class-ecare-cpt.php';
        require_once ECARE_PLUGIN_DIR . 'includes/class-ecare-ajax.php';
        require_once ECARE_PLUGIN_DIR . 'includes/class-ecare-woocommerce.php';
        require_once ECARE_PLUGIN_DIR . 'includes/class-ecare-shortcodes.php';
        require_once ECARE_PLUGIN_DIR . 'admin/class-ecare-admin.php';

        ECare_CPT::init();
        ECare_Ajax::init();
        ECare_WooCommerce::init();
        ECare_Shortcodes::init();
        ECare_Admin::init();
    }

    public function admin_enqueue_scripts($hook) {
        wp_enqueue_style('google-font-inter', 'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap', array(), null);
        wp_enqueue_style('ecare-admin-style', ECARE_PLUGIN_URL . 'assets/css/ecare-style.css', array(), ECARE_VERSION);
        wp_enqueue_script('ecare-admin-script', ECARE_PLUGIN_URL . 'assets/js/ecare-script.js', array('jquery'), ECARE_VERSION, true);
        wp_localize_script('ecare-admin-script', 'ecare_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('ecare_nonce'),
        ));
    }

    public function frontend_enqueue_scripts() {
        wp_enqueue_style('google-font-inter', 'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap', array(), null);
        wp_enqueue_style('ecare-frontend-style', ECARE_PLUGIN_URL . 'assets/css/ecare-style.css', array(), ECARE_VERSION);
        wp_enqueue_script('ecare-frontend-script', ECARE_PLUGIN_URL . 'assets/js/ecare-script.js', array('jquery'), ECARE_VERSION, true);
        wp_localize_script('ecare-frontend-script', 'ecare_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('ecare_nonce'),
        ));
    }
}

function ecare_health_services() {
    return ECare_Health_Services::instance();
}

ecare_health_services();
