<?php
/**
 * Plugin Name: Quantity Ceiling
 * Description: Set minimum and maximum quantity restrictions for selected WooCommerce products and categories.
 * Version:     1.0.0
 * Author:      Ansar
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: quantity-ceiling
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * WC requires at least: 7.0
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'QC_VERSION' ) ) {
	define( 'QC_VERSION', '1.0.0' );
}

if ( ! defined( 'QC_PLUGIN_FILE' ) ) {
	define( 'QC_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'QC_PLUGIN_DIR' ) ) {
	define( 'QC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'QC_PLUGIN_URL' ) ) {
	define( 'QC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

add_action( 'plugins_loaded', 'qc_init_plugin' );

/**
 * Load the plugin once WooCommerce is available.
 */
function qc_init_plugin() {
	if ( ! qc_check_woocommerce_dependency() ) {
		return;
	}

	load_plugin_textdomain( 'quantity-ceiling', false, dirname( plugin_basename( QC_PLUGIN_FILE ) ) . '/languages' );

	require_once QC_PLUGIN_DIR . 'includes/class-qc-frontend.php';

	if ( is_admin() ) {
		require_once QC_PLUGIN_DIR . 'includes/class-qc-admin.php';
		new QC_Admin();
	}

	new QC_Frontend();
}

/**
 * Check whether WooCommerce is active.
 *
 * @return bool
 */
function qc_check_woocommerce_dependency() {
	if ( class_exists( 'WooCommerce' ) ) {
		return true;
	}

	add_action( 'admin_notices', 'qc_woocommerce_missing_notice' );
	return false;
}

/**
 * Show dependency notice when WooCommerce is missing.
 */
function qc_woocommerce_missing_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	echo '<div class="notice notice-error"><p>' . esc_html__( 'Quantity Ceiling requires WooCommerce to be installed and active.', 'quantity-ceiling' ) . '</p></div>';
}
