<?php
/**
 * Uninstall routine for E-Care Health Services.
 *
 * WordPress runs this when the plugin is deleted from the Plugins screen - and
 * deleting a plugin is an ordinary housekeeping act: replacing one copy with
 * another, clearing a duplicate entry, renaming a folder. None of that should
 * cost a clinic its booking history, so nothing here removes anything unless
 * the site owner has asked for it in as many words:
 *
 *     wp option update ecare_delete_data_on_uninstall yes
 *
 * Without that option, deleting the plugin removes only the plugin's own files.
 * Every booking, provider, lab test, caregiver type and uploaded document stays
 * exactly where it is, ready for the next copy of the plugin to pick up.
 *
 * With it, the routine below runs in full and is deliberately thorough: custom
 * tables, all three post types (trashed and auto-draft rows included), the
 * caregiver-type taxonomy and its package prices, the plugin's options and
 * upload rate-limit transients, and the private document store. The opt-in
 * option is cleared last, so a later reinstall starts from the safe default.
 *
 * Not touched even then: Media Library attachments, WooCommerce orders and
 * their line items. Those belong to other plugins and to the shop's records.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

if (get_option('ecare_delete_data_on_uninstall') !== 'yes') {
    return;
}

global $wpdb;

// ---------------------------------------------------------------------------
// Custom tables
// ---------------------------------------------------------------------------
$ecare_tables = array(
    $wpdb->prefix . 'ecare_bookings',
    // Never created since 1.1.0, but sites upgraded from 1.0.0 still carry it.
    $wpdb->prefix . 'ecare_locations',
);

foreach ($ecare_tables as $ecare_table) {
    $wpdb->query("DROP TABLE IF EXISTS {$ecare_table}");
}

// ---------------------------------------------------------------------------
// Content
//
// post_status is listed out rather than passed as 'any': 'any' skips trashed
// and auto-draft rows, which would leave a deleted provider's record - name,
// phone, NID - behind after the plugin that owned it is gone.
// ---------------------------------------------------------------------------
$ecare_statuses = array_keys(get_post_stati());

$ecare_post_types = array(
    'ecare_caregiver',
    'ecare_lab_test',
    'ecare_ambulance',
);

foreach ($ecare_post_types as $ecare_post_type) {
    // Batched, so a large catalogue cannot exhaust memory. The pass counter is
    // a stop for the case where a post refuses to delete: without it the same
    // batch would come back for ever.
    for ($ecare_pass = 0; $ecare_pass < 500; $ecare_pass++) {
        $ecare_posts = get_posts(array(
            'post_type'        => $ecare_post_type,
            'post_status'      => $ecare_statuses,
            'posts_per_page'   => 100,
            'fields'           => 'ids',
            'suppress_filters' => true,
            'no_found_rows'    => true,
        ));

        if (empty($ecare_posts)) {
            break;
        }

        $ecare_deleted = 0;
        foreach ($ecare_posts as $ecare_post_id) {
            if (wp_delete_post($ecare_post_id, true)) {
                $ecare_deleted++;
            }
        }

        if ($ecare_deleted === 0) {
            break;
        }
    }
}

// ---------------------------------------------------------------------------
// Caregiver types
//
// The taxonomy is not registered during uninstall - the plugin's files are not
// loaded - so get_terms() and wp_delete_term() are unavailable to us here. The
// rows are removed directly instead.
// ---------------------------------------------------------------------------
$ecare_terms = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT term_id, term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s",
        'ecare_caregiver_type'
    )
);

foreach ((array) $ecare_terms as $ecare_term) {
    $wpdb->delete($wpdb->term_relationships, array('term_taxonomy_id' => (int) $ecare_term->term_taxonomy_id), array('%d'));
    $wpdb->delete($wpdb->term_taxonomy,      array('term_taxonomy_id' => (int) $ecare_term->term_taxonomy_id), array('%d'));
    $wpdb->delete($wpdb->termmeta,           array('term_id'          => (int) $ecare_term->term_id),          array('%d'));
    $wpdb->delete($wpdb->terms,              array('term_id'          => (int) $ecare_term->term_id),          array('%d'));
}

// ---------------------------------------------------------------------------
// Options and transients
// ---------------------------------------------------------------------------
$ecare_options = array(
    'ecare_activation_date',
    'ecare_rewrite_version',
    'ecare_default_daily_12_price',
    'ecare_default_daily_24_price',
    'ecare_default_monthly_12_price',
    'ecare_default_monthly_24_price',
    'ecare_default_physio_regular_price',
    'ecare_default_physio_premium_price',
);

foreach ($ecare_options as $ecare_option) {
    delete_option($ecare_option);
}

// Upload rate-limit counters: ecare_ul_<md5 of ip>, stored as transients.
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like('_transient_ecare_ul_') . '%',
        $wpdb->esc_like('_transient_timeout_ecare_ul_') . '%'
    )
);

// ---------------------------------------------------------------------------
// Private document store
//
// Prescriptions and identity documents live outside the Media Library, so
// nothing else will ever clean them up. Every step is checked against the
// uploads basedir, and symlinks are unlinked rather than followed.
// ---------------------------------------------------------------------------
if (!function_exists('ecare_uninstall_delete_tree')) {
    function ecare_uninstall_delete_tree($path, $base) {
        $path = wp_normalize_path($path);

        if ($path === '' || strpos($path, $base . '/') !== 0) {
            return;
        }

        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $entries = @scandir($path);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            ecare_uninstall_delete_tree($path . '/' . $entry, $base);
        }

        @rmdir($path);
    }
}

$ecare_uploads = wp_upload_dir();

if (empty($ecare_uploads['error']) && !empty($ecare_uploads['basedir'])) {
    $ecare_base    = wp_normalize_path(untrailingslashit($ecare_uploads['basedir']));
    $ecare_private = realpath($ecare_base . '/ecare-private');

    if ($ecare_private !== false) {
        ecare_uninstall_delete_tree($ecare_private, $ecare_base);
    }
}

// Back to the safe default for whoever installs this next.
delete_option('ecare_delete_data_on_uninstall');
