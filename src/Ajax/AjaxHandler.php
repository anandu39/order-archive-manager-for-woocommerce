<?php

/**
 *
 * Ajax Handler
 * Bridge between the admin UI and ArchiveHandler, RestoreHandler and DeleteHandler.
 * Each endpoints verify nonce + capability, prevent concurrent jobs via
 * a transient lock, runs one batch, and return a JSON summary.
 *
 * @package HW\WOAM\Ajax
 */

namespace HW\WOAM\Ajax;

use HW\WOAM\Archive\ArchiveHandler;
use HW\WOAM\Archive\RestoreHandler;
use HW\WOAM\Archive\DeleteHandler;

defined( 'ABSPATH' ) || exit;

/**
 *
 * Class AjaxHandler
 */

class AjaxHandler {

	/**
	 *
	 * Archive Handler.
	 *
	 * @var ArchiveHandler
	 */

	private ArchiveHandler $archive_handler;

	/**
	 *
	 * Restore Handler
	 *
	 * @var RestoreHandler
	 */

	private RestoreHandler $restore_handler;

	/**
	 *
	 * Delete Handler
	 *
	 * @var DeleteHandler
	 */

	private DeleteHandler $delete_handler;

	/**
	 * Transient key used to prevent concurrent batch jobs.
	 */

	private const LOCK_KEY = 'hw_woam_job_running';

	/**
	 * Lock lifetime in seconds. Acts as a safety timeout —
	 * if a request dies mid-batch, the lock self-clears after this.
	 */

	private const LOCK_TTL = 300;

	/**
	 * Constructor.
	 *
	 * @param ArchiveHandler $archive_handler Archive handler.
	 * @param RestoreHandler $restore_handler Restore handler.
	 * @param DeleteHandler  $delete_handler  Delete handler.
	 */
	public function __construct(
		ArchiveHandler $archive_handler,
		RestoreHandler $restore_handler,
		DeleteHandler $delete_handler
	) {
		$this->archive_handler = $archive_handler;
		$this->restore_handler = $restore_handler;
		$this->delete_handler  = $delete_handler;
	}

	/**
	 * Registers all wp_ajax_ hooks.
	 * Called once from Plugin::boot().
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'wp_ajax_hw_woam_get_count', array( $this, 'handle_get_count' ) );
		add_action( 'wp_ajax_hw_woam_archive_batch', array( $this, 'handle_archive_batch' ) );
		add_action( 'wp_ajax_hw_woam_restore_batch', array( $this, 'handle_restore_batch' ) );
		add_action( 'wp_ajax_hw_woam_delete_batch', array( $this, 'handle_delete_batch' ) );
		add_action( 'wp_ajax_hw_woam_get_db_stats', array( $this, 'handle_get_db_stats' ) );
		add_action( 'wp_ajax_hw_woam_get_savings_estimate', array( $this, 'handle_get_savings_estimate' ) );
		add_action( 'wp_ajax_hw_woam_get_archive_health', array( $this, 'handle_get_archive_health' ) );
		add_action( 'wp_ajax_hw_woam_get_recent_activity', array( $this, 'handle_get_recent_activity' ) );
		add_action( 'wp_ajax_hw_woam_get_archive_breakdown', array( $this, 'handle_get_archive_breakdown' ) );
		add_action( 'wp_ajax_hw_woam_run_integrity_check', array( $this, 'handle_run_integrity_check' ) );
	}

	/**
	 * Verifies the request nonce and that the current user can manage WooCommerce.
	 * Sends a JSON error and stops execution if either check fails.
	 *
	 * @return void
	 */
	private function verify_request(): void {

		check_ajax_referer( 'hw_woam_ajax', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to perform this action.', 'woo-order-archive-manager' ) ),
				403
			);
		}
	}

	/**
	 * Attempts to acquire the job lock.
	 * Sends a JSON error and stops execution if a job is already running.
	 *
	 * @return void
	 */
	private function acquire_lock(): void {

		if ( get_transient( self::LOCK_KEY ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Another archive job is already running. Please wait for it to finish.', 'woo-order-archive-manager' ) ),
				409
			);
		}

		set_transient( self::LOCK_KEY, true, self::LOCK_TTL );
	}

	/**
	 * Releases the job lock.
	 * Called after a batch completes — successfully or with an error.
	 *
	 * @return void
	 */
	private function release_lock(): void {
		delete_transient( self::LOCK_KEY );
	}

	/**
	 * Returns the count of orders matching the given filters.
	 * Used by the admin UI before starting a batch — shows the user
	 * how many orders will be affected.
	 *
	 * Expects POST: nonce, mode (archive|restore|delete), before_date, statuses[]
	 *
	 * @return void
	 */
	public function handle_get_count(): void {

		$this->verify_request();

		$mode = sanitize_key( $_POST['mode'] ?? '' );

		$statuses = isset( $_POST['statuses'] ) && is_array( $_POST['statuses'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['statuses'] ) )
			: array();

		$count = match ( $mode ) {
			'archive'          => $this->archive_handler->get_total_orders_to_archive(
				sanitize_text_field( wp_unslash( $_POST['before_date'] ?? '' ) ),
				$statuses
			),
			'restore', 'delete' => $this->restore_handler->get_total_archived_orders( $statuses ),
			default            => 0,
		};

		wp_send_json_success( array( 'count' => $count ) );
	}

	/**
	 * Processes one batch of the archive operation.
	 *
	 * Expects POST: nonce, before_date, statuses[], dry_run (optional)
	 *
	 * @return void
	 */
	public function handle_archive_batch(): void {

		$this->verify_request();
		$this->acquire_lock();

		try {
			$before_date = sanitize_text_field( wp_unslash( $_POST['before_date'] ?? '' ) );

			$statuses = isset( $_POST['statuses'] ) && is_array( $_POST['statuses'] )
				? array_map( 'sanitize_text_field', wp_unslash( $_POST['statuses'] ) )
				: array();

			$dry_run = ! empty( $_POST['dry_run'] );

			$result = $this->archive_handler->process_batch( $before_date, $statuses, $dry_run );

		} finally {
			$this->release_lock();
		}

		wp_send_json_success( $result );
	}

	/**
	 * Processes one batch of the restore operation.
	 *
	 * Expects POST: nonce, statuses[] (optional), dry_run (optional)
	 *
	 * @return void
	 */
	public function handle_restore_batch(): void {

		$this->verify_request();
		$this->acquire_lock();

		try {
			$statuses = isset( $_POST['statuses'] ) && is_array( $_POST['statuses'] )
				? array_map( 'sanitize_text_field', wp_unslash( $_POST['statuses'] ) )
				: array();

			$dry_run = ! empty( $_POST['dry_run'] );

			$result = $this->restore_handler->process_restore_batch( $statuses, $dry_run );

		} finally {
			$this->release_lock();
		}

		wp_send_json_success( $result );
	}

	/**
	 * Processes one batch of the permanent delete operation.
	 *
	 * Expects POST: nonce, statuses[] (optional), dry_run (optional)
	 *
	 * @return void
	 */
	public function handle_delete_batch(): void {

		$this->verify_request();
		$this->acquire_lock();

		try {
			$statuses = isset( $_POST['statuses'] ) && is_array( $_POST['statuses'] )
				? array_map( 'sanitize_text_field', wp_unslash( $_POST['statuses'] ) )
				: array();

			$dry_run = ! empty( $_POST['dry_run'] );

			$result = $this->delete_handler->process_delete_batch( $statuses, $dry_run );

		} finally {
			$this->release_lock();
		}

		wp_send_json_success( $result );
	}

	/**
	 * Returns size statistics for WooCommerce-related database tables.
	 * Used by the Overview tab to render the database visualizer.
	 *
	 * Queries information_schema.TABLES for DATA_LENGTH + INDEX_LENGTH
	 * of the six tables that hold WooCommerce order data.
	 *
	 * Expects POST: nonce
	 *
	 * @return void
	 */
	public function handle_get_db_stats(): void {

		$this->verify_request();

		global $wpdb;

		$table_names = array(
			'posts'          => $wpdb->posts,
			'postmeta'       => $wpdb->postmeta,
			'order_items'    => $wpdb->prefix . 'woocommerce_order_items',
			'order_itemmeta' => $wpdb->prefix . 'woocommerce_order_itemmeta',
			'comments'       => $wpdb->comments,
			'commentmeta'    => $wpdb->commentmeta,
		);

		$placeholders = implode( ', ', array_fill( 0, count( $table_names ), '%s' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT TABLE_NAME, DATA_LENGTH, INDEX_LENGTH
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_values( $table_names )
			)
		);

		$stats       = array();
		$total_bytes = 0;

		foreach ( $rows as $row ) {
			$size_bytes   = (int) $row->DATA_LENGTH + (int) $row->INDEX_LENGTH;
			$total_bytes += $size_bytes;

			// Flip the map so we can look up the friendly key by table name.
			$key = array_search( $row->TABLE_NAME, $table_names, true );

			$stats[ $key ] = array(
				'table'     => $row->TABLE_NAME,
				'bytes'     => $size_bytes,
				'formatted' => $this->format_bytes( $size_bytes ),
			);
		}

		wp_send_json_success(
			array(
				'tables'          => $stats,
				'total_bytes'     => $total_bytes,
				'total_formatted' => $this->format_bytes( $total_bytes ),
			)
		);
	}

	/**
	 * Formats a byte count into a human-readable string.
	 * Used internally by handle_get_db_stats() and handle_get_savings_estimate().
	 *
	 * @param int $bytes Raw byte count.
	 * @return string Formatted string e.g. '2.3 GB', '845 MB', '12 KB'.
	 */
	private function format_bytes( int $bytes ): string {

		if ( $bytes >= 1073741824 ) {
			return number_format( $bytes / 1073741824, 1 ) . ' GB';
		}

		if ( $bytes >= 1048576 ) {
			return number_format( $bytes / 1048576, 1 ) . ' MB';
		}

		if ( $bytes >= 1024 ) {
			return number_format( $bytes / 1024, 1 ) . ' KB';
		}

		return $bytes . ' B';
	}

	/**
	 * Returns an estimated space saving for archiving orders matching the given filters.
	 * Used by the Archive tab Step 2 (Review Impact) before the user starts a batch.
	 *
	 * Counts matching order rows and their related records, then multiplies
	 * by average row size from information_schema to produce a byte estimate.
	 * This is an approximation — actual savings may vary.
	 *
	 * Expects POST: nonce, before_date, statuses[]
	 *
	 * @return void
	 */
	public function handle_get_savings_estimate(): void {

		$this->verify_request();

		global $wpdb;

		$before_date = sanitize_text_field( wp_unslash( $_POST['before_date'] ?? '' ) );

		$statuses = isset( $_POST['statuses'] ) && is_array( $_POST['statuses'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['statuses'] ) )
			: array();

		if ( empty( $before_date ) || empty( $statuses ) ) {
			wp_send_json_success(
				array(
					'order_count'     => 0,
					'row_counts'      => array(),
					'estimated_bytes' => 0,
					'estimated_size'  => '0 B',
				)
			);
			return;
		}

		// Step 1 — count matching orders.
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$order_count  = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts}
                WHERE post_type = 'shop_order'
                AND post_date < %s
                AND post_status IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( array( $before_date ), $statuses )
			)
		);

		if ( 0 === $order_count ) {
			wp_send_json_success(
				array(
					'order_count'     => 0,
					'row_counts'      => array(),
					'estimated_bytes' => 0,
					'estimated_size'  => '0 B',
				)
			);
			return;
		}

		// Step 2 — count related rows across all six tables.
		$order_items_table      = $wpdb->prefix . 'woocommerce_order_items';
		$order_items_meta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';

		$meta_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                WHERE p.post_type = 'shop_order'
                AND p.post_date < %s
                AND p.post_status IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( array( $before_date ), $statuses )
			)
		);

		$items_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$order_items_table} oi
                INNER JOIN {$wpdb->posts} p ON oi.order_id = p.ID
                WHERE p.post_type = 'shop_order'
                AND p.post_date < %s
                AND p.post_status IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( array( $before_date ), $statuses )
			)
		);

		$itemmeta_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$order_items_meta_table} oim
                INNER JOIN {$order_items_table} oi ON oim.order_item_id = oi.order_item_id
                INNER JOIN {$wpdb->posts} p ON oi.order_id = p.ID
                WHERE p.post_type = 'shop_order'
                AND p.post_date < %s
                AND p.post_status IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( array( $before_date ), $statuses )
			)
		);

		$notes_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->comments}
                WHERE comment_post_ID IN (
                    SELECT ID FROM {$wpdb->posts}
                    WHERE post_type = 'shop_order'
                    AND post_date < %s
                    AND post_status IN ({$placeholders})
                )
                AND comment_type IN ('order_note', 'order_note_private')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( array( $before_date ), $statuses )
			)
		);

		$row_counts = array(
			'orders'      => $order_count,
			'order_meta'  => $meta_count,
			'order_items' => $items_count,
			'item_meta'   => $itemmeta_count,
			'order_notes' => $notes_count,
		);

		// Step 3 — get average row sizes from information_schema.
		$table_list = array(
			'orders'      => $wpdb->posts,
			'order_meta'  => $wpdb->postmeta,
			'order_items' => $order_items_table,
			'item_meta'   => $order_items_meta_table,
			'order_notes' => $wpdb->comments,
		);

		$avg_row_sizes = array();

		foreach ( $table_list as $key => $table ) {
			$avg                   = (float) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT ROUND( ( DATA_LENGTH + INDEX_LENGTH ) / TABLE_ROWS, 2 )
                    FROM information_schema.TABLES
                    WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = %s
                    AND TABLE_ROWS > 0',
					$table
				)
			);
			$avg_row_sizes[ $key ] = $avg;
		}

		// Step 4 — estimate total bytes freed.
		$estimated_bytes = 0;

		foreach ( $row_counts as $key => $count ) {
			$estimated_bytes += $count * ( $avg_row_sizes[ $key ] ?? 0 );
		}

		$estimated_bytes = (int) $estimated_bytes;

		wp_send_json_success(
			array(
				'order_count'     => $order_count,
				'row_counts'      => $row_counts,
				'estimated_bytes' => $estimated_bytes,
				'estimated_size'  => $this->format_bytes( $estimated_bytes ),
			)
		);
	}

	/**
	 * Returns the health status of the archive system.
	 * Used by the Overview tab to render the Archive Health widget.
	 *
	 * Checks table existence, schema version, last operation dates,
	 * and whether a job lock is currently active.
	 *
	 * Expects POST: nonce
	 *
	 * @return void
	 */
	public function handle_get_archive_health(): void {

		$this->verify_request();

		global $wpdb;

		// Check 1 — all archive tables exist.
		$expected_tables = array(
			$wpdb->prefix . 'woam_orders',
			$wpdb->prefix . 'woam_orders_meta',
			$wpdb->prefix . 'woam_order_items',
			$wpdb->prefix . 'woam_order_items_meta',
			$wpdb->prefix . 'woam_logs',
			$wpdb->prefix . 'woam_order_notes',
			$wpdb->prefix . 'woam_order_notes_meta',
		);

		$missing_tables = array();

		foreach ( $expected_tables as $table ) {
			$exists = $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
			);

			if ( $exists !== $table ) {
				$missing_tables[] = $table;
			}
		}

		$tables_ok = empty( $missing_tables );

		// Check 2 — schema version matches current plugin version.
		$installed_version = get_option( 'hw_woam_db_version', '0.0.0' );
		$version_ok        = version_compare( $installed_version, HW_WOAM_VERSION, '>=' );

		// Check 3 — last archive, restore, delete dates from log table.
		$logs_table   = $wpdb->prefix . 'woam_logs';
		$last_archive = null;
		$last_restore = null;
		$last_delete  = null;

		if ( $tables_ok ) {
			$last_archive = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT MAX(created_at) FROM `{$logs_table}` WHERE action = %s AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					'archive',
					'success'
				)
			);

			$last_restore = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT MAX(created_at) FROM `{$logs_table}` WHERE action = %s AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					'restore',
					'success'
				)
			);

			$last_delete = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT MAX(created_at) FROM `{$logs_table}` WHERE action = %s AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					'delete',
					'success'
				)
			);
		}

		// Check 4 — no job lock currently active.
		$job_running = (bool) get_transient( self::LOCK_KEY );

		wp_send_json_success(
			array(
				'tables_ok'         => $tables_ok,
				'missing_tables'    => $missing_tables,
				'version_ok'        => $version_ok,
				'installed_version' => $installed_version,
				'current_version'   => HW_WOAM_VERSION,
				'last_archive'      => $last_archive,
				'last_restore'      => $last_restore,
				'last_delete'       => $last_delete,
				'job_running'       => $job_running,
			)
		);
	}

	/**
	 * Returns a summary of recent archive activity grouped by date and action.
	 * Used by the Overview tab to render the Recent Activity timeline.
	 *
	 * Returns up to 5 entries, each representing one distinct action type
	 * on one date — with a count of how many orders succeeded that day.
	 *
	 * Expects POST: nonce
	 *
	 * @return void
	 */
	public function handle_get_recent_activity(): void {

		$this->verify_request();

		global $wpdb;

		$logs_table = $wpdb->prefix . 'woam_logs';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
                    DATE(created_at)  AS activity_date,
                    action,
                    COUNT(*)          AS order_count
                FROM `{$logs_table}`
                WHERE status = %s
                AND action   IN ('archive', 'restore', 'delete')
                GROUP BY DATE(created_at), action
                ORDER BY activity_date DESC, action ASC
                LIMIT 5", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'success'
			)
		);

		$activity = array();

		foreach ( $rows as $row ) {
			$activity[] = array(
				'date'           => $row->activity_date,
				'date_formatted' => date_i18n(
					get_option( 'date_format' ),
					strtotime( $row->activity_date )
				),
				'action'         => $row->action,
				'order_count'    => (int) $row->order_count,
			);
		}

		wp_send_json_success( array( 'activity' => $activity ) );
	}

	/**
	 * Returns a breakdown of archived orders grouped by status.
	 * Used by Tab 3 (Archived Orders) to show an inventory table
	 * of what's currently sitting in the archive.
	 *
	 * Expects POST: nonce
	 *
	 * @return void
	 */
	public function handle_get_archive_breakdown(): void {

		$this->verify_request();

		global $wpdb;

		$orders_table = $wpdb->prefix . 'woam_orders';

		$rows = $wpdb->get_results(
			"SELECT post_status, COUNT(*) AS order_count
            FROM `{$orders_table}`
            GROUP BY post_status
            ORDER BY order_count DESC" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$breakdown   = array();
		$total_count = 0;

		foreach ( $rows as $row ) {
			$breakdown[]  = array(
				'status'      => $row->post_status,
				'label'       => $this->get_status_label( $row->post_status ),
				'order_count' => (int) $row->order_count,
			);
			$total_count += (int) $row->order_count;
		}

		wp_send_json_success(
			array(
				'breakdown'   => $breakdown,
				'total_count' => $total_count,
			)
		);
	}

	/**
	 * Converts a raw order status slug into a human-readable label.
	 * Uses WooCommerce's own registered statuses when available,
	 * falls back to a simple transformation for unrecognised statuses.
	 *
	 * @param string $status Raw status slug e.g. 'wc-completed'.
	 * @return string Human-readable label e.g. 'Completed'.
	 */
	private function get_status_label( string $status ): string {

		$wc_statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();

		if ( isset( $wc_statuses[ $status ] ) ) {
			return $wc_statuses[ $status ];
		}

		// Fallback: 'wc-on-hold' → 'On hold'.
		$label = str_replace( 'wc-', '', $status );
		$label = str_replace( '-', ' ', $label );

		return ucfirst( $label );
	}

	/**
	 * Checks the archive tables for orphaned rows — meta, items, or notes
	 * that reference an order ID no longer present in woam_orders.
	 *
	 * This should normally return zero orphans since all operations run
	 * inside transactions. Provided as a diagnostic tool for the admin.
	 *
	 * Expects POST: nonce
	 *
	 * @return void
	 */
	public function handle_run_integrity_check(): void {

		$this->verify_request();

		global $wpdb;

		$orders_table           = $wpdb->prefix . 'woam_orders';
		$orders_meta_table      = $wpdb->prefix . 'woam_orders_meta';
		$order_items_table      = $wpdb->prefix . 'woam_order_items';
		$order_items_meta_table = $wpdb->prefix . 'woam_order_items_meta';
		$order_notes_table      = $wpdb->prefix . 'woam_order_notes';
		$order_notes_meta_table = $wpdb->prefix . 'woam_order_notes_meta';

		$orphaned_meta = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM `{$orders_meta_table}` om
            LEFT JOIN `{$orders_table}` o ON om.post_id = o.ID
            WHERE o.ID IS NULL" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$orphaned_items = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM `{$order_items_table}` oi
            LEFT JOIN `{$orders_table}` o ON oi.order_id = o.ID
            WHERE o.ID IS NULL" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$orphaned_item_meta = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM `{$order_items_meta_table}` oim
            LEFT JOIN `{$order_items_table}` oi ON oim.order_item_id = oi.order_item_id
            WHERE oi.order_item_id IS NULL" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$orphaned_notes = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM `{$order_notes_table}` on_
            LEFT JOIN `{$orders_table}` o ON on_.comment_post_ID = o.ID
            WHERE o.ID IS NULL" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$orphaned_note_meta = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM `{$order_notes_meta_table}` onm
            LEFT JOIN `{$order_notes_table}` on_ ON onm.comment_id = on_.comment_ID
            WHERE on_.comment_ID IS NULL" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$total_orphans = $orphaned_meta + $orphaned_items + $orphaned_item_meta + $orphaned_notes + $orphaned_note_meta;

		wp_send_json_success(
			array(
				'is_healthy'         => 0 === $total_orphans,
				'total_orphans'      => $total_orphans,
				'orphaned_meta'      => $orphaned_meta,
				'orphaned_items'     => $orphaned_items,
				'orphaned_item_meta' => $orphaned_item_meta,
				'orphaned_notes'     => $orphaned_notes,
				'orphaned_note_meta' => $orphaned_note_meta,
			)
		);
	}
}
