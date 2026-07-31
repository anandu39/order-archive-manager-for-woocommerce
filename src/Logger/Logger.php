<?php
/**
 *
 * Activity Logger
 *
 * Record all the archive, restore and delete action to the log tables.
 * Provide read methods to retrieve the log data for display in the admin page.
 *
 * @package HW\WOAM\Logger
 */

namespace HW\WOAM\Logger;

use HW\WOAM\Database\Tables;

defined( 'ABSPATH' ) || exit;

/**
 *
 * Class Logger
 */
class Logger {
	/**
	 *
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
	 * In-memory queue for batch log writes.
	 * Logs are queued during a batch and flushed to the database at the end of the batch.
	 * instead of writing one DB row per order, we can write all logs in one query at the end of the
	 * batch, improving performance.
	 *
	 * @var array<int, array<string, mixed>>
	 */

	private array $log_queue = array();

	/**
	 *
	 * Constructor.
	 *
	 * @param \wpdb  $wpdb WordPress database object, injected for testability.
	 * @param Tables $tables Table name definitions, injected for testability.
	 */
	public function __construct( \wpdb $wpdb, Tables $tables ) {
		$this->wpdb   = $wpdb;
		$this->tables = $tables;
	}

	/**
	 *
	 * Add a log entry to the in-memory queue.
	 * call this once per order during a batch operation.
	 * this log is written to the database when flush() is called at the end of the batch.
	 *
	 * @param int    $order_id The ID of the order being logged.
	 * @param string $action The action being logged (archive, restore, delete).
	 * @param string $status Result status of the action (success, failure).
	 * @param string $message Optional message providing additional details about the action.
	 * @return void
	 */
	public function queue( int $order_id, string $action, string $status, string $message = '' ): void {

		$this->log_queue[] = array(
			'order_id'   => $order_id,
			'action'     => $action,
			'status'     => $status,
			'message'    => $message,
			'created_at' => current_time( 'mysql' ),
		);
	}

	/**
	 *
	 * Write all queued log entries to the database in a single query.
	 * Clear all the queries after writing.
	 * Call this at the end of a batch operation to persist all logs.
	 *
	 * @return int Number of log entries written to the database.
	 */
	public function flush_queue(): int {

		if ( empty( $this->log_queue ) ) {
			return 0;
		}

		$db           = $this->wpdb;
		$target_table = $this->tables->logs;

		$values       = array();
		$placeholders = array();

		foreach ( $this->log_queue as $log ) {
			$placeholders[] = '(%d, %s, %s, %s, %s)';
			$values[]       = $log['order_id'];
			$values[]       = $log['action'];
			$values[]       = $log['status'];
			$values[]       = $log['message'];
			$values[]       = $log['created_at'];
		}

		$query = 'INSERT INTO %i (order_id, action, status, message, created_at) VALUES ' . implode( ', ', $placeholders );
		$args  = array_merge( array( $target_table ), $values );

		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholder count is dynamic (batch size); every interpolated token is a literal %d/%s from array construction above, never raw input.
		$prepared_sql = $db->prepare( $query, $args );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk insert as part of a batch write operation, not cacheable.
		$result = $db->query( $prepared_sql );

		$this->log_queue = array();

		return is_int( $result ) ? $result : 0;
	}

	/**
	 * Retrieves log entries from the database.
	 * Used by the admin log viewer page.
	 *
	 * @param array<string, mixed> $args {
	 * Optional query arguments.
	 * @type int    $per_page  Rows per page. Default 20.
	 * @type int    $page      Page number. Default 1.
	 * @type string $action    Filter by action: 'archive', 'restore', 'delete'.
	 * @type string $status    Filter by status: 'success', 'error'.
	 * @type int    $order_id  Filter by specific order ID.
	 * }
	 * @return array<int, object>
	 */
	public function get_logs( array $args = array() ): array {

		$defaults = array(
			'per_page' => 20,
			'page'     => 1,
			'action'   => '',
			'status'   => '',
			'order_id' => 0,
		);

		$args   = wp_parse_args( $args, $defaults );
		$offset = ( $args['page'] - 1 ) * $args['per_page'];
		$where  = $this->build_where_clause( $args );

		$db           = $this->wpdb;
		$target_table = $this->tables->logs;

		$query  = 'SELECT * FROM %i ' . $where['clause'] . ' ORDER BY created_at DESC LIMIT %d OFFSET %d';
		$params = array_merge( array( $target_table ), $where['values'], array( $args['per_page'], $offset ) );

		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholder count varies with active filters; all tokens are %i/%s/%d from controlled construction above, never raw input.
		$prepared_sql = $db->prepare( $query, $params );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin log viewer needs live data, not a candidate for object caching.
		$results = $db->get_results( $prepared_sql );

		return ! empty( $results ) ? $results : array();
	}

	/**
	 * Returns the total count of log entries matching the given filters.
	 * Used for pagination in the admin log viewer.
	 *
	 * @param array<string, mixed> $args Same filter arguments as get_logs().
	 * @return int
	 */
	public function get_count( array $args = array() ): int {

		$db           = $this->wpdb;
		$target_table = $this->tables->logs;
		$where        = $this->build_where_clause( $args );

		$query  = 'SELECT COUNT(*) FROM %i ' . $where['clause'];
		$params = array_merge( array( $target_table ), $where['values'] );

		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholder count varies with active filters; all tokens are %i/%s from controlled construction, never raw input.
		$prepared_sql = $db->prepare( $query, $params );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin log viewer pagination count needs live data, not a candidate for object caching.
		return (int) $db->get_var( $prepared_sql );
	}

	/**
	 * Deletes log entries older than the given number of days.
	 * Used for log retention management.
	 *
	 * @param int $days Delete logs older than this many days.
	 * @return int Number of rows deleted.
	 */
	public function prune( int $days = 90 ): int {

		$db           = $this->wpdb;
		$target_table = $this->tables->logs;

		$query = 'DELETE FROM %i WHERE created_at < DATE_SUB( NOW(), INTERVAL %d DAY )';
		$args  = array( $target_table, $days );

		$prepared_sql = $db->prepare( $query, $args );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- retention cleanup; direct delete on a fixed argument count, not a candidate for caching.
		$result = $db->query( $prepared_sql );

		return is_int( $result ) ? $result : 0;
	}

	/**
	 * Builds a SQL WHERE clause template and its bound values from filter arguments.
	 * Used internally by get_logs() and get_count().
	 *
	 * @param array<string, mixed> $args Filter arguments.
	 * @return array{clause: string, values: array<int, mixed>} WHERE clause template (with placeholders, or empty string if no filters) and its values.
	 */
	private function build_where_clause( array $args ): array {

		$conditions = array();
		$values     = array();

		if ( ! empty( $args['action'] ) ) {
			$conditions[] = 'action = %s';
			$values[]     = $args['action'];
		}

		if ( ! empty( $args['status'] ) ) {
			$conditions[] = 'status = %s';
			$values[]     = $args['status'];
		}

		if ( ! empty( $args['order_id'] ) ) {
			$conditions[] = 'order_id = %d';
			$values[]     = $args['order_id'];
		}

		return array(
			'clause' => empty( $conditions ) ? '' : 'WHERE ' . implode( ' AND ', $conditions ),
			'values' => $values,
		);
	}
}
