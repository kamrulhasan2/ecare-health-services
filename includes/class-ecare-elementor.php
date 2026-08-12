<?php
defined('ABSPATH') || exit;

class ECare_Elementor {

    public static function init() {
        add_action('elementor/elements/categories_registered', array(__CLASS__, 'register_category'));
        add_action('elementor/widgets/register', array(__CLASS__, 'register_widgets'));
    }

    public static function register_category($elements_manager) {
        $elements_manager->add_category(
            'ecare-elements',
            array(
                'title' => esc_html__('E-Care Health Services', 'ecare-health-services'),
                'icon'  => 'fa fa-heartbeat',
            )
        );
    }

    public static function register_widgets($widgets_manager) {
        require_once plugin_dir_path(__FILE__) . 'class-ecare-elementor-widgets.php';
        
        $widgets_manager->register(new ECare_Elementor_Caregiver_Booking());
        $widgets_manager->register(new ECare_Elementor_Caregiver_Registration());
        $widgets_manager->register(new ECare_Elementor_Lab_Tests());
        $widgets_manager->register(new ECare_Elementor_Ambulance_Booking());
        $widgets_manager->register(new ECare_Elementor_Ambulance_Registration());
    }
}
