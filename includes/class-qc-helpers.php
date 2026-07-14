<?php
/**
 * Shared, reusable lookups used by every other class in the plugin.
 *
 * No other class in this plugin is allowed to read the qc_* options or the
 * _qc_* post meta directly - everything routes through here so the
 * "Variable Product beats Global Rules beats Category Rules" priority is
 * enforced in exactly one place.
 *
 * @package QuantityCeiling
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'QC_Helpers' ) ) {
	return;
}

/**
 * Class QC_Helpers
 */
class QC_Helpers {

	/**
	 * Post meta key: whether quantity limits are enabled for a Variable Product.
	 *
	 * @var string
	 */
	const META_ENABLE = '_qc_enable';

	/**
	 * Post meta key: minimum quantity for a Variable Product.
	 *
	 * @var string
	 */
	const META_MIN = '_qc_min_qty';

	/**
	 * Post meta key: maximum quantity for a Variable Product.
	 *
	 * @var string
	 */
	const META_MAX = '_qc_max_qty';

	/**
	 * Post meta key: quantity step for a Variable Product.
	 *
	 * @var string
	 */
	const META_STEP = '_qc_step';

	/**
	 * Post meta key: optional per-variation notice label override.
	 *
	 * @var string
	 */
	const META_LABEL = '_qc_label';

	/**
	 * Cached, sanitized list of globally selected simple product IDs.
	 *
	 * @var int[]|null
	 */
	private $allowed_products = null;

	/**
	 * Cached, sanitized list of globally selected category term IDs.
	 *
	 * @var int[]|null
	 */
	private $allowed_categories = null;

	/**
	 * Resolves a product ID or WC_Product into a WC_Product instance.
	 *
	 * @param int|WC_Product $product Product ID or instance.
	 * @return WC_Product|false
	 */
	public function resolve_product( $product ) {
		if ( $product instanceof WC_Product ) {
			return $product;
		}

		if ( is_numeric( $product ) ) {
			return wc_get_product( absint( $product ) );
		}

		return false;
	}

	/**
	 * Gets the globally selected Simple Product IDs.
	 *
	 * @return int[]
	 */
	public function get_allowed_products() {
		if ( null === $this->allowed_products ) {
			$this->allowed_products = array_map( 'absint', (array) get_option( 'qc_products', array() ) );
		}

		return $this->allowed_products;
	}

	/**
	 * Gets the globally selected category term IDs.
	 *
	 * @return int[]
	 */
	public function get_allowed_categories() {
		if ( null === $this->allowed_categories ) {
			$this->allowed_categories = array_map( 'absint', (array) get_option( 'qc_categories', array() ) );
		}

		return $this->allowed_categories;
	}

	/**
	 * Determines whether a product is a Variable Product, or a variation
	 * belonging to one. Variable Products never use Global/Category rules.
	 *
	 * @param int|WC_Product $product Product ID or instance.
	 * @return bool
	 */
	public function is_variable_context( $product ) {
		$product = $this->resolve_product( $product );

		if ( ! $product ) {
			return false;
		}

		if ( $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );

			return $parent && $parent->is_type( 'variable' );
		}

		return $product->is_type( 'variable' );
	}

	/**
	 * Determines whether a product is a Simple Product.
	 *
	 * @param int|WC_Product $product Product ID or instance.
	 * @return bool
	 */
	public function is_simple_product( $product ) {
		$product = $this->resolve_product( $product );

		return $product && $product->is_type( 'simple' );
	}

	/**
	 * Checks whether a product ID is part of the Global Selected Products list.
	 *
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	public function product_has_global_rule( $product_id ) {
		return in_array( absint( $product_id ), $this->get_allowed_products(), true );
	}

	/**
	 * Checks whether a product ID belongs to a Global Selected Category.
	 *
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	public function product_has_category_rule( $product_id ) {
		$categories = $this->get_allowed_categories();

		if ( empty( $categories ) ) {
			return false;
		}

		return (bool) has_term( $categories, 'product_cat', absint( $product_id ) );
	}

	/**
	 * Gets the Global Quantity Rule.
	 *
	 * @return array{min:int|string,max:int|string,step:int,source:string}
	 */
	public function get_global_rules() {
		$min = absint( get_option( 'qc_min_qty', 1 ) );
		$max = get_option( 'qc_max_qty', '' );
		$max = ( '' === $max ) ? '' : absint( $max );

		return array(
			'min'    => $min,
			'max'    => $max,
			'step'   => 1,
			'source' => 'global',
		);
	}

	/**
	 * Gets the Quantity Rule stored on one individual variation. Each
	 * variation of a Variable Product carries its own independent limits -
	 * they are never shared with, or inherited from, sibling variations.
	 * Returns false when limits are not enabled for that specific variation.
	 *
	 * @param int $variation_id Variation post ID.
	 * @return array{min:int|string,max:int|string,step:int,source:string}|false
	 */
	public function get_variation_rules( $variation_id ) {
		$variation_id = absint( $variation_id );

		if ( ! $variation_id ) {
			return false;
		}

		$enabled = get_post_meta( $variation_id, self::META_ENABLE, true );

		if ( 'yes' !== $enabled ) {
			return false;
		}

		$min  = get_post_meta( $variation_id, self::META_MIN, true );
		$max  = get_post_meta( $variation_id, self::META_MAX, true );
		$step = get_post_meta( $variation_id, self::META_STEP, true );

		return array(
			'min'    => ( '' === $min ) ? '' : absint( $min ),
			'max'    => ( '' === $max ) ? '' : absint( $max ),
			'step'   => ( '' !== $step && null !== $step ) ? max( 1, absint( $step ) ) : 1,
			'source' => 'variation',
		);
	}

	/**
	 * The single entry point every other class must use to resolve which
	 * quantity rule (if any) applies to a product. Enforces the mandatory
	 * priority: a specific variation's own settings ALWAYS win and
	 * Global/Category rules are never consulted for Variable Products,
	 * their variations, or the parent Variable Product itself (which has
	 * no rule of its own - only its individual variations do).
	 *
	 * @param int|WC_Product $product Product ID or instance (may be a variation).
	 * @return array{min:int|string,max:int|string,step:int,source:string}|false
	 */
	public function get_quantity_rule( $product ) {
		$product = $this->resolve_product( $product );

		if ( ! $product ) {
			return false;
		}

		if ( $product->is_type( 'variation' ) ) {
			return $this->get_variation_rules( $product->get_id() );
		}

		if ( $this->is_variable_context( $product ) ) {
			// The parent Variable Product itself never carries a rule -
			// only its individual variations do.
			return false;
		}

		$product_id = $product->get_id();

		if ( $this->product_has_global_rule( $product_id ) || $this->product_has_category_rule( $product_id ) ) {
			return $this->get_global_rules();
		}

		return false;
	}

	/**
	 * Alias of get_quantity_rule() for readability at call sites.
	 *
	 * @param int|WC_Product $product Product ID or instance.
	 * @return array{min:int|string,max:int|string,step:int,source:string}|false
	 */
	public function get_product_rules( $product ) {
		return $this->get_quantity_rule( $product );
	}

	/**
	 * Gets a human readable product name for notices.
	 *
	 * @param int|WC_Product $product Product ID or instance.
	 * @return string
	 */
	public function get_product_display_name( $product ) {
		$product = $this->resolve_product( $product );

		return $product ? $product->get_name() : __( 'this product', 'quantity-ceiling' );
	}

	/**
	 * Extracts the effective product ID (variation ID when present) from a cart item.
	 *
	 * @param array $cart_item WooCommerce cart item.
	 * @return int
	 */
	public function get_cart_item_product_id( $cart_item ) {
		if ( ! empty( $cart_item['variation_id'] ) ) {
			return absint( $cart_item['variation_id'] );
		}

		if ( ! empty( $cart_item['product_id'] ) ) {
			return absint( $cart_item['product_id'] );
		}

		return 0;
	}

	/**
	 * Formats a human-readable "Min X / Max Y" summary for a resolved rule.
	 * Used next to the quantity input and in the notice banner so customers
	 * see the actual numbers, not just the generic notice text.
	 *
	 * @param array{min:int|string,max:int|string}|false $rule Resolved quantity rule.
	 * @return string Empty string when there is nothing to show.
	 */
	public function get_limit_summary( $rule ) {
		if ( ! $rule ) {
			return '';
		}

		$has_min = ( '' !== $rule['min'] );
		$has_max = ( '' !== $rule['max'] );

		if ( $has_min && $has_max ) {
			return sprintf(
				/* translators: 1: minimum quantity, 2: maximum quantity. */
				__( 'Min %1$d / Max %2$d', 'quantity-ceiling' ),
				$rule['min'],
				$rule['max']
			);
		}

		if ( $has_min ) {
			return sprintf(
				/* translators: %d: minimum quantity. */
				__( 'Min %d', 'quantity-ceiling' ),
				$rule['min']
			);
		}

		if ( $has_max ) {
			return sprintf(
				/* translators: %d: maximum quantity. */
				__( 'Max %d', 'quantity-ceiling' ),
				$rule['max']
			);
		}

		return '';
	}

	/**
	 * Gets the customer notice banner display settings. Colors are always
	 * global. The label uses a variation's own override text when one has
	 * been set for it, otherwise it falls back to the global notice text.
	 *
	 * @param int|WC_Product|null $product Optional variation to check for a label override.
	 * @return array{label:string,text_color:string,bg_color:string}
	 */
	public function get_notice_settings( $product = null ) {
		$label = get_option( 'qc_label_text', 'Limits apply to this item' );

		if ( null !== $product ) {
			$resolved = $this->resolve_product( $product );

			if ( $resolved && $resolved->is_type( 'variation' ) ) {
				$override = get_post_meta( $resolved->get_id(), self::META_LABEL, true );

				if ( '' !== $override ) {
					$label = $override;
				}
			}
		}

		return array(
			'label'      => $label,
			'text_color' => get_option( 'qc_text_color', '#ffffff' ),
			'bg_color'   => get_option( 'qc_bg_color', '#2271b1' ),
		);
	}
}
