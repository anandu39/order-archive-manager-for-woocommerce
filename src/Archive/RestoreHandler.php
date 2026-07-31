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
	 * Filterable via 'hw_woam_batch_size'.
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
		$this->batch_size = (int) apply_filters( 'hw_woam_batch_size', 500 );
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

		if ( empty( $statuses ) ) {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table identifier bound via %i, not interpolated; $table is a trusted plugin property, not user input; count must reflect current data, not a cached/stale value.
			return (int) $db->get_var(
				$db->prepare( 'SELECT COUNT(*) FROM %i', $table )
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$params       = array_merge( array( $table ), $statuses );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table identifier bound via %i, not interpolated; $placeholders contains only literal %s tokens from array_fill(), never raw input; param count depends on count( $statuses ), which PHPCS cannot verify statically; count must reflect current data, not a cached/stale value.
		return (int) $db->get_var(
			$db->prepare(
				'SELECT COUNT(*) FROM %i WHERE post_status IN (' . $placeholders . ')',
				$params
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table identifier bound via %i, not interpolated; $table is a trusted plugin property, not user input; batch fetch must reflect current data.
			return array_map(
				'intval',
				$this->wpdb->get_col(
					$this->wpdb->prepare(
						'SELECT ID FROM %i ORDER BY ID ASC LIMIT %d',
						$table,
						$this->batch_size
					)
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$params       = array_merge( array( $table ), $statuses, array( $this->batch_size ) );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table identifier bound via %i, not interpolated; $placeholders contains only literal %s tokens from array_fill(), never raw input; param count depends on count( $statuses ), which PHPCS cannot verify statically; batch fetch must reflect current data.
		return array_map(
			'intval',
			$this->wpdb->get_col(
				$this->wpdb->prepare(
					'SELECT ID FROM %i WHERE post_status IN (' . $placeholders . ') ORDER BY ID ASC LIMIT %d',
					$params
				)
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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

		$this->wpdb->query( 'START TRANSACTION' );

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
				$this->wpdb->query( 'ROLLBACK' );
				$this->logger->queue( $order_id, 'dry_run', 'success' );
			} else {
				$this->wpdb->query( 'COMMIT' );
				$this->logger->queue( $order_id, 'restore', 'success' );
			}

			return true;

		} catch ( \Throwable $e ) {
			$this->wpdb->query( 'ROLLBACK' );
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off existence check during restore; must reflect current live state, not cached.
		$exists = (int) $db->get_var(
			$db->prepare(
				'SELECT COUNT(*) FROM %i WHERE ID = %d',
				$target_tbl,
				$order_id
			)
		);

		if ( $exists > 0 ) {
			throw new \Exception(
				esc_html(
					"Order #{$order_id} already exists in wp_posts. It may not have been fully removed before archiving, or a previous restore partially completed."
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off restore operation copying a single archived order row back into wp_posts; not cacheable.
		$result = $db->query(
			$db->prepare(
				'INSERT INTO %i (
					ID, post_author, post_date, post_date_gmt, post_content, post_title,
					post_excerpt, post_status, post_password, post_name, post_modified,
					post_modified_gmt, post_content_filtered, post_parent, guid, menu_order,
					post_type, post_mime_type, comment_count
				)
				SELECT
					ID, post_author, post_date, post_date_gmt, post_content, post_title,
					post_excerpt, post_status, post_password, post_name, post_modified,
					post_modified_gmt, post_content_filtered, post_parent, guid, menu_order,
					post_type, post_mime_type, comment_count
				FROM %i
				WHERE ID = %d',
				$target_tbl,
				$source_tbl,
				$order_id
			)
		);

		if ( false === $result ) {
			throw new \Exception( esc_html( "Failed to restore order #{$order_id} to wp_posts. DB error: " . $db->last_error ) );
		}

		if ( 0 === $result ) {
			throw new \Exception( esc_html( "Order #{$order_id} not found in archive." ) );
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off restore operation copying archived order meta back into wp_postmeta; not cacheable.
		$result = $db->query(
			$db->prepare(
				'INSERT IGNORE INTO %i (meta_id, post_id, meta_key, meta_value) SELECT meta_id, post_id, meta_key, meta_value FROM %i WHERE post_id = %d',
				$target_tbl,
				$source_tbl,
				$order_id
			)
		);

		if ( false === $result ) {
			throw new \Exception( esc_html( "Failed to restore meta for order #{$order_id}." ) );
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off restore operation copying archived order items back into woocommerce_order_items; not cacheable.
		$result = $db->query(
			$db->prepare(
				'INSERT IGNORE INTO %i (order_item_id, order_item_name, order_item_type, order_id) SELECT order_item_id, order_item_name, order_item_type, order_id FROM %i WHERE order_id = %d',
				$target_tbl,
				$source_tbl,
				$order_id
			)
		);

		if ( false === $result ) {
			throw new \Exception( esc_html( "Failed to restore order items for order #{$order_id}." ) );
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off restore operation copying archived order item meta back into woocommerce_order_itemmeta; not cacheable.
		$result = $db->query(
			$db->prepare(
				'INSERT IGNORE INTO %i (meta_id, order_item_id, meta_key, meta_value)
				SELECT oim.meta_id, oim.order_item_id, oim.meta_key, oim.meta_value
				FROM %i oim
				INNER JOIN %i oi ON oim.order_item_id = oi.order_item_id
				WHERE oi.order_id = %d',
				$target_tbl,
				$src_meta_tbl,
				$src_item_tbl,
				$order_id
			)
		);

		if ( false === $result ) {
			throw new \Exception( esc_html( "Failed to restore order item meta for order #{$order_id}." ) );
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off restore operation copying archived order notes back into wp_comments; not cacheable.
		$result = $db->query(
			$db->prepare(
				'INSERT INTO %i (
					comment_ID, comment_post_ID, comment_author, comment_author_email,
					comment_author_url, comment_author_IP, comment_date, comment_date_gmt,
					comment_content, comment_karma, comment_approved, comment_agent,
					comment_type, comment_parent, user_id
				)
				SELECT
					comment_ID, comment_post_ID, comment_author, comment_author_email,
					comment_author_url, comment_author_IP, comment_date, comment_date_gmt,
					comment_content, comment_karma, comment_approved, comment_agent,
					comment_type, comment_parent, user_id
				FROM %i
				WHERE comment_post_ID = %d',
				$target_tbl,
				$source_tbl,
				$order_id
			)
		);

		if ( false === $result ) {
			throw new \Exception( esc_html( "Failed to restore order notes for order #{$order_id}." ) );
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off restore operation copying archived order note meta back into wp_commentmeta; not cacheable.
		$result = $db->query(
			$db->prepare(
				'INSERT INTO %i (meta_id, comment_id, meta_key, meta_value)
				SELECT onm.meta_id, onm.comment_id, onm.meta_key, onm.meta_value
				FROM %i onm
				INNER JOIN %i on_ ON onm.comment_id = on_.comment_ID
				WHERE on_.comment_post_ID = %d',
				$target_tbl,
				$src_meta_tbl,
				$src_note_tbl,
				$order_id
			)
		);

		if ( false === $result ) {
			throw new \Exception( esc_html( "Failed to restore order note meta for order #{$order_id}." ) );
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off restore operation copying archived refund posts back into wp_posts; not cacheable.
		$result = $db->query(
			$db->prepare(
				'INSERT IGNORE INTO %i (
					ID, post_author, post_date, post_date_gmt, post_content, post_title,
					post_excerpt, post_status, post_password, post_name, post_modified,
					post_modified_gmt, post_content_filtered, post_parent, guid, menu_order,
					post_type, post_mime_type, comment_count
				)
				SELECT
					ID, post_author, post_date, post_date_gmt, post_content, post_title,
					post_excerpt, post_status, post_password, post_name, post_modified,
					post_modified_gmt, post_content_filtered, post_parent, guid, menu_order,
					post_type, post_mime_type, comment_count
				FROM %i
				WHERE post_parent = %d',
				$target_tbl,
				$source_tbl,
				$order_id
			)
		);

		if ( false === $result ) {
			throw new \Exception( esc_html( "Failed to restore refunds for order #{$order_id}." ) );
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off restore operation copying archived refund meta back into wp_postmeta; not cacheable.
		$result = $db->query(
			$db->prepare(
				'INSERT IGNORE INTO %i (meta_id, post_id, meta_key, meta_value)
				SELECT rm.meta_id, rm.post_id, rm.meta_key, rm.meta_value
				FROM %i rm
				INNER JOIN %i r ON rm.post_id = r.ID
				WHERE r.post_parent = %d',
				$target_tbl,
				$src_meta_tbl,
				$src_rfnd_tbl,
				$order_id
			)
		);

		if ( false === $result ) {
			throw new \Exception( esc_html( "Failed to restore refund meta for order #{$order_id}." ) );
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

		$db          = $this->wpdb;
		$posts_table = $db->posts;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off verification read immediately after restore; must reflect current state, not cached.
		$exists = (int) $db->get_var(
			$db->prepare( 'SELECT COUNT(*) FROM %i WHERE ID = %d', $posts_table, $order_id )
		);

		if ( 0 === $exists ) {
			throw new \Exception(
				esc_html(
					"Restore verification failed for order #{$order_id}: post row not found in wp_posts after INSERT."
				)
			);
		}
	}

	/**
	 * Deletes order note meta from the archive notes meta table.
	 * Must run before delete_order_notes(). It depends on hw_woam_order_notes
	 * still containing the comment_post_ID link.
	 *
	 * @param int $order_id Order ID whose note meta should be deleted.
	 * @return void
	 */
	private function delete_order_notes_meta( int $order_id ): void {

		$db            = $this->wpdb;
		$target_tbl    = $this->tables->order_notes_meta;
		$src_notes_tbl = $this->tables->order_notes;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup deleting archived order note meta as part of the restore transaction; not cacheable.
		$db->query(
			$db->prepare(
				'DELETE onm FROM %i onm
				INNER JOIN %i on_ ON onm.comment_id = on_.comment_ID
				WHERE on_.comment_post_ID = %d',
				$target_tbl,
				$src_notes_tbl,
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

		$db         = $this->wpdb;
		$source_tbl = $this->tables->order_notes;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup deleting archived order notes as part of the restore transaction; not cacheable.
		$db->query(
			$db->prepare(
				'DELETE FROM %i WHERE comment_post_ID = %d',
				$source_tbl,
				$order_id
			)
		);
	}

	/**
	 * Deletes order item meta from the archive item meta table.
	 * Must run before delete_order_items(). It depends on hw_woam_order_items
	 * still containing the order_id link.
	 *
	 * @param int $order_id Order ID whose item meta should be deleted.
	 * @return void
	 */
	private function delete_order_items_meta( int $order_id ): void {

		$db           = $this->wpdb;
		$target_tbl   = $this->tables->order_items_meta;
		$src_item_tbl = $this->tables->order_items;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup deleting archived order item meta as part of the restore transaction; not cacheable.
		$db->query(
			$db->prepare(
				'DELETE oim FROM %i oim
				INNER JOIN %i oi ON oim.order_item_id = oi.order_item_id
				WHERE oi.order_id = %d',
				$target_tbl,
				$src_item_tbl,
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

		$db         = $this->wpdb;
		$source_tbl = $this->tables->order_items;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup deleting archived order items as part of the restore transaction; not cacheable.
		$db->query(
			$db->prepare(
				'DELETE FROM %i WHERE order_id = %d',
				$source_tbl,
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

		$db         = $this->wpdb;
		$source_tbl = $this->tables->orders_meta;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup deleting archived order meta as part of the restore transaction; not cacheable.
		$db->query(
			$db->prepare(
				'DELETE FROM %i WHERE post_id = %d',
				$source_tbl,
				$order_id
			)
		);
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup deleting archived refund meta as part of the restore transaction; not cacheable.
		$db->query(
			$db->prepare(
				'DELETE rm FROM %i rm
				INNER JOIN %i r ON rm.post_id = r.ID
				WHERE r.post_parent = %d',
				$target_tbl,
				$src_rfnd_tbl,
				$order_id
			)
		);
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup deleting archived refund posts as part of the restore transaction; not cacheable.
		$db->query(
			$db->prepare(
				'DELETE FROM %i WHERE post_parent = %d',
				$source_tbl,
				$order_id
			)
		);
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup deleting the archived order row as the final step of the restore transaction; not cacheable.
		$db->query(
			$db->prepare(
				'DELETE FROM %i WHERE ID = %d',
				$source_tbl,
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
