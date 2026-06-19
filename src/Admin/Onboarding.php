<?php
/**
 * Onboarding Wizard
 *
 * Handles first-time user setup, initial scans, and guided tour.
 * Shows welcome modal on first activation and guides users through first archive.
 *
 * @package HW\WOAM\Admin
 */

namespace HW\WOAM\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Class Onboarding
 */
class Onboarding {

	/**
	 * Option key for tracking onboarding status.
	 */
	private const ONBOARDING_COMPLETED_KEY = 'hw_woam_onboarding_completed';

	/**
	 * Option key for skipping onboarding.
	 */
	private const ONBOARDING_SKIPPED_KEY = 'hw_woam_onboarding_skipped';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_onboarding_assets' ) );
		add_action( 'wp_ajax_hw_woam_run_initial_scan', array( $this, 'handle_initial_scan' ) );
		add_action( 'wp_ajax_hw_woam_dismiss_onboarding', array( $this, 'handle_dismiss_onboarding' ) );
	}

	/**
	 * Check if onboarding should be shown.
	 *
	 * @param string $hook Current admin page hook.
	 * @return bool
	 */
	public function should_show_onboarding( string $hook ): bool {
		// Only show on our plugin page.
		if ( 'toplevel_page_woam-dashboard' !== $hook ) {
			return false;
		}

		// Don't show if already completed or skipped.
		if ( get_option( self::ONBOARDING_COMPLETED_KEY, false ) ) {
			return false;
		}

		if ( get_option( self::ONBOARDING_SKIPPED_KEY, false ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Enqueue onboarding assets.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_onboarding_assets( string $hook ): void {
		if ( ! $this->should_show_onboarding( $hook ) ) {
			return;
		}

		wp_enqueue_style(
			'woam-onboarding',
			HW_WOAM_URL . 'assets/css/woam-onboarding.css',
			array(),
			HW_WOAM_VERSION
		);

		wp_enqueue_script(
			'woam-onboarding',
			HW_WOAM_URL . 'assets/js/woam-onboarding.js',
			array(),
			HW_WOAM_VERSION,
			true
		);

		wp_localize_script(
			'woam-onboarding',
			'woamOnboarding',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'hw_woam_onboarding' ),
				'i18n'    => array(
					'welcomeTitle'   => __( 'Welcome to Order Archive Manager!', 'order-archive-manager-for-woocommerce' ),
					'welcomeDesc'    => __( "Let's get your store optimized in less than 2 minutes.", 'order-archive-manager-for-woocommerce' ),
					'scanning'       => __( 'Scanning your store...', 'order-archive-manager-for-woocommerce' ),
					'analyzing'      => __( 'Analyzing order data...', 'order-archive-manager-for-woocommerce' ),
					'preparing'      => __( 'Preparing recommendations...', 'order-archive-manager-for-woocommerce' ),
					'skip'           => __( 'Skip Setup', 'order-archive-manager-for-woocommerce' ),
					'next'           => __( 'Next', 'order-archive-manager-for-woocommerce' ),
					'startArchiving' => __( 'Start Archiving', 'order-archive-manager-for-woocommerce' ),
					'gotIt'          => __( 'Got It!', 'order-archive-manager-for-woocommerce' ),
				),
			)
		);
	}

	/**
	 * Handle initial store scan.
	 *
	 * @return void
	 */
	public function handle_initial_scan(): void {
		check_ajax_referer( 'hw_woam_onboarding', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'order-archive-manager-for-woocommerce' ) ) );
		}

		global $wpdb;

		// Get order statistics.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total_orders = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM `{$wpdb->posts}` WHERE post_type = 'shop_order'"
			)
		);

		// Get oldest order date.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$oldest_order = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT MIN(post_date) FROM `{$wpdb->posts}` WHERE post_type = 'shop_order'"
			)
		);

		// Get completed orders older than 12 months.
		$twelve_months_ago  = gmdate( 'Y-m-d H:i:s', strtotime( '-12 months' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$archive_candidates = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM `{$wpdb->posts}`
				WHERE post_type = 'shop_order'
				AND post_status = 'wc-completed'
				AND post_date < %s",
				$twelve_months_ago
			)
		);


		// Get database size.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$db_size = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT SUM(DATA_LENGTH + INDEX_LENGTH)
				FROM information_schema.TABLES
				WHERE TABLE_SCHEMA = DATABASE()
				AND TABLE_NAME IN (%s, %s, %s, %s)',
				$wpdb->posts,
				$wpdb->postmeta,
				$wpdb->prefix . 'woocommerce_order_items',
				$wpdb->prefix . 'woocommerce_order_itemmeta'
			)
		);

		$estimated_savings = $archive_candidates * 50 * 1024; // ~50KB per order.

		wp_send_json_success(
			array(
				'total_orders'                => $total_orders,
				'oldest_order_date'           => $oldest_order,
				'oldest_order_formatted'      => $oldest_order ? date_i18n( get_option( 'date_format' ), strtotime( $oldest_order ) ) : __( 'N/A', 'order-archive-manager-for-woocommerce' ),
				'archive_candidates'          => $archive_candidates,
				'db_size_bytes'               => $db_size,
				'db_size_formatted'           => $this->format_bytes( $db_size ),
				'estimated_savings_bytes'     => $estimated_savings,
				'estimated_savings_formatted' => $this->format_bytes( $estimated_savings ),
				'has_old_orders'              => $archive_candidates > 0,
			)
		);
	}

	/**
	 * Handle dismissing onboarding.
	 *
	 * @return void
	 */
	public function handle_dismiss_onboarding(): void {
		check_ajax_referer( 'hw_woam_onboarding', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'order-archive-manager-for-woocommerce' ) ) );
		}

		$skip = isset( $_POST['skip'] ) ? filter_var( wp_unslash( $_POST['skip'] ), FILTER_VALIDATE_BOOLEAN ) : false;

		if ( $skip ) {
			update_option( self::ONBOARDING_SKIPPED_KEY, true );
		} else {
			update_option( self::ONBOARDING_COMPLETED_KEY, true );
		}

		wp_send_json_success();
	}

	/**
	 * Reset onboarding (for testing/debugging).
	 *
	 * @return void
	 */
	public static function reset_onboarding(): void {
		delete_option( self::ONBOARDING_COMPLETED_KEY );
		delete_option( self::ONBOARDING_SKIPPED_KEY );
	}

	/**
	 * Format bytes into human-readable string.
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
}
