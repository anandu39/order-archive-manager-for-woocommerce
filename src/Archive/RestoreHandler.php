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
	 * Pass an empty array to count all archived orders.
	 * @return int
	 */
	public function get_total_archived_orders( array $statuses = array() ): int {

		$db    = $this->wpdb;
		$table = $this->tables->orders;

		// Branch 1: Empty statuses - count all archived records cleanly.
		if ( empty( $statuses ) ) {
			$query        = 'SELECT COUNT(*) FROM %i';
			$args         = array( $table );
			$prepared_sql = $db->prepare( $query, $args );

			return (int) $db->get_var( $prepared_sql );
		}

		// Branch 2: Handle status list logic safely.
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		// Assemble a flat query text layout before passing it into the engine.
		$query = "SELECT COUNT(*) FROM %i WHERE post_status IN ({$placeholders})";

		// Combine table identifier and status strings sequentially into a single argument list.
		$args = array_merge( array( $table ), $statuses );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared_sql = $db->prepare( $query, $args );

		return (int) $db->get_var( $prepared_sql );
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
			$this->copy_order_refunds( $order_id );
			$this->copy_order_refunds_meta( $order_id );

			// Verify the order post itself was written to wp_posts before we delete from archive.
			$this->verify_order_post_restored( $order_id );

			// Delete from archive — children first, parent last.
			$this->delete_order_notes_meta( $order_id );
			$this->delete_order_notes( $order_id );
			$this->delete_order_items_meta( $order_id );
			$this->delete_order_items( $order_id );
			$this->delete_order_meta( $order_id );
			$this->delete_order_refunds_meta( $order_id );
			$this->delete_order_refunds( $order_id );
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

		$db         = $this->wpdb;
		$target_tbl = $db->posts;
		$source_tbl = $this->tables->orders;

		// Guard: check if this order ID already exists in wp_posts.
		// If it does, the INSERT will hit a duplicate primary key error.
		// This happens when an order was not fully removed from live tables
		// before archiving, or a previous restore partially completed.
		$exists = (int) $db->get_var(
			$db->prepare( 'SELECT COUNT(*) FROM %i WHERE ID = %d', array( $target_tbl, $order_id ) )
		);

		if ( $exists > 0 ) {
			throw new \Exception(
				"Order #{$order_id} already exists in wp_posts. It may not have been fully removed before archiving, or a previous restore partially completed."
			);
		}

		$query = 'INSERT INTO %i (ID, post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, post_password, post_name, post_modified, post_modified_gmt, post_content_filtered, post_parent, guid, menu_order, post_type, post_mime_type, comment_count) SELECT ID, post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, post_password, post_name, post_modified, post_modified_gmt, post_content_filtered, post_parent, guid, menu_order, post_type, post_mime_type, comment_count FROM %i WHERE ID = %d';
		$args  = array( $target_tbl, $source_tbl, $order_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared_sql = $db->prepare( $query, $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$result = $db->query( $prepared_sql );

		if ( false === $result ) {
			throw new \Exception( "Failed to restore order #{$order_id} to wp_posts. DB error: " . $db->last_error );
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

		$db         = $this->wpdb;
		$target_tbl = $db->postmeta;
		$source_tbl = $this->tables->orders_meta;

		// Clean template using single quotes and isolated double identifier placeholders.
		$query = 'INSERT IGNORE INTO %i (meta_id, post_id, meta_key, meta_value) SELECT meta_id, post_id, meta_key, meta_value FROM %i WHERE post_id = %d';
		$args  = array( $target_tbl, $source_tbl, $order_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared_sql = $db->prepare( $query, $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$result = $db->query( $prepared_sql );

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

		$db         = $this->wpdb;
		$target_tbl = $db->prefix . 'woocommerce_order_items';
		$source_tbl = $this->tables->order_items;

		// Clean template using single quotes and double %i table identifier placeholders.
		$query = 'INSERT IGNORE INTO %i (order_item_id, order_item_name, order_item_type, order_id) SELECT order_item_id, order_item_name, order_item_type, order_id FROM %i WHERE order_id = %d';
		$args  = array( $target_tbl, $source_tbl, $order_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared_sql = $db->prepare( $query, $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$result = $db->query( $prepared_sql );

		if ( false === $result ) {
			throw new \Exception( "Failed to restore order items for order #{$order_id}." );
		}
	}

	/**
	 * Copies all order item meta rows from the archive back into woocommerce_order_itemmeta.
	 * Joins against the archive order items table to resolve which item IDs belong
	 * to this order. Item meta does not store order_id directly.
	 *
	 * @param int $order_id Order ID whose item meta should be copied.
	 * @throws \Exception If the insert fails.
	 * @return void
	 */
	private function copy_order_items_meta( int $order_id ): void {

		$db           = $this->wpdb;
		$target_tbl   = $db->prefix . 'woocommerce_order_itemmeta';
		$src_meta_tbl = $this->tables->order_items_meta;
		$src_item_tbl = $this->tables->order_items;

		// Clean complex layout template passing explicit triple %i table mappings sequentially.
		$query = 'INSERT IGNORE INTO %i (meta_id, order_item_id, meta_key, meta_value) SELECT oim.meta_id, oim.order_item_id, oim.meta_key, oim.meta_value FROM %i oim INNER JOIN %i oi ON oim.order_item_id = oi.order_item_id WHERE oi.order_id = %d';
		$args  = array( $target_tbl, $src_meta_tbl, $src_item_tbl, $order_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared_sql = $db->prepare( $query, $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$result = $db->query( $prepared_sql );

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

		$db         = $this->wpdb;
		$target_tbl = $db->comments;
		$source_tbl = $this->tables->order_notes;

		// Clean template using single quotes and isolated double %i identifier placeholders.
		$query = 'INSERT INTO %i (comment_ID, comment_post_ID, comment_author, comment_author_email, comment_author_url, comment_author_IP, comment_date, comment_date_gmt, comment_content, comment_karma, comment_approved, comment_agent, comment_type, comment_parent, user_id) SELECT comment_ID, comment_post_ID, comment_author, comment_author_email, comment_author_url, comment_author_IP, comment_date, comment_date_gmt, comment_content, comment_karma, comment_approved, comment_agent, comment_type, comment_parent, user_id FROM %i WHERE comment_post_ID = %d';
		$args  = array( $target_tbl, $source_tbl, $order_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared_sql = $db->prepare( $query, $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$result = $db->query( $prepared_sql );

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

		$db           = $this->wpdb;
		$target_tbl   = $db->commentmeta;
		$src_meta_tbl = $this->tables->order_notes_meta;
		$src_note_tbl = $this->tables->order_notes;

		// Clean template using single quotes and explicit sequential %i identifier mappings.
		$query = 'INSERT INTO %i (meta_id, comment_id, meta_key, meta_value) SELECT onm.meta_id, onm.comment_id, onm.meta_key, onm.meta_value FROM %i onm INNER JOIN %i on_ ON onm.comment_id = on_.comment_ID WHERE on_.comment_post_ID = %d';
		$args  = array( $target_tbl, $src_meta_tbl, $src_note_tbl, $order_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared_sql = $db->prepare( $query, $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$result = $db->query( $prepared_sql );

		if ( false === $result ) {
			throw new \Exception( "Failed to restore order note meta for order #{$order_id}." );
		}
	}

	/**
	 * Copies refund posts from the archive back into wp_posts.
	 *
	 * @param int $order_id Parent order ID.
	 * @throws \Exception If the insert fails.
	 * @return void
	 */
	private function copy_order_refunds( int $order_id ): void {

		$db         = $this->wpdb;
		$target_tbl = $db->posts;
		$source_tbl = $this->tables->order_refunds;

		// Clean template layout handling table allocations via %i placeholders.
		$query = 'INSERT IGNORE INTO %i (ID, post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, post_password, post_name, post_modified, post_modified_gmt, post_content_filtered, post_parent, guid, menu_order, post_type, post_mime_type, comment_count) SELECT ID, post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, post_password, post_name, post_modified, post_modified_gmt, post_content_filtered, post_parent, guid, menu_order, post_type, post_mime_type, comment_count FROM %i WHERE post_parent = %d';
		$args  = array( $target_tbl, $source_tbl, $order_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared_sql = $db->prepare( $query, $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$result = $db->query( $prepared_sql );

		if ( false === $result ) {
			throw new \Exception( "Failed to restore refunds for order #{$order_id}." );
		}
	}

	/**
	 * Copies refund meta from the archive back into wp_postmeta.
	 *
	 * @param int $order_id Parent order ID.
	 * @throws \Exception If the insert fails.
	 * @return void
	 */
	private function copy_order_refunds_meta( int $order_id ): void {

		$db           = $this->wpdb;
		$target_tbl   = $db->postmeta;
		$src_meta_tbl = $this->tables->order_refunds_meta;
		$src_rfnd_tbl = $this->tables->order_refunds;

		// Clean template using single quotes and triple %i identifier tokens sequentially.
		$query = 'INSERT IGNORE INTO %i (meta_id, post_id, meta_key, meta_value) SELECT rm.meta_id, rm.post_id, rm.meta_key, rm.meta_value FROM %i rm INNER JOIN %i r ON rm.post_id = r.ID WHERE r.post_parent = %d';
		$args  = array( $target_tbl, $src_meta_tbl, $src_rfnd_tbl, $order_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared_sql = $db->prepare( $query, $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$result = $db->query( $prepared_sql );

		if ( false === $result ) {
			throw new \Exception( "Failed to restore refund meta for order #{$order_id}." );
		}
	}

	/**
	 * Verifies the order post row landed in wp_posts before we delete from archive.
	 * Lightweight check — just confirms the INSERT succeeded.
	 * We don't count meta/items because WooCommerce may have written additional
	 * rows since the order was archived, making count comparisons unreliable.
	 *
	 * @param int $order_id Order ID to verify.
	 * @throws \Exception If the order post is not found in wp_posts.
	 * @return void
	 */
	private function verify_order_post_restored( int $order_id ): void {

		$sql = "SELECT COUNT(*) FROM `{$this->wpdb->posts}` WHERE ID = %d";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- query is a static string with no user input.
		$exists = (int) $this->wpdb->get_var( $this->wpdb->prepare( $sql, $order_id ) );

		if ( 0 === $exists ) {
			throw new \Exception(
				"Restore verification failed for order #{$order_id}: post row not found in wp_posts after INSERT."
			);
		}
	}

	/**
	 * Deletes order note meta from the archive notes meta table.
	 * Must run before delete_order_notes(). It depends on woam_order_notes
	 * still containing the comment_post_ID link.
	 *
	 * @param int $order_id Order ID whose note meta should be deleted.
	 * @return void
	 */
	private function delete_order_notes_meta( int $order_id ): void {

		$db            = $this->wpdb;
		$target_tbl    = $this->tables->order_notes_meta;
		$src_notes_tbl = $this->tables->order_notes;

		// Clean template using single quotes and sequential %i table identifier placeholders.
		$query = 'DELETE onm FROM %i onm INNER JOIN %i on_ ON onm.comment_id = on_.comment_ID WHERE on_.comment_post_ID = %d';
		$args  = array( $target_tbl, $src_notes_tbl, $order_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared_sql = $db->prepare( $query, $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$db->query( $prepared_sql );
	}

	/**
	 * Deletes order notes from the archive notes table.
	 *
	 * @param int $order_id Order ID whose notes should be deleted.
	 * @return void
	 */
	private function delete_order_notes( int $order_id ): void {

		$db         = $this->wpdb;
		$source_tbl = $this->tables->order_notes;

		// Clean template layout with single quotes and isolated %i token mapping.
		$query = 'DELETE FROM %i WHERE comment_post_ID = %d';
		$args  = array( $source_tbl, $order_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared_sql = $db->prepare( $query, $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$db->query( $prepared_sql );
	}

	/**
	 * Deletes order item meta from the archive item meta table.
	 * Must run before delete_order_items(). It depends on woam_order_items
	 * still containing the order_id link.
	 *
	 * @param int $order_id Order ID whose item meta should be deleted.
	 * @return void
	 */
	private function delete_order_items_meta( int $order_id ): void {

		$db           = $this->wpdb;
		$target_tbl   = $this->tables->order_items_meta;
		$src_item_tbl = $this->tables->order_items;

		// Clean template using single quotes and explicit double %i mappings sequentially.
		$query = 'DELETE oim FROM %i oim INNER JOIN %i oi ON oim.order_item_id = oi.order_item_id WHERE oi.order_id = %d';
		$args  = array( $target_tbl, $src_item_tbl, $order_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared_sql = $db->prepare( $query, $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$db->query( $prepared_sql );
	}

	/**
	 * Deletes order items from the archive items table.
	 *
	 * @param int $order_id Order ID whose items should be deleted.
	 * @return void
	 */
	private function delete_order_items( int $order_id ): void {

		$db         = $this->wpdb;
		$source_tbl = $this->tables->order_items;

		// Clean template layout with single quotes and isolated %i token mapping.
		$query = 'DELETE FROM %i WHERE order_id = %d';
		$args  = array( $source_tbl, $order_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared_sql = $db->prepare( $query, $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$db->query( $prepared_sql );
	}

	/**
	 * Deletes order meta from the archive meta table.
	 *
	 * @param int $order_id Order ID whose meta should be deleted.
	 * @return void
	 */
	private function delete_order_meta( int $order_id ): void {

		$db         = $this->wpdb;
		$source_tbl = $this->tables->orders_meta;

		// Clean template using single quotes and isolated identifier placeholders.
		$query = 'DELETE FROM %i WHERE post_id = %d';
		$args  = array( $source_tbl, $order_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared_sql = $db->prepare( $query, $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$db->query( $prepared_sql );
	}

	/**
	 * Deletes refund meta from the archive refunds meta table.
	 * Must run before delete_order_refunds().
	 *
	 * @param int $order_id Parent order ID.
	 * @return void
	 */
	private function delete_order_refunds_meta( int $order_id ): void {

		$db           = $this->wpdb;
		$target_tbl   = $this->tables->order_refunds_meta;
		$src_rfnd_tbl = $this->tables->order_refunds;

		// Clean join template layout using double %i identifier maps.
		$query = 'DELETE rm FROM %i rm INNER JOIN %i r ON rm.post_id = r.ID WHERE r.post_parent = %d';
		$args  = array( $target_tbl, $src_rfnd_tbl, $order_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared_sql = $db->prepare( $query, $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$db->query( $prepared_sql );
	}

	/**
	 * Deletes refund posts from the archive refunds table.
	 *
	 * @param int $order_id Parent order ID.
	 * @return void
	 */
	private function delete_order_refunds( int $order_id ): void {

		$db         = $this->wpdb;
		$source_tbl = $this->tables->order_refunds;

		// Clean template using single quotes and isolated identifier placeholders.
		$query = 'DELETE FROM %i WHERE post_parent = %d';
		$args  = array( $source_tbl, $order_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared_sql = $db->prepare( $query, $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$db->query( $prepared_sql );
	}

	/**
	 * Deletes the order row from the archive orders table.
	 * This is the final delete step. It runs last because every other
	 * archive table cleanup depends on this row still being present.
	 *
	 * @param int $order_id Order ID to delete from the archive.
	 * @return void
	 */
	private function delete_order_post( int $order_id ): void {

		$db         = $this->wpdb;
		$source_tbl = $this->tables->orders;

		// Clean template using single quotes and isolated identifier placeholders.
		$query = 'DELETE FROM %i WHERE ID = %d';
		$args  = array( $source_tbl, $order_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared_sql = $db->prepare( $query, $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$db->query( $prepared_sql );
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
