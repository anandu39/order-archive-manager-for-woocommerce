<?php

/**
 *
 * Delete Handler
 *
 * permanently deletes orders from the archive tables.
 * No data is copied back to to the original WooCommerce tables. This is a one way process.
 * Each operation run inside a database transaction, to ensure data integrity.
 * If any step of the process fails, the transaction is rolled back, and no data is deleted.
 *
 * @package HW\WOAM\Archive
 */

namespace HW\WOAM\Archive;

use HW\WOAM\Database\Tables;
use HW\WOAM\Logger\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Class DeleteHandler
 */

class DeleteHandler {
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
	 * Number of orders to delete in each batch.
	 * Filterable via 'hw_woam_batch_size' filter.
	 *
	 * @var int
	 */

	private int $batch_size;

	/**
	 *
	 * constructor.
	 *
	 * @param \wpdb  $wpdb WordPress database object, injected for testability.
	 * @param Tables $tables Table name definitions, injected for testability.
	 * @param Logger $logger Activity logger, injected for testability.
	 */
	public function __construct( \wpdb $wpdb, Tables $tables, Logger $logger ) {

		$this->wpdb       = $wpdb;
		$this->tables     = $tables;
		$this->logger     = $logger;
		$this->batch_size = (int) apply_filters( 'hw_woam_batch_size', 50 );
	}

	/**
	 * Returns the total number of archived orders eligible for deletion.
	 * Used by the admin UI to show the order count before starting a permanent delete.
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
	 * Returns a batch of archived order IDs eligible for permanent deletion.
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
	 * Permanently deletes a single order from the archive tables.
	 * Runs inside a database transaction so either all steps succeed
	 * or nothing changes, ensuring no partial deletes.
	 *
	 * When $dry_run is true the transaction is always rolled back — all SQL
	 * still executes (so real errors surface) but nothing is permanently deleted.
	 * Logged with action 'dry_run' instead of 'delete'.
	 *
	 * @param int  $order_id Order ID to permanently delete.
	 * @param bool $dry_run  If true, roll back instead of committing.
	 * @return bool True on success, false on failure.
	 */
	private function delete_order( int $order_id, bool $dry_run = false ): bool {

		$this->wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		try {
			// Delete — children first, parent last.
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
				$this->logger->queue( $order_id, 'delete', 'success' );
			}

			return true;

		} catch ( \Exception $e ) {
			$this->wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$action = $dry_run ? 'dry_run' : 'delete';
			$this->logger->queue( $order_id, $action, 'error', $e->getMessage() );

			return false;
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
	 * Processes one batch of archived orders for permanent deletion.
	 *
	 * Fetches up to batch_size archived order IDs, permanently deletes each one,
	 * then flushes all queued log entries in a single write.
	 *
	 * Returns a summary array so the Ajax handler can report progress
	 * to the admin UI without needing to track state on the JS side.
	 *
	 * @param array<int, string> $statuses Optional. Filter by order status.
	 *                                     Pass an empty array to delete all archived orders.
	 * @param bool               $dry_run  If true, all DB changes are rolled back — nothing is deleted.
	 * @return array{processed: int, succeeded: int, failed: int, dry_run: bool}
	 */
	public function process_delete_batch( array $statuses = array(), bool $dry_run = false ): array {

		$order_ids = $this->get_batch_archived_order_ids( $statuses );

		$results = array(
			'processed' => 0,
			'succeeded' => 0,
			'failed'    => 0,
			'dry_run'   => $dry_run,
		);

		foreach ( $order_ids as $order_id ) {
			++$results['processed'];

			if ( $this->delete_order( $order_id, $dry_run ) ) {
				++$results['succeeded'];
			} else {
				++$results['failed'];
			}
		}

		$this->logger->flush_queue();

		return $results;
	}
}
