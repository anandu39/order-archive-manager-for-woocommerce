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
		$this->batch_size = (int) apply_filters( 'hw_woam_batch_size', 50 );
	}

	/**
	 *
	 * Return the total number of orders eligible for archiving.
	 * Used by the admin UI to show the order count before starting the archive process.
	 *
	 * @param string            $before_date Archive orders placed before this date (Y-m-d format).
	 * @param array<int string> $statuses Order statuses to include in the count (e.g. ['wc-completed', 'wc-processing']).
	 * @return int Total number of orders eligible for archiving.
	 */
	public function get_total_orders_to_archive( string $before_date, array $statuses ): int {

		if ( empty( $statuses ) ) {
			return 0; // No statuses means no orders to archive
		}

		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		$sql = $this->wpdb->prepare(
			"SELECT COUNT(*) FROM {$this->wpdb->posts}
            WHERE post_type = 'shop_order'
            AND post_date < %s
            AND post_status IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			array_merge( array( $before_date ), $statuses )
		);

		return (int) $this->wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Returns a batch of order IDs eligible for archiving.
	 * Limited to batch_size. Call repeatedly until it returns an empty array.
	 *
	 * @param string             $before_date Archive orders placed before this date (Y-m-d format).
	 * @param array<int, string> $statuses    Order statuses to include.
	 * @return array<int, int> Order IDs.
	 */
	public function get_batch_order_ids( string $before_date, array $statuses ): array {

		if ( empty( $statuses ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		$sql = $this->wpdb->prepare(
			"SELECT ID FROM {$this->wpdb->posts}
            WHERE post_type = 'shop_order'
            AND post_date < %s
            AND post_status IN ({$placeholders})
            ORDER BY ID ASC
            LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			array_merge( array( $before_date ), $statuses, array( $this->batch_size ) )
		);

		return array_map( 'intval', $this->wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Checks whether an order is linked to an active subscription.
	 * Archiving subscription-linked orders would break WooCommerce Subscriptions
	 * billing chains - the renewal system queries wp_posts for parent order IDs.
	 *
	 * Return true (and therefore blocks archiving) if:
	 * - The order has '_subscription_renewal' meta (it is a renewal order)
	 * - The order has '_subscription_resubscribe' meta (it is a resubscribe order)
	 * - Any active subscription exists with this order as its parent post
	 *
	 * Only runs when WooCommerce Subscription is active. If the plugin is not installed,
	 * the action is skipped - no false positives.
	 *
	 * @param int $order_id Order to check.
	 * @return bool True if the subscription-linked and should be skipped.
	 */
	private function is_subscription_linked( int $order_id ): bool {

		// If WooCommerce Subscription isn't active skip this completely.

		if ( ! class_exists( 'WC_Subscriptions' ) ) {
			return false;
		}

		// Check 1 - is the order a renewal or resubscribe order?

		$renewal_meta = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT meta_id FROM {$this->wpdb->postmeta}
                WHERE post_id = %d
                AND meta_key IN ('_subscription_renewal', '_subscription_resubscribe')
                LIMIT 1",
				$order_id
			)
		);

		if ( $renewal_meta ) {
			return true;
		}

		// Check 2 - does any active subscription have this order as its parent?

		$active_subscription = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT ID FROM {$this->wpdb->posts}
                WHERE post_parent = %d
                AND post_type = 'shop_subscription'
                AND post_status IN ( 'wc-active','wc-pending-cancel' )
                LIMIT 1",
				$order_id
			)
		);

		return (bool) $active_subscription;
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

		$order_items_table      = $this->wpdb->prefix . 'woocommerce_order_items';
		$order_items_meta_table = $this->wpdb->prefix . 'woocommerce_order_itemmeta';

		// Count Source row.
		$source_meta = (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT (*) FROM {$this->wpdb->postmeta} WHERE post_id = %d",
				$order_id
			)
		);

		$source_items = (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT (*) FROM {$order_items_table} WHERE order_id = %d",
				$order_id
			)
		);

		$source_item_meta = (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT (*) FROM {$order_items_meta_table} oim
                INNER JOIN {$order_items_table} oi ON oim.order_item_id = oi.order_item_id
                WHERE oi.order_id = %d", //phpcs:ignore WordPress.DB.PrepareSQL.InterpolateNotPrepared.
				$order_id
			)
		);

		// Count Archive rows.
		$archive_meta = (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT (*) FROM {$this->tables->orders_meta} WHERE post_id = %d",
				$order_id
			)
		);

		$archive_items = (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT (*) FROM {$this->tables->order_items} WHERE order_id = %d",
				$order_id
			)
		);

		$archive_item_meta = (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT (*) FROM {$this->tables->order_items_meta} oim
                INNER JOIN {$this->tables->order_items} oi ON oim.order_item_id = oi.order_item_id
                WHERE oi.order_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$order_id
			)
		);

		// Verify Counts Match.

		if ( $archive_meta !== $source_meta ) {
			throw new \Exception(
				"Archive verification failed for #{$order_id}: meta rows expected {$source_meta}, got {$archive_meta}."
			);
		}

		if ( $archive_items !== $source_items ) {
			throw new \Exception(
				"Archive verification failed for #{$order_id}: items rows expected {$source_items}, got {$archive_items}."
			);
		}

		if ( $archive_item_meta !== $source_item_meta ) {
			throw new \Exception(
				"Archive verification failed for #{$order_id}: item meta rows expected {$source_item_meta}, got {$archive_item_meta}."
			);
		}
	}

	/**
	 *
	 * Archives a single order by moving its data from live tables to archive tables,
	 * then deleting it from live tables. Runs inside a database transaction so
	 * either all steps succeed or nothing changes, ensuring data integrity.
	 *
	 * @param int $order_id ID of the order to archive.
	 * @return bool True on success, false on failure.
	 */
	private function archive_order( int $order_id, bool $dry_run = false ): bool {

		$this->wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		try {

			// Guard - skip subscription-linked order to protect billing chains.

			if ( $this->is_subscription_linked( $order_id ) ) {
				$this->logger->queue( $order_id, 'archived', 'skipped', 'Order is linked to an Subscription.' );
				return false;
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

			// Verify all rows were copied before we delete anything from the source tabel.
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
			$this->delete_order_post( $order_id );

			if ( $dry_run ) {
				$this->wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$this->logger->queue( $order_id, 'dry_run', 'success' );
			} else {
				$this->wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$this->logger->queue( $order_id, 'archive', 'success' );
			}

			return true;

		} catch ( \Exception $e ) {
			$this->wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$action = $dry_run ? 'dry_run' : 'archive';
			$this->logger->queue( $order_id, $action, 'error', $e->getMessage() );

			return false;
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

		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				"INSERT IGNORE INTO {$this->tables->orders}
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
                FROM {$this->wpdb->posts}
                WHERE ID = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$order_id
			)
		);

		if ( false === $result ) {
			throw new \Exception( "Failed to copy order #{$order_id} to archive." );
		}

		if ( 0 === $result ) {
			throw new \Exception( "Order #{$order_id} not found in wp_posts." );
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

		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				"INSERT IGNORE INTO {$this->tables->orders_meta}
                (meta_id, post_id, meta_key, meta_value)
                SELECT meta_id, post_id, meta_key, meta_value
                FROM {$this->wpdb->postmeta}
                WHERE post_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$order_id
			)
		);

		if ( false === $result ) {
			throw new \Exception( "Failed to copy meta for order #{$order_id}." );
		}
	}

	/**
	 * Copies all order item rows for an order into the order_items archive table.
	 * Order items are line items — products, shipping, taxes, fees, coupons.
	 *
	 * @param int $order_id Order ID whose items should be copied.
	 * @throws \Exception If the insert fails.
	 * @return void
	 */
	private function copy_order_items( int $order_id ): void {

		$order_items_table = $this->wpdb->prefix . 'woocommerce_order_items';

		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				"INSERT IGNORE INTO {$this->tables->order_items}
                (order_item_id, order_item_name, order_item_type, order_id)
                SELECT order_item_id, order_item_name, order_item_type, order_id
                FROM {$order_items_table}
                WHERE order_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$order_id
			)
		);

		if ( false === $result ) {
			throw new \Exception( "Failed to copy order items for order #{$order_id}." );
		}
	}

	/**
	 * Copies all order item meta rows for an order into the
	 * order_items_meta archive table. This includes product quantities,
	 * line totals, tax data, and other per-item metadata.
	 *
	 * Joins against woocommerce_order_items because order item meta
	 * doesn't store the order_id directly — only the order_item_id.
	 *
	 * @param int $order_id Order ID whose item meta should be copied.
	 * @throws \Exception If the insert fails.
	 * @return void
	 */
	private function copy_order_items_meta( int $order_id ): void {

		$order_items_table      = $this->wpdb->prefix . 'woocommerce_order_items';
		$order_items_meta_table = $this->wpdb->prefix . 'woocommerce_order_itemmeta';

		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				"INSERT IGNORE INTO {$this->tables->order_items_meta}
                (meta_id, order_item_id, meta_key, meta_value)
                SELECT oim.meta_id, oim.order_item_id, oim.meta_key, oim.meta_value
                FROM {$order_items_meta_table} oim
                INNER JOIN {$order_items_table} oi
                    ON oim.order_item_id = oi.order_item_id
                WHERE oi.order_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$order_id
			)
		);

		if ( false === $result ) {
			throw new \Exception( "Failed to copy order item meta for order #{$order_id}." );
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

		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				"INSERT IGNORE INTO {$this->tables->order_notes}
                (comment_ID, comment_post_ID, comment_author, comment_author_email,
                comment_author_url, comment_author_IP, comment_date, comment_date_gmt,
                comment_content, comment_karma, comment_approved, comment_agent,
                comment_type, comment_parent, user_id)
                SELECT comment_ID, comment_post_ID, comment_author, comment_author_email,
                comment_author_url, comment_author_IP, comment_date, comment_date_gmt,
                comment_content, comment_karma, comment_approved, comment_agent,
                comment_type, comment_parent, user_id
                FROM {$this->wpdb->comments}
                WHERE comment_post_ID = %d
                AND comment_type IN ('order_note', 'order_note_private')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$order_id
			)
		);

		if ( false === $result ) {
			throw new \Exception( "Failed to copy order notes for order #{$order_id}." );
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

		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				"INSERT IGNORE INTO {$this->tables->order_notes_meta}
                (meta_id, comment_id, meta_key, meta_value)
                SELECT cm.meta_id, cm.comment_id, cm.meta_key, cm.meta_value
                FROM {$this->wpdb->commentmeta} cm
                INNER JOIN {$this->wpdb->comments} c
                    ON cm.comment_id = c.comment_ID
                WHERE c.comment_post_ID = %d
                AND c.comment_type IN ('order_note', 'order_note_private')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$order_id
			)
		);

		if ( false === $result ) {
			throw new \Exception( "Failed to copy order note meta for order #{$order_id}." );
		}
	}

	/**
	 *
	 * Copy all refund posts for an order into the order_refunds archive table.
	 * Refunds are shop_order_refunds posts with post_parent = order_id.
	 *
	 * @param int $order_id Parent Order ID.
	 * @throws \Exception if the insert fails.
	 * @return void
	 */
	private function copy_order_refunds( int $order_id ): void {

		$results = $this->wpdb->query(
			$this->wpdb->prepare(
				"INSERT IGNORE INTO {$this->tables->order_refunds}
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
                FROM {$this->wpdb->posts}
                WHERE post_parent = %d
                AND post_type = 'shop_order_refund'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$order_id
			)
		);

		if ( false === $result ) {
			throw new \Exception(
				"Failed to copy order refunds for order #{$order_id}."
			);
		}
	}

	/**
	 * Copies all refund meta rows for an order's refund into archive.
	 * Joins against wp_posts to find refund IDs belonging to this order
	 *
	 * @param int $order_id Parent order ID.
	 * @throws \Exception If the insert Fails.
	 * @return void
	 */
	private function copy_order_refunds_meta( int $order_id ): void {

		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				"INSERT IGNORE INTO {$this->tables->order_refunds_meta}
                (meta_id, post_id, meta_key, meta_value)
                SELECT pm.meta_id, pm.post_id, pm.meta_key, pm.meta_value
                FROM {$this->wpdb->postmeta} pm
                INNER JOIN {$this->wpdb->posts} p ON pm.post_id = p.ID
                WHERE p.post_parent = %d
                AND p.post_type = 'shop_order_refund'", //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$order_id
			)
		);

		if ( false === $result ) {
			throw new \Exception(
				"Failed to copy refund meta for order #{$order_id}."
			);
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

		$this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE cm FROM {$this->wpdb->commentmeta} cm
                INNER JOIN {$this->wpdb->comments} c
                    ON cm.comment_id = c.comment_ID
                WHERE c.comment_post_ID = %d
                AND c.comment_type IN ('order_note', 'order_note_private')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$order_id
			)
		);
	}

	/**
	 * Deletes order note comments from wp_comments.
	 *
	 * @param int $order_id Order ID whose notes should be deleted.
	 * @return void
	 */
	private function delete_order_notes( int $order_id ): void {

		$this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM {$this->wpdb->comments}
                WHERE comment_post_ID = %d
                AND comment_type IN ('order_note', 'order_note_private')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$order_id
			)
		);
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

		$order_items_table      = $this->wpdb->prefix . 'woocommerce_order_items';
		$order_items_meta_table = $this->wpdb->prefix . 'woocommerce_order_itemmeta';

		$this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE oim FROM {$order_items_meta_table} oim
                INNER JOIN {$order_items_table} oi
                    ON oim.order_item_id = oi.order_item_id
                WHERE oi.order_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$order_id
			)
		);
	}

	/**
	 * Deletes order items from woocommerce_order_items.
	 *
	 * @param int $order_id Order ID whose items should be deleted.
	 * @return void
	 */
	private function delete_order_items( int $order_id ): void {

		$order_items_table = $this->wpdb->prefix . 'woocommerce_order_items';

		$this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM {$order_items_table} WHERE order_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$order_id
			)
		);
	}

	/**
	 * Deletes order meta from wp_postmeta.
	 *
	 * @param int $order_id Order ID whose meta should be deleted.
	 * @return void
	 */
	private function delete_order_meta( int $order_id ): void {

		$this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM {$this->wpdb->postmeta} WHERE post_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$order_id
			)
		);
	}

	/**
	 * Deletes refund meta from wp_postmeta for all refunds of this order.
	 * Must run before delete_order_refunds().
	 *
	 * @param int $order_id Parent order ID.
	 * @return void
	 */
	private function delete_order_refunds_meta( int $order_id ): void {
		$this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE pm FROM {$this->wpdb->postmeta} pm
                INNER JOIN {$this->wpdb->posts} p ON pm.post_id = p.ID
                WHERE p.post_parent = %d
                AND p.post_type = 'shop_order_refund'", //phpcs:ignore Wordpress.DB.PreparedSQL.InterpolatedNotPrepared
				$order_id
			)
		);
	}

	/**
	 * Deletes refund posts from wp_posts for this order.
	 *
	 * @param int $order_id Parent Order ID.
	 * @return void
	 */
	private function delete_order_refunds( int $order_id ): void {

		$this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM {$this->wpdb->posts}
                WHERE post_parent = %d
                AND post_type = 'shop_order_refund'", //phpcs:ignore WordPress.DB.PreparedSQL.InterpolateNotPrepared
				$order_id
			)
		);
	}

	/**
	 * Deletes the cached analytics row for this order from
	 * wp_wc_order_stats. This is regenerable data — not archived,
	 * just removed so WooCommerce Analytics doesn't show ghost
	 * entries for orders that no longer exist in wp_posts.
	 *
	 * Uses a direct table name since wp_wc_order_stats may not
	 * exist on very old WooCommerce installs — checked first.
	 *
	 * @param int $order_id Order ID whose stats row should be deleted.
	 * @return void
	 */
	private function delete_order_stats( int $order_id ): void {

		$stats_table = $this->wpdb->prefix . 'wc_order_stats';

		if ( $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $stats_table ) ) !== $stats_table ) {
			return;
		}

		$this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM {$stats_table} WHERE order_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$order_id
			)
		);
	}

	/**
	 * Deletes the order post from wp_posts.
	 * This is the final delete step — runs last because every
	 * other table's cleanup depends on this row still existing
	 * (via post_id / order_id / comment_post_ID references).
	 *
	 * @param int $order_id Order ID to delete.
	 * @return void
	 */
	private function delete_order_post( int $order_id ): void {

		$this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM {$this->wpdb->posts} WHERE ID = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$order_id
			)
		);
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
	 * @param string             $before_date Archive orders placed before this date (Y-m-d format).
	 * @param array<int, string> $statuses    Order statuses to include.
	 * @param bool               $dry_run     If true, all DB changes are rolled back — nothing is archived.
	 * @return array{processed: int, succeeded: int, failed: int, dry_run: bool}
	 */
	public function process_batch( string $before_date, array $statuses, bool $dry_run = false ): array {

		$order_ids = $this->get_batch_order_ids( $before_date, $statuses );

		$results = array(
			'processed' => 0,
			'succeeded' => 0,
			'failed'    => 0,
			'dry_run'   => $dry_run,
		);

		foreach ( $order_ids as $order_id ) {
			++$results['processed'];

			if ( $this->archive_order( $order_id, $dry_run ) ) {
				++$results['succeeded'];
			} else {
				++$results['failed'];
			}
		}

		$this->logger->flush_queue();

		return $results;
	}
}
