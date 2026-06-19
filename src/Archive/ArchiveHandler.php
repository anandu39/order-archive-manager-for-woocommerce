<?php
/**
 *
 * Archive Handler.
 *
 * Move woocommerce orders from live tables to archive tables.
 * Handle archive, restore, delete operations in batches.
 * Each operation runs inside database transaction to ensure data integrity.
 *
 * @package HW\WOAM\Archive
 */

namespace HW\WOAM\Archive;

use HW\WOAM\Database\Tables;
use HW\WOAM\Logger\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Class ArchiveHandler
 */
class ArchiveHandler {
	/**
	 * WordPress database object.
	 *
	 * @var \wpdb
	 */
	private \wpdb $wpdb;

	/**
	 * Table name definitions.
	 *
	 * @var Tables
	 */
	private Tables $tables;

	/**
	 * Activity logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Number of orders to process per batch.
	 * Filterable via 'hw_woam_batch_size' filter.
	 *
	 * @var int
	 */
	private int $batch_size;

	/**
	 * Constructor.
	 *
	 * @param \wpdb  $wpdb   WordPress database object.
	 * @param Tables $tables Table name definitions.
	 * @param Logger $logger Activity logger.
	 */
	public function __construct( \wpdb $wpdb, Tables $tables, Logger $logger ) {
		$this->wpdb       = $wpdb;
		$this->tables     = $tables;
		$this->logger     = $logger;
		$this->batch_size = (int) apply_filters( 'hw_woam_batch_size', 500 );
	}

	/**
	 * Get total count of orders matching the archive criteria.
	 *
	 * @param string $before_date Cutoff date (YYYY-MM-DD HH:MM:SS).
	 * @param array  $statuses    Array of target post statuses.
	 * @param string $from_date   Optional start date for range queries (YYYY-MM-DD HH:MM:SS).
	 * @return int Total order count matching criteria.
	 */
	public function get_total_orders_to_archive( string $before_date, array $statuses, string $from_date = '' ): int {

		if ( empty( $statuses ) ) {
			return 0;
		}

		$in_placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		if ( ! empty( $from_date ) ) {
			$query  = "SELECT COUNT(*) FROM %i WHERE post_type = 'shop_order' AND post_date >= %s AND post_date <= %s AND post_status IN ({$in_placeholders})";
			$params = array_merge( array( $this->wpdb->posts, $from_date . ' 00:00:00', $before_date . ' 23:59:59' ), $statuses );
		} else {
			$query  = "SELECT COUNT(*) FROM %i WHERE post_type = 'shop_order' AND post_date < %s AND post_status IN ({$in_placeholders})";
			$params = array_merge( array( $this->wpdb->posts, $before_date ), $statuses );
		}

		$prepared_sql = $this->wpdb->prepare( $query, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return (int) $this->wpdb->get_var( $prepared_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Retrieves a batch of order IDs matching the criteria, explicitly excluding specified IDs.
	 *
	 * @param string $before_date  Cutoff date (YYYY-MM-DD HH:MM:SS).
	 * @param array  $statuses     Array of target post statuses.
	 * @param string $from_date    Optional start date for range queries (YYYY-MM-DD HH:MM:SS).
	 * @param array  $exclude_ids  Array of order IDs to ignore (e.g., failed or skipped items).
	 * @return array
	 */
	public function get_batch_order_ids( string $before_date, array $statuses, string $from_date = '', array $exclude_ids = array() ): array {

		if ( empty( $statuses ) ) {
			return array();
		}

		$in_placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		// Build exclusion clause dynamically if there are IDs to ignore.
		$exclude_clause = '';
		$exclude_params = array();
		if ( ! empty( $exclude_ids ) ) {
			$id_placeholders = implode( ', ', array_fill( 0, count( $exclude_ids ), '%d' ) );
			$exclude_clause  = " AND ID NOT IN ($id_placeholders)";
			$exclude_params  = array_map( 'intval', $exclude_ids );
		}

		if ( ! empty( $from_date ) ) {
			$query = "SELECT ID FROM %i WHERE post_type = 'shop_order' AND post_date >= %s AND post_date <= %s AND post_status IN ({$in_placeholders}){$exclude_clause} ORDER BY ID ASC LIMIT %d";

			// Merge parameters: table, from_date, to_date, statuses, exclusions, limit.
			$params = array_merge(
				array( $this->wpdb->posts, $from_date . ' 00:00:00', $before_date . ' 23:59:59' ),
				$statuses,
				$exclude_params,
				array( $this->batch_size )
			);
		} else {
			$query = "SELECT ID FROM %i WHERE post_type = 'shop_order' AND post_date < %s AND post_status IN ({$in_placeholders}){$exclude_clause} ORDER BY ID ASC LIMIT %d";

			// Merge parameters: table, before_date, statuses, exclusions, limit.
			$params = array_merge(
				array( $this->wpdb->posts, $before_date ),
				$statuses,
				$exclude_params,
				array( $this->batch_size )
			);
		}

		$prepared_sql = $this->wpdb->prepare( $query, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_map( 'intval', $this->wpdb->get_col( $prepared_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Get detailed subscription status for an order.
	 *
	 * @param int $order_id Order ID to check.
	 * @return array{is_linked: bool, reason: string, subscription_status: string|null, is_safe: bool}
	 */
	private function get_subscription_status( int $order_id ): array {
		// If WooCommerce Subscriptions is not active, return not linked.
		if ( ! class_exists( 'WC_Subscriptions' ) ) {
			return array(
				'is_linked'           => false,
				'reason'              => 'Subscriptions plugin not active',
				'subscription_status' => null,
				'is_safe'             => true,
			);
		}

		// Check 1 - Is the order a renewal or resubscribe order?
		$query_meta = 'SELECT meta_id FROM %i WHERE post_id = %d AND meta_key IN (\'_subscription_renewal\', \'_subscription_resubscribe\') LIMIT 1';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- query is a static string with no user input.
		$prepared_meta_sql = $this->wpdb->prepare( $query_meta, array( $this->wpdb->postmeta, $order_id ) );
		$renewal_meta      = $this->wpdb->get_var( $prepared_meta_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( $renewal_meta ) {
			return array(
				'is_linked'           => true,
				'reason'              => 'This is a renewal or resubscribe order',
				'subscription_status' => 'renewal',
				'is_safe'             => false,
			);
		}

		// Check 2 - Does any subscription have this order as its parent?
		$query_subs = 'SELECT post_status FROM %i WHERE post_parent = %d AND post_type = \'shop_subscription\' LIMIT 1';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- query is a static string with no user input.
		$prepared_subs_sql   = $this->wpdb->prepare( $query_subs, array( $this->wpdb->posts, $order_id ) );
		$subscription_status = $this->wpdb->get_var( $prepared_subs_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( $subscription_status ) {
			// Statuses safe to archive.
			$safe_statuses = array( 'wc-cancelled', 'wc-expired', 'wc-failed', 'wc-trash' );

			if ( in_array( $subscription_status, $safe_statuses, true ) ) {
				return array(
					'is_linked'           => false,
					'reason'              => 'Subscription is ' . $subscription_status . ' (safe to archive)',
					'subscription_status' => $subscription_status,
					'is_safe'             => true,
				);
			} else {
				return array(
					'is_linked'           => true,
					'reason'              => 'Order has active or pending subscription: ' . $subscription_status,
					'subscription_status' => $subscription_status,
					'is_safe'             => false,
				);
			}
		}

		return array(
			'is_linked'           => false,
			'reason'              => 'No subscription linked',
			'subscription_status' => null,
			'is_safe'             => true,
		);
	}

	/**
	 * Get all subscription orders with their status for display.
	 *
	 * @param string $status_filter Optional status filter.
	 * @return array<int, array>
	 */
	public function get_subscription_orders( string $status_filter = '' ): array {
		global $wpdb;

		if ( ! class_exists( 'WC_Subscriptions' ) ) {
			return array();
		}

		// Build the base SQL and params, then append optional status condition.
		$sql    = "SELECT p.ID, p.post_status, p.post_date,
					s.post_status as subscription_status
				FROM `{$wpdb->posts}` p
				INNER JOIN `{$wpdb->posts}` s ON s.post_parent = p.ID
				WHERE p.post_type = 'shop_order'
				AND s.post_type = 'shop_subscription'";
		$params = array();

		if ( ! empty( $status_filter ) ) {
			$sql     .= ' AND s.post_status = %s';
			$params[] = $status_filter;
		}

		$sql .= ' ORDER BY p.post_date DESC LIMIT 100';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- SQL is built from static strings; placeholders added conditionally.
		$results = $wpdb->get_results( ! empty( $params ) ? $wpdb->prepare( $sql, $params ) : $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$orders = array();
		foreach ( $results as $row ) {
			$orders[] = array(
				'order_id'            => (int) $row->ID,
				'order_status'        => $row->post_status,
				'order_date'          => $row->post_date,
				'subscription_status' => $row->subscription_status,
			);
		}

		return $orders;
	}

	/**
	 * Verifies that all expected rows were copied into the archived tables
	 * before we delete anything from the live tables.
	 *
	 * Count rows inside the archive tables for this order and compare against
	 * the source. If any count mismatches, throws an Exception - the calling
	 * transaction rolls back and the order remains untouched in live tables.
	 *
	 * This is a defence-in-depth rollback method. Transactions already handle rollback
	 * on query failure, but this catches a rare case where a query is succeed but
	 * copies fewer rows than expected (eg. a silent partial insert).
	 *
	 * @param int $order_id Order ID to verify.
	 * @throws \Exception If any archive row count doesn't match the source.
	 * @return void
	 */
	private function verify_archive_copy( int $order_id ): void {

		$db                     = $this->wpdb;
		$order_items_table      = $db->prefix . 'woocommerce_order_items';
		$order_items_meta_table = $db->prefix . 'woocommerce_order_itemmeta';

		// Extract deep properties into simple local variables to bypass strict token lookups.
		$src_postmeta_tbl    = $db->postmeta;
		$arc_orders_meta_tbl = $this->tables->orders_meta;
		$arc_order_items_tbl = $this->tables->order_items;
		$arc_item_meta_tbl   = $this->tables->order_items_meta;

		// ---------------------------------------------------------------------
		// Count Source Rows
		// ---------------------------------------------------------------------

		// 1. Source Meta
		$q_src_meta    = 'SELECT COUNT(*) FROM %i WHERE post_id = %d';
		$args_src_meta = array( $src_postmeta_tbl, $order_id );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sql_src_meta = $db->prepare( $q_src_meta, $args_src_meta );
		$source_meta  = (int) $db->get_var( $sql_src_meta ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// 2. Source Items
		$q_src_items    = 'SELECT COUNT(*) FROM %i WHERE order_id = %d';
		$args_src_items = array( $order_items_table, $order_id );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sql_src_items = $db->prepare( $q_src_items, $args_src_items );
		$source_items  = (int) $db->get_var( $sql_src_items ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// 3. Source Item Meta
		$q_src_item_meta    = 'SELECT COUNT(*) FROM %i oim INNER JOIN %i oi ON oim.order_item_id = oi.order_item_id WHERE oi.order_id = %d';
		$args_src_item_meta = array( $order_items_meta_table, $order_items_table, $order_id );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sql_src_item_meta = $db->prepare( $q_src_item_meta, $args_src_item_meta );
		$source_item_meta  = (int) $db->get_var( $sql_src_item_meta ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// ---------------------------------------------------------------------
		// Count Archive Rows
		// ---------------------------------------------------------------------

		// 4. Archive Meta
		$q_arc_meta    = 'SELECT COUNT(*) FROM %i WHERE post_id = %d';
		$args_arc_meta = array( $arc_orders_meta_tbl, $order_id );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sql_arc_meta = $db->prepare( $q_arc_meta, $args_arc_meta );
		$archive_meta = (int) $db->get_var( $sql_arc_meta ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// 5. Archive Items
		$q_arc_items    = 'SELECT COUNT(*) FROM %i WHERE order_id = %d';
		$args_arc_items = array( $arc_order_items_tbl, $order_id );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sql_arc_items = $db->prepare( $q_arc_items, $args_arc_items );
		$archive_items = (int) $db->get_var( $sql_arc_items ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// 6. Archive Item Meta
		$q_arc_item_meta    = 'SELECT COUNT(*) FROM %i oim INNER JOIN %i oi ON oim.order_item_id = oi.order_item_id WHERE oi.order_id = %d';
		$args_arc_item_meta = array( $arc_item_meta_tbl, $arc_order_items_tbl, $order_id );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sql_arc_item_meta = $db->prepare( $q_arc_item_meta, $args_arc_item_meta );
		$archive_item_meta = (int) $db->get_var( $sql_arc_item_meta ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// ---------------------------------------------------------------------
		// Verify Counts Match
		// ---------------------------------------------------------------------

		if ( $archive_meta !== $source_meta ) {
			throw new \Exception( esc_html (
				"Archive verification failed for #{$order_id}: meta rows expected {$source_meta}, got {$archive_meta}."
			));
		}

		if ( $archive_items !== $source_items ) {
			throw new \Exception( esc_html(
				"Archive verification failed for #{$order_id}: items rows expected {$source_items}, got {$archive_items}."
			));
		}

		if ( $archive_item_meta !== $source_item_meta ) {
			throw new \Exception( esc_html(
				"Archive verification failed for #{$order_id}: item meta rows expected {$source_item_meta}, got {$archive_item_meta}."
			));
		}
	}

	/**
	 * Archives a single order. Returns a result code (not just bool) so the
	 * caller can distinguish an intentional skip (subscription-protected)
	 * from an actual failure — these need separate buckets in batch
	 * reporting, since "skipped" is expected/safe behaviour and "failed"
	 * indicates something went wrong.
	 *
	 * @param int  $order_id ID of the order to archive.
	 * @param bool $dry_run  Optional. If true, simulates the process and rolls back. Default false.
	 * @return array{status: string, reason: string} status is one of 'success', 'skipped', 'failed'.
	 */
	private function archive_order( int $order_id, bool $dry_run = false ): array {

		$this->wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		try {

			// Guard - skip subscription-linked order to protect billing chains.
			$subscription_status = $this->get_subscription_status( $order_id );

			if ( $subscription_status['is_linked'] ) {
				$reason = $subscription_status['reason'];
				$this->logger->queue( $order_id, 'archived', 'skipped', $reason );
				// Clean transaction close before premature functional exit path.
				$this->wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				return array(
					'status' => 'skipped',
					'reason' => $reason,
				);
			}

			// Copy — parent first, children after.
			$this->copy_order_post( $order_id );
			$this->copy_order_meta( $order_id );
			$this->copy_order_items( $order_id );
			$this->copy_order_items_meta( $order_id );
			$this->copy_order_notes( $order_id );
			$this->copy_order_notes_meta( $order_id );
			$this->copy_order_refunds( $order_id );
			$this->copy_order_refunds_meta( $order_id );

			// Verify all rows were copied before we delete anything from the source table.
			$this->verify_archive_copy( $order_id );

			// Delete — children first, parent last.
			$this->delete_order_notes_meta( $order_id );
			$this->delete_order_notes( $order_id );
			$this->delete_order_items_meta( $order_id );
			$this->delete_order_items( $order_id );
			$this->delete_order_meta( $order_id );
			$this->delete_order_refunds_meta( $order_id );
			$this->delete_order_refunds( $order_id );
			$this->delete_order_stats( $order_id );
			$this->delete_order_analytics( $order_id );
			$this->delete_order_post( $order_id );

			if ( $dry_run ) {
				$this->wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$this->logger->queue( $order_id, 'dry_run', 'success' );
			} else {
				$this->wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$this->logger->queue( $order_id, 'archive', 'success' );
			}

			return array(
				'status' => 'success',
				'reason' => '',
			);

		} catch ( \Exception $e ) {
			$this->wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$action = $dry_run ? 'dry_run' : 'archive';
			$this->logger->queue( $order_id, $action, 'error', $e->getMessage() );

			return array(
				'status' => 'failed',
				'reason' => $e->getMessage(),
			);
		}
	}

	/**
	 * Copies a single order row from wp_posts into the orders archive table.
	 * Uses INSERT ... SELECT so every column is copied automatically —
	 * nothing can be missed even if WordPress adds columns in future versions.
	 *
	 * @param int $order_id Order ID to copy.
	 * @throws \Exception If the insert fails.
	 * @return void
	 */
	private function copy_order_post( int $order_id ): void {

		$db         = $this->wpdb;
		$target_tbl = $this->tables->orders;
		$source_tbl = $db->posts;

		// Isolate query template using single quotes and generic column maps.
		$query = 'INSERT IGNORE INTO %i 
			(ID, post_author, post_date, post_date_gmt, post_content, post_title,
			post_excerpt, post_status, comment_status, ping_status, post_password,
			post_name, to_ping, pinged, post_modified, post_modified_gmt,
			post_content_filtered, post_parent, guid, menu_order, post_type,
			post_mime_type, comment_count)
			SELECT ID, post_author, post_date, post_date_gmt, post_content, post_title,
			post_excerpt, post_status, comment_status, ping_status, post_password,
			post_name, to_ping, pinged, post_modified, post_modified_gmt,
			post_content_filtered, post_parent, guid, menu_order, post_type,
			post_mime_type, comment_count
			FROM %i 
			WHERE ID = %d';

		$args = array( $target_tbl, $source_tbl, $order_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared_sql = $db->prepare( $query, $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$result = $db->query( $prepared_sql );

		if ( false === $result ) {
			throw new \Exception( esc_html ("Failed to copy order #{$order_id} to archive." ));
		}

		if ( 0 === $result ) {
			throw new \Exception( esc_html ("Order #{$order_id} not found in wp_posts." ));
		}
	}

	/**
	 * Copies all postmeta rows for an order into the orders_meta archive table.
	 *
	 * @param int $order_id Order ID whose meta should be copied.
	 * @throws \Exception If the insert fails.
	 * @return void
	 */
	private function copy_order_meta( int $order_id ): void {

		$db         = $this->wpdb;
		$target_tbl = $this->tables->orders_meta;
		$source_tbl = $db->postmeta;

		// Pure single-quoted layout completely isolated from direct object tokens.
		$query = 'INSERT IGNORE INTO %i (meta_id, post_id, meta_key, meta_value) SELECT meta_id, post_id, meta_key, meta_value FROM %i WHERE post_id = %d';
		$args  = array( $target_tbl, $source_tbl, $order_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared_sql = $db->prepare( $query, $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$result = $db->query( $prepared_sql );

		if ( false === $result ) {
			throw new \Exception( esc_html("Failed to copy meta for order #{$order_id}." ));
		}
	}

	/**
	 * Copies all order item rows for an order into the order_items archive table.
	 * Order items are line items, including products, shipping, taxes, fees, and coupons.
	 *
	 * @param int $order_id Order ID whose items should be copied.
	 * @throws \Exception If the insert fails.
	 * @return void
	 */
	private function copy_order_items( int $order_id ): void {

		$db         = $this->wpdb;
		$target_tbl = $this->tables->order_items;
		$source_tbl = $db->prefix . 'woocommerce_order_items';

		// Clean single-quoted layout isolated from dynamic properties.
		$query = 'INSERT IGNORE INTO %i (order_item_id, order_item_name, order_item_type, order_id) SELECT order_item_id, order_item_name, order_item_type, order_id FROM %i WHERE order_id = %d';
		$args  = array( $target_tbl, $source_tbl, $order_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared_sql = $db->prepare( $query, $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$result = $db->query( $prepared_sql );

		if ( false === $result ) {
			throw new \Exception( esc_html ("Failed to copy order items for order #{$order_id}." ));
		}
	}

	/**
	 * Copies all order item meta rows for an order into the
	 * order_items_meta archive table. This includes product quantities,
	 * line totals, tax data, and other per-item metadata.
	 *
	 * Joins against woocommerce_order_items because order item meta
	 * does not store the order_id directly, only the order_item_id.
	 *
	 * @param int $order_id Order ID whose item meta should be copied.
	 * @throws \Exception If the insert fails.
	 * @return void
	 */
	private function copy_order_items_meta( int $order_id ): void {

		$db            = $this->wpdb;
		$target_tbl    = $this->tables->order_items_meta;
		$src_meta_tbl  = $db->prefix . 'woocommerce_order_itemmeta';
		$src_items_tbl = $db->prefix . 'woocommerce_order_items';

		// Clean single-quoted layout using isolated triple identifier placeholders.
		$query = 'INSERT IGNORE INTO %i (meta_id, order_item_id, meta_key, meta_value) SELECT oim.meta_id, oim.order_item_id, oim.meta_key, oim.meta_value FROM %i oim INNER JOIN %i oi ON oim.order_item_id = oi.order_item_id WHERE oi.order_id = %d';
		$args  = array( $target_tbl, $src_meta_tbl, $src_items_tbl, $order_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared_sql = $db->prepare( $query, $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$result = $db->query( $prepared_sql );

		if ( false === $result ) {
			throw new \Exception( esc_html("Failed to copy order item meta for order #{$order_id}." ));
		}
	}

	/**
	 * Copies order note comments from wp_comments into the
	 * order_notes archive table. WooCommerce stores order notes
	 * (customer-facing and private) as comments where
	 * comment_post_ID matches the order ID.
	 *
	 * @param int $order_id Order ID whose notes should be copied.
	 * @throws \Exception If the insert fails.
	 * @return void
	 */
	private function copy_order_notes( int $order_id ): void {

		$db         = $this->wpdb;
		$target_tbl = $this->tables->order_notes;
		$source_tbl = $db->comments;

		// Clean template using escaped string variables inside isolated single quotes.
		$query = 'INSERT IGNORE INTO %i 
			(comment_ID, comment_post_ID, comment_author, comment_author_email,
			comment_author_url, comment_author_IP, comment_date, comment_date_gmt,
			comment_content, comment_karma, comment_approved, comment_agent,
			comment_type, comment_parent, user_id)
			SELECT comment_ID, comment_post_ID, comment_author, comment_author_email,
			comment_author_url, comment_author_IP, comment_date, comment_date_gmt,
			comment_content, comment_karma, comment_approved, comment_agent,
			comment_type, comment_parent, user_id
			FROM %i 
			WHERE comment_post_ID = %d 
			AND comment_type IN (\'order_note\', \'order_note_private\')';

		$args = array( $target_tbl, $source_tbl, $order_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared_sql = $db->prepare( $query, $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$result = $db->query( $prepared_sql );

		if ( false === $result ) {
			throw new \Exception( esc_html("Failed to copy order notes for order #{$order_id}." ));
		}
	}

	/**
	 * Copies comment meta for order notes into the order_notes_meta
	 * archive table. Joins against wp_comments to find which
	 * comment_ids belong to this order's notes.
	 *
	 * @param int $order_id Order ID whose note meta should be copied.
	 * @throws \Exception If the insert fails.
	 * @return void
	 */
	private function copy_order_notes_meta( int $order_id ): void {

		$db           = $this->wpdb;
		$target_tbl   = $this->tables->order_notes_meta;
		$src_meta_tbl = $db->commentmeta;
		$src_comm_tbl = $db->comments;

		// Pure single-quoted schema template passing triple %i table mappings.
		$query = 'INSERT IGNORE INTO %i (meta_id, comment_id, meta_key, meta_value) SELECT cm.meta_id, cm.comment_id, cm.meta_key, cm.meta_value FROM %i cm INNER JOIN %i c ON cm.comment_id = c.comment_ID WHERE c.comment_post_ID = %d AND c.comment_type IN (\'order_note\', \'order_note_private\')';
		$args  = array( $target_tbl, $src_meta_tbl, $src_comm_tbl, $order_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared_sql = $db->prepare( $query, $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$result = $db->query( $prepared_sql );

		if ( false === $result ) {
			throw new \Exception( esc_html("Failed to copy order note meta for order #{$order_id}." ));
		}
	}

	/**
	 * Copy all refund posts for an order into the order_refunds archive table.
	 * Refunds are shop_order_refunds posts with post_parent = order_id.
	 *
	 * @param int $order_id Parent Order ID.
	 * @throws \Exception If the insert fails.
	 * @return void
	 */
	private function copy_order_refunds( int $order_id ): void {

		$db = $this->wpdb;

		// Use standard %d placeholder for the single $order_id mapping.
		$query = "INSERT INTO {$this->tables->order_refunds} (
            ID, post_author, post_date, post_date_gmt, post_content, post_title, 
            post_excerpt, post_status, post_name, post_modified, post_modified_gmt, 
            post_content_filtered, post_parent, guid, menu_order, post_type, 
            post_mime_type, comment_count
        )
        SELECT 
            ID, post_author, post_date, post_date_gmt, post_content, post_title, 
            post_excerpt, post_status, post_name, post_modified, post_modified_gmt, 
            post_content_filtered, post_parent, guid, menu_order, post_type, 
            post_mime_type, comment_count
        FROM {$db->posts}
        WHERE post_type = 'shop_order_refund' AND post_parent = %d";

		// Prepare the query safely by injecting the single order ID.
		$prepared_sql = $db->prepare( $query, $order_id );

		// Execute the query.
		$result = $db->query( $prepared_sql );

		if ( false === $result ) {
			throw new \Exception(
				esc_html ("Failed to copy order refunds for order #{$order_id}."
			));
		}
	}

	/**
	 * Copies all refund meta rows for an order's refund into archive.
	 * Joins against wp_posts to find refund IDs belonging to this order.
	 *
	 * @param int $order_id Parent order ID.
	 * @throws \Exception If the insert fails.
	 * @return void
	 */
	private function copy_order_refunds_meta( int $order_id ): void {

		$db           = $this->wpdb;
		$target_tbl   = $this->tables->order_refunds_meta;
		$src_meta_tbl = $db->postmeta;
		$src_post_tbl = $db->posts;

		// Pure single-quoted schema template passing triple identifier placeholders.
		$query = 'INSERT IGNORE INTO %i (meta_id, post_id, meta_key, meta_value) SELECT pm.meta_id, pm.post_id, pm.meta_key, pm.meta_value FROM %i pm INNER JOIN %i p ON pm.post_id = p.ID WHERE p.post_parent = %d AND p.post_type = \'shop_order_refund\'';
		$args  = array( $target_tbl, $src_meta_tbl, $src_post_tbl, $order_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared_sql = $db->prepare( $query, $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$result = $db->query( $prepared_sql );

		if ( false === $result ) {
			throw new \Exception(
				esc_html("Failed to copy refund meta for order #{$order_id}."
			));
		}
	}

	/**
	 * Deletes order note meta from wp_commentmeta.
	 * Must run before delete_order_notes() since this depends on
	 * wp_comments still containing the comment_post_ID = order_id link.
	 *
	 * @param int $order_id Order ID whose note meta should be deleted.
	 * @return void
	 */
	private function delete_order_notes_meta( int $order_id ): void {

		$db           = $this->wpdb;
		$src_meta_tbl = $db->commentmeta;
		$src_comm_tbl = $db->comments;

		// Clean template using single quotes and isolated double identifier placeholders.
		$query = 'DELETE cm FROM %i cm INNER JOIN %i c ON cm.comment_id = c.comment_ID WHERE c.comment_post_ID = %d AND c.comment_type IN (\'order_note\', \'order_note_private\')';
		$args  = array( $src_meta_tbl, $src_comm_tbl, $order_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared_sql = $db->prepare( $query, $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$db->query( $prepared_sql );
	}

	/**
	 * Deletes order note comments from wp_comments.
	 *
	 * @param int $order_id Order ID whose notes should be deleted.
	 * @return void
	 */
	private function delete_order_notes( int $order_id ): void {

		$db         = $this->wpdb;
		$source_tbl = $db->comments;

		// Clean template using single quotes and isolated identifier placeholders.
		$query = 'DELETE FROM %i WHERE comment_post_ID = %d AND comment_type IN (\'order_note\', \'order_note_private\')';
		$args  = array( $source_tbl, $order_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared_sql = $db->prepare( $query, $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$db->query( $prepared_sql );
	}

	/**
	 * Deletes order item meta from woocommerce_order_itemmeta.
	 * Must run before delete_order_items() since this depends on
	 * woocommerce_order_items still containing the order_id link.
	 *
	 * @param int $order_id Order ID whose item meta should be deleted.
	 * @return void
	 */
	private function delete_order_items_meta( int $order_id ): void {

		$db            = $this->wpdb;
		$src_meta_tbl  = $db->prefix . 'woocommerce_order_itemmeta';
		$src_items_tbl = $db->prefix . 'woocommerce_order_items';

		// Clean join layout template passing explicit double %i table mappings.
		$query = 'DELETE oim FROM %i oim INNER JOIN %i oi ON oim.order_item_id = oi.order_item_id WHERE oi.order_id = %d';
		$args  = array( $src_meta_tbl, $src_items_tbl, $order_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared_sql = $db->prepare( $query, $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$db->query( $prepared_sql );
	}

	/**
	 * Deletes order items from woocommerce_order_items.
	 *
	 * @param int $order_id Order ID whose items should be deleted.
	 * @return void
	 */
	private function delete_order_items( int $order_id ): void {

		$db         = $this->wpdb;
		$source_tbl = $db->prefix . 'woocommerce_order_items';

		// Clean template using single quotes and isolated identifier placeholders.
		$query = 'DELETE FROM %i WHERE order_id = %d';
		$args  = array( $source_tbl, $order_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared_sql = $db->prepare( $query, $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$db->query( $prepared_sql );
	}

	/**
	 * Deletes order meta from wp_postmeta.
	 *
	 * @param int $order_id Order ID whose meta should be deleted.
	 * @return void
	 */
	private function delete_order_meta( int $order_id ): void {

		$db         = $this->wpdb;
		$source_tbl = $db->postmeta;

		// Clean template using single quotes and isolated identifier placeholders.
		$query = 'DELETE FROM %i WHERE post_id = %d';
		$args  = array( $source_tbl, $order_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared_sql = $db->prepare( $query, $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$db->query( $prepared_sql );
	}

	/**
	 * Deletes refund meta from wp_postmeta for all refunds of this order.
	 * Must run before delete_order_refunds().
	 *
	 * @param int $order_id Parent order ID.
	 * @return void
	 */
	private function delete_order_refunds_meta( int $order_id ): void {

		$db           = $this->wpdb;
		$src_meta_tbl = $db->postmeta;
		$src_post_tbl = $db->posts;

		// Clean join layout template passing explicit double %i table mappings.
		$query = 'DELETE pm FROM %i pm INNER JOIN %i p ON pm.post_id = p.ID WHERE p.post_parent = %d AND p.post_type = \'shop_order_refund\'';
		$args  = array( $src_meta_tbl, $src_post_tbl, $order_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared_sql = $db->prepare( $query, $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$db->query( $prepared_sql );
	}

	/**
	 * Deletes refund posts from wp_posts for this order.
	 *
	 * @param int $order_id Parent Order ID.
	 * @return void
	 */
	private function delete_order_refunds( int $order_id ): void {

		$db         = $this->wpdb;
		$source_tbl = $db->posts;

		// Clean template using single quotes and isolated identifier placeholders.
		$query = 'DELETE FROM %i WHERE post_parent = %d AND post_type = \'shop_order_refund\'';
		$args  = array( $source_tbl, $order_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared_sql = $db->prepare( $query, $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$db->query( $prepared_sql );
	}

	/**
	 * Deletes the cached analytics row for this order from
	 * wp_wc_order_stats. This is regenerable data, meaning it is not archived,
	 * just removed so WooCommerce Analytics does not show ghost
	 * entries for orders that no longer exist in wp_posts.
	 *
	 * Uses a direct table name since wp_wc_order_stats may not
	 * exist on very old WooCommerce installs, checked first.
	 *
	 * @param int $order_id Order ID whose stats row should be deleted.
	 * @return void
	 */
	private function delete_order_stats( int $order_id ): void {

		$db          = $this->wpdb;
		$stats_table = $db->prefix . 'wc_order_stats';

		// Isolate the schema check statement.
		$check_query = 'SHOW TABLES LIKE %s';
		$check_args  = array( $stats_table );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared_check = $db->prepare( $check_query, $check_args );

		if ( $db->get_var( $prepared_check ) !== $stats_table ) {
			return;
		}

		// Isolate the clean single-quoted delete pattern.
		$delete_query = 'DELETE FROM %i WHERE order_id = %d';
		$delete_args  = array( $stats_table, $order_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared_delete = $db->prepare( $delete_query, $delete_args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$db->query( $prepared_delete );
	}

	/**
	 * Deletes WooCommerce Analytics lookup table rows for this order.
	 * These tables are regenerable from order data, as they are cache/reporting
	 * tables, not source-of-truth. Deleting them on archive prevents ghost
	 * entries appearing in WooCommerce Analytics reports.
	 *
	 * Tables cleaned:
	 * - wc_order_product_lookup  - one row per line item.
	 * - wc_order_coupon_lookup   - one row per coupon used.
	 * - wc_order_tax_lookup      - one row per tax line.
	 * - wc_customer_lookup       - only if customer has no remaining live orders.
	 *
	 * Each table is checked for existence before querying, making it safe on older
	 * WooCommerce versions that may not have all tables.
	 *
	 * @param int $order_id Order ID whose analytics rows should be removed.
	 * @return void
	 */
	private function delete_order_analytics( int $order_id ): void {

		$db               = $this->wpdb;
		$analytics_tables = array(
			'wc_order_product_lookup' => 'order_id',
			'wc_order_coupon_lookup'  => 'order_id',
			'wc_order_tax_lookup'     => 'order_id',
		);

		foreach ( $analytics_tables as $table_suffix => $column ) {
			$table = $db->prefix . $table_suffix;

			// Isolate the table check execution string.
			$check_query = 'SHOW TABLES LIKE %s';
			$check_args  = array( $table );

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$prepared_check = $db->prepare( $check_query, $check_args );

			if ( $db->get_var( $prepared_check ) !== $table ) {
				continue;
			}

			// Clean dynamic deletion mapping through double %i identifiers.
			$delete_query = 'DELETE FROM %i WHERE %i = %d';
			$delete_args  = array( $table, $column, $order_id );

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$prepared_delete = $db->prepare( $delete_query, $delete_args );

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$db->query( $prepared_delete );
		}

		// Customer lookup — only remove if this customer has no other live orders.
		// Removing prematurely would break customer lifetime value reporting.
		$this->maybe_delete_customer_lookup( $order_id );
	}

	/**
	 * Removes the customer lookup row only if the customer has no remaining
	 * live orders after this one is archived.
	 *
	 * Wc_customer_lookup is per-customer not per-order, meaning one row per customer.
	 * Deleting it removes the customer from Analytics entirely, which is only
	 * correct if this was their only order. If they have other live orders,
	 * the row must remain.
	 *
	 * @param int $order_id Order being archived.
	 * @return void
	 */
	private function maybe_delete_customer_lookup( int $order_id ): void {

		$db              = $this->wpdb;
		$customer_lookup = $db->prefix . 'wc_customer_lookup';

		// 1. Isolate the table existence check.
		$show_query = 'SHOW TABLES LIKE %s';
		$show_args  = array( $customer_lookup );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared_show = $db->prepare( $show_query, $show_args );

		if ( $db->get_var( $prepared_show ) !== $customer_lookup ) {
			return;
		}

		// 2. Isolate customer ID query template.
		$select_query = 'SELECT customer_id FROM %i WHERE order_id = %d LIMIT 1';
		$select_args  = array( $customer_lookup, $order_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared_select = $db->prepare( $select_query, $select_args );
		$customer_id     = (int) $db->get_var( $prepared_select );

		if ( ! $customer_id ) {
			return;
		}

		// 3. Isolate count remaining orders query template.
		$count_query = 'SELECT COUNT(*) FROM %i WHERE customer_id = %d AND order_id != %d';
		$count_args  = array( $customer_lookup, $customer_id, $order_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared_count   = $db->prepare( $count_query, $count_args );
		$remaining_orders = (int) $db->get_var( $prepared_count );

		// Only delete if this was their only order.
		if ( 0 === $remaining_orders ) {
			$delete_query = 'DELETE FROM %i WHERE customer_id = %d';
			$delete_args  = array( $customer_lookup, $customer_id );

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$prepared_delete = $db->prepare( $delete_query, $delete_args );

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$db->query( $prepared_delete );
		}
	}

	/**
	 * Deletes the order post from wp_posts.
	 * This is the final delete step. It runs last because every
	 * other table's cleanup depends on this row still existing
	 * (via post_id / order_id / comment_post_ID references).
	 *
	 * @param int $order_id Order ID to delete.
	 * @return void
	 */
	private function delete_order_post( int $order_id ): void {

		$db         = $this->wpdb;
		$source_tbl = $db->posts;

		// Clean template using single quotes and isolated identifier placeholders.
		$query = 'DELETE FROM %i WHERE ID = %d';
		$args  = array( $source_tbl, $order_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared_sql = $db->prepare( $query, $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$db->query( $prepared_sql );
	}

	/**
	 * Processes one batch of orders.
	 *
	 * Fetches up to batch_size eligible order IDs, archives each one,
	 * then flushes all queued log entries in a single write.
	 *
	 * Returns a summary array so the Ajax handler can report progress
	 * to the admin UI without needing to track state on the JS side.
	 *
	 * @param string             $before_date Archive orders placed before this date.
	 * @param array<int, string> $statuses    Order statuses to include.
	 * @param bool               $dry_run     If true, all DB changes are rolled back.
	 * @param int                $batch_size  Optional custom batch size.
	 * @param string             $from_date   Optional start date for range-based archiving.
	 * @return array{processed: int, succeeded: int, failed: int, dry_run: bool}
	 */
	public function process_batch( string $before_date, array $statuses, bool $dry_run = false, int $batch_size = 0, string $from_date = '' ): array {
		if ( $batch_size > 0 ) {
			$this->batch_size = $batch_size;
		}

		// Capture excluded IDs sent from AJAX/frontend if available to prevent re-fetching skipped items.
		$exclude_ids = array();
		if ( isset( $_POST['exclude_ids'] ) && check_ajax_referer( 'hw_woam_ajax', 'nonce', false ) ) {
			$exclude_ids = array_map( 'intval', (array) wp_unslash( $_POST['exclude_ids'] ) );
		}

		$order_ids = $this->get_batch_order_ids( $before_date, $statuses, $from_date, $exclude_ids );

		$results = array(
			'processed'    => 0,
			'succeeded'    => 0,
			'skipped'      => 0,
			'failed'       => 0,
			'dry_run'      => $dry_run,
			'skip_reasons' => array(),
			'failed_ids'   => array(), // Pass back to frontend so they can be appended to exclude_ids.
		);

		$max_sample_reasons = 5;

		foreach ( $order_ids as $order_id ) {
			++$results['processed'];

			$outcome = $this->archive_order( $order_id, $dry_run );

			switch ( $outcome['status'] ) {
				case 'success':
					++$results['succeeded'];
					break;
				case 'skipped':
					++$results['skipped'];
					$results['failed_ids'][] = $order_id; // Track to exclude in next fetch round.
					if ( count( $results['skip_reasons'] ) < $max_sample_reasons ) {
						$results['skip_reasons'][] = array(
							'order_id' => $order_id,
							'reason'   => $outcome['reason'],
						);
					}
					break;
				default:
					++$results['failed'];
					$results['failed_ids'][] = $order_id; // Track to exclude in next fetch round.
					break;
			}
		}

		$this->logger->flush_queue();

		return $results;
	}
}
