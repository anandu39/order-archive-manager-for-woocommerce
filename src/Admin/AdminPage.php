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
			__( 'Order Archive Manager', 'order-archive-manager-for-woocommerce' ),
			__( 'Order Archive', 'order-archive-manager-for-woocommerce' ),
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
			array( 'jquery-ui-datepicker' ),
			HW_WOAM_VERSION,
			true
		);

		// WordPress ships jQuery UI Datepicker CSS via this handle.
		wp_enqueue_style(
			'woam-jquery-ui',
			HW_WOAM_URL . 'assets/css/jquery-ui.min.css',
			array(),
			'1.13.2'
		);

		wp_localize_script(
			'woam-admin',
			'woamData',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'hw_woam_ajax' ),
				'i18n'    => array(
					'confirmArchive' => __( 'Type ARCHIVE to confirm', 'order-archive-manager-for-woocommerce' ),
					'confirmDelete'  => __( 'Type DELETE to confirm', 'order-archive-manager-for-woocommerce' ),
					'jobRunning'     => __( 'Another job is already running, Please wait..', 'order-archive-manager-for-woocommerce' ),
					'noOrders'       => __( 'No Orders match the selected filer', 'order-archive-manager-for-woocommerce' ),
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

		<div class="wrap woam-wrap woam-onboarding-ready">
			<div class="woam-header">
				<h1><?php esc_html_e( 'Order Archive Manager', 'order-archive-manager-for-woocommerce' ); ?></h1>
				<div id="woam-opportunity-banner" class="woam-opportunity-banner">
					<div class="woam-opportunity-banner-content">
						<div class="woam-opportunity-icon">
							<span class="dashicons dashicons-chart-line"></span>
						</div>
						<div class="woam-opportunity-text">
							<span id="woam-opportunity-message"><?php esc_html_e( 'Loading database insights...', 'order-archive-manager-for-woocommerce' ); ?></span>
						</div>
						<button type="button" class="woam-button woam-button--primary" id="woam-opportunity-cta" style="display: none;">
							<span class="dashicons dashicons-archive"></span>
							<?php esc_html_e( 'Review Archive Opportunities', 'order-archive-manager-for-woocommerce' ); ?>
						</button>
					</div>
				</div>
			</div>

			<nav class ="woam-tabs" role ="tablist">
				<button
					class="woam-tab woam-tab--active"
					role="tab"
					aria-selected="true"
					aria-controls="woam-panel-overview"
					data-tab="overview"
				>
					<?php esc_html_e( 'Overview', 'order-archive-manager-for-woocommerce' ); ?>
				</button>

				<button
					class="woam-tab"
					role="tab"
					aria-selected="false"
					aria-controls="woam-panel-archive"
					data-tab="archive"
				>
					<?php esc_html_e( 'Archive Orders', 'order-archive-manager-for-woocommerce' ); ?>
				</button>

				<button
					class="woam-tab"
					role="tab"
					aria-selected="false"
					aria-controls="woam-panel-archived"
					data-tab="archived"
				>
					<?php esc_html_e( 'Archived Orders', 'order-archive-manager-for-woocommerce' ); ?>
				</button>
			</nav>

			<div class="woam-panels-container">
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
