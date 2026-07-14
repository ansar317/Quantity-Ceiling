<?php
/**
 * Variations tab: per-variation quantity limit fields.
 *
 * Each variation gets its own independent Enable / Min / Max / Step /
 * Notice label fields, rendered in the same row as its SKU and pricing
 * fields, and saved individually via woocommerce_save_product_variation
 * (which WooCommerce fires once per variation). Nothing here is shared
 * across sibling variations and nothing is stored on the parent product.
 *
 * @package QuantityCeiling
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'QC_Product_Fields' ) ) {
	return;
}

/**
 * Class QC_Product_Fields
 */
class QC_Product_Fields {

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

		add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'render_variation_fields' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation_meta' ), 10, 2 );
	}

	/**
	 * Renders the Quantity Ceiling fields inside one variation's row, in
	 * the same panel where its SKU and pricing are edited.
	 *
	 * @param int     $loop           Position in the variation loop, used to key the field names/ids.
	 * @param array   $variation_data Variation attribute data (unused).
	 * @param WP_Post $variation      The variation post object.
	 * @return void
	 */
	public function render_variation_fields( $loop, $variation_data, $variation ) {
		unset( $variation_data );

		$variation_id = $variation->ID;
		$enabled      = get_post_meta( $variation_id, QC_Helpers::META_ENABLE, true );
		$min          = get_post_meta( $variation_id, QC_Helpers::META_MIN, true );
		$max          = get_post_meta( $variation_id, QC_Helpers::META_MAX, true );
		$step         = get_post_meta( $variation_id, QC_Helpers::META_STEP, true );
		$label        = get_post_meta( $variation_id, QC_Helpers::META_LABEL, true );
		?>
		<div class="qc-variation-fields" style="width:100%; clear:both; padding-top:10px; margin-top:10px; border-top:1px dashed #d5d5d5;">
			<p class="form-row form-row-full options">
				<strong><?php esc_html_e( 'Quantity Ceiling (this variation only)', 'quantity-ceiling' ); ?></strong>
			</p>

			<?php
			woocommerce_wp_checkbox(
				array(
					'id'            => "variable_qc_enable{$loop}",
					'name'          => "_qc_enable[{$loop}]",
					'label'         => __( 'Enable quantity limits', 'quantity-ceiling' ),
					'description'   => __( 'Applies to this exact variation only.', 'quantity-ceiling' ),
					'value'         => $enabled,
					'cbvalue'       => 'yes',
					'wrapper_class' => 'form-row form-row-full',
				)
			);

			woocommerce_wp_text_input(
				array(
					'id'                => "variable_qc_min_qty{$loop}",
					'name'              => "_qc_min_qty[{$loop}]",
					'label'             => __( 'Minimum quantity', 'quantity-ceiling' ),
					'type'              => 'number',
					'custom_attributes' => array(
						'min'  => '1',
						'step' => '1',
					),
					'value'             => $min,
					'wrapper_class'     => 'form-row form-row-first',
				)
			);

			woocommerce_wp_text_input(
				array(
					'id'                => "variable_qc_max_qty{$loop}",
					'name'              => "_qc_max_qty[{$loop}]",
					'label'             => __( 'Maximum quantity', 'quantity-ceiling' ),
					'type'              => 'number',
					'custom_attributes' => array(
						'min'  => '1',
						'step' => '1',
					),
					'value'             => $max,
					'description'       => __( 'Leave blank for no maximum.', 'quantity-ceiling' ),
					'wrapper_class'     => 'form-row form-row-last',
				)
			);

			woocommerce_wp_text_input(
				array(
					'id'                => "variable_qc_step{$loop}",
					'name'              => "_qc_step[{$loop}]",
					'label'             => __( 'Quantity step', 'quantity-ceiling' ),
					'type'              => 'number',
					'custom_attributes' => array(
						'min'  => '1',
						'step' => '1',
					),
					'value'             => ( '' === $step ) ? '1' : $step,
					'description'       => __( 'Customers can only order in multiples of this number, e.g. 2 means 2, 4, 6... Leave as 1 to allow any quantity.', 'quantity-ceiling' ),
					'wrapper_class'     => 'form-row form-row-first',
				)
			);

			woocommerce_wp_text_input(
				array(
					'id'            => "variable_qc_label{$loop}",
					'name'          => "_qc_label[{$loop}]",
					'label'         => __( 'Notice label (optional)', 'quantity-ceiling' ),
					'value'         => $label,
					'description'   => __( 'Overrides the global notice text for this variation only. Leave blank to use the default.', 'quantity-ceiling' ),
					'wrapper_class' => 'form-row form-row-last',
				)
			);
			?>
		</div>
		<?php
	}

	/**
	 * Saves one variation's Quantity Ceiling meta. Fired once per variation
	 * by WooCommerce, so each variation is written independently.
	 *
	 * @param int $variation_id Variation post ID.
	 * @param int $loop         Position in the variation loop (matches the posted field indexes).
	 * @return void
	 */
	public function save_variation_meta( $variation_id, $loop ) {
		if ( ! current_user_can( 'edit_product', $variation_id ) ) {
			return;
		}

		$enable = isset( $_POST['_qc_enable'][ $loop ] ) ? 'yes' : 'no';
		update_post_meta( $variation_id, QC_Helpers::META_ENABLE, $enable );

		$min = isset( $_POST['_qc_min_qty'][ $loop ] ) ? sanitize_text_field( wp_unslash( $_POST['_qc_min_qty'][ $loop ] ) ) : '';
		$min = ( '' === $min ) ? '' : max( 1, absint( $min ) );
		update_post_meta( $variation_id, QC_Helpers::META_MIN, $min );

		$max = isset( $_POST['_qc_max_qty'][ $loop ] ) ? sanitize_text_field( wp_unslash( $_POST['_qc_max_qty'][ $loop ] ) ) : '';
		$max = ( '' === $max ) ? '' : absint( $max );

		if ( '' !== $max ) {
			$min_for_compare = ( '' === $min ) ? 1 : $min;
			$max             = max( $min_for_compare, $max );
		}

		update_post_meta( $variation_id, QC_Helpers::META_MAX, $max );

		$step = isset( $_POST['_qc_step'][ $loop ] ) ? absint( wp_unslash( $_POST['_qc_step'][ $loop ] ) ) : 1;
		update_post_meta( $variation_id, QC_Helpers::META_STEP, max( 1, $step ) );

		$label = isset( $_POST['_qc_label'][ $loop ] ) ? sanitize_text_field( wp_unslash( $_POST['_qc_label'][ $loop ] ) ) : '';
		update_post_meta( $variation_id, QC_Helpers::META_LABEL, $label );
	}
}
