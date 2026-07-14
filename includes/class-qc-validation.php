<?php
/**
 * Centralized quantity validation.
 *
 * validate_quantity() is the ONE method every entry point (add to cart,
 * AJAX add to cart, manual cart quantity updates) calls. No other class
 * re-implements min/max comparison logic.
 *
 * @package QuantityCeiling
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'QC_Validation' ) ) {
	return;
}

/**
 * Class QC_Validation
 */
class QC_Validation {

	/**
	 * Helpers instance.
	 *
	 * @var QC_Helpers
	 */
	private $helpers;

	/**
	 * Constructor.
	 *
	 * @param QC_Helpers $helpers Shared helpers instance.
	 */
	public function __construct( QC_Helpers $helpers ) {
		$this->helpers = $helpers;

		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), 10, 5 );
	}

	/**
	 * The single centralized quantity check used everywhere in the plugin.
	 *
	 * @param int|WC_Product $product  Product ID or instance (simple, variable, or variation).
	 * @param int|float       $quantity Requested quantity.
	 * @return true|WP_Error True when the quantity is allowed, WP_Error otherwise.
	 */
	public function validate_quantity( $product, $quantity ) {
		$rule = $this->helpers->get_quantity_rule( $product );

		if ( ! $rule ) {
			return true;
		}

		$quantity = absint( $quantity );
		$name     = $this->helpers->get_product_display_name( $product );

		if ( '' !== $rule['min'] && $quantity < $rule['min'] ) {
			return new WP_Error(
				'qc_min_qty',
				sprintf(
					/* translators: 1: minimum quantity, 2: product name. */
					__( 'You must add a minimum of %1$d items of %2$s to the cart.', 'quantity-ceiling' ),
					$rule['min'],
					$name
				)
			);
		}

		if ( '' !== $rule['max'] && $quantity > $rule['max'] ) {
			return new WP_Error(
				'qc_max_qty',
				sprintf(
					/* translators: 1: maximum quantity, 2: product name. */
					__( 'You cannot add more than %1$d items of %2$s to your cart.', 'quantity-ceiling' ),
					$rule['max'],
					$name
				)
			);
		}

		return true;
	}

	/**
	 * Blocks add-to-cart (product page and AJAX) when the quantity is invalid.
	 *
	 * @param bool $passed          Whether the item may be added.
	 * @param int  $product_id      Product ID.
	 * @param int  $quantity        Requested quantity.
	 * @param int  $variation_id    Variation ID, when applicable.
	 * @param array $variation_data Variation attributes, when applicable.
	 * @return bool
	 */
	public function validate_add_to_cart( $passed, $product_id, $quantity, $variation_id = 0, $variation_data = array() ) {
		unset( $variation_data );

		$target = $variation_id ? absint( $variation_id ) : absint( $product_id );
		$result = $this->validate_quantity( $target, $quantity );

		if ( is_wp_error( $result ) ) {
			wc_add_notice( $result->get_error_message(), 'error' );

			return false;
		}

		return $passed;
	}
}
