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
 *
 * Root loader for local zip installs.
 *
 * WordPress.org SVN keeps distributable plugin code in /trunk. The full plugin
 * bootstrap lives there so a release zip generated from trunk has the expected
 * root-level plugin file.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trunk/quantity-ceiling.php';
