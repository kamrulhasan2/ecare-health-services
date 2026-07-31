<?php
/**
 * Uninstall routine for E-Care Health Services.
 *
 * Runs only when the plugin is deleted from the WordPress admin.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

$tables = array(
    $wpdb->prefix . 'ecare_bookings',
    $wpdb->prefix . 'ecare_locations',
);

foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS {$table}");
}

delete_option('ecare_activation_date');

$post_types = array(
    'ecare_caregiver',
    'ecare_lab_test',
    'ecare_ambulance',
);

foreach ($post_types as $post_type) {
    $posts = get_posts(array(
        'post_type'      => $post_type,
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ));

    if (empty($posts)) {
        continue;
    }

    foreach ($posts as $post_id) {
        wp_delete_post($post_id, true);
    }
}

