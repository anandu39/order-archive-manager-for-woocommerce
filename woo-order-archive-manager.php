<?php
/**
 * Plugin Name: Woo Order Archive Manager
 * Plugin URI: https://anandu39.github.io/Anandu-Ravikumar/woo-order-archive-manager
 * Description: A plugin to securely archive WooCommerce orders from legacy tables into separate archive custom tables, ensuring data integrity and compliance with data retention policies.
 * Version: 1.0.0
 * Author: Anandu Ravikumar
 * Author URI: https://anandu39.github.io/Anandu-Ravikumar/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: woo-order-archive-manager
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 7.0
 * Requires PHP: 8.2
 * Requires Plugins: woocommerce
 *
 * @package HW\WOAM
 */

defined( 'ABSPATH' ) || exit;

/**
 * PHP version check.
 * Must run before the autoloader — class files use PHP 8.2 syntax
 * and will cause a parse error on older PHP before we can show a notice.
 */
if ( version_compare( PHP_VERSION, '8.2', '<' ) ) {

	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>'
				. sprintf(
					/* translators: %s: required PHP version */
					esc_html__( 'Woo Order Archive Manager requires PHP %s or higher. Please upgrade PHP or contact your hosting provider.', 'woo-order-archive-manager' ),
					'8.2'
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
				. esc_html__( 'Woo Order Archive Manager: Composer dependencies are missing. Please run composer install in the plugin directory.', 'woo-order-archive-manager' )
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
