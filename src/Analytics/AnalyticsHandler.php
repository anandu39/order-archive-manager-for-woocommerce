<?php
/**
 * Analytics Handler
 *
 * Provides database health metrics, statistics, recommendations, and readiness checks.
 * Used by the admin UI to display dashboard widgets and smart recommendations.
 *
 * @package HW\WOAM\Analytics
 */

namespace HW\WOAM\Analytics;

defined( 'ABSPATH' ) || exit;

/**
 * Class AnalyticsHandler
 */
class AnalyticsHandler {

	/**
	 * Lock key constant for job running check.
	 */
	private const LOCK_KEY = 'hw_woam_job_running';

	/**
	 * Returns a health score (0-100) for the database and archive system.
	 *
	 * Calculates score based on:
	 * - Database size (smaller = better, 30% weight).
	 * - Archive usage percentage (more archived = better, 25% weight).
	 * - Table integrity (no orphans = perfect, 25% weight).
	 * - Growth rate (slower growth = better, 20% weight).
	 *
	 * @return array<string, mixed>
	 */
	public function get_health_score(): array {
		// Factor 1: Database Size (30% of score).
		$db_stats    = $this->get_db_stats_array();
		$total_bytes = $db_stats['total_bytes'] ?? 0;
		$size_score  = max( 0, min( 100, 100 - (int) ( $total_bytes / ( 50 * 1024 * 1024 ) ) ) );

		// Factor 2: Archive Usage (25% of score).
		$archive_score = $this->calculate_archive_usage_score();

		// Factor 3: Table Integrity (25% of score).
		$integrity_data  = $this->run_quick_integrity_check();
		$total_orphans   = $integrity_data['total_orphans'];
		$integrity_score = max( 0, min( 100, 100 - (int) ( $total_orphans / 10 ) ) );

		// Factor 4: Growth Rate (20% of score).
		$growth_rate  = $this->calculate_monthly_growth_rate();
		$growth_score = max( 0, min( 100, 100 - (int) ( $growth_rate / 5 ) ) );

		// Calculate weighted final score.
		$final_score = (int) (
			( $size_score * 0.30 ) +
			( $archive_score * 0.25 ) +
			( $integrity_score * 0.25 ) +
			( $growth_score * 0.20 )
		);

		// Determine status label.
		$status = $this->get_score_status( $final_score );

		return array(
			'score'        => $final_score,
			'status'       => $status,
			'status_label' => $this->get_health_status_label( $status ),
			'factors'      => array(
				'database_size'   => array(
					'score'   => $size_score,
					'weight'  => 30,
					'value'   => $db_stats['total_formatted'] ?? '0 B',
					'message' => $size_score >= 70
						? __( 'Database size is healthy', 'woo-order-archive-manager' )
						: __( 'Large database size affecting performance', 'woo-order-archive-manager' ),
				),
				'archive_usage'   => array(
					'score'   => $archive_score,
					'weight'  => 25,
					'value'   => $this->get_archive_percentage() . '% archived',
					'message' => $archive_score >= 50
						? __( 'Good archive coverage', 'woo-order-archive-manager' )
						: __( 'More orders could be archived', 'woo-order-archive-manager' ),
				),
				'table_integrity' => array(
					'score'   => $integrity_score,
					'weight'  => 25,
					'value'   => sprintf(
						/* translators: %d: number of orphaned records */
						_n( '%d orphan', '%d orphans', $total_orphans, 'woo-order-archive-manager' ),
						$total_orphans
					),
					'message' => 0 === $total_orphans
						? __( 'All archive tables are clean', 'woo-order-archive-manager' )
						/* translators: %d: number of orphaned records */
						: sprintf( __( 'Found %d orphaned records', 'woo-order-archive-manager' ), $total_orphans ),
				),
				'growth_rate'     => array(
					'score'   => $growth_score,
					'weight'  => 20,
					'value'   => $this->format_bytes( $growth_rate * 1024 * 1024 ) . '/month',
					'message' => $growth_score >= 70
						? __( 'Database growth is under control', 'woo-order-archive-manager' )
						: __( 'Database is growing quickly', 'woo-order-archive-manager' ),
				),
			),
		);
	}

	/**
	 * Returns lifetime statistics for the archive system.
	 *
	 * @return array<string, mixed>
	 */
	public function get_lifetime_stats(): array {
		global $wpdb;

		$logs_table   = $wpdb->prefix . 'woam_logs';
		$orders_table = $wpdb->prefix . 'woam_orders';

		$logs_exists   = $this->table_exists( $logs_table );
		$orders_exists = $this->table_exists( $orders_table );

		$total_archived        = 0;
		$total_saved_bytes     = 0;
		$restore_success_count = 0;
		$restore_failure_count = 0;
		$archive_run_count     = 0;
		$restore_run_count     = 0;
		$delete_run_count      = 0;

		if ( $orders_exists ) {
			$total_archived = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i', $orders_table )
			);
		}

		if ( $logs_exists ) {
			$archive_success_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE action = %s AND status = %s',
					$logs_table,
					'archive',
					'success'
				)
			);

			$avg_order_size    = $this->get_average_order_size_bytes();
			$total_saved_bytes = $archive_success_count * $avg_order_size;

			$restore_success_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE action = %s AND status = %s',
					$logs_table,
					'restore',
					'success'
				)
			);

			$restore_failure_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE action = %s AND status = %s',
					$logs_table,
					'restore',
					'error'
				)
			);

			$archive_run_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(DISTINCT DATE(created_at)) FROM %i WHERE action = %s',
					$logs_table,
					'archive'
				)
			);

			$restore_run_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(DISTINCT DATE(created_at)) FROM %i WHERE action = %s',
					$logs_table,
					'restore'
				)
			);

			$delete_run_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(DISTINCT DATE(created_at)) FROM %i WHERE action = %s',
					$logs_table,
					'delete'
				)
			);
		}

		$total_operations     = $archive_run_count + $restore_run_count + $delete_run_count;
		$restore_total        = $restore_success_count + $restore_failure_count;
		$restore_success_rate = $restore_total > 0
			? (int) ( ( $restore_success_count / $restore_total ) * 100 )
			: 100;

		$archived_revenue = $this->calculate_archived_revenue();

		return array(
			'total_archived_orders'      => $total_archived,
			'total_saved_bytes'          => $total_saved_bytes,
			'total_saved_formatted'      => $this->format_bytes( $total_saved_bytes ),
			'archived_revenue'           => $archived_revenue,
			'archived_revenue_formatted' => $this->format_price( $archived_revenue ),
			'restore_success_rate'       => $restore_success_rate,
			'restore_success_count'      => $restore_success_count,
			'restore_failure_count'      => $restore_failure_count,
			'archive_run_count'          => $archive_run_count,
			'restore_run_count'          => $restore_run_count,
			'delete_run_count'           => $delete_run_count,
			'total_operations'           => $total_operations,
		);
	}

	/**
	 * Returns smart recommendations for which orders to archive.
	 *
	 * @return array<string, mixed>
	 */
	public function get_recommendations(): array {
		global $wpdb;

		$monthly_distribution = $this->get_monthly_order_distribution();

		$recommended_date  = null;
		$estimated_savings = 0;
		$confidence        = 'medium';
		$reason            = '';
		$title             = '';

		if ( ! empty( $monthly_distribution ) ) {
			// Look for orders older than 12 months.
			$twelve_months_ago = gmdate( 'Y-m', strtotime( '-12 months' ) );

			foreach ( $monthly_distribution as $month_data ) {
				if ( $month_data->month < $twelve_months_ago && $month_data->order_count > 0 ) {
					$recommended_date  = $month_data->month . '-01';
					$estimated_savings = (int) ( $month_data->order_count * 50 * 1024 );
					$confidence        = 'high';
					$reason            = __( 'Orders from this period are over 12 months old and unlikely to be modified.', 'woo-order-archive-manager' );
					$title             = sprintf(
						/* translators: %s: month and year */
						__( 'Archive orders from %s', 'woo-order-archive-manager' ),
						date_i18n( 'F Y', strtotime( $month_data->month . '-01' ) )
					);
					break;
				}
			}

			// If no orders older than 12 months, check for 6 months.
			if ( ! $recommended_date ) {
				$six_months_ago = gmdate( 'Y-m', strtotime( '-6 months' ) );

				foreach ( $monthly_distribution as $month_data ) {
					if ( $month_data->month < $six_months_ago && $month_data->order_count > 0 ) {
						$recommended_date  = $month_data->month . '-01';
						$estimated_savings = (int) ( $month_data->order_count * 50 * 1024 );
						$confidence        = 'medium';
						$reason            = __( 'Orders from this period are over 6 months old. Archiving them will improve performance.', 'woo-order-archive-manager' );
						$title             = sprintf(
							/* translators: %s: month and year */
							__( 'Archive orders from %s', 'woo-order-archive-manager' ),
							date_i18n( 'F Y', strtotime( $month_data->month . '-01' ) )
						);
						break;
					}
				}
			}
		}

		// Default recommendation if no old orders found.
		if ( ! $recommended_date ) {
			$recommended_date = gmdate( 'Y-m-d', strtotime( '-12 months' ) );
			$confidence       = 'low';
			$reason           = __( 'Your store doesn\'t have many old orders. Consider archiving completed orders older than 12 months.', 'woo-order-archive-manager' );
			$title            = __( 'Archive Completed Orders', 'woo-order-archive-manager' );
		}

		$statuses    = array( 'wc-completed', 'wc-cancelled', 'wc-refunded', 'wc-failed' );
		$order_count = $this->get_order_count_by_date_status( $recommended_date, $statuses );

		return array(
			'id'                          => md5( $recommended_date . implode( '', $statuses ) ), // Simple unique ID.
			'has_recommendation'          => $order_count > 0,
			'title'                       => $title,
			'recommended_date'            => $recommended_date,
			'recommended_date_formatted'  => date_i18n(
				get_option( 'date_format' ),
				strtotime( $recommended_date )
			),
			'recommended_statuses'        => $statuses,
			'estimated_order_count'       => $order_count,
			'estimated_savings_bytes'     => $estimated_savings,
			'estimated_savings_formatted' => $this->format_bytes( $estimated_savings ),
			'confidence'                  => $confidence,
			'confidence_label'            => $this->get_confidence_label( $confidence ),
			'reason'                      => $reason,
			'action_label'                => $order_count > 0
				/* translators: %d: number of orders to archive */
				? sprintf( __( 'Archive %d orders', 'woo-order-archive-manager' ), $order_count )
				: __( 'Review archive settings', 'woo-order-archive-manager' ),
		);
	}

	/**
	 * Get monthly growth rate in MB.
	 *
	 * @return int
	 */
	public function get_monthly_growth_rate_mb(): int {
		return $this->calculate_monthly_growth_rate();
	}

	/**
	 * Returns the current archive readiness status.
	 *
	 * @return array<string, mixed>
	 */
	public function get_archive_readiness(): array {
		global $wpdb;

		$readiness_checks = array();
		$all_passed       = true;

		// Check 1: Archive tables exist.
		$expected_tables = array(
			$wpdb->prefix . 'woam_orders',
			$wpdb->prefix . 'woam_orders_meta',
			$wpdb->prefix . 'woam_order_items',
			$wpdb->prefix . 'woam_order_items_meta',
			$wpdb->prefix . 'woam_logs',
		);

		$missing_tables = array();
		foreach ( $expected_tables as $table ) {
			if ( ! $this->table_exists( $table ) ) {
				$missing_tables[] = $table;
			}
		}

		$tables_ok          = empty( $missing_tables );
		$readiness_checks[] = array(
			'check'   => 'archive_tables',
			'label'   => __( 'Archive tables installed', 'woo-order-archive-manager' ),
			'passed'  => $tables_ok,
			'message' => $tables_ok
				? __( 'All archive tables exist', 'woo-order-archive-manager' )
				/* translators: %s: list of missing table names */
				: sprintf( __( 'Missing tables: %s', 'woo-order-archive-manager' ), implode( ', ', $missing_tables ) ),
		);

		if ( ! $tables_ok ) {
			$all_passed = false;
		}

		// Check 2: No active job running.
		$job_running        = (bool) get_transient( self::LOCK_KEY );
		$readiness_checks[] = array(
			'check'   => 'no_active_job',
			'label'   => __( 'No active archive job', 'woo-order-archive-manager' ),
			'passed'  => ! $job_running,
			'message' => ! $job_running
				? __( 'No jobs are currently running', 'woo-order-archive-manager' )
				: __( 'An archive job is currently in progress', 'woo-order-archive-manager' ),
		);

		if ( $job_running ) {
			$all_passed = false;
		}

		// Check 3: Quick integrity check.
		$integrity_data     = $this->run_quick_integrity_check();
		$integrity_passed   = 0 === $integrity_data['total_orphans'];
		$readiness_checks[] = array(
			'check'   => 'data_integrity',
			'label'   => __( 'Archive data integrity', 'woo-order-archive-manager' ),
			'passed'  => $integrity_passed,
			'message' => $integrity_passed
				? __( 'No orphaned records found', 'woo-order-archive-manager' )
				/* translators: %d: number of orphaned records */
				: sprintf( __( 'Found %d orphaned records', 'woo-order-archive-manager' ), $integrity_data['total_orphans'] ),
		);

		if ( ! $integrity_passed ) {
			$all_passed = false;
		}

		// Check 4: WooCommerce HPOS compatibility.
		$hpos_enabled       = $this->is_hpos_enabled();
		$readiness_checks[] = array(
			'check'   => 'hpos_compatible',
			'label'   => __( 'HPOS compatibility', 'woo-order-archive-manager' ),
			'passed'  => ! $hpos_enabled,
			'message' => ! $hpos_enabled
				? __( 'Legacy order storage is active', 'woo-order-archive-manager' )
				: __( 'HPOS is not yet supported', 'woo-order-archive-manager' ),
		);

		// Check 5: Database version is current.
		$installed_version  = get_option( 'hw_woam_db_version', '0.0.0' );
		$version_ok         = version_compare( $installed_version, HW_WOAM_VERSION, '>=' );
		$readiness_checks[] = array(
			'check'   => 'db_version',
			'label'   => __( 'Database schema version', 'woo-order-archive-manager' ),
			'passed'  => $version_ok,
			'message' => $version_ok
				/* translators: %s: installed version number */
				? sprintf( __( 'Version %s (current)', 'woo-order-archive-manager' ), $installed_version )
				/* translators: %1$s: installed version, %2$s: current version */
				: sprintf( __( 'Version %1$s needs upgrade to %2$s', 'woo-order-archive-manager' ), $installed_version, HW_WOAM_VERSION ),
		);

		if ( ! $version_ok ) {
			$all_passed = false;
		}

		return array(
			'all_passed'  => $all_passed,
			'checks'      => $readiness_checks,
			'can_archive' => $tables_ok && ! $job_running && $integrity_passed && $version_ok,
			'summary'     => $all_passed
				? __( 'Ready to archive', 'woo-order-archive-manager' )
				: __( 'Issues detected before archiving', 'woo-order-archive-manager' ),
		);
	}

	/**
	 * Get cached health score with optional force refresh.
	 *
	 * @param bool $force_refresh Force recalculation.
	 * @return array<string, mixed>
	 */
	public function get_cached_health_score( bool $force_refresh = false ): array {
		$cache = new \HW\WOAM\Health\HealthScoreCache();

		if ( ! $force_refresh && ! $cache->is_stale() ) {
			$cached = $cache->get_cached_score();
			if ( $cached ) {
				return $cached;
			}
		}

		// Calculate fresh score.
		$score = $this->get_health_score();
		$cache->cache_score( $score );

		return $score;
	}

	/**
	 * Checks if a database table exists.
	 *
	 * @param string $table_name Table name to check.
	 * @return bool
	 */
	private function table_exists( string $table_name ): bool {
		global $wpdb;

		$result = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
		);

		return $result === $table_name;
	}

	/**
	 * Gets database statistics as an array.
	 *
	 * @return array<string, mixed>
	 */
	public function get_db_stats_array(): array {
		global $wpdb;

		$table_names = array(
			'posts'          => $wpdb->posts,
			'postmeta'       => $wpdb->postmeta,
			'order_items'    => $wpdb->prefix . 'woocommerce_order_items',
			'order_itemmeta' => $wpdb->prefix . 'woocommerce_order_itemmeta',
		);

		$placeholders = implode( ', ', array_fill( 0, count( $table_names ), '%s' ) );

		// Build the query with proper placeholders.
		$query = "SELECT TABLE_NAME, DATA_LENGTH, INDEX_LENGTH
			FROM information_schema.TABLES
			WHERE TABLE_SCHEMA = DATABASE()
			AND TABLE_NAME IN ({$placeholders})";

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

		return array(
			'tables'          => $stats,
			'total_bytes'     => $total_bytes,
			'total_formatted' => $this->format_bytes( $total_bytes ),
		);
	}

	/**
	 * Calculates the archive usage score.
	 *
	 * @return int Score 0-100
	 */
	private function calculate_archive_usage_score(): int {
		global $wpdb;

		$orders_table      = $wpdb->prefix . 'woam_orders';
		$live_orders_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE post_type = 'shop_order'",
				$wpdb->posts
			)
		);

		$archived_orders_count = 0;
		if ( $this->table_exists( $orders_table ) ) {
			$archived_orders_count = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i', $orders_table )
			);
		}

		$total_orders       = $live_orders_count + $archived_orders_count;
		$archive_percentage = $total_orders > 0
			? (int) ( ( $archived_orders_count / $total_orders ) * 100 )
			: 0;

		return min( 100, $archive_percentage );
	}

	/**
	 * Gets the archive usage percentage.
	 *
	 * @return int
	 */
	private function get_archive_percentage(): int {
		global $wpdb;

		$orders_table      = $wpdb->prefix . 'woam_orders';
		$live_orders_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE post_type = 'shop_order'",
				$wpdb->posts
			)
		);

		$archived_orders_count = 0;
		if ( $this->table_exists( $orders_table ) ) {
			$archived_orders_count = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i', $orders_table )
			);
		}

		$total_orders = $live_orders_count + $archived_orders_count;

		return $total_orders > 0
			? (int) ( ( $archived_orders_count / $total_orders ) * 100 )
			: 0;
	}

	/**
	 * Runs a quick integrity check without full details.
	 *
	 * @return array<string, int>
	 */
	private function run_quick_integrity_check(): array {
		global $wpdb;

		$orders_table           = $wpdb->prefix . 'woam_orders';
		$orders_meta_table      = $wpdb->prefix . 'woam_orders_meta';
		$order_items_table      = $wpdb->prefix . 'woam_order_items';
		$order_items_meta_table = $wpdb->prefix . 'woam_order_items_meta';

		$orphaned_meta      = 0;
		$orphaned_items     = 0;
		$orphaned_item_meta = 0;

		$orders_exists = $this->table_exists( $orders_table );

		if ( $orders_exists ) {
			// Check orphaned meta.
			$orphaned_meta = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i om
					LEFT JOIN %i o ON om.post_id = o.ID
					WHERE o.ID IS NULL',
					$orders_meta_table,
					$orders_table
				)
			);

			// Check orphaned order items.
			$orphaned_items = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i oi
					LEFT JOIN %i o ON oi.order_id = o.ID
					WHERE o.ID IS NULL',
					$order_items_table,
					$orders_table
				)
			);

			// Check orphaned item meta if items table exists.
			if ( 0 === $orphaned_items && $this->table_exists( $order_items_table ) ) {
				$orphaned_item_meta = (int) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT COUNT(*) FROM %i oim
						LEFT JOIN %i oi ON oim.order_item_id = oi.order_item_id
						WHERE oi.order_item_id IS NULL',
						$order_items_meta_table,
						$order_items_table
					)
				);
			}
		}

		return array(
			'orphaned_meta'      => $orphaned_meta,
			'orphaned_items'     => $orphaned_items,
			'orphaned_item_meta' => $orphaned_item_meta,
			'total_orphans'      => $orphaned_meta + $orphaned_items + $orphaned_item_meta,
		);
	}

	/**
	 * Calculates monthly database growth rate in MB per month.
	 *
	 * @return int Growth rate in MB per month
	 */
	private function calculate_monthly_growth_rate(): int {
		$growth_history = get_option( 'hw_woam_growth_history', array() );

		if ( empty( $growth_history ) ) {
			$current_size = $this->get_db_stats_array()['total_bytes'] ?? 0;
			// Default estimate: assume 50MB per month average growth.
			return (int) ( $current_size / ( 1024 * 1024 ) ) > 100 ? 20 : 5;
		}

		$dates = array_keys( $growth_history );
		if ( count( $dates ) < 2 ) {
			return 10;
		}

		$oldest_date = $dates[0];
		$newest_date = $dates[ count( $dates ) - 1 ];
		$oldest_size = $growth_history[ $oldest_date ];
		$newest_size = $growth_history[ $newest_date ];

		$months_diff  = max( 1, ( strtotime( $newest_date ) - strtotime( $oldest_date ) ) / ( 30 * 24 * 60 * 60 ) );
		$size_diff_mb = ( $newest_size - $oldest_size ) / ( 1024 * 1024 );

		return max( 0, (int) ( $size_diff_mb / $months_diff ) );
	}

	/**
	 * Gets average order size in bytes for estimation purposes.
	 *
	 * @return int Average order size in bytes
	 */
	private function get_average_order_size_bytes(): int {
		global $wpdb;

		$sample_orders = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT ID FROM %i WHERE post_type = \'shop_order\' LIMIT 100',
				$wpdb->posts
			)
		);

		if ( empty( $sample_orders ) ) {
			return 50 * 1024;
		}

		$order_count  = count( $sample_orders );
		$placeholders = implode( ', ', array_fill( 0, $order_count, '%d' ) );
		$meta_bytes   = 0;
		$post_bytes   = 0;
		$total_bytes  = 0;

		// Get meta data size.
		$meta_query = "SELECT SUM(LENGTH(meta_key) + LENGTH(meta_value)) 
			FROM {$wpdb->postmeta}
			WHERE post_id IN ({$placeholders})";

		$meta_bytes = (int) $wpdb->get_var(
			$wpdb->prepare( $meta_query, $sample_orders ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		// Get post data size.
		$post_query = "SELECT SUM(LENGTH(post_title) + LENGTH(post_content) + LENGTH(post_excerpt))
			FROM {$wpdb->posts}
			WHERE ID IN ({$placeholders})";

		$post_bytes = (int) $wpdb->get_var(
			$wpdb->prepare( $post_query, $sample_orders ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		$total_bytes = $meta_bytes + $post_bytes;

		return $order_count > 0 ? (int) ( $total_bytes / $order_count ) : 50 * 1024;
	}

	/**
	 * Calculates total revenue from archived orders.
	 *
	 * @return float Total revenue
	 */
	private function calculate_archived_revenue(): float {
		global $wpdb;

		$orders_table      = $wpdb->prefix . 'woam_orders';
		$orders_meta_table = $wpdb->prefix . 'woam_orders_meta';

		$tables_exist = $this->table_exists( $orders_table ) && $this->table_exists( $orders_meta_table );

		if ( ! $tables_exist ) {
			return 0.0;
		}

		$revenue = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT SUM(CAST(meta_value AS DECIMAL(10,2)))
				FROM %i om
				INNER JOIN %i o ON om.post_id = o.ID
				WHERE om.meta_key = \'_order_total\'',
				$orders_meta_table,
				$orders_table
			)
		);

		return (float) $revenue;
	}

	/**
	 * Gets monthly order distribution for recommendations.
	 *
	 * @return array<int, object>
	 */
	private function get_monthly_order_distribution(): array {
		global $wpdb;

		$query = "SELECT 
			DATE_FORMAT(post_date, '%%Y-%%m') as month,
			COUNT(*) as order_count,
			SUM((
				SELECT meta_value 
				FROM {$wpdb->postmeta} pm2 
				WHERE pm2.post_id = p.ID 
				AND pm2.meta_key = '_order_total' 
				LIMIT 1
			)) as total_revenue
		FROM {$wpdb->posts} p
		WHERE post_type = 'shop_order'
		AND post_status IN ('wc-completed', 'wc-cancelled', 'wc-refunded', 'wc-failed')
		GROUP BY DATE_FORMAT(post_date, '%%Y-%%m')
		ORDER BY month DESC
		LIMIT 24";

		$results = $wpdb->get_results( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Gets order count for a specific date and statuses.
	 *
	 * @param string             $before_date Date threshold.
	 * @param array<int, string> $statuses    Order statuses.
	 * @return int
	 */
	private function get_order_count_by_date_status( string $before_date, array $statuses ): int {
		global $wpdb;

		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		$query = "SELECT COUNT(*) FROM {$wpdb->posts}
			WHERE post_type = 'shop_order'
			AND post_date < %s
			AND post_status IN ({$placeholders})";

		$params = array_merge( array( $before_date ), $statuses );

		return (int) $wpdb->get_var(
			$wpdb->prepare( $query, $params ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
	}

	/**
	 * Checks if HPOS is enabled.
	 *
	 * @return bool
	 */
	private function is_hpos_enabled(): bool {
		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			return false;
		}

		return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Gets score status based on numeric score.
	 *
	 * @param int $score Health score.
	 * @return string
	 */
	private function get_score_status( int $score ): string {
		if ( $score >= 80 ) {
			return 'excellent';
		}
		if ( $score >= 60 ) {
			return 'good';
		}
		if ( $score >= 40 ) {
			return 'fair';
		}
		if ( $score >= 20 ) {
			return 'poor';
		}
		return 'critical';
	}

	/**
	 * Formats bytes into human-readable string.
	 *
	 * @param int $bytes Raw byte count.
	 * @return string
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
	 * Formats a price value for display.
	 *
	 * @param float $price Price value.
	 * @return string Formatted price.
	 */
	private function format_price( float $price ): string {
		$currency_symbol = function_exists( 'get_woocommerce_currency_symbol' )
			? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' )
			: '$';

		return $currency_symbol . number_format( $price, 0 );
	}

	/**
	 * Gets human-readable label for health status.
	 *
	 * @param string $status Status key.
	 * @return string Label.
	 */
	private function get_health_status_label( string $status ): string {
		$labels = array(
			'excellent' => __( 'Excellent', 'woo-order-archive-manager' ),
			'good'      => __( 'Good', 'woo-order-archive-manager' ),
			'fair'      => __( 'Fair', 'woo-order-archive-manager' ),
			'poor'      => __( 'Poor', 'woo-order-archive-manager' ),
			'critical'  => __( 'Critical', 'woo-order-archive-manager' ),
		);

		return $labels[ $status ] ?? __( 'Unknown', 'woo-order-archive-manager' );
	}

	/**
	 * Gets human-readable label for confidence level.
	 *
	 * @param string $confidence Confidence key.
	 * @return string Label.
	 */
	private function get_confidence_label( string $confidence ): string {
		$labels = array(
			'high'   => __( 'High confidence', 'woo-order-archive-manager' ),
			'medium' => __( 'Medium confidence', 'woo-order-archive-manager' ),
			'low'    => __( 'Low confidence', 'woo-order-archive-manager' ),
		);

		return $labels[ $confidence ] ?? __( 'Unknown', 'woo-order-archive-manager' );
	}
}
