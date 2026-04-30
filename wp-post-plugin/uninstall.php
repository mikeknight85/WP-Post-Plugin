<?php
/**
 * Fired when the plugin is deleted via the WP admin.
 * Removes plugin options. Shipment CPT posts and label files are left
 * on disk so they aren't destroyed accidentally.
 *
 * @package WPPost
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$options = [
    'wpp_language',
    'wpp_prod_client_id',
    'wpp_prod_client_secret',
    'wpp_prod_subscription_key',
    'wpp_franking_license',
    'wpp_default_product',
    'wpp_default_label_format',
    'wpp_default_label_size',
    'wpp_default_resolution',
    'wpp_sender_address',
    // Legacy options removed in 0.3.0 — kept here so uninstall on an old
    // install still cleans up after itself.
    'wpp_environment',
    'wpp_test_client_id',
    'wpp_test_client_secret',
    'wpp_test_subscription_key',
    'wpp_default_przl',
];
foreach ($options as $opt) {
    delete_option($opt);
    delete_site_option($opt);
}

// Drop any cached OAuth tokens.
global $wpdb;
$like = $wpdb->esc_like('_site_transient_wpp_dcapi_token_') . '%';
$wpdb->query(
    $wpdb->prepare("DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s", $like)
);
$like2 = $wpdb->esc_like('_transient_wpp_dcapi_token_') . '%';
$wpdb->query(
    $wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like2)
);
