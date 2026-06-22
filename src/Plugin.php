<?php
/**
 * Main plugin class file.
 *
 * Bootstraps the plugin and checks dependencies, create service objects, and registers all WordPress hooks.
 *
 * @package HW\WOAM
 */

namespace HW\WOAM;

use HW\WOAM\Logger\Logger;
use HW\WOAM\Archive\ArchiveHandler;
use HW\WOAM\Archive\RestoreHandler;
use HW\WOAM\Archive\DeleteHandler;
use HW\WOAM\Ajax\AjaxHandler;
use HW\WOAM\Admin\AdminPage;
use HW\WOAM\Analytics\AnalyticsHandler;
use HW\WOAM\Admin\Onboarding;


defined( 'ABSPATH' ) || exit;

/**
 * Class Plugin
 */
class Plugin {

	/**
	 * Single instance of the plugin.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Table name definitions.
	 *
	 * @var \HW\WOAM\Database\Tables
	 */
	private Database\Tables $tables;

	/**
	 * Database schema manager.
	 *
	 * @var Database\Schema
	 */
	private Database\Schema $schema;

	/**
	 * Activity logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Archive Handler.
	 *
	 * @var ArchiveHandler
	 */

	private ArchiveHandler $archive_handler;

	/**
	 * Restore Handler.
	 *
	 * @var RestoreHandler
	 */

	private RestoreHandler $restore_handler;

	/**
	 * Delete Handler.
	 *
	 * @var DeleteHandler
	 */

	private DeleteHandler $delete_handler;

	/**
	 * Ajax Handler.
	 *
	 * @var AjaxHandler
	 */

	private AjaxHandler $ajax_handler;

	/**
	 * Admin Page
	 *
	 * @var AdminPage
	 */

	private AdminPage $admin_page;

	/**
	 * Analtyics Page
	 *
	 * @var AnalyticsHandler
	 */
	private AnalyticsHandler $analytics_handler;

	/**
	 * Onboarding Page
	 *
	 * @var Onboarding
	 */

	private Onboarding $onboarding;

	/**
	 * Constructor.
	 * Private to prevent direct instantiation.
	 * Use Plugin::instance() instead.
	 */
	private function __construct() {
	}

	/**
	 * Prevent cloning the instance.
	 */
	private function __clone() {
	}

	/**
	 * Prevent unserializing the instance.
	 *
	 * @throws \Exception Direct block to prevent the cloning or restoration of a singleton instance.
	 * @return void
	 */
	public function __wakeup() {
		throw new \Exception( 'Cannot unserialize singleton.' );
	}

	/**
	 * Returns the single instance of the plugin.
	 * Creates it on first call, returns existing on subsequent calls.
	 *
	 * @return self
	 */
	public static function instance(): self {

		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Add settings link on plugins page.
	 *
	 * @param array<string, string> $links Existing plugin action links.
	 * @return array<string, string> Modified links.
	 */
	public function add_settings_link( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			admin_url( 'admin.php?page=woam-dashboard' ),
			__( 'Settings', 'order-archive-manager-for-woocommerce' )
		);

		// Add settings link at the beginning.
		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Register health score cron refresh hook.
	 *
	 * @return void
	 */
	private function register_health_cron(): void {
		add_action( 'hw_woam_refresh_health_score', array( $this, 'refresh_health_score_callback' ) );
	}

	/**
	 * Callback for refreshing health score cache.
	 *
	 * @return void
	 */
	public function refresh_health_score_callback(): void {
		// This will be called via WP Cron.
		// Force refresh the analytics handler cache.
		if ( isset( $this->analytics_handler ) ) {
			// Recalculate and recache.
			$this->analytics_handler->get_cached_health_score( true );
		}
	}

	/**
	 * Boots the plugin.
	 * Called once from the main plugin file on plugins_loaded.
	 *
	 * @return void
	 */
	public function boot(): void {

		if ( ! $this->is_woocommerce_active() ) {
			return;
		}

		// Add settings link on plugins page.
		add_filter( 'plugin_action_links_' . HW_WOAM_BASENAME, array( $this, 'add_settings_link' ) );

		if ( $this->is_hpos_enabled() ) {
			add_action(
				'admin_notices',
				function () {
					$screen = get_current_screen();
					if ( ! $screen || 'plugins' !== $screen->id ) {
						return;
					}
					echo '<div class="notice notice-warning is-dismissible"><p>'
						. esc_html__( 'Woo Order Archive Manager: This version supports legacy order storage (post-based orders) only. High-Performance Order Storage (HPOS) support is planned for a future release.', 'order-archive-manager-for-woocommerce' )
						. '</p></div>';
				}
			);
			return;
		}

		global $wpdb;
		$this->tables            = new Database\Tables( $wpdb );
		$this->schema            = new Database\Schema( $wpdb, $this->tables );
		$this->logger            = new Logger( $wpdb, $this->tables );
		$this->archive_handler   = new ArchiveHandler( $wpdb, $this->tables, $this->logger );
		$this->restore_handler   = new RestoreHandler( $wpdb, $this->tables, $this->logger );
		$this->delete_handler    = new DeleteHandler( $wpdb, $this->tables, $this->logger );
		$this->analytics_handler = new AnalyticsHandler();
		$this->ajax_handler      = new AjaxHandler(
			$this->archive_handler,
			$this->restore_handler,
			$this->delete_handler,
			$this->analytics_handler
		);
		$this->onboarding        = new Onboarding();

		if ( is_admin() ) {
			$this->admin_page = new AdminPage();
			$this->admin_page->register_hooks();
			$this->ajax_handler->register_hooks();
			$this->onboarding->register_hooks();
		}
		$this->register_health_cron();

		$this->schema->maybe_upgrade();
	}

	/**
	 *
	 * Runs on plugin activation.
	 * Create database tables and do any other setup tasks.
	 *
	 * @return void
	 */
	public function activate(): void {
		global $wpdb;
		$tables = new Database\Tables( $wpdb );
		$schema = new Database\Schema( $wpdb, $tables );

		$schema->create_tables();

		// Schedule health score cron.
		$health_cache = new \HW\WOAM\Health\HealthScoreCache();
		$health_cache->schedule_cron();
	}

	/**
	 * Runs on plugin deactivation.
	 * Clean up any scheduled tasks or other things that should not persist after deactivation.
	 *
	 * @return void
	 */
	public function deactivate(): void {
		delete_transient( 'hw_woam_order_date_range' );

		// Clear health score cron.
		$health_cache = new \HW\WOAM\Health\HealthScoreCache();
		$health_cache->clear_cron();
	}


	/**
	 * Checks whether WooCommerce is active and loaded.
	 * Shows an admin notice if not.
	 *
	 * @return bool
	 */
	private function is_woocommerce_active(): bool {

		if ( class_exists( 'WooCommerce' ) ) {
			return true;
		}

		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-error"><p>'
					. esc_html__( 'Woo Order Archive Manager requires WooCommerce to be installed and activated.', 'order-archive-manager-for-woocommerce' )
					. '</p></div>';
			}
		);

		return false;
	}

	/**
	 * Checks if WooCommerce High-Performance Order Storage (HPOS) is enabled.
	 * This plugin currently supports legacy post-based order storage only.
	 *
	 * @return bool
	 */
	private function is_hpos_enabled(): bool {

		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			return false;
		}

		return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}
}
