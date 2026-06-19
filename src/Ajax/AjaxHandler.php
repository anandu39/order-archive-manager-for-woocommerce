<?php
/**
 * Ajax Handler
 *
 * @package HW\WOAM\Ajax
 */

namespace HW\WOAM\Ajax;

use HW\WOAM\Archive\ArchiveHandler;
use HW\WOAM\Archive\RestoreHandler;
use HW\WOAM\Archive\DeleteHandler;
use HW\WOAM\Analytics\AnalyticsHandler;

defined( 'ABSPATH' ) || exit;

/**
 * Class AjaxHandler
 */
class AjaxHandler {

	/**
	 * Archive Handler.
	 *
	 * @var ArchiveHandler
	 */
	private ArchiveHandler $archive_handler;

	/**
	 * Restore Handler.
	 *
	 * @var RestoreHandler
	 */
	private RestoreHandler $restore_handler;

	/**
	 * Delete Handler.
	 *
	 * @var DeleteHandler
	 */
	private DeleteHandler $delete_handler;

	/**
	 * Analytics Handler.
	 *
	 * @var AnalyticsHandler
	 */
	private AnalyticsHandler $analytics_handler;

	/**
	 * WordPress database object.
	 *
	 * @var \wpdb
	 */
	private \wpdb $wpdb;

	/**
	 * Transient key used to prevent concurrent batch jobs.
	 */
	private const LOCK_KEY = 'hw_woam_job_running';

	/**
	 * Lock lifetime in seconds.
	 */
	private const LOCK_TTL = 300;

	/**
	 * Constructor.
	 *
	 * @param ArchiveHandler   $archive_handler   Archive handler.
	 * @param RestoreHandler   $restore_handler   Restore handler.
	 * @param DeleteHandler    $delete_handler    Delete handler.
	 * @param AnalyticsHandler $analytics_handler Analytics handler.
	 */
	public function __construct(
		ArchiveHandler $archive_handler,
		RestoreHandler $restore_handler,
		DeleteHandler $delete_handler,
		AnalyticsHandler $analytics_handler
	) {
		global $wpdb;
		$this->archive_handler   = $archive_handler;
		$this->restore_handler   = $restore_handler;
		$this->delete_handler    = $delete_handler;
		$this->analytics_handler = $analytics_handler;
		$this->wpdb              = $wpdb;
	}

	/**
	 * Registers all wp_ajax_ hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Existing hooks.
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
		add_action( 'wp_ajax_hw_woam_get_subscription_stats', array( $this, 'handle_get_subscription_stats' ) );
		add_action( 'wp_ajax_hw_woam_get_oldest_order_date', array( $this, 'handle_get_oldest_order_date' ) );
		add_action( 'wp_ajax_hw_woam_preview_general_orders_range', array( $this, 'handle_preview_general_orders_range' ) );
		add_action( 'wp_ajax_hw_woam_preview_subscription_orders_range', array( $this, 'handle_preview_subscription_orders_range' ) );

		// New analytics hooks.
		add_action( 'wp_ajax_hw_woam_get_health_score', array( $this, 'handle_get_health_score' ) );
		add_action( 'wp_ajax_hw_woam_get_lifetime_stats', array( $this, 'handle_get_lifetime_stats' ) );
		add_action( 'wp_ajax_hw_woam_get_recommendations', array( $this, 'handle_get_recommendations' ) );
		add_action( 'wp_ajax_hw_woam_get_archive_readiness', array( $this, 'handle_get_archive_readiness' ) );
		add_action( 'wp_ajax_hw_woam_get_growth_forecast', array( $this, 'handle_get_growth_forecast' ) );

		// Benchmark and Subscription hooks.
		add_action( 'wp_ajax_hw_woam_get_subscription_analysis', array( $this, 'handle_get_subscription_analysis' ) );
		add_action( 'wp_ajax_hw_woam_get_archive_preview', array( $this, 'handle_get_archive_preview' ) );
		add_action( 'wp_ajax_hw_woam_run_benchmark', array( $this, 'handle_run_benchmark' ) );
		add_action( 'wp_ajax_hw_woam_get_benchmark_comparison', array( $this, 'handle_get_benchmark_comparison' ) );
		add_action( 'wp_ajax_hw_woam_get_order_breakdown_by_period', array( $this, 'handle_get_order_breakdown_by_period' ) );
	}

	/**
	 * Handle get health score request.
	 *
	 * @return void
	 */
	public function handle_get_health_score(): void {
		$this->verify_request();
		wp_send_json_success( $this->analytics_handler->get_cached_health_score() );
	}

	/**
	 * Handle get lifetime stats request.
	 *
	 * @return void
	 */
	public function handle_get_lifetime_stats(): void {
		$this->verify_request();
		wp_send_json_success( $this->analytics_handler->get_lifetime_stats() );
	}

	/**
	 * Handle get recommendations request.
	 *
	 * @return void
	 */
	public function handle_get_recommendations(): void {
		$this->verify_request();
		wp_send_json_success( $this->analytics_handler->get_recommendations() );
	}

	/**
	 * Handle get archive readiness request.
	 *
	 * @return void
	 */
	public function handle_get_archive_readiness(): void {
		$this->verify_request();
		wp_send_json_success( $this->analytics_handler->get_archive_readiness() );
	}

	/**
	 * Handle get growth forecast request.
	 *
	 * @return void
	 */
	public function handle_get_growth_forecast(): void {
		$this->verify_request();

		global $wpdb;

		// Get current database size - FIXED: use analytics_handler.
		$current_size = $this->analytics_handler->get_db_stats_array()['total_bytes'] ?? 0;

		// Get monthly growth rate from analytics handler.
		$monthly_growth_mb = $this->analytics_handler->get_monthly_growth_rate_mb();

		// Calculate projections.
		$projected_6_months_bytes  = $current_size + ( $monthly_growth_mb * 6 * 1024 * 1024 );
		$projected_12_months_bytes = $current_size + ( $monthly_growth_mb * 12 * 1024 * 1024 );

		// Get historical data for chart.
		$history         = get_option( 'hw_woam_growth_history', array() );
		$historical_data = array();

		foreach ( $history as $date => $size ) {
			$historical_data[] = array(
				'date'    => $date,
				'size_mb' => round( $size / ( 1024 * 1024 ), 1 ),
			);
		}

		wp_send_json_success(
			array(
				'current_size_bytes'            => $current_size,
				'current_size_formatted'        => $this->format_bytes( $current_size ),
				'monthly_growth_rate_mb'        => $monthly_growth_mb,
				'projected_6_months_bytes'      => $projected_6_months_bytes,
				'projected_6_months_formatted'  => $this->format_bytes( $projected_6_months_bytes ),
				'projected_12_months_bytes'     => $projected_12_months_bytes,
				'projected_12_months_formatted' => $this->format_bytes( $projected_12_months_bytes ),
				'historical_data'               => $historical_data,
			)
		);
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
				array( 'message' => __( 'You do not have permission to perform this action.', 'order-archive-manager-for-woocommerce' ) ),
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
				array( 'message' => __( 'Another archive job is already running. Please wait for it to finish.', 'order-archive-manager-for-woocommerce' ) ),
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

        // phpcs:disable WordPress.Security.NonceVerification.Missing
		$mode = sanitize_key( $_POST['mode'] ?? '' );

		$statuses = isset( $_POST['statuses'] ) && is_array( $_POST['statuses'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['statuses'] ) )
			: array();

		$count = match ( $mode ) {
			'archive'          => $this->archive_handler->get_total_orders_to_archive(
				sanitize_text_field( wp_unslash( $_POST['before_date'] ?? '' ) ),
				$statuses,
				sanitize_text_field( wp_unslash( $_POST['from_date'] ?? '' ) )
			),
			'restore', 'delete' => $this->restore_handler->get_total_archived_orders( $statuses ),
			default            => 0,
		};

        // phpcs:disable WordPress.Security.NonceVerification.Missing
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
			// phpcs:disable WordPress.Security.NonceVerification.Missing
			$before_date = sanitize_text_field( wp_unslash( $_POST['before_date'] ?? '' ) );
			$from_date   = sanitize_text_field( wp_unslash( $_POST['from_date'] ?? '' ) );

			$statuses = isset( $_POST['statuses'] ) && is_array( $_POST['statuses'] )
				? array_map( 'sanitize_text_field', wp_unslash( $_POST['statuses'] ) )
				: array();

			$dry_run    = ! empty( $_POST['dry_run'] );
			$batch_size = isset( $_POST['batch_size'] ) ? (int) $_POST['batch_size'] : 0;
			// phpcs:enable WordPress.Security.NonceVerification.Missing

			$result = $this->archive_handler->process_batch( $before_date, $statuses, $dry_run, $batch_size, $from_date );

			// Add has_more so the JS loop knows exactly when to stop.
			// Dry run rolls back everything — nothing moves — so one pass is always enough.
			if ( $dry_run ) {
				$result['has_more'] = false;
			} else {
				// Real run: re-query remaining eligible count after the batch.
				$remaining          = $this->archive_handler->get_total_orders_to_archive( $before_date, $statuses, $from_date );
				$result['has_more'] = $remaining > 0;
			}
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
			// phpcs:disable WordPress.Security.NonceVerification.Missing
			$statuses = isset( $_POST['statuses'] ) && is_array( $_POST['statuses'] )
				? array_map( 'sanitize_text_field', wp_unslash( $_POST['statuses'] ) )
				: array();

			$dry_run = ! empty( $_POST['dry_run'] );
			// phpcs:enable WordPress.Security.NonceVerification.Missing

			$result = $this->restore_handler->process_restore_batch( $statuses, $dry_run );

			// Tell the JS loop whether more orders remain.
			// Dry run rolls everything back so one pass is always enough.
			if ( $dry_run ) {
				$result['has_more'] = false;
			} else {
				$remaining          = $this->restore_handler->get_total_archived_orders( $statuses );
				$result['has_more'] = $remaining > 0;
			}
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
			// phpcs:disable WordPress.Security.NonceVerification.Missing
			$statuses = isset( $_POST['statuses'] ) && is_array( $_POST['statuses'] )
				? array_map( 'sanitize_text_field', wp_unslash( $_POST['statuses'] ) )
				: array();

			$dry_run = ! empty( $_POST['dry_run'] );
			// phpcs:enable WordPress.Security.NonceVerification.Missing

			$result = $this->delete_handler->process_delete_batch( $statuses, $dry_run );

			// Tell the JS loop whether more orders remain.
			if ( $dry_run ) {
				$result['has_more'] = false;
			} else {
				$remaining          = $this->delete_handler->get_total_archived_orders( $statuses );
				$result['has_more'] = $remaining > 0;
			}
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

		// Move the query generation out or keep it clean by removing the inline ignore comment.
		$query = "SELECT TABLE_NAME, DATA_LENGTH, INDEX_LENGTH
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME IN ({$placeholders})";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare( $query, array_values( $table_names ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		$stats       = array();
		$total_bytes = 0;

		foreach ( $rows as $row ) {
			$row_array    = (array) $row;
			$data_length  = (int) ( $row_array['DATA_LENGTH'] ?? 0 );
			$index_length = (int) ( $row_array['INDEX_LENGTH'] ?? 0 );
			$size_bytes   = $data_length + $index_length;
			$total_bytes += $size_bytes;

			$table_name = $row_array['TABLE_NAME'] ?? '';
			$key        = array_search( $table_name, $table_names, true );

			if ( false !== $key ) {
				$stats[ $key ] = array(
					'table'     => $table_name,
					'bytes'     => $size_bytes,
					'formatted' => $this->format_bytes( $size_bytes ),
				);
			}
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

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$before_date = sanitize_text_field( wp_unslash( $_POST['before_date'] ?? '' ) );
		$from_date   = sanitize_text_field( wp_unslash( $_POST['from_date'] ?? '' ) );

		$statuses = isset( $_POST['statuses'] ) && is_array( $_POST['statuses'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['statuses'] ) )
			: array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( empty( $before_date ) || empty( $statuses ) ) {
			wp_send_json_success(
				array(
					'order_count'     => 0,
					'row_counts'      => array(),
					'estimated_bytes' => 0,
					'estimated_size'  => '0 B',
				)
			);
		}

		// Setup localized variables for standard WooCommerce tables.
		$order_items_table      = $wpdb->prefix . 'woocommerce_order_items';
		$order_items_meta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';

		// Generate the dynamic placeholder string for the SQL IN clause.
		$in_placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		// ---------------------------------------------------------------------
		// Step 1 — count matching orders.
		// ---------------------------------------------------------------------

		// Build date condition and arguments.
		if ( ! empty( $from_date ) ) {
			$from_datetime = $from_date . ' 00:00:00';
			$to_datetime   = $before_date . ' 23:59:59';

			$order_count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT COUNT(*) FROM %i
					WHERE post_type = 'shop_order'
					AND post_date >= %s
					AND post_date <= %s
					AND post_status IN ({$in_placeholders})",
					array_merge( array( $wpdb->posts, $from_datetime, $to_datetime ), $statuses )
				)
			);
		} else {
			$order_count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT COUNT(*) FROM %i
					WHERE post_type = 'shop_order'
					AND post_date < %s
					AND post_status IN ({$in_placeholders})",
					array_merge( array( $wpdb->posts, $before_date ), $statuses )
				)
			);
		}

		if ( 0 === $order_count ) {
			wp_send_json_success(
				array(
					'order_count'     => 0,
					'row_counts'      => array(),
					'estimated_bytes' => 0,
					'estimated_size'  => '0 B',
				)
			);
		}

		// ---------------------------------------------------------------------
		// Step 2 — count related rows across all tables.
		// ---------------------------------------------------------------------

		// Build date condition for JOIN queries.
		if ( ! empty( $from_date ) ) {
			$from_datetime  = $from_date . ' 00:00:00';
			$to_datetime    = $before_date . ' 23:59:59';
			$date_condition = 'AND p.post_date >= %s AND p.post_date <= %s';
			$date_args      = array( $from_datetime, $to_datetime );
		} else {
			$date_condition = 'AND p.post_date < %s';
			$date_args      = array( $before_date );
		}

		// Count Meta Rows.
		$meta_count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM %i pm
				INNER JOIN %i p ON pm.post_id = p.ID
				WHERE p.post_type = 'shop_order'
				{$date_condition}
				AND p.post_status IN ({$in_placeholders})",
				array_merge( array( $wpdb->postmeta, $wpdb->posts ), $date_args, $statuses )
			)
		);

		// Count Order Items Rows.
		$items_count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM %i oi
				INNER JOIN %i p ON oi.order_id = p.ID
				WHERE p.post_type = 'shop_order'
				{$date_condition}
				AND p.post_status IN ({$in_placeholders})",
				array_merge( array( $order_items_table, $wpdb->posts ), $date_args, $statuses )
			)
		);

		// Count Order Item Meta Rows.
		$itemmeta_count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM %i oim
				INNER JOIN %i oi ON oim.order_item_id = oi.order_item_id
				INNER JOIN %i p ON oi.order_id = p.ID
				WHERE p.post_type = 'shop_order'
				{$date_condition}
				AND p.post_status IN ({$in_placeholders})",
				array_merge( array( $order_items_meta_table, $order_items_table, $wpdb->posts ), $date_args, $statuses )
			)
		);

		// Count Order Notes/Comments Rows.
		$notes_count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM %i
				WHERE comment_post_ID IN (
					SELECT ID FROM %i p
					WHERE p.post_type = 'shop_order'
					{$date_condition}
					AND p.post_status IN ({$in_placeholders})
				)
				AND comment_type IN ('order_note', 'order_note_private')",
				array_merge( array( $wpdb->comments, $wpdb->posts ), $date_args, $statuses )
			)
		);

		// Count Refund Rows.
		$refunds_count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM %i p
				WHERE p.post_type = 'shop_order_refund'
				AND p.post_parent IN (
					SELECT ID FROM %i
					WHERE post_type = 'shop_order'
					{$date_condition}
					AND post_status IN ({$in_placeholders})
				)",
				array_merge( array( $wpdb->posts, $wpdb->posts ), $date_args, $statuses )
			)
		);

		$row_counts = array(
			'orders'      => $order_count,
			'order_meta'  => $meta_count,
			'order_items' => $items_count,
			'item_meta'   => $itemmeta_count,
			'order_notes' => $notes_count,
			'refunds'     => $refunds_count,
		);

		// ---------------------------------------------------------------------
		// Step 3 — get average row sizes from information_schema.
		// ---------------------------------------------------------------------
		$table_list = array(
			'orders'      => $wpdb->posts,
			'order_meta'  => $wpdb->postmeta,
			'order_items' => $order_items_table,
			'item_meta'   => $order_items_meta_table,
			'order_notes' => $wpdb->comments,
			'refunds'     => $wpdb->posts, // Refunds are in wp_posts too.
		);

		// Per-type fallback estimates, used only when information_schema
		// can't give us a real average (e.g. table has zero rows right now).
		// Declared before the loop so it can be used as the per-type
		// fallback instead of a single flat number for every table.
		$avg_size_map = array(
			'orders'      => 2000, // ~2KB per order post
			'order_meta'  => 100,  // ~100 bytes per meta
			'order_items' => 200,  // ~200 bytes per item
			'item_meta'   => 100,  // ~100 bytes per item meta
			'order_notes' => 150,  // ~150 bytes per note
			'refunds'     => 1500, // ~1.5KB per refund
		);

		$avg_row_sizes = array();

		foreach ( $table_list as $key => $table ) {
			$avg = (float) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					'SELECT ROUND( ( DATA_LENGTH + INDEX_LENGTH ) / TABLE_ROWS, 2 )
					FROM information_schema.TABLES
					WHERE TABLE_SCHEMA = DATABASE()
					AND TABLE_NAME = %s
					AND TABLE_ROWS > 0',
					$table
				)
			);

			// Fall back to the per-type estimate (not a flat 100 bytes)
			// when information_schema has no usable row-size data.
			$avg_row_sizes[ $key ] = $avg > 0 ? $avg : $avg_size_map[ $key ];
		}

		// ---------------------------------------------------------------------
		// Step 4 — estimate total bytes freed.
		// ---------------------------------------------------------------------
		$estimated_bytes = 0;

		foreach ( $row_counts as $key => $count ) {
			$avg_size         = $avg_row_sizes[ $key ];
			$estimated_bytes += $count * $avg_size;
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
	 * Handle get oldest order date request.
	 *
	 * @return void
	 */
	public function handle_get_oldest_order_date(): void {
		$this->verify_request();

		global $wpdb;

		// $wpdb->posts is a trusted, internally-generated table name — no user
		// input involved — so it is safe to reference directly without prepare().
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$oldest_date = $wpdb->get_var( "SELECT MIN(post_date) FROM {$wpdb->posts} WHERE post_type = 'shop_order'" );

		if ( $oldest_date ) {
			$date = new \DateTime( $oldest_date );
			wp_send_json_success(
				array(
					'oldest_date'           => $date->format( 'Y-m-d' ),
					'oldest_date_formatted' => date_i18n( get_option( 'date_format' ), strtotime( $oldest_date ) ),
				)
			);
		} else {
			wp_send_json_success(
				array(
					'oldest_date'           => null,
					'oldest_date_formatted' => null,
				)
			);
		}
	}

	/**
	 * Handle preview general orders with date range.
	 *
	 * @return void
	 */
	public function handle_preview_general_orders_range(): void {
		check_ajax_referer( 'hw_woam_ajax', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		$from_date = isset( $_POST['from_date'] )
			? sanitize_text_field( wp_unslash( $_POST['from_date'] ) )
			: '';
		$to_date   = isset( $_POST['to_date'] )
			? sanitize_text_field( wp_unslash( $_POST['to_date'] ) )
			: '';

		if ( empty( $from_date ) || empty( $to_date ) ) {
			wp_send_json_error( array( 'message' => 'Please select a date range' ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via array_map below.
		$statuses = isset( $_POST['statuses'] ) && is_array( $_POST['statuses'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['statuses'] ) )
			: array();

		global $wpdb;

		$from_datetime = $from_date . ' 00:00:00';
		$to_datetime   = $to_date . ' 23:59:59';

		if ( empty( $statuses ) ) {
			wp_send_json_success(
				array(
					'breakdown'                   => array(),
					'total'                       => 0,
					'eligible'                    => 0,
					'estimated_savings'           => 0,
					'estimated_savings_formatted' => '0 B',
				)
			);
		}

		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		// Build the full SQL string before passing to prepare() to avoid interpolation sniff.
		$sql = "SELECT post_status, COUNT(*) as count
			FROM `{$wpdb->posts}`
			WHERE post_type = 'shop_order'
			AND post_date >= %s
			AND post_date <= %s
			AND post_status IN ({$placeholders})
			GROUP BY post_status
			ORDER BY count DESC";

		$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				$sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				array_merge( array( $from_datetime, $to_datetime ), $statuses )
			)
		);

		$breakdown         = array();
		$total             = 0;
		$eligible          = 0;
		$eligible_statuses = array( 'wc-completed', 'wc-cancelled', 'wc-refunded', 'wc-failed' );

		foreach ( $results as $row ) {
			$breakdown[ $row->post_status ] = (int) $row->count;
			$total                         += (int) $row->count;
			if ( in_array( $row->post_status, $eligible_statuses, true ) ) {
				$eligible += (int) $row->count;
			}
		}

		// Calculate estimated savings.
		$avg_order_size    = $this->get_average_order_size_bytes();
		$estimated_savings = $eligible * $avg_order_size;

		wp_send_json_success(
			array(
				'breakdown'                   => $breakdown,
				'total'                       => $total,
				'eligible'                    => $eligible,
				'estimated_savings'           => $estimated_savings,
				'estimated_savings_formatted' => $this->format_bytes( $estimated_savings ),
			)
		);
	}

	/**
	 * Handle preview subscription orders with date range.
	 *
	 * @return void
	 */
	public function handle_preview_subscription_orders_range(): void {
		check_ajax_referer( 'hw_woam_ajax', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		$from_date = isset( $_POST['from_date'] )
		? sanitize_text_field( wp_unslash( $_POST['from_date'] ) )
		: '';
		$to_date   = isset( $_POST['to_date'] )
		? sanitize_text_field( wp_unslash( $_POST['to_date'] ) )
		: '';

		if ( empty( $from_date ) || empty( $to_date ) ) {
			wp_send_json_error( array( 'message' => 'Please select a date range' ) );
		}

		global $wpdb;

		if ( ! class_exists( 'WC_Subscriptions' ) ) {
			wp_send_json_success(
				array(
					'subscriptions_active' => false,
					'message'              => 'WooCommerce Subscriptions is not active',
				)
			);
		}

		$from_datetime = $from_date . ' 00:00:00';
		$to_datetime   = $to_date . ' 23:59:59';

		// Get subscription stats for the period.
		$statuses     = array( 'wc-active', 'wc-pending-cancel', 'wc-on-hold', 'wc-cancelled', 'wc-expired', 'wc-failed' );
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		// Build full SQL before prepare() to avoid interpolation sniff.
		$sql = "SELECT s.post_status, COUNT(DISTINCT p.ID) as count
		FROM `{$wpdb->posts}` p
		INNER JOIN `{$wpdb->posts}` s ON s.post_parent = p.ID
		WHERE p.post_type = 'shop_order'
		AND s.post_type = 'shop_subscription'
		AND p.post_date >= %s
		AND p.post_date <= %s
		AND s.post_status IN ({$placeholders})
		GROUP BY s.post_status";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders are dynamically built and safe.
		$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				$sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				array_merge( array( $from_datetime, $to_datetime ), $statuses )
			)
		);

		$breakdown          = array();
		$total              = 0;
		$eligible           = 0;
		$protected          = 0;
		$protected_statuses = array( 'wc-active', 'wc-pending-cancel', 'wc-on-hold' );
		$eligible_statuses  = array( 'wc-cancelled', 'wc-expired', 'wc-failed' );

		foreach ( $results as $row ) {
			$status               = str_replace( 'wc-', '', $row->post_status );
			$breakdown[ $status ] = (int) $row->count;
			$total               += (int) $row->count;

			if ( in_array( $row->post_status, $protected_statuses, true ) ) {
				$protected += (int) $row->count;
			} elseif ( in_array( $row->post_status, $eligible_statuses, true ) ) {
				$eligible += (int) $row->count;
			}
		}

		wp_send_json_success(
			array(
				'breakdown'            => $breakdown,
				'total'                => $total,
				'eligible'             => $eligible,
				'protected'            => $protected,
				'subscriptions_active' => true,
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
			$exists = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
		// $wpdb->prefix is a trusted internal value; inlining directly is safe and
		// avoids the UnescapedDBParameter sniff triggered by assigning to $logs_table.
		$logs_table   = $wpdb->prefix . 'woam_logs';
		$last_archive = null;
		$last_restore = null;
		$last_delete  = null;

		if ( $tables_ok ) {
			$last_archive = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT MAX(created_at) FROM `{$wpdb->prefix}woam_logs` WHERE action = %s AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					'archive',
					'success'
				)
			);

			$last_restore = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT MAX(created_at) FROM `{$wpdb->prefix}woam_logs` WHERE action = %s AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					'restore',
					'success'
				)
			);

			$last_delete = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT MAX(created_at) FROM `{$wpdb->prefix}woam_logs` WHERE action = %s AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT
					DATE(created_at)  AS activity_date,
					action,
					COUNT(*)          AS order_count
				FROM `{$wpdb->prefix}woam_logs`
				WHERE status = %s
				AND action IN ('archive', 'restore', 'delete')
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

		// No user input in this query — plain get_results() with an inlined prefix is correct.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			'SELECT post_status, COUNT(*) AS order_count
			FROM `' . $wpdb->prefix . 'woam_orders`
			GROUP BY post_status
			ORDER BY order_count DESC'
		);

		$breakdown   = array();
		$total_count = 0;

		foreach ( $rows as $row ) {
			$breakdown[] = array(
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
	 * Handle get subscription stats request.
	 *
	 * @return void
	 */
	public function handle_get_subscription_stats(): void {
		check_ajax_referer( 'hw_woam_ajax', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		global $wpdb;

		if ( ! class_exists( 'WC_Subscriptions' ) ) {
			wp_send_json_success(
				array(
					'subscriptions_active' => false,
					'message'              => 'WooCommerce Subscriptions is not active',
				)
			);
		}

		// Get subscription counts by status.
		$statuses     = array( 'wc-active', 'wc-cancelled', 'wc-expired', 'wc-on-hold', 'wc-pending-cancel', 'wc-failed' );
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		$sql = "SELECT post_status, COUNT(*) as count
			FROM `{$wpdb->posts}`
			WHERE post_type = 'shop_subscription'
			AND post_status IN ({$placeholders})
			GROUP BY post_status";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders are dynamically built and safe.
		$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( $sql, $statuses ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		$stats = array(
			'total_subscriptions'  => 0,
			'active'               => 0,
			'cancelled'            => 0,
			'expired'              => 0,
			'on_hold'              => 0,
			'pending_cancel'       => 0,
			'failed'               => 0,
			'protected_orders'     => 0,
			'archivable_orders'    => 0,
			'subscriptions_active' => true,
		);

		foreach ( $results as $row ) {
			$status = str_replace( 'wc-', '', $row->post_status );
			if ( isset( $stats[ $status ] ) ) {
				$stats[ $status ] = (int) $row->count;
			}
			$stats['total_subscriptions'] += (int) $row->count;
		}

		// Count protected orders (active subscriptions that should not be archived).
		$protected_statuses = array( 'wc-active', 'wc-pending-cancel', 'wc-on-hold' );
		$placeholders       = implode( ', ', array_fill( 0, count( $protected_statuses ), '%s' ) );

		$protected_sql = "SELECT COUNT(DISTINCT post_parent)
			FROM `{$wpdb->posts}`
			WHERE post_type = 'shop_subscription'
			AND post_status IN ({$placeholders})
			AND post_parent > 0";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders are dynamically built and safe.
		$stats['protected_orders'] = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( $protected_sql, $protected_statuses ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		// Count archivable orders (cancelled, expired, failed subscriptions).
		$archivable_statuses = array( 'wc-cancelled', 'wc-expired', 'wc-failed' );
		$placeholders        = implode( ', ', array_fill( 0, count( $archivable_statuses ), '%s' ) );

		$archivable_sql = "SELECT COUNT(DISTINCT post_parent)
			FROM `{$wpdb->posts}`
			WHERE post_type = 'shop_subscription'
			AND post_status IN ({$placeholders})
			AND post_parent > 0";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders are dynamically built and safe.
		$stats['archivable_orders'] = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( $archivable_sql, $archivable_statuses ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		wp_send_json_success( $stats );
	}

	/**
	 * Handle get subscription analysis request.
	 *
	 * @return void
	 */
	public function handle_get_subscription_analysis(): void {
		try {
			check_ajax_referer( 'hw_woam_ajax', 'nonce' );

			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_send_json_error( array( 'message' => 'Unauthorized' ) );
			}

			$manager = new \HW\WOAM\Subscription\SubscriptionManager( $this->wpdb );
			$data    = $manager->get_subscription_breakdown();

			wp_send_json_success( $data );

		} catch ( \Exception $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional error logging for plugin diagnostics.
			error_log( 'WOAM Subscription analysis failed: ' . $e->getMessage() );
			wp_send_json_error( array( 'message' => __( 'Unable to load subscription data.', 'order-archive-manager-for-woocommerce' ) ) );
		}
	}

	/**
	 * Handle get archive preview request.
	 *
	 * @return void
	 */
	public function handle_get_archive_preview(): void {
		try {
			check_ajax_referer( 'hw_woam_ajax', 'nonce' );

			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_send_json_error( array( 'message' => 'Unauthorized' ) );
			}

			$before_date = isset( $_POST['before_date'] )
				? sanitize_text_field( wp_unslash( $_POST['before_date'] ) )
				: '';

			if ( empty( $before_date ) ) {
				wp_send_json_error( array( 'message' => 'No date provided' ) );
			}

			global $wpdb;

			// Get regular orders breakdown.
			$statuses     = array( 'wc-completed', 'wc-cancelled', 'wc-refunded', 'wc-failed', 'wc-processing', 'wc-on-hold', 'wc-pending' );
			$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

			$sql = "SELECT post_status, COUNT(*) as count
				FROM `{$wpdb->posts}`
				WHERE post_type = 'shop_order'
				AND post_date < %s
				AND post_status IN ({$placeholders})
				GROUP BY post_status";

			$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare( $sql, array_merge( array( $before_date ), $statuses ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			);

			$breakdown      = array();
			$total_eligible = 0;

			foreach ( $results as $row ) {
				$breakdown[ $row->post_status ] = (int) $row->count;
				if ( in_array( $row->post_status, array( 'wc-completed', 'wc-cancelled', 'wc-refunded', 'wc-failed' ), true ) ) {
					$total_eligible += (int) $row->count;
				}
			}

			// Get subscription data.
			$sub_manager = new \HW\WOAM\Subscription\SubscriptionManager( $wpdb );
			$sub_data    = $sub_manager->get_subscription_breakdown();

			// Calculate estimated savings.
			$avg_order_size    = $this->get_average_order_size_bytes();
			$estimated_savings = $total_eligible * $avg_order_size;

			wp_send_json_success(
				array(
					'order_breakdown'             => $breakdown,
					'total_eligible'              => $total_eligible,
					'subscription_data'           => $sub_data,
					'estimated_savings'           => $estimated_savings,
					'estimated_savings_formatted' => $this->format_bytes( $estimated_savings ),
				)
			);

		} catch ( \Exception $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional error logging for plugin diagnostics.
			error_log( 'WOAM Archive preview failed: ' . $e->getMessage() );
			wp_send_json_error( array( 'message' => __( 'Unable to generate archive preview.', 'order-archive-manager-for-woocommerce' ) ) );
		}
	}

	/**
	 * Handle run benchmark request.
	 *
	 * @return void
	 */
	public function handle_run_benchmark(): void {
		try {
			check_ajax_referer( 'hw_woam_ajax', 'nonce' );

			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_send_json_error( array( 'message' => 'Unauthorized' ) );
			}

			$type = isset( $_POST['type'] )
				? sanitize_text_field( wp_unslash( $_POST['type'] ) )
				: 'before';

			$manager = new \HW\WOAM\Benchmark\BenchmarkManager( $this->wpdb );
			$results = $manager->run_benchmarks();
			$manager->store_benchmarks( $type, $results );

			wp_send_json_success(
				array(
					'type'    => $type,
					'results' => $results,
					'message' => sprintf(
						/* translators: %s: archive type label, either 'before' or 'after'. */
						__( 'Benchmark completed successfully. Results stored as %s archive.', 'order-archive-manager-for-woocommerce' ),
						$type
					),
				)
			);

		} catch ( \Exception $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional error logging for plugin diagnostics.
			error_log( 'WOAM Benchmark failed: ' . $e->getMessage() );
			wp_send_json_error( array( 'message' => __( 'Unable to run benchmark.', 'order-archive-manager-for-woocommerce' ) ) );
		}
	}

	/**
	 * Handle get benchmark comparison request.
	 *
	 * @return void
	 */
	public function handle_get_benchmark_comparison(): void {
		try {
			check_ajax_referer( 'hw_woam_ajax', 'nonce' );

			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_send_json_error(
					array( 'message' => __( 'You do not have permission to perform this action.', 'order-archive-manager-for-woocommerce' ) ),
					403
				);
			}

			$manager    = new \HW\WOAM\Benchmark\BenchmarkManager( $this->wpdb );
			$comparison = $manager->get_comparison();

			wp_send_json_success( $comparison );

		} catch ( \Exception $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional error logging for plugin diagnostics.
			error_log( 'WOAM Benchmark comparison failed: ' . $e->getMessage() );
			wp_send_json_error( array( 'message' => __( 'Unable to load benchmark comparison.', 'order-archive-manager-for-woocommerce' ) ) );
		}
	}

	/**
	 * Handle get order breakdown by period request.
	 *
	 * @return void
	 */
	public function handle_get_order_breakdown_by_period(): void {
		try {
			check_ajax_referer( 'hw_woam_ajax', 'nonce' );

			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_send_json_error(
					array( 'message' => __( 'You do not have permission to perform this action.', 'order-archive-manager-for-woocommerce' ) ),
					403
				);
			}

			$period = isset( $_POST['period'] )
				? sanitize_text_field( wp_unslash( $_POST['period'] ) )
				: '12 months';

			$cutoff_date = gmdate( 'Y-m-d H:i:s', strtotime( "-{$period}" ) );

			global $wpdb;

			$statuses     = array( 'wc-completed', 'wc-cancelled', 'wc-refunded', 'wc-failed', 'wc-processing', 'wc-on-hold', 'wc-pending' );
			$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

			$sql = "SELECT post_status, COUNT(*) as count
				FROM `{$wpdb->posts}`
				WHERE post_type = 'shop_order'
				AND post_date < %s
				AND post_status IN ({$placeholders})
				GROUP BY post_status";

			$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare( $sql, array_merge( array( $cutoff_date ), $statuses ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			);

			$breakdown = array();
			$total     = 0;

			foreach ( $results as $row ) {
				$status               = str_replace( 'wc-', '', $row->post_status );
				$breakdown[ $status ] = (int) $row->count;
				$total               += (int) $row->count;
			}

			wp_send_json_success(
				array(
					'breakdown'   => $breakdown,
					'total'       => $total,
					'period'      => $period,
					'cutoff_date' => $cutoff_date,
				)
			);

		} catch ( \Exception $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional error logging for plugin diagnostics.
			error_log( 'WOAM Order breakdown failed: ' . $e->getMessage() );
			wp_send_json_error( array( 'message' => __( 'Unable to load order breakdown.', 'order-archive-manager-for-woocommerce' ) ) );
		}
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

		$p = $wpdb->prefix;

		// All table names are built from $wpdb->prefix — a trusted internal value.
		// No user input is present in any of these queries, so prepare() is not needed.

		// 1. Check orphaned metadata.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$orphaned_meta = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM `{$p}woam_orders_meta` om
			LEFT JOIN `{$p}woam_orders` o ON om.post_id = o.ID
			WHERE o.ID IS NULL"
		);

		// 2. Check orphaned order items.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$orphaned_items = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM `{$p}woam_order_items` oi
			LEFT JOIN `{$p}woam_orders` o ON oi.order_id = o.ID
			WHERE o.ID IS NULL"
		);

		// 3. Check orphaned order item meta.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$orphaned_item_meta = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM `{$p}woam_order_items_meta` oim
			LEFT JOIN `{$p}woam_order_items` oi ON oim.order_item_id = oi.order_item_id
			WHERE oi.order_item_id IS NULL"
		);

		// 4. Check orphaned order notes (comments).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$orphaned_notes = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM `{$p}woam_order_notes` on_
			LEFT JOIN `{$p}woam_orders` o ON on_.comment_post_ID = o.ID
			WHERE o.ID IS NULL"
		);

		// 5. Check orphaned order note meta.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$orphaned_note_meta = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM `{$p}woam_order_notes_meta` onm
			LEFT JOIN `{$p}woam_order_notes` on_ ON onm.comment_id = on_.comment_ID
			WHERE on_.comment_ID IS NULL"
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

	/**
	 * Returns an estimated average order size in bytes.
	 * Queries information_schema for the combined average row size
	 * across posts, postmeta, order_items, and order_itemmeta.
	 *
	 * @return int Estimated bytes per order.
	 */
	private function get_average_order_size_bytes(): int {
		global $wpdb;

		$tables = array(
			$wpdb->posts,
			$wpdb->postmeta,
			$wpdb->prefix . 'woocommerce_order_items',
			$wpdb->prefix . 'woocommerce_order_itemmeta',
		);

		$placeholders = implode( ', ', array_fill( 0, count( $tables ), '%s' ) );

		$sql = "SELECT ROUND( SUM( DATA_LENGTH + INDEX_LENGTH ) / SUM( TABLE_ROWS ), 2 )
			FROM information_schema.TABLES
			WHERE TABLE_SCHEMA = DATABASE()
			AND TABLE_NAME IN ({$placeholders})
			AND TABLE_ROWS > 0";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders are dynamically built and safe.
		$avg = (float) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( $sql, $tables ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		// Fallback to 50 KB if information_schema returns nothing.
		return $avg > 0 ? (int) $avg : 51200;
	}
}
