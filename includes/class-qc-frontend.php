<?php
/**
 * Frontend display: quantity input min/max/step and the customer notice banner.
 *
 * Never reads options or post meta directly - everything is resolved via
 * QC_Helpers so the Variable-Product-first priority stays enforced in one place.
 *
 * @package QuantityCeiling
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'QC_Frontend' ) ) {
	return;
}

/**
 * Class QC_Frontend
 */
class QC_Frontend {

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

		add_filter( 'woocommerce_quantity_input_args', array( $this, 'apply_quantity_input_args' ), 10, 2 );
		add_action( 'woocommerce_before_add_to_cart_form', array( $this, 'display_quantity_banner' ) );
		add_action( 'woocommerce_before_single_variation', array( $this, 'display_quantity_banner' ) );
		add_action( 'woocommerce_before_add_to_cart_quantity', array( $this, 'display_quantity_limits_row' ) );
		add_filter( 'woocommerce_available_variation', array( $this, 'add_variation_quantity_data' ), 10, 3 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_variation_script' ) );
	}

	/**
	 * Sets min/max/step and a sane default input value on the quantity field.
	 * Simple Products use the Global Rule, Variable Products use their own
	 * Product Edit settings - both resolved through QC_Helpers::get_quantity_rule().
	 *
	 * @param array      $args    Quantity input arguments.
	 * @param WC_Product $product Product instance.
	 * @return array
	 */
	public function apply_quantity_input_args( $args, $product ) {
		if ( ! $product ) {
			return $args;
		}

		$rule = $this->helpers->get_quantity_rule( $product );

		if ( ! $rule ) {
			return $args;
		}

		if ( '' !== $rule['min'] ) {
			$args['min_value'] = $rule['min'];
		}

		if ( '' !== $rule['max'] ) {
			$args['max_value'] = $rule['max'];
		}

		if ( ! empty( $rule['step'] ) ) {
			$args['step'] = $rule['step'];
		}

		if ( isset( $args['min_value'] ) && ( empty( $args['input_value'] ) || $args['input_value'] < $args['min_value'] ) ) {
			$args['input_value'] = $args['min_value'];
		}

		return $args;
	}

	/**
	 * Prints the customer notice banner above the add-to-cart form when a
	 * quantity rule (Global or Variable Product) applies to the current product.
	 *
	 * @return void
	 */
	public function display_quantity_banner() {
		global $product;

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$rule = $this->helpers->get_quantity_rule( $product );

		if ( ! $rule ) {
			return;
		}

		$settings = $this->helpers->get_notice_settings( $product );
		$summary  = $this->helpers->get_limit_summary( $rule );
		$text     = $settings['label'];

		if ( '' !== $summary ) {
			$text .= ' — ' . $summary;
		}

		printf(
			'<div class="qc-quantity-alert-banner" style="background-color:%1$s; color:%2$s; padding:10px 12px; margin-bottom:15px; border-radius:6px; font-weight:700; display:inline-block;">%3$s</div>',
			esc_attr( $settings['bg_color'] ),
			esc_attr( $settings['text_color'] ),
			esc_html( $text )
		);
	}

	/**
	 * Prints a compact row directly beside the quantity input showing the
	 * same notice label plus the actual min/max numbers, so the limit is
	 * visible right where the customer is typing the quantity.
	 *
	 * @return void
	 */
	public function display_quantity_limits_row() {
		global $product;

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$rule = $this->helpers->get_quantity_rule( $product );

		if ( ! $rule ) {
			return;
		}

		$settings = $this->helpers->get_notice_settings( $product );
		$summary  = $this->helpers->get_limit_summary( $rule );
		$text     = $settings['label'];

		if ( '' !== $summary ) {
			$text .= ' — ' . $summary;
		}

		printf(
			'<div id="qc-quantity-limits-row" class="qc-quantity-limits-row" style="margin-bottom:8px; font-size:13px; font-weight:600; color:%1$s;">%2$s</div>',
			esc_attr( $settings['bg_color'] ),
			esc_html( $text )
		);
	}

	/**
	 * Adds this variation's quantity rule to the data WooCommerce sends to
	 * the browser when it is selected, so the frontend JS can apply the
	 * correct min/max/step/label for that exact variation.
	 *
	 * @param array      $data      Variation data sent to the browser.
	 * @param WC_Product $product   Parent Variable Product.
	 * @param WC_Product $variation The variation instance.
	 * @return array
	 */
	public function add_variation_quantity_data( $data, $product, $variation ) {
		unset( $product );

		$rule = $this->helpers->get_quantity_rule( $variation );

		$data['qc_enabled'] = (bool) $rule;
		$data['qc_min_qty'] = $rule ? $rule['min'] : '';
		$data['qc_max_qty'] = $rule ? $rule['max'] : '';
		$data['qc_step']    = $rule ? $rule['step'] : 1;

		$notice                  = $this->helpers->get_notice_settings( $rule ? $variation : null );
		$data['qc_label']        = $rule ? $notice['label'] : '';
		$data['qc_limit_summary'] = $rule ? $this->helpers->get_limit_summary( $rule ) : '';

		return $data;
	}

	/**
	 * Enqueues a small inline script, on single Variable Product pages only,
	 * that listens for WooCommerce's own "found_variation"/"reset_data"
	 * events and applies that variation's own quantity limits and notice
	 * label to the page - each variation independently, never inherited
	 * from siblings or the parent product.
	 *
	 * @return void
	 */
	public function enqueue_variation_script() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		global $post;

		$product = $post ? wc_get_product( $post ) : null;

		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return;
		}

		$settings = $this->helpers->get_notice_settings();

		$default_label = wp_json_encode( $settings['label'] );
		$bg_color      = wp_json_encode( $settings['bg_color'] );
		$text_color    = wp_json_encode( $settings['text_color'] );

		$script = "
		(function ($) {
			var qcDefaultLabel = {$default_label};
			var qcBgColor = {$bg_color};
			var qcTextColor = {$text_color};

			function qcGetQtyInput(\$form) {
				return \$form.closest('form.cart').find('.quantity input.qty, .quantity input[type=number]').first();
			}

			function qcGetBanner(\$form) {
				var \$banner = \$('#qc-variation-banner');

				if (!\$banner.length) {
					\$banner = $('<div id=\"qc-variation-banner\" class=\"qc-quantity-alert-banner\" style=\"padding:10px 12px; margin-bottom:15px; border-radius:6px; font-weight:700; display:none;\"></div>');
					\$form.closest('form.cart').find('.quantity').first().before(\$banner);
				}

				return \$banner;
			}

			function qcGetQtyRow(\$form) {
				var \$row = \$('#qc-quantity-limits-row');

				if (!\$row.length) {
					\$row = $('<div id=\"qc-quantity-limits-row\" class=\"qc-quantity-limits-row\" style=\"margin-bottom:8px; font-size:13px; font-weight:600; display:none;\"></div>');
					\$form.closest('form.cart').find('.quantity').first().before(\$row);
				}

				return \$row;
			}

			\$(document).on('found_variation', '.variations_form', function (event, variation) {
				var \$form = \$(this);
				var \$qty = qcGetQtyInput(\$form);
				var \$banner = qcGetBanner(\$form);
				var \$row = qcGetQtyRow(\$form);

				if (!\$qty.length) {
					return;
				}

				if (variation.qc_enabled) {
					if (variation.qc_min_qty !== '') {
						\$qty.attr('min', variation.qc_min_qty);
						if (parseInt(\$qty.val(), 10) < parseInt(variation.qc_min_qty, 10)) {
							\$qty.val(variation.qc_min_qty);
						}
					} else {
						\$qty.removeAttr('min');
					}

					if (variation.qc_max_qty !== '') {
						\$qty.attr('max', variation.qc_max_qty);
						if (parseInt(\$qty.val(), 10) > parseInt(variation.qc_max_qty, 10)) {
							\$qty.val(variation.qc_max_qty);
						}
					} else {
						\$qty.removeAttr('max');
					}

					\$qty.attr('step', variation.qc_step || 1);

					var label = variation.qc_label && variation.qc_label.length ? variation.qc_label : qcDefaultLabel;
					var summary = variation.qc_limit_summary || '';
					var fullText = summary ? (label + ' — ' + summary) : label;

					\$banner.text(fullText).css({
						backgroundColor: qcBgColor,
						color: qcTextColor
					}).show();

					\$row.text(fullText).css({
						color: qcBgColor
					}).show();
				} else {
					\$qty.removeAttr('min').removeAttr('max').attr('step', '1');
					\$banner.hide();
					\$row.hide();
				}
			});

			\$(document).on('reset_data', '.variations_form', function () {
				var \$form = \$(this);
				var \$qty = qcGetQtyInput(\$form);

				\$qty.removeAttr('min').removeAttr('max').attr('step', '1');
				\$('#qc-variation-banner').hide();
				\$('#qc-quantity-limits-row').hide();
			});
		})(jQuery);
		";

		wp_add_inline_script( 'wc-add-to-cart-variation', $script, 'after' );
	}
}
