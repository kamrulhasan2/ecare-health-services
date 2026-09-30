<?php
defined('ABSPATH') || exit;

/**
 * Elementor widgets for the new lab front end. Kept out of
 * class-ecare-elementor-widgets.php so the existing widgets are untouched.
 * Widget names match the shortcode tags, which is how asset loading finds them.
 */
class ECare_Elementor_Lab_Home extends \Elementor\Widget_Base {
    public function get_name() { return 'ecare_lab_home'; }
    public function get_title() { return esc_html__('E-Care Lab Home', 'ecare-health-services'); }
    public function get_icon() { return 'eicon-apps'; }
    public function get_categories() { return array('ecare-elements'); }
    protected function render() {
        echo do_shortcode('[ecare_lab_home]');
    }
}
