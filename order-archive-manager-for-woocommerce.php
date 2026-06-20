<?php
/**
 * Plugin Name: Order Archive Manager for WooCommerce
 * Plugin URI: https://anandu39.github.io/Anandu-Ravikumar/order-archive-manager-for-woocommerce
 * Description: A plugin to securely archive WooCommerce orders from legacy tables into separate archive custom tables, ensuring data integrity and compliance with data retention policies.
 * Version: 1.0.0
 * Author: Anandu Ravikumar
 * Author URI: https://anandu39.github.io/Anandu-Ravikumar/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: order-archive-manager-for-woocommerce
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 7.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 *
 * @package HW\WOAM
 */

defined( 'ABSPATH' ) || exit;

if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {

	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>'
				. sprintf(
					/* translators: %s: required PHP version */
					esc_html__( 'Order Archive Manager for WooCommerce requires PHP %s or higher. Please upgrade PHP or contact your hosting provider.', 'order-archive-manager-for-woocommerce' ),
					'7.4'
				)
				. '</p></div>';
		}
	);

	return;
}

/**
 * Plugin constants.
 */
define( 'HW_WOAM_VERSION', '1.0.0' );
define( 'HW_WOAM_PATH', plugin_dir_path( __FILE__ ) );
define( 'HW_WOAM_URL', plugin_dir_url( __FILE__ ) );
define( 'HW_WOAM_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Composer autoloader.
 */
if ( ! file_exists( HW_WOAM_PATH . 'vendor/autoload.php' ) ) {

	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>'
				. esc_html__( 'Order Archive Manager for WooCommerce: Composer dependencies are missing. Please run composer install in the plugin directory.', 'order-archive-manager-for-woocommerce' )
				. '</p></div>';
		}
	);

	return;
}

require_once HW_WOAM_PATH . 'vendor/autoload.php';

/**
 * Activation hook.
 * Runs when the plugin is activated from the WordPress plugins screen.
 */
register_activation_hook(
	__FILE__,
	function () {
		\HW\WOAM\Plugin::instance()->activate();
	}
);

/**
 * Deactivation hook.
 * Runs when the plugin is deactivated from the WordPress plugins screen.
 */
register_deactivation_hook(
	__FILE__,
	function () {
		\HW\WOAM\Plugin::instance()->deactivate();
	}
);

/**
 * Boot the plugin on plugins_loaded.
 * This fires after all plugins are loaded — WooCommerce is available here.
 */
add_action(
	'plugins_loaded',
	function () {
		\HW\WOAM\Plugin::instance()->boot();
	}
);
