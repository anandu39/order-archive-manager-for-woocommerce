<?php

/**
 *
 * Restore Handler
 *
 * Move archived orders from archive tables back to main WooCommerce tables.
 * Handles operations like copying order data, order items,
 * and order notes from archive tables to main tables in batches.
 * Each operation is performed within a database transaction to ensure data integrity.
 *
 * @package HW\WOAM\Archive
 */

namespace HW\WOAM\Archive;

use HW\WOAM\Database\Tables;
use HW\WOAM\Logger\Logger;

defined( 'ABSPATH' ) || exit;

/**
 *
 * Class RestoreHandler
 */

class RestoreHandler {

	/**
	 * WordPress database object.
	 *
	 * @var \wpdb
	 */

	private \wpdb $wpdb;

	/**
	 *
	 * Table name definitions.
	 *
	 * @var Tables
	 */
	private Tables $tables;

	/**
	 *
	 * Activity Logger instance.
	 *
	 * @var Logger
	 */

	private Logger $logger;

	/**
	 *
	 * Number of orders to process in each batch.
	 * Filterable via 'woam_restore_batch_size'.
	 *
	 * @var int
	 */

	private int $batch_size;

	/**
	 *
	 * Constructor.
	 *
	 * @param \wpdb  $wpdb WordPress database object.
	 * @param Tables $tables Table name definitions.
	 * @param Logger $logger Activity Logger instance.
	 */
	public function __construct( \wpdb $wpdb, Tables $tables, Logger $logger ) {

		$this->wpdb       = $wpdb;
		$this->tables     = $tables;
		$this->logger     = $logger;
		$this->batch_size = (int) apply_filters( 'hw_woam_batch_size', 50 );
	}

	/**
	 * Returns the total number of archived orders.
	 * Used by the admin UI to show the order count before starting a restore.
	 *
	 * @param array<int, string> $statuses Optional. Filter by order status (e.g. ['wc-completed']).
	 *                                     Pass an empty array to count all archived orders.
	 * @return int
	 */
	public function get_total_archived_orders( array $statuses = array() ): int {

		$table = $this->tables->orders;

		if ( empty( $statuses ) ) {
			return (int) $this->wpdb->get_var(
				"SELECT COUNT(*) FROM `{$table}`" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
		}

		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		$sql = $this->wpdb->prepare(
			"SELECT COUNT(*) FROM `{$table}` WHERE post_status IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$statuses
		);

		return (int) $this->wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Returns a batch of archived order IDs eligible for restoring.
	 * Limited to batch_size. Call repeatedly until it returns an empty array.
	 *
	 * @param array<int, string> $statuses Optional. Filter by order status.
	 *                                     Pass an empty array to include all archived orders.
	 * @return array<int, int> Order IDs.
	 */
	public function get_batch_archived_order_ids( array $statuses = array() ): array {

		$table = $this->tables->orders;

		if ( empty( $statuses ) ) {
			$sql = $this->wpdb->prepare(
				"SELECT ID FROM `{$table}` ORDER BY ID ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$this->batch_size
			);
		} else {
			$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

			$sql = $this->wpdb->prepare(
				"SELECT ID FROM `{$table}` WHERE post_status IN ({$placeholders}) ORDER BY ID ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( $statuses, array( $this->batch_size ) )
			);
		}

		return array_map( 'intval', $this->wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Restores a single archived order by copying its data from archive tables
	 * back to live WooCommerce tables, then deleting it from the archive.
	 * Runs inside a database transaction so either all steps succeed or
	 * nothing changes, ensuring data integrity.
	 *
	 * When $dry_run is true the transaction is always rolled back — all SQL
	 * still executes (so real errors surface) but nothing is permanently written.
	 * Logged with action 'dry_run' instead of 'restore'.
	 *
	 * @param int  $order_id Order ID to restore.
	 * @param bool $dry_run  If true, roll back instead of committing.
	 * @return bool True on success, false on failure.
	 */
	private function restore_order( int $order_id, bool $dry_run = false ): bool {

		$this->wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		try {
			// Copy — parent first, children after.
			$this->copy_order_post( $order_id );
			$this->copy_order_meta( $order_id );
			$this->copy_order_items( $order_id );
			$this->copy_order_items_meta( $order_id );
			$this->copy_order_notes( $order_id );
			$this->copy_order_notes_meta( $order_id );

			// Delete from archive — children first, parent last.
			$this->delete_order_notes_meta( $order_id );
			$this->delete_order_notes( $order_id );
			$this->delete_order_items_meta( $order_id );
			$this->delete_order_items( $order_id );
			$this->delete_order_meta( $order_id );
			$this->delete_order_post( $order_id );

			if ( $dry_run ) {
				$this->wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$this->logger->queue( $order_id, 'dry_run', 'success' );
			} else {
				$this->wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$this->logger->queue( $order_id, 'restore', 'success' );
			}

			return true;

		} catch ( \Exception $e ) {
			$this->wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$action = $dry_run ? 'dry_run' : 'restore';
			$this->logger->queue( $order_id, $action, 'error', $e->getMessage() );

			return false;
		}
	}

	/**
	 * Copies a single order row from the orders archive table back into wp_posts.
	 * Excludes the archived_at column — that exists only in the archive table.
	 *
	 * @param int $order_id Order ID to copy.
	 * @throws \Exception If the insert fails or the order is not found in the archive.
	 * @return void
	 */
	private function copy_order_post( int $order_id ): void {

		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				"INSERT INTO {$this->wpdb->posts}
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
                FROM {$this->tables->orders}
                WHERE ID = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$order_id
			)
		);

		if ( false === $result ) {
			throw new \Exception( "Failed to restore order #{$order_id} to wp_posts." );
		}

		if ( 0 === $result ) {
			throw new \Exception( "Order #{$order_id} not found in archive." );
		}
	}

	/**
	 * Copies all order meta rows from the archive back into wp_postmeta.
	 *
	 * @param int $order_id Order ID whose meta should be copied.
	 * @throws \Exception If the insert fails.
	 * @return void
	 */
	private function copy_order_meta( int $order_id ): void {

		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				"INSERT INTO {$this->wpdb->postmeta}
                (meta_id, post_id, meta_key, meta_value)
                SELECT meta_id, post_id, meta_key, meta_value
                FROM {$this->tables->orders_meta}
                WHERE post_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$order_id
			)
		);

		if ( false === $result ) {
			throw new \Exception( "Failed to restore meta for order #{$order_id}." );
		}
	}

	/**
	 * Copies all order item rows from the archive back into woocommerce_order_items.
	 *
	 * @param int $order_id Order ID whose items should be copied.
	 * @throws \Exception If the insert fails.
	 * @return void
	 */
	private function copy_order_items( int $order_id ): void {

		$order_items_table = $this->wpdb->prefix . 'woocommerce_order_items';

		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				"INSERT INTO {$order_items_table}
                (order_item_id, order_item_name, order_item_type, order_id)
                SELECT order_item_id, order_item_name, order_item_type, order_id
                FROM {$this->tables->order_items}
                WHERE order_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$order_id
			)
		);

		if ( false === $result ) {
			throw new \Exception( "Failed to restore order items for order #{$order_id}." );
		}
	}

	/**
	 * Copies all order item meta rows from the archive back into woocommerce_order_itemmeta.
	 * Joins against the archive order items table to resolve which item IDs belong
	 * to this order — item meta does not store order_id directly.
	 *
	 * @param int $order_id Order ID whose item meta should be copied.
	 * @throws \Exception If the insert fails.
	 * @return void
	 */
	private function copy_order_items_meta( int $order_id ): void {

		$order_items_meta_table = $this->wpdb->prefix . 'woocommerce_order_itemmeta';

		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				"INSERT INTO {$order_items_meta_table}
                (meta_id, order_item_id, meta_key, meta_value)
                SELECT oim.meta_id, oim.order_item_id, oim.meta_key, oim.meta_value
                FROM {$this->tables->order_items_meta} oim
                INNER JOIN {$this->tables->order_items} oi
                    ON oim.order_item_id = oi.order_item_id
                WHERE oi.order_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$order_id
			)
		);

		if ( false === $result ) {
			throw new \Exception( "Failed to restore order item meta for order #{$order_id}." );
		}
	}

	/**
	 * Copies order note rows from the archive back into wp_comments.
	 *
	 * @param int $order_id Order ID whose notes should be copied.
	 * @throws \Exception If the insert fails.
	 * @return void
	 */
	private function copy_order_notes( int $order_id ): void {

		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				"INSERT INTO {$this->wpdb->comments}
                (comment_ID, comment_post_ID, comment_author, comment_author_email,
                comment_author_url, comment_author_IP, comment_date, comment_date_gmt,
                comment_content, comment_karma, comment_approved, comment_agent,
                comment_type, comment_parent, user_id)
                SELECT comment_ID, comment_post_ID, comment_author, comment_author_email,
                comment_author_url, comment_author_IP, comment_date, comment_date_gmt,
                comment_content, comment_karma, comment_approved, comment_agent,
                comment_type, comment_parent, user_id
                FROM {$this->tables->order_notes}
                WHERE comment_post_ID = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$order_id
			)
		);

		if ( false === $result ) {
			throw new \Exception( "Failed to restore order notes for order #{$order_id}." );
		}
	}

	/**
	 * Copies order note meta rows from the archive back into wp_commentmeta.
	 * Joins against the archive order notes table to resolve which comment IDs
	 * belong to this order.
	 *
	 * @param int $order_id Order ID whose note meta should be copied.
	 * @throws \Exception If the insert fails.
	 * @return void
	 */
	private function copy_order_notes_meta( int $order_id ): void {

		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				"INSERT INTO {$this->wpdb->commentmeta}
                (meta_id, comment_id, meta_key, meta_value)
                SELECT onm.meta_id, onm.comment_id, onm.meta_key, onm.meta_value
                FROM {$this->tables->order_notes_meta} onm
                INNER JOIN {$this->tables->order_notes} on_
                    ON onm.comment_id = on_.comment_ID
                WHERE on_.comment_post_ID = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$order_id
			)
		);

		if ( false === $result ) {
			throw new \Exception( "Failed to restore order note meta for order #{$order_id}." );
		}
	}

	/**
	 * Deletes order note meta from the archive notes meta table.
	 * Must run before delete_order_notes() — depends on woam_order_notes
	 * still containing the comment_post_ID link.
	 *
	 * @param int $order_id Order ID whose note meta should be deleted.
	 * @return void
	 */
	private function delete_order_notes_meta( int $order_id ): void {

		$this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE onm FROM {$this->tables->order_notes_meta} onm
                INNER JOIN {$this->tables->order_notes} on_
                    ON onm.comment_id = on_.comment_ID
                WHERE on_.comment_post_ID = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$order_id
			)
		);
	}

	/**
	 * Deletes order notes from the archive notes table.
	 *
	 * @param int $order_id Order ID whose notes should be deleted.
	 * @return void
	 */
	private function delete_order_notes( int $order_id ): void {

		$this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM {$this->tables->order_notes}
                WHERE comment_post_ID = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$order_id
			)
		);
	}

	/**
	 * Deletes order item meta from the archive item meta table.
	 * Must run before delete_order_items() — depends on woam_order_items
	 * still containing the order_id link.
	 *
	 * @param int $order_id Order ID whose item meta should be deleted.
	 * @return void
	 */
	private function delete_order_items_meta( int $order_id ): void {

		$this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE oim FROM {$this->tables->order_items_meta} oim
                INNER JOIN {$this->tables->order_items} oi
                    ON oim.order_item_id = oi.order_item_id
                WHERE oi.order_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$order_id
			)
		);
	}

	/**
	 * Deletes order items from the archive items table.
	 *
	 * @param int $order_id Order ID whose items should be deleted.
	 * @return void
	 */
	private function delete_order_items( int $order_id ): void {

		$this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM {$this->tables->order_items}
                WHERE order_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$order_id
			)
		);
	}

	/**
	 * Deletes order meta from the archive meta table.
	 *
	 * @param int $order_id Order ID whose meta should be deleted.
	 * @return void
	 */
	private function delete_order_meta( int $order_id ): void {

		$this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM {$this->tables->orders_meta}
                WHERE post_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$order_id
			)
		);
	}

	/**
	 * Deletes the order row from the archive orders table.
	 * This is the final delete step — runs last because every other
	 * archive table cleanup depends on this row still being present.
	 *
	 * @param int $order_id Order ID to delete from the archive.
	 * @return void
	 */
	private function delete_order_post( int $order_id ): void {

		$this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM {$this->tables->orders}
                WHERE ID = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$order_id
			)
		);
	}

	/**
	 * Processes one batch of archived orders for restoration.
	 *
	 * Fetches up to batch_size archived order IDs, restores each one,
	 * then flushes all queued log entries in a single write.
	 *
	 * Returns a summary array so the Ajax handler can report progress
	 * to the admin UI without needing to track state on the JS side.
	 *
	 * @param array<int, string> $statuses Optional. Filter by order status.
	 *                                     Pass an empty array to restore all archived orders.
	 * @param bool               $dry_run  If true, all DB changes are rolled back — nothing is restored.
	 * @return array{processed: int, succeeded: int, failed: int, dry_run: bool}
	 */
	public function process_restore_batch( array $statuses = array(), bool $dry_run = false ): array {

		$order_ids = $this->get_batch_archived_order_ids( $statuses );

		$results = array(
			'processed' => 0,
			'succeeded' => 0,
			'failed'    => 0,
			'dry_run'   => $dry_run,
		);

		foreach ( $order_ids as $order_id ) {
			++$results['processed'];

			if ( $this->restore_order( $order_id, $dry_run ) ) {
				++$results['succeeded'];
			} else {
				++$results['failed'];
			}
		}

		$this->logger->flush_queue();

		return $results;
	}
}
