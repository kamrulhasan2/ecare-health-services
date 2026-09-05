<?php
defined('ABSPATH') || exit;

class ECare_Activator {

    public static function activate() {
        self::create_tables();
        self::set_default_options();

        // Private storage for prescriptions and identity documents.
        if (class_exists('ECare_Secure_Files')) {
            ECare_Secure_Files::ensure_dir();
        }

        // Persist the default package prices once, here, rather than letting a
        // front-end page view do it.
        if (class_exists('ECare_CPT')) {
            ECare_CPT::seed_all_term_packages();
        }
    }

    private static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $table_bookings  = $wpdb->prefix . 'ecare_bookings';
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

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_bookings);

        // There is deliberately no wp_ecare_locations table any more. It was
        // created and seeded with divisions, districts, areas and lab providers,
        // and then never read: get_locations() builds the cascading dropdowns by
        // parsing the comma-separated _division / _district / _area /
        // _lab_provider meta on each lab test instead. Keeping a table that only
        // looks authoritative is how the next person ends up adding rows to it
        // and wondering why nothing changes. uninstall.php still drops it, so an
        // older install cleans up on delete; to remove it now, run:
        //   DROP TABLE IF EXISTS {$wpdb->prefix}ecare_locations;
    }

    private static function set_default_options() {
        if (!get_option('ecare_activation_date')) {
            update_option('ecare_activation_date', current_time('mysql'));
        }
    }
}
