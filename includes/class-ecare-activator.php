<?php
defined('ABSPATH') || exit;

class ECare_Activator {

    public static function activate() {
        self::create_tables();
        self::set_default_options();
    }

    private static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $table_bookings  = $wpdb->prefix . 'ecare_bookings';
        $table_locations = $wpdb->prefix . 'ecare_locations';

        $sql_bookings = "CREATE TABLE IF NOT EXISTS {$table_bookings} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            booking_type VARCHAR(50) NOT NULL COMMENT 'caregiver|lab|ambulance',
            user_id BIGINT UNSIGNED DEFAULT NULL,
            provider_id BIGINT UNSIGNED DEFAULT NULL,
            package_type VARCHAR(100) DEFAULT NULL,
            patient_name VARCHAR(255) DEFAULT NULL,
            patient_type VARCHAR(100) DEFAULT NULL,
            required_date DATE DEFAULT NULL,
            diaper_change TINYINT(1) DEFAULT 0,
            address TEXT DEFAULT NULL,
            contact_phone VARCHAR(50) DEFAULT NULL,
            disease TEXT DEFAULT NULL,
            file_urls TEXT DEFAULT NULL,
            lab_test_ids TEXT DEFAULT NULL,
            ambulance_type VARCHAR(100) DEFAULT NULL,
            pickup_address TEXT DEFAULT NULL,
            destination TEXT DEFAULT NULL,
            schedule_time DATETIME DEFAULT NULL,
            priority_level VARCHAR(50) DEFAULT 'Normal',
            notes TEXT DEFAULT NULL,
            total_amount DECIMAL(12,2) DEFAULT 0.00,
            status VARCHAR(50) DEFAULT 'pending' COMMENT 'pending|approved|completed|cancelled|dispatched|assigned',
            order_id BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_booking_type (booking_type),
            INDEX idx_user_id (user_id),
            INDEX idx_status (status),
            INDEX idx_provider_id (provider_id)
        ) {$charset_collate};";

        $sql_locations = "CREATE TABLE IF NOT EXISTS {$table_locations} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            location_type VARCHAR(50) NOT NULL COMMENT 'division|district|area|lab_provider',
            name VARCHAR(255) NOT NULL,
            parent_id BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_location_type (location_type),
            INDEX idx_parent_id (parent_id),
            INDEX idx_name (name)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_bookings);
        dbDelta($sql_locations);
    }

    private static function set_default_options() {
        if (!get_option('ecare_activation_date')) {
            update_option('ecare_activation_date', current_time('mysql'));
        }
        self::seed_locations();
    }

    private static function seed_locations() {
        global $wpdb;
        $table = $wpdb->prefix . 'ecare_locations';

        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        if ($count > 0) {
            return;
        }

        // Divisions
        $divisions = array(
            'Dhaka', 'Chattogram', 'Rajshahi', 'Khulna',
            'Barishal', 'Sylhet', 'Rangpur', 'Mymensingh',
        );

        $division_ids = array();
        foreach ($divisions as $div) {
            $wpdb->insert($table, array('location_type' => 'division', 'name' => $div));
            $division_ids[$div] = $wpdb->insert_id;
        }

        // District: Dhaka under Dhaka Division
        if (isset($division_ids['Dhaka'])) {
            $dhaka_div_id = $division_ids['Dhaka'];
            $wpdb->insert($table, array('location_type' => 'district', 'name' => 'Dhaka', 'parent_id' => $dhaka_div_id));
            $dhaka_dist_id = $wpdb->insert_id;

            // Areas: Ashkona, Mirpur, Uttara
            $areas = array('Ashkona', 'Mirpur', 'Uttara');
            $area_ids = array();
            foreach ($areas as $area) {
                $wpdb->insert($table, array('location_type' => 'area', 'name' => $area, 'parent_id' => $dhaka_dist_id));
                $area_ids[$area] = $wpdb->insert_id;
            }

            // Lab Providers under Areas
            if (isset($area_ids['Ashkona'])) {
                $ashkona_id = $area_ids['Ashkona'];
                $wpdb->insert($table, array('location_type' => 'lab_provider', 'name' => 'Praava Health', 'parent_id' => $ashkona_id));
            }
            if (isset($area_ids['Mirpur'])) {
                $mirpur_id = $area_ids['Mirpur'];
                $wpdb->insert($table, array('location_type' => 'lab_provider', 'name' => 'Labaid Diagnostics', 'parent_id' => $mirpur_id));
            }
            if (isset($area_ids['Uttara'])) {
                $uttara_id = $area_ids['Uttara'];
                $wpdb->insert($table, array('location_type' => 'lab_provider', 'name' => 'Popular Diagnostic', 'parent_id' => $uttara_id));
            }
        }
    }
}
