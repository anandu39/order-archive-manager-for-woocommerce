<?php
/**
 *
 * Uninstall handler,
 *
 * Runs when the plugin is deleted from the WordPress plugin screen.
 * Drops all archive tables and remove all plugin options and transcients.
 *
 * This file is executed directly by WordPress - NOT via autoloader.
 * No Classes, no namespaces. Plain procedural PHP only.
 *
 * @package HW\WOAM
 */

// WordPress call this directly. Exit if accessed outside the context.

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop all archive tables.

$tables = array(
	$wpdb->prefix . 'woam_orders',
	$wpdb->prefix . 'woam_orders_meta',
	$wpdb->prefix . 'woam_order_items',
	$wpdb->prefix . 'woam_order_items_meta',
	$wpdb->prefix . 'woam_order_notes',
	$wpdb->prefix . 'woam_order_notes_meta',
	$wpdb->prefix . 'woam_logs',
	$wpdb->prefix . 'woam_order_refunds',
	$wpdb->prefix . 'woam_order_refunds_meta',
);

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

// Delete plugin options.

delete_option( 'hw_woam_db_version' );

// Delete transients.

delete_option( 'hw_woam_job_running' );
delete_option( 'hw_woam_order_date_range' );
