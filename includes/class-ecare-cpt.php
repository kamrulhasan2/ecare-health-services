<?php
defined('ABSPATH') || exit;

class ECare_CPT {

    public static function init() {
        add_action('init', array(__CLASS__, 'register_caregiver_cpt'));
        add_action('init', array(__CLASS__, 'register_lab_test_cpt'));
        add_action('init', array(__CLASS__, 'register_ambulance_provider_cpt'));

        add_action('add_meta_boxes', array(__CLASS__, 'add_meta_boxes'));
        add_action('save_post', array(__CLASS__, 'save_meta_boxes'));
    }

    public static function register_caregiver_cpt() {
        $labels = array(
            'name'               => __('Care Providers', 'ecare-health-services'),
            'singular_name'      => __('Care Provider', 'ecare-health-services'),
            'add_new'            => __('Add New', 'ecare-health-services'),
            'add_new_item'       => __('Add New Care Provider', 'ecare-health-services'),
            'edit_item'          => __('Edit Care Provider', 'ecare-health-services'),
            'view_item'          => __('View Care Provider', 'ecare-health-services'),
            'search_items'       => __('Search Care Providers', 'ecare-health-services'),
            'not_found'          => __('No care providers found', 'ecare-health-services'),
            'all_items'          => __('Care Providers', 'ecare-health-services'),
        );

        register_post_type('ecare_caregiver', array(
            'labels'       => $labels,
            'public'       => true,
            'has_archive'  => true,
            'supports'     => array('title', 'editor', 'thumbnail', 'excerpt'),
            'menu_icon'    => 'dashicons-nametag',
            'show_in_menu' => false,
            'rewrite'      => array('slug' => 'care-provider'),
        ));
    }

    public static function register_lab_test_cpt() {
        $labels = array(
            'name'               => __('Lab Tests', 'ecare-health-services'),
            'singular_name'      => __('Lab Test', 'ecare-health-services'),
            'add_new'            => __('Add New Test', 'ecare-health-services'),
            'add_new_item'       => __('Add New Lab Test', 'ecare-health-services'),
            'edit_item'          => __('Edit Lab Test', 'ecare-health-services'),
            'view_item'          => __('View Lab Test', 'ecare-health-services'),
            'search_items'       => __('Search Lab Tests', 'ecare-health-services'),
            'not_found'          => __('No lab tests found', 'ecare-health-services'),
            'all_items'          => __('Lab Tests', 'ecare-health-services'),
        );

        register_post_type('ecare_lab_test', array(
            'labels'       => $labels,
            'public'       => true,
            'has_archive'  => true,
            'supports'     => array('title', 'editor'),
            'menu_icon'    => 'dashicons-microscope',
            'show_in_menu' => false,
            'rewrite'      => array('slug' => 'lab-test'),
        ));
    }

    public static function register_ambulance_provider_cpt() {
        $labels = array(
            'name'               => __('Ambulance Providers', 'ecare-health-services'),
            'singular_name'      => __('Ambulance Provider', 'ecare-health-services'),
            'add_new'            => __('Add New', 'ecare-health-services'),
            'add_new_item'       => __('Add New Ambulance Provider', 'ecare-health-services'),
            'edit_item'          => __('Edit Ambulance Provider', 'ecare-health-services'),
            'view_item'          => __('View Ambulance Provider', 'ecare-health-services'),
            'search_items'       => __('Search Ambulance Providers', 'ecare-health-services'),
            'not_found'          => __('No ambulance providers found', 'ecare-health-services'),
            'all_items'          => __('Ambulance Providers', 'ecare-health-services'),
        );

        register_post_type('ecare_ambulance', array(
            'labels'       => $labels,
            'public'       => true,
            'has_archive'  => true,
            'supports'     => array('title', 'editor', 'thumbnail'),
            'menu_icon'    => 'dashicons-ambulance',
            'show_in_menu' => false,
            'rewrite'      => array('slug' => 'ambulance-provider'),
        ));
    }

    public static function add_meta_boxes() {
        add_meta_box('ecare_caregiver_details', __('Care Provider Details', 'ecare-health-services'), array(__CLASS__, 'render_caregiver_meta'), 'ecare_caregiver', 'normal', 'high');
        add_meta_box('ecare_lab_test_details', __('Lab Test Details', 'ecare-health-services'), array(__CLASS__, 'render_lab_test_meta'), 'ecare_lab_test', 'normal', 'high');
        add_meta_box('ecare_ambulance_details', __('Ambulance Provider Details', 'ecare-health-services'), array(__CLASS__, 'render_ambulance_meta'), 'ecare_ambulance', 'normal', 'high');
    }

    public static function render_caregiver_meta($post) {
        wp_nonce_field('ecare_caregiver_meta', 'ecare_caregiver_meta_nonce');
        $fields = array(
            'provider_type'  => get_post_meta($post->ID, '_provider_type', true),
            'experience'     => get_post_meta($post->ID, '_experience', true),
            'category'       => get_post_meta($post->ID, '_category', true),
            'skills'         => get_post_meta($post->ID, '_skills', true),
            'education'      => get_post_meta($post->ID, '_education', true),
            'nid_passport'   => get_post_meta($post->ID, '_nid_passport', true),
            'bank_name'      => get_post_meta($post->ID, '_bank_name', true),
            'bank_account'   => get_post_meta($post->ID, '_bank_account', true),
            'status'         => get_post_meta($post->ID, '_provider_status', true) ?: 'pending',
            'daily_12_price' => get_post_meta($post->ID, '_daily_12_price', true),
            'daily_24_price' => get_post_meta($post->ID, '_daily_24_price', true),
            'monthly_12_price' => get_post_meta($post->ID, '_monthly_12_price', true),
            'monthly_24_price' => get_post_meta($post->ID, '_monthly_24_price', true),
        );
        ?>
        <table class="form-table">
            <tr><th><label>Provider Type</label></th><td><select name="_provider_type"><?php foreach (array('Nurse', 'Senior Care', 'Nanny', 'Physiotherapist') as $t): ?><option value="<?php echo esc_attr($t); ?>" <?php selected($fields['provider_type'], $t); ?>><?php echo esc_html($t); ?></option><?php endforeach; ?></select></td></tr>
            <tr><th><label>Experience (years)</label></th><td><input type="number" name="_experience" value="<?php echo esc_attr($fields['experience']); ?>" class="regular-text" /></td></tr>
            <tr><th><label>Category</label></th><td><input type="text" name="_category" value="<?php echo esc_attr($fields['category']); ?>" class="regular-text" /></td></tr>
            <tr><th><label>Skills</label></th><td><textarea name="_skills" class="large-text" rows="3"><?php echo esc_textarea($fields['skills']); ?></textarea></td></tr>
            <tr><th><label>Education</label></th><td><textarea name="_education" class="large-text" rows="3"><?php echo esc_textarea($fields['education']); ?></textarea></td></tr>
            <tr><th><label>NID/Passport</label></th><td><input type="text" name="_nid_passport" value="<?php echo esc_attr($fields['nid_passport']); ?>" class="regular-text" /></td></tr>
            <tr><th><label>Bank Name</label></th><td><input type="text" name="_bank_name" value="<?php echo esc_attr($fields['bank_name']); ?>" class="regular-text" /></td></tr>
            <tr><th><label>Bank Account</label></th><td><input type="text" name="_bank_account" value="<?php echo esc_attr($fields['bank_account']); ?>" class="regular-text" /></td></tr>
            <tr><th><label>Daily 12H Price (৳)</label></th><td><input type="number" step="0.01" name="_daily_12_price" value="<?php echo esc_attr($fields['daily_12_price']); ?>" class="regular-text" /></td></tr>
            <tr><th><label>Daily 24H Price (৳)</label></th><td><input type="number" step="0.01" name="_daily_24_price" value="<?php echo esc_attr($fields['daily_24_price']); ?>" class="regular-text" /></td></tr>
            <tr><th><label>Monthly 12H Price (৳)</label></th><td><input type="number" step="0.01" name="_monthly_12_price" value="<?php echo esc_attr($fields['monthly_12_price']); ?>" class="regular-text" /></td></tr>
            <tr><th><label>Monthly 24H Price (৳)</label></th><td><input type="number" step="0.01" name="_monthly_24_price" value="<?php echo esc_attr($fields['monthly_24_price']); ?>" class="regular-text" /></td></tr>
            <tr><th><label>Status</label></th><td><select name="_provider_status"><option value="pending" <?php selected($fields['status'], 'pending'); ?>>Pending</option><option value="approved" <?php selected($fields['status'], 'approved'); ?>>Approved</option><option value="rejected" <?php selected($fields['status'], 'rejected'); ?>>Rejected</option></select></td></tr>
        </table>
        <?php
    }

    public static function render_lab_test_meta($post) {
        wp_nonce_field('ecare_lab_test_meta', 'ecare_lab_test_meta_nonce');
        $fields = array(
            'test_code'       => get_post_meta($post->ID, '_test_code', true),
            'price'           => get_post_meta($post->ID, '_price', true),
            'category'        => get_post_meta($post->ID, '_test_category', true),
            'sample_type'     => get_post_meta($post->ID, '_sample_type', true),
            'turnaround_days' => get_post_meta($post->ID, '_turnaround_days', true),
            'lab_provider'    => get_post_meta($post->ID, '_lab_provider', true),
            'division'        => get_post_meta($post->ID, '_division', true),
            'district'        => get_post_meta($post->ID, '_district', true),
            'area'            => get_post_meta($post->ID, '_area', true),
            'status'          => get_post_meta($post->ID, '_test_status', true) ?: 'active',
        );
        ?>
        <table class="form-table">
            <tr><th><label>Test Code</label></th><td><input type="text" name="_test_code" value="<?php echo esc_attr($fields['test_code']); ?>" class="regular-text" /></td></tr>
            <tr><th><label>Price (৳)</label></th><td><input type="number" step="0.01" name="_price" value="<?php echo esc_attr($fields['price']); ?>" class="regular-text" /></td></tr>
            <tr><th><label>Category</label></th><td><input type="text" name="_test_category" value="<?php echo esc_attr($fields['category']); ?>" class="regular-text" /></td></tr>
            <tr><th><label>Sample Type</label></th><td><input type="text" name="_sample_type" value="<?php echo esc_attr($fields['sample_type']); ?>" class="regular-text" placeholder="Blood, Urine, etc." /></td></tr>
            <tr><th><label>Turnaround Days</label></th><td><input type="number" name="_turnaround_days" value="<?php echo esc_attr($fields['turnaround_days']); ?>" class="regular-text" /></td></tr>
            <tr><th><label>Lab Provider</label></th><td><input type="text" name="_lab_provider" value="<?php echo esc_attr($fields['lab_provider']); ?>" class="regular-text" /></td></tr>
            <tr><th><label>Division</label></th><td><input type="text" name="_division" value="<?php echo esc_attr($fields['division']); ?>" class="regular-text" /></td></tr>
            <tr><th><label>District</label></th><td><input type="text" name="_district" value="<?php echo esc_attr($fields['district']); ?>" class="regular-text" /></td></tr>
            <tr><th><label>Area</label></th><td><input type="text" name="_area" value="<?php echo esc_attr($fields['area']); ?>" class="regular-text" /></td></tr>
            <tr><th><label>Status</label></th><td><select name="_test_status"><option value="active" <?php selected($fields['status'], 'active'); ?>>Active</option><option value="inactive" <?php selected($fields['status'], 'inactive'); ?>>Inactive</option></select></td></tr>
        </table>
        <?php
    }

    public static function render_ambulance_meta($post) {
        wp_nonce_field('ecare_ambulance_meta', 'ecare_ambulance_meta_nonce');
        $fields = array(
            'license_plate'   => get_post_meta($post->ID, '_license_plate', true),
            'vehicle_model'   => get_post_meta($post->ID, '_vehicle_model', true),
            'driver_name'     => get_post_meta($post->ID, '_driver_name', true),
            'driver_license'  => get_post_meta($post->ID, '_driver_license', true),
            'driver_nid'      => get_post_meta($post->ID, '_driver_nid', true),
            'ambulance_type'  => get_post_meta($post->ID, '_ambulance_type', true),
            'base_price'      => get_post_meta($post->ID, '_base_price', true),
            'status'          => get_post_meta($post->ID, '_ambulance_status', true) ?: 'pending',
        );
        ?>
        <table class="form-table">
            <tr><th><label>License Plate</label></th><td><input type="text" name="_license_plate" value="<?php echo esc_attr($fields['license_plate']); ?>" class="regular-text" /></td></tr>
            <tr><th><label>Vehicle Model</label></th><td><input type="text" name="_vehicle_model" value="<?php echo esc_attr($fields['vehicle_model']); ?>" class="regular-text" /></td></tr>
            <tr><th><label>Driver Name</label></th><td><input type="text" name="_driver_name" value="<?php echo esc_attr($fields['driver_name']); ?>" class="regular-text" /></td></tr>
            <tr><th><label>Driving License No</label></th><td><input type="text" name="_driver_license" value="<?php echo esc_attr($fields['driver_license']); ?>" class="regular-text" /></td></tr>
            <tr><th><label>Driver NID</label></th><td><input type="text" name="_driver_nid" value="<?php echo esc_attr($fields['driver_nid']); ?>" class="regular-text" /></td></tr>
            <tr><th><label>Ambulance Type</label></th><td><select name="_ambulance_type"><option value="Standard" <?php selected($fields['ambulance_type'], 'Standard'); ?>>Standard (Non-AC)</option><option value="ICU" <?php selected($fields['ambulance_type'], 'ICU'); ?>>ICU (AC)</option><option value="Freezer" <?php selected($fields['ambulance_type'], 'Freezer'); ?>>Freezer Type</option></select></td></tr>
            <tr><th><label>Base Price (৳)</label></th><td><input type="number" step="0.01" name="_base_price" value="<?php echo esc_attr($fields['base_price']); ?>" class="regular-text" /></td></tr>
            <tr><th><label>Status</label></th><td><select name="_ambulance_status"><option value="pending" <?php selected($fields['status'], 'pending'); ?>>Pending</option><option value="approved" <?php selected($fields['status'], 'approved'); ?>>Approved</option><option value="rejected" <?php selected($fields['status'], 'rejected'); ?>>Rejected</option></select></td></tr>
        </table>
        <?php
    }

    public static function save_meta_boxes($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $post_type = get_post_type($post_id);

        if ($post_type === 'ecare_caregiver') {
            if (!isset($_POST['ecare_caregiver_meta_nonce']) || !wp_verify_nonce($_POST['ecare_caregiver_meta_nonce'], 'ecare_caregiver_meta')) return;
            $keys = array('_provider_type', '_experience', '_category', '_skills', '_education', '_nid_passport', '_bank_name', '_bank_account', '_daily_12_price', '_daily_24_price', '_monthly_12_price', '_monthly_24_price', '_provider_status');
            foreach ($keys as $key) {
                if (isset($_POST[$key])) {
                    update_post_meta($post_id, $key, sanitize_text_field($_POST[$key]));
                }
            }
        }

        if ($post_type === 'ecare_lab_test') {
            if (!isset($_POST['ecare_lab_test_meta_nonce']) || !wp_verify_nonce($_POST['ecare_lab_test_meta_nonce'], 'ecare_lab_test_meta')) return;
            $keys = array('_test_code', '_price', '_test_category', '_sample_type', '_turnaround_days', '_lab_provider', '_division', '_district', '_area', '_test_status');
            foreach ($keys as $key) {
                if (isset($_POST[$key])) {
                    update_post_meta($post_id, $key, sanitize_text_field($_POST[$key]));
                }
            }
        }

        if ($post_type === 'ecare_ambulance') {
            if (!isset($_POST['ecare_ambulance_meta_nonce']) || !wp_verify_nonce($_POST['ecare_ambulance_meta_nonce'], 'ecare_ambulance_meta')) return;
            $keys = array('_license_plate', '_vehicle_model', '_driver_name', '_driver_license', '_driver_nid', '_ambulance_type', '_base_price', '_ambulance_status');
            foreach ($keys as $key) {
                if (isset($_POST[$key])) {
                    update_post_meta($post_id, $key, sanitize_text_field($_POST[$key]));
                }
            }
        }
    }
}
