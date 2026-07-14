<?php
/**
 * Cart and checkout quantity behaviour.
 *
 * Owns auto-correction of out-of-range cart quantities (cart page and
 * checkout, via woocommerce_check_cart_items) and blocks manual quantity
 * updates on the cart page. Rule lookup goes through QC_Helpers and the
 * actual pass/fail check is delegated to QC_Validation::validate_quantity()
 * so the comparison logic is never duplicated.
 *
 * @package QuantityCeiling
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'QC_Cart' ) ) {
	return;
}

/**
 * Class QC_Cart
 */
class QC_Cart {

	/**
	 * Helpers instance.
	 *
	 * @var QC_Helpers
	 */
	private $helpers;

	/**
	 * Validation instance.
	 *
	 * @var QC_Validation
	 */
	private $validation;

	/**
	 * Constructor.
	 *
	 * @param QC_Helpers    $helpers    Shared helpers instance.
	 * @param QC_Validation $validation Shared validation instance.
	 */
	public function __construct( QC_Helpers $helpers, QC_Validation $validation ) {
		$this->helpers    = $helpers;
		$this->validation = $validation;

		add_action( 'woocommerce_check_cart_items', array( $this, 'adjust_cart_quantities' ) );
		add_filter( 'woocommerce_update_cart_validation', array( $this, 'validate_cart_update' ), 10, 4 );
	}

	/**
	 * Auto-corrects cart quantities that fall outside the applicable rule.
	 * Runs on the Cart page and at Checkout.
	 *
	 * @return void
	 */
	public function adjust_cart_quantities() {
		if ( ! WC()->cart ) {
			return;
		}

		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			$product_id = $this->helpers->get_cart_item_product_id( $cart_item );

			if ( ! $product_id ) {
				continue;
			}

			$rule = $this->helpers->get_quantity_rule( $product_id );

			if ( ! $rule ) {
				continue;
			}

			$quantity = absint( $cart_item['quantity'] );
			$name     = $this->helpers->get_product_display_name( $product_id );

			if ( '' !== $rule['min'] && $quantity < $rule['min'] ) {
				wc_add_notice(
					sprintf(
						/* translators: 1: product name, 2: minimum quantity. */
						__( 'The quantity for "%1$s" has been adjusted to meet the minimum item purchase rule of %2$d.', 'quantity-ceiling' ),
						$name,
						$rule['min']
					),
					'error'
				);

				WC()->cart->set_quantity( $cart_item_key, $rule['min'] );
			} elseif ( '' !== $rule['max'] && $quantity > $rule['max'] ) {
				wc_add_notice(
					sprintf(
						/* translators: 1: product name, 2: maximum quantity. */
						__( 'The quantity for "%1$s" exceeded the allowed threshold limits. Reset to safety limit of %2$d.', 'quantity-ceiling' ),
						$name,
						$rule['max']
					),
					'error'
				);

				WC()->cart->set_quantity( $cart_item_key, $rule['max'] );
			}
		}
	}

	/**
	 * Blocks manual quantity updates on the Cart page that violate the rule.
	 *
	 * @param bool   $passed        Whether the update may proceed.
	 * @param string $cart_item_key Cart item key.
	 * @param array  $values        Cart item data.
	 * @param int    $quantity      Requested new quantity.
	 * @return bool
	 */
	public function validate_cart_update( $passed, $cart_item_key, $values, $quantity ) {
		unset( $cart_item_key );

		$product_id = $this->helpers->get_cart_item_product_id( $values );

		if ( ! $product_id ) {
			return $passed;
		}

		$result = $this->validation->validate_quantity( $product_id, $quantity );

		if ( is_wp_error( $result ) ) {
			wc_add_notice( $result->get_error_message(), 'error' );

			return false;
		}

		return $passed;
	}
}
