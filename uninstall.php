<?php
/**
 * Admin Compass Uninstall
 *
 * Uninstalling Admin Compass deletes the search index table and options.
 */

// Exit if accessed directly or not during uninstall
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Remove the search index table
$table_name = $wpdb->prefix . 'admin_compass_search_index';
$wpdb->query("DROP TABLE IF EXISTS {$table_name}");

// Remove options
delete_option('admin_compass_version');
delete_option('admin_compass_indexing_in_progress');
delete_option('admin_compass_indexing_started');

// Remove transients
delete_transient('admin_compass_reindex_admin_menu');

// Clear any scheduled events
wp_clear_scheduled_hook('admin_compass_rebuild_index');