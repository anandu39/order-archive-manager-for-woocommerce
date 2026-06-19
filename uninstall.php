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
	// Current tables.
	$wpdb->prefix . 'woam_orders',
	$wpdb->prefix . 'woam_orders_meta',
	$wpdb->prefix . 'woam_order_items',
	$wpdb->prefix . 'woam_order_items_meta',
	$wpdb->prefix . 'woam_order_notes',
	$wpdb->prefix . 'woam_order_notes_meta',
	$wpdb->prefix . 'woam_logs',
	$wpdb->prefix . 'woam_order_refunds',
	$wpdb->prefix . 'woam_order_refunds_meta',

	// Legacy beta tables (created by older versions, clean them up too).
	$wpdb->prefix . 'woam_orders_archive',
	$wpdb->prefix . 'woam_orders_meta_archive',
	$wpdb->prefix . 'woam_order_items_archive',
	$wpdb->prefix . 'woam_order_itemmeta_archive',
);

foreach ( $hw_woam_tables as $hw_woam_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS `{$hw_woam_table}`" );
}

// Delete plugin options.
delete_option( 'hw_woam_db_version' );

// Delete transients.
delete_option( 'hw_woam_job_running' );
delete_option( 'hw_woam_order_date_range' );
