<?php
defined('ABSPATH') || exit;

class ECare_Elementor_Caregiver_Booking extends \Elementor\Widget_Base {
    public function get_name() { return 'ecare_caregiver_booking'; }
    public function get_title() { return esc_html__('E-Care Caregiver Booking', 'ecare-health-services'); }
    public function get_icon() { return 'eicon-user-circle-o'; }
    public function get_categories() { return array('ecare-elements'); }
    protected function render() {
        echo do_shortcode('[ecare_caregiver_booking]');
    }
}

class ECare_Elementor_Caregiver_Registration extends \Elementor\Widget_Base {
    public function get_name() { return 'ecare_caregiver_registration'; }
    public function get_title() { return esc_html__('E-Care Caregiver Reg Form', 'ecare-health-services'); }
    public function get_icon() { return 'eicon-form-horizontal'; }
    public function get_categories() { return array('ecare-elements'); }
    protected function render() {
        echo do_shortcode('[ecare_caregiver_registration]');
    }
}

class ECare_Elementor_Lab_Tests extends \Elementor\Widget_Base {
    public function get_name() { return 'ecare_lab_tests'; }
    public function get_title() { return esc_html__('E-Care Lab Tests Catalog', 'ecare-health-services'); }
    public function get_icon() { return 'eicon-product-grid'; }
    public function get_categories() { return array('ecare-elements'); }
    protected function render() {
        echo do_shortcode('[ecare_lab_tests]');
    }
}

class ECare_Elementor_Ambulance_Booking extends \Elementor\Widget_Base {
    public function get_name() { return 'ecare_ambulance_request'; }
    public function get_title() { return esc_html__('E-Care Ambulance Booking', 'ecare-health-services'); }
    public function get_icon() { return 'eicon-google-maps'; }
    public function get_categories() { return array('ecare-elements'); }
    protected function render() {
        echo do_shortcode('[ecare_ambulance_request]');
    }
}

class ECare_Elementor_Ambulance_Registration extends \Elementor\Widget_Base {
    public function get_name() { return 'ecare_ambulance_registration'; }
    public function get_title() { return esc_html__('E-Care Ambulance Reg Form', 'ecare-health-services'); }
    public function get_icon() { return 'eicon-document-file'; }
    public function get_categories() { return array('ecare-elements'); }
    protected function render() {
        echo do_shortcode('[ecare_ambulance_registration]');
    }
}
