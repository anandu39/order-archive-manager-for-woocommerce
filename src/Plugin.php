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


defined( 'ABSPATH' ) || exit;

/**
 * Class Plugin
 */
class Plugin {

	/**
	 * Single instance of the plugin.
	 *
	 * @var Plugin|null
	 */

	private static ?Plugin $instance = null;

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
	 * @return static
	 */
	public static function instance(): static {

		if ( null === self::$instance ) {
			self::$instance = new static();
		}

		return self::$instance;
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

		if ( $this->is_hpos_enabled() ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-warning"><p>'
						. esc_html__( 'Woo Order Archive Manager: This version supports legacy order storage (post-based orders) only. High-Performance Order Storage (HPOS) support is planned for a future release.', 'woo-order-archive-manager' )
						. '</p></div>';
				}
			);
			return;
		}

		global $wpdb;
		$this->tables          = new Database\Tables( $wpdb );
		$this->schema          = new Database\Schema( $wpdb, $this->tables );
		$this->logger          = new Logger( $wpdb, $this->tables );
		$this->archive_handler = new ArchiveHandler( $wpdb, $this->tables, $this->logger );
		$this->restore_handler = new RestoreHandler( $wpdb, $this->tables, $this->logger );
		$this->delete_handler  = new DeleteHandler( $wpdb, $this->tables, $this->logger );
		$this->ajax_handler    = new AjaxHandler( $this->archive_handler, $this->restore_handler, $this->delete_handler );

		if ( is_admin() ) {
			$this->admin_page = new AdminPage();
			$this->admin_page->register_hooks();
			$this->ajax_handler->register_hooks();
		}

		$this->schema->maybe_upgrade();
		$this->load_textdomain();
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
	}

	/**
	 * Runs on plugin deactivation.
	 * Clean up any scheduled tasks or other things that should not persist after deactivation.
	 *
	 * @return void
	 */
	public function deactivate(): void {
		delete_transient( 'hw_woam_order_date_range' );
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
					. esc_html__( 'Woo Order Archive Manager requires WooCommerce to be installed and activated.', 'woo-order-archive-manager' )
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

	/**
	 * Loads the plugin text domain for translations.
	 *
	 * @return void
	 */
	private function load_textdomain(): void {

		load_plugin_textdomain(
			'woo-order-archive-manager',
			false,
			dirname( HW_WOAM_BASENAME ) . '/languages'
		);
	}
}
