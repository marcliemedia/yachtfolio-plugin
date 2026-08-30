<?php
/**
 * Uninstall routine.
 *
 * Deleting sync state is opt-in: the plugin never removes yacht posts, meta or
 * media, because those are the site's content, not the plugin's.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$settings = get_option('oy_yf_settings');
$remove = is_array($settings) && !empty($settings['remove_data_on_uninstall']);

if (!$remove) {
    return;
}

global $wpdb;

foreach (['oy_yf_map', 'oy_yf_run', 'oy_yf_log', 'oy_yf_media'] as $table) {
    $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . $table);
}

foreach ([
    'oy_yf_settings',
    'oy_yf_db_version',
    'oy_yf_reference_cache',
    'oy_yf_field_map',
    'oy_yf_equipment_map',
    'oy_yf_area_alias',
    'oy_yf_type_alias',
    'oy_yf_last_unmapped',
    'oy_yf_budget',
] as $option) {
    delete_option($option);
}

$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'oy_yf_lock_%'");

$role = get_role('administrator');
if ($role) {
    $role->remove_cap('manage_yachtfolio');
}
