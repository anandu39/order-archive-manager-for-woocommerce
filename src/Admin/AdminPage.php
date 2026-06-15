<?php
/**
 *
 * Admin Page
 *
 * Register the plugin's admin page as a top-level menu item,
 * Enqueue styles and scripts used and render the page as a shell.
 * All interactive behaviour is handled by the woam-admin.js file via the AJAX
 * endpoints registered in the AjaxHandler.
 *
 * @package HW\WOAM\Admin
 */

namespace HW\WOAM\Admin;

defined( 'ABSPATH' ) || exit;

/**
 *
 * Class AdminPage
 */
class AdminPage {
	/**
	 * The page slug used in the URL and add_menu_page()
	 */

	private const PAGE_SLUG = 'woam-dashboard';

	/**
	 *
	 * Registers admin menu and asset hooks.
	 * Called once from Plugin::boot().
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 *
	 * Register the menu in top-level,
	 * Position Just above the WooCommerce menu in the sidebar.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_menu_page(
			__( 'Order Archive Manager', 'woo-order-archive-manager' ),
			__( 'Order Archive', 'woo-order-archive-manager' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( $this, 'admin_render_page' ),
			'dashicons-archive',
			56 // menu-position.
		);
	}

	/**
	 *
	 * Enqueue the plugins CSS and JS on the admin's pages only.
	 * Also passes the AJAX_URL and a nonce via wp_localize_script().
	 *
	 * @param string $hook The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {

		if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'woam-admin',
			HW_WOAM_URL . 'assets/css/woam-admin.css',
			array(),
			HW_WOAM_VERSION
		);

		wp_enqueue_script(
			'woam-admin',
			HW_WOAM_URL . 'assets/js/woam-admin.js',
			array(),
			HW_WOAM_VERSION,
			true  // Load in footer — DOM is ready, no jQuery dependency needed.
		);

		wp_localize_script(
			'woam-admin',
			'woamData',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'hw_woam_ajax' ),
				'i18n'    => array(
					'confirmArchive' => __( 'Type ARCHIVE to confirm', 'woo-order-archive-manager' ),
					'confirmDelete'  => __( 'Type DELETE to confirm', 'woo-order-archive-manager' ),
					'jobRunning'     => __( 'Another job is already running, Please wait..', 'woo-order-archive-manager' ),
					'noOrders'       => __( 'No Orders match the selected filer', 'woo-order-archive-manager' ),
				),
			)
		);
	}

	/**
	 *
	 * Renders the full admin page - tab navigation and three empty
	 * tab panels. JavaScript fills each panels on tab activation via
	 * AJAX.
	 *
	 * @return void
	 */
	public function admin_render_page(): void {
		?>

		<div class="wrap woam-wrap">
			<div class="woam-header">
				<h1> <?php esc_html_e( 'Order Archive Manager', 'woo-order-archive-manager' ); ?></h1>
				<p> <?php esc_html_e( 'Archive, restore and manager your WooCommerce data', 'woo-order-archive-manager' ); ?></p>
			</div>

			<nav class ="woam-tabs" role ="tablist">
				<button
					class="woam-tab woam-tab--active"
					role="tab"
					aria-selected="true"
					aria-controls="woam-panel-overview"
					data-tab="overview"
				>
					<?php esc_html_e( 'Overview', 'woo-order-archive-manager' ); ?>
				</button>

				<button
					class="woam-tab"
					role="tab"
					aria-selected="false"
					aria-controls="woam-panel-archive"
					data-tab="archive"
				>
					<?php esc_html_e( 'Archive Orders', 'woo-order-archive-manager' ); ?>
				</button>

				<button
					class="woam-tab"
					role="tab"
					aria-selected="false"
					aria-controls="woam-panel-archived"
					data-tab="archived"
				>
					<?php esc_html_e( 'Archived Orders', 'woo-order-archive-manager' ); ?>
				</button>
			</nav>

			<div class="woam-panel">
				<div
					id="woam-panel-overview"
					class="woam-panel woam-panel--active"
					role="tabpanel"
					aria-labelledby="woam-tab-overview"
				>
					<?php include HW_WOAM_PATH . 'assets/views/tab-overview.php'; ?>
				</div>

				<div
					id="woam-panel-archive"
					class="woam-panel"
					role="tabpanel"
					aria-labelledby="woam-tab-archive"
				>
					<?php include HW_WOAM_PATH . 'assets/views/tab-archive.php'; ?>
				</div>

				<div
					id="woam-panel-archived"
					class="woam-panel"
					role="tabpanel"
					aria-labelledby="woam-tab-archived"
				>
					<?php include HW_WOAM_PATH . 'assets/views/tab-archived.php'; ?>
				</div>
			</div>
		</div>

		<?php
	}
}

