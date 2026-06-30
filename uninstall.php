<?php
/**
 * Uninstall handler.
 *
 * Runs when the plugin is deleted from the WordPress plugin screen.
 * Drops all archive tables and removes all plugin options and transients.
 *
 * This file is executed directly by WordPress - NOT via autoloader.
 * No classes, no namespaces. Plain procedural PHP only.
 *
 * @package HW\WOAM
 */

// WordPress calls this directly. Exit if accessed outside the context.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop all archive tables.
$hw_woam_tables = array(
	$wpdb->prefix . 'hw_woam_orders',
	$wpdb->prefix . 'hw_woam_orders_meta',
	$wpdb->prefix . 'hw_woam_order_items',
	$wpdb->prefix . 'hw_woam_order_items_meta',
	$wpdb->prefix . 'hw_woam_order_notes',
	$wpdb->prefix . 'hw_woam_order_notes_meta',
	$wpdb->prefix . 'hw_woam_logs',
	$wpdb->prefix . 'hw_woam_order_refunds',
	$wpdb->prefix . 'hw_woam_order_refunds_meta',
);

foreach ( $hw_woam_tables as $hw_woam_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS `{$hw_woam_table}`" );
}

// Delete plugin options.
delete_option( 'hw_woam_db_version' );
delete_option( 'hw_woam_growth_history' );
delete_option( 'hw_woam_benchmarks' );
delete_option( 'hw_woam_onboarding_completed' );
delete_option( 'hw_woam_onboarding_skipped' );
delete_option( 'hw_woam_last_health_cron' );

// Delete transients.
delete_transient( 'hw_woam_job_running' );
delete_transient( 'hw_woam_order_date_range' );
delete_transient( 'hw_woam_health_score_cache' );

// Clear any scheduled cron event.
wp_clear_scheduled_hook( 'hw_woam_refresh_health_score' );
