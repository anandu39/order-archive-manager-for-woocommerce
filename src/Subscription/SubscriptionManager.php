<?php
/**
 * Subscription Manager
 *
 * Handles all WooCommerce Subscriptions related functionality including detection,
 * status tracking, and protection of subscription-linked orders.
 *
 * @package HW\WOAM\Subscription
 */

namespace HW\WOAM\Subscription;

defined( 'ABSPATH' ) || exit;

/**
 * Class SubscriptionManager
 */
class SubscriptionManager {

	/**
	 * WordPress database object.
	 *
	 * @var \wpdb
	 */
	private \wpdb $wpdb;

	/**
	 * Protected subscription statuses.
	 */
	private const PROTECTED_STATUSES = array( 'wc-active', 'wc-pending-cancel', 'wc-on-hold' );

	/**
	 * Safe subscription statuses (eligible for archiving).
	 */
	private const SAFE_STATUSES = array( 'wc-cancelled', 'wc-expired', 'wc-failed' );

	/**
	 * Constructor.
	 *
	 * @param \wpdb $wpdb WordPress database object.
	 */
	public function __construct( \wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * Check if WooCommerce Subscriptions is active.
	 *
	 * @return bool
	 */
	public function is_subscriptions_active(): bool {
		return class_exists( 'WC_Subscriptions' );
	}

	/**
	 * Get subscription status breakdown.
	 *
	 * @return array<string, int>
	 */
	public function get_subscription_breakdown(): array {
		if ( ! $this->is_subscriptions_active() ) {
			return array(
				'active'         => 0,
				'pending_cancel' => 0,
				'on_hold'        => 0,
				'cancelled'      => 0,
				'expired'        => 0,
				'failed'         => 0,
				'total'          => 0,
				'protected'      => 0,
				'eligible'       => 0,
			);
		}

		$statuses     = array_merge( self::PROTECTED_STATUSES, self::SAFE_STATUSES );
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		$sql = "SELECT post_status, COUNT(*) as count
            FROM `{$this->wpdb->posts}`
            WHERE post_type = 'shop_subscription'
            AND post_status IN ({$placeholders})
            GROUP BY post_status";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders are dynamically built and safe.
		$results = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $statuses ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$stats = array(
			'active'         => 0,
			'pending_cancel' => 0,
			'on_hold'        => 0,
			'cancelled'      => 0,
			'expired'        => 0,
			'failed'         => 0,
			'total'          => 0,
			'protected'      => 0,
			'eligible'       => 0,
		);

		foreach ( $results as $row ) {
			$status           = str_replace( 'wc-', '', $row->post_status );
			$stats[ $status ] = (int) $row->count;
			$stats['total']  += (int) $row->count;

			if ( in_array( $row->post_status, self::PROTECTED_STATUSES, true ) ) {
				$stats['protected'] += (int) $row->count;
			} elseif ( in_array( $row->post_status, self::SAFE_STATUSES, true ) ) {
				$stats['eligible'] += (int) $row->count;
			}
		}

		// Get protected parent orders.
		$protected_placeholders = implode( ', ', array_fill( 0, count( self::PROTECTED_STATUSES ), '%s' ) );
		$protected_sql          = "SELECT COUNT(DISTINCT post_parent)
            FROM `{$this->wpdb->posts}`
            WHERE post_type = 'shop_subscription'
            AND post_status IN ({$protected_placeholders})
            AND post_parent > 0";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders are dynamically built and safe.
		$stats['protected_parent_orders'] = (int) $this->wpdb->get_var( $this->wpdb->prepare( $protected_sql, self::PROTECTED_STATUSES ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// Get eligible parent orders (safe to archive).
		$safe_placeholders = implode( ', ', array_fill( 0, count( self::SAFE_STATUSES ), '%s' ) );
		$eligible_sql      = "SELECT COUNT(DISTINCT post_parent)
            FROM `{$this->wpdb->posts}`
            WHERE post_type = 'shop_subscription'
            AND post_status IN ({$safe_placeholders})
            AND post_parent > 0";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders are dynamically built and safe.
		$stats['eligible_parent_orders'] = (int) $this->wpdb->get_var( $this->wpdb->prepare( $eligible_sql, self::SAFE_STATUSES ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// Get renewal orders.
		$renewal_sql = "SELECT COUNT(*) FROM `{$this->wpdb->postmeta}` WHERE meta_key = '_subscription_renewal'";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- query is a static string with no user input.
		$stats['renewal_orders'] = (int) $this->wpdb->get_var( $renewal_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return $stats;
	}

	/**
	 * Check if an order is protected (linked to active subscription).
	 *
	 * @param int $order_id Order ID.
	 * @return bool
	 */
	public function is_order_protected( int $order_id ): bool {
		if ( ! $this->is_subscriptions_active() ) {
			return false;
		}

		// Check if order is a renewal.
		$renewal_sql = "SELECT COUNT(*)
            FROM `{$this->wpdb->postmeta}`
            WHERE post_id = %d
            AND meta_key IN ('_subscription_renewal', '_subscription_resubscribe')";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- query is a static string with no user input.
		$is_renewal = (int) $this->wpdb->get_var( $this->wpdb->prepare( $renewal_sql, $order_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( $is_renewal > 0 ) {
			return true;
		}

		// Check if any protected subscription has this order as parent.
		$placeholders  = implode( ', ', array_fill( 0, count( self::PROTECTED_STATUSES ), '%s' ) );
		$protected_sql = "SELECT COUNT(*)
            FROM `{$this->wpdb->posts}`
            WHERE post_parent = %d
            AND post_type = 'shop_subscription'
            AND post_status IN ({$placeholders})
            LIMIT 1";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders are dynamically built and safe.
		$has_protected = (int) $this->wpdb->get_var( $this->wpdb->prepare( $protected_sql, array_merge( array( $order_id ), self::PROTECTED_STATUSES ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return $has_protected > 0;
	}

	/**
	 * Get subscription orders by status.
	 *
	 * @param string $status Subscription status.
	 * @param int    $limit  Limit results.
	 * @return array<int, array>
	 */
	public function get_subscription_orders_by_status( string $status, int $limit = 100 ): array {
		if ( ! $this->is_subscriptions_active() ) {
			return array();
		}

		$sql = "SELECT p.ID, p.post_status, p.post_date,
                    s.post_status as subscription_status
            FROM `{$this->wpdb->posts}` p
            INNER JOIN `{$this->wpdb->posts}` s ON s.post_parent = p.ID
            WHERE p.post_type = 'shop_order'
            AND s.post_type = 'shop_subscription'
            AND s.post_status = %s
            ORDER BY p.post_date DESC
            LIMIT %d";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names are trusted wpdb properties; only status and limit are dynamic.
		$results = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $status, $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

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
}
