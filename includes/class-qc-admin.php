<?php
/**
 * Admin: Global Rules settings page.
 *
 * Handles only the settings screen (menu, settings registration, the Simple
 * Product / Category selectors, and admin assets). Never touches product
 * meta, frontend output, validation, or cart logic.
 *
 * @package QuantityCeiling
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'QC_Admin' ) ) {
	return;
}

/**
 * Class QC_Admin
 */
class QC_Admin {

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

		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_ajax_qc_search_simple_products', array( $this, 'ajax_search_simple_products' ) );
	}

	/**
	 * Registers the top level admin menu page.
	 *
	 * @return void
	 */
	public function add_admin_menu() {
		add_menu_page(
			__( 'Quantity Ceiling Settings', 'quantity-ceiling' ),
			__( 'Quantity Ceiling', 'quantity-ceiling' ),
			'manage_woocommerce',
			'quantity-ceiling-settings',
			array( $this, 'render_settings_page' ),
			'dashicons-chart-bar',
			56
		);
	}

	/**
	 * Enqueues admin CSS/JS only on the plugin settings screen.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook ) {
		$is_settings_screen = ( 'toplevel_page_quantity-ceiling-settings' === $hook );
		$is_product_screen  = in_array( $hook, array( 'post.php', 'post-new.php' ), true )
			&& isset( $_GET['post'] ) && 'product' === get_post_type( absint( wp_unslash( $_GET['post'] ) ) );
		$is_new_product     = ( 'post-new.php' === $hook ) && isset( $_GET['post_type'] ) && 'product' === $_GET['post_type'];

		if ( ! $is_settings_screen && ! $is_product_screen && ! $is_new_product ) {
			return;
		}

		wp_enqueue_style( 'qc-admin-style', QC_PLUGIN_URL . 'assets/css/qc-admin.css', array(), QC_VERSION );

		if ( ! $is_settings_screen ) {
			return;
		}

		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_script( 'wc-enhanced-select' );

		$inline_script = "jQuery(function($) {
			$('.qc-enhanced-select').selectWoo({
				width: '100%',
				placeholder: function() {
					return $(this).data('placeholder');
				}
			});

			$('#qc_products').selectWoo({
				width: '100%',
				allowClear: true,
				placeholder: $('#qc_products').data('placeholder'),
				minimumInputLength: 2,
				ajax: {
					url: ajaxurl,
					dataType: 'json',
					delay: 250,
					data: function( params ) {
						return {
							term: params.term,
							action: 'qc_search_simple_products',
							security: wc_enhanced_select_params.search_products_nonce
						};
					},
					processResults: function( data ) {
						var terms = [];
						if ( data ) {
							$.each( data, function( id, text ) {
								terms.push( { id: id, text: text } );
							} );
						}
						return { results: terms };
					},
					cache: true
				}
			});

			$('.qc-preview-source').on('input change', function() {
				$('.qc-preview').text($('#qc_label_text').val());
				$('.qc-preview').css({
					backgroundColor: $('#qc_bg_color').val(),
					color: $('#qc_text_color').val()
				});
			});
		});";

		wp_add_inline_script( 'wc-enhanced-select', $inline_script );
	}

	/**
	 * AJAX handler powering the Simple Product selector. Excludes Variable
	 * Products, variations, Grouped Products, and External/Affiliate products.
	 *
	 * @return void
	 */
	public function ajax_search_simple_products() {
		check_ajax_referer( 'search-products', 'security' );

		if ( ! current_user_can( 'edit_products' ) ) {
			wp_die( -1 );
		}

		$term = isset( $_GET['term'] ) ? wc_clean( wp_unslash( $_GET['term'] ) ) : '';

		if ( '' === $term ) {
			wp_die();
		}

		$data_store = WC_Data_Store::load( 'product' );
		$ids        = $data_store->search_products( $term, '', false, false, 30 );

		$results = array();

		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );

			if ( ! $product || ! $product->is_type( 'simple' ) ) {
				continue;
			}

			$results[ $product->get_id() ] = wp_strip_all_tags( $product->get_formatted_name() );
		}

		wp_send_json( $results );
	}

	/**
	 * Registers all plugin settings. Option names are kept unchanged for
	 * backwards compatibility with existing installs.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'qc_settings_group',
			'qc_min_qty',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_positive_integer' ),
				'default'           => 1,
			)
		);

		register_setting(
			'qc_settings_group',
			'qc_max_qty',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_max_quantity' ),
				'default'           => '',
			)
		);

		register_setting(
			'qc_settings_group',
			'qc_label_text',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'Limits apply to this item',
			)
		);

		register_setting(
			'qc_settings_group',
			'qc_text_color',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_text_color' ),
				'default'           => '#ffffff',
			)
		);

		register_setting(
			'qc_settings_group',
			'qc_bg_color',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_background_color' ),
				'default'           => '#2271b1',
			)
		);

		register_setting(
			'qc_settings_group',
			'qc_products',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_simple_product_ids' ),
				'default'           => array(),
			)
		);

		register_setting(
			'qc_settings_group',
			'qc_categories',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_id_array' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Sanitizes a required positive integer, floored at 1.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public function sanitize_positive_integer( $value ) {
		return max( 1, absint( $value ) );
	}

	/**
	 * Sanitizes an optional positive integer, allowing an empty value.
	 *
	 * @param mixed $value Raw value.
	 * @return int|string
	 */
	public function sanitize_optional_positive_integer( $value ) {
		if ( '' === $value || null === $value ) {
			return '';
		}

		return max( 1, absint( $value ) );
	}

	/**
	 * Sanitizes the maximum quantity, ensuring it never falls below the minimum.
	 *
	 * @param mixed $value Raw value.
	 * @return int|string
	 */
	public function sanitize_max_quantity( $value ) {
		$value = $this->sanitize_optional_positive_integer( $value );

		if ( '' === $value ) {
			return '';
		}

		$posted_min_qty = filter_input( INPUT_POST, 'qc_min_qty', FILTER_SANITIZE_NUMBER_INT );
		$min_qty        = $this->sanitize_positive_integer( null === $posted_min_qty ? get_option( 'qc_min_qty', 1 ) : $posted_min_qty );

		return max( $min_qty, $value );
	}

	/**
	 * Sanitizes the notice text color, falling back to the default.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_text_color( $value ) {
		$color = sanitize_hex_color( $value );

		return $color ? $color : '#ffffff';
	}

	/**
	 * Sanitizes the notice background color, falling back to the default.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_background_color( $value ) {
		$color = sanitize_hex_color( $value );

		return $color ? $color : '#2271b1';
	}

	/**
	 * Sanitizes a generic array of post/term IDs.
	 *
	 * @param mixed $value Raw value.
	 * @return int[]
	 */
	public function sanitize_id_array( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'absint', $value ) ) );
	}

	/**
	 * Sanitizes the Simple Product selector, silently dropping any ID that
	 * is not a Simple Product (defense in depth against tampered requests).
	 *
	 * @param mixed $value Raw value.
	 * @return int[]
	 */
	public function sanitize_simple_product_ids( $value ) {
		$ids = $this->sanitize_id_array( $value );

		return array_values(
			array_filter(
				$ids,
				array( $this->helpers, 'is_simple_product' )
			)
		);
	}

	/**
	 * Renders the settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		$min_qty             = get_option( 'qc_min_qty', 1 );
		$max_qty             = get_option( 'qc_max_qty', '' );
		$label_text          = get_option( 'qc_label_text', 'Limits apply to this item' );
		$text_color          = get_option( 'qc_text_color', '#ffffff' );
		$bg_color            = get_option( 'qc_bg_color', '#2271b1' );
		$selected_products   = array_map( 'absint', (array) get_option( 'qc_products', array() ) );
		$selected_categories = array_map( 'absint', (array) get_option( 'qc_categories', array() ) );
		?>
		<div class="wrap qc-settings-wrap">
			<div class="qc-page-header">
				<div>
					<h1><?php esc_html_e( 'Quantity Ceiling', 'quantity-ceiling' ); ?></h1>
					<p><?php esc_html_e( 'Set minimum and maximum purchase quantities for selected WooCommerce products or categories.', 'quantity-ceiling' ); ?></p>
				</div>
				<span class="qc-version"><?php echo esc_html( 'v' . QC_VERSION ); ?></span>
			</div>

			<form method="post" action="options.php" class="qc-settings-form">
				<?php settings_fields( 'qc_settings_group' ); ?>

				<div class="qc-settings-grid">
					<section class="qc-panel">
						<div class="qc-panel-header">
							<h2><?php esc_html_e( 'Global Quantity Rules', 'quantity-ceiling' ); ?></h2>
							<p><?php esc_html_e( 'Define the allowed purchase range for Simple Products covered by the selectors below.', 'quantity-ceiling' ); ?></p>
						</div>

						<div class="qc-field-row qc-field-row-split">
							<div class="qc-field">
								<label for="qc_min_qty"><?php esc_html_e( 'Minimum quantity', 'quantity-ceiling' ); ?></label>
								<input type="number" id="qc_min_qty" name="qc_min_qty" value="<?php echo esc_attr( $min_qty ); ?>" min="1" />
								<p><?php esc_html_e( 'Smallest quantity accepted on product and cart pages.', 'quantity-ceiling' ); ?></p>
							</div>

							<div class="qc-field">
								<label for="qc_max_qty"><?php esc_html_e( 'Maximum quantity', 'quantity-ceiling' ); ?></label>
								<input type="number" id="qc_max_qty" name="qc_max_qty" value="<?php echo esc_attr( $max_qty ); ?>" min="1" placeholder="<?php esc_attr_e( 'No limit', 'quantity-ceiling' ); ?>" />
								<p><?php esc_html_e( 'Leave blank when there is no upper limit.', 'quantity-ceiling' ); ?></p>
							</div>
						</div>

						<div class="qc-rule-summary">
							<span><?php esc_html_e( 'Current rule', 'quantity-ceiling' ); ?></span>
							<strong>
								<?php
								if ( '' === $max_qty ) {
									printf(
										/* translators: %d: minimum quantity. */
										esc_html__( 'Minimum %d, no maximum', 'quantity-ceiling' ),
										absint( $min_qty )
									);
								} else {
									printf(
										/* translators: 1: minimum quantity, 2: maximum quantity. */
										esc_html__( 'Minimum %1$d, maximum %2$d', 'quantity-ceiling' ),
										absint( $min_qty ),
										absint( $max_qty )
									);
								}
								?>
							</strong>
						</div>
					</section>

					<section class="qc-panel">
						<div class="qc-panel-header">
							<h2><?php esc_html_e( 'Product Selection', 'quantity-ceiling' ); ?></h2>
							<p><?php esc_html_e( 'Choose exactly where the Global Rule should apply. Only Simple Products can be selected here.', 'quantity-ceiling' ); ?></p>
						</div>

						<div class="qc-field-row">
							<div class="qc-field">
								<label for="qc_products"><?php esc_html_e( 'Target Simple Products', 'quantity-ceiling' ); ?></label>
								<input type="hidden" name="qc_products[]" value="" />
								<select
									id="qc_products"
									name="qc_products[]"
									class="qc-enhanced-select"
									multiple="multiple"
									data-placeholder="<?php esc_attr_e( 'Search Simple Products', 'quantity-ceiling' ); ?>"
								>
									<?php
									foreach ( $selected_products as $product_id ) {
										$product = wc_get_product( $product_id );

										if ( ! $product || ! $product->is_type( 'simple' ) ) {
											continue;
										}

										printf(
											'<option value="%1$d" selected="selected">%2$s</option>',
											esc_attr( $product_id ),
											esc_html( $product->get_formatted_name() )
										);
									}
									?>
								</select>
								<p><?php esc_html_e( 'Variable Products and variations never appear here - they are configured on their own Product Edit page.', 'quantity-ceiling' ); ?></p>
							</div>
						</div>

						<div class="qc-field-row">
							<div class="qc-field">
								<label for="qc_categories"><?php esc_html_e( 'Target categories', 'quantity-ceiling' ); ?></label>
								<input type="hidden" name="qc_categories[]" value="" />
								<select id="qc_categories" name="qc_categories[]" class="qc-enhanced-select" multiple="multiple" data-placeholder="<?php esc_attr_e( 'Search or select categories', 'quantity-ceiling' ); ?>">
									<?php
									$categories = get_terms(
										array(
											'taxonomy'   => 'product_cat',
											'hide_empty' => false,
										)
									);

									if ( ! is_wp_error( $categories ) ) {
										foreach ( $categories as $cat ) {
											printf(
												'<option value="%1$d" %2$s>%3$s</option>',
												esc_attr( $cat->term_id ),
												selected( in_array( $cat->term_id, $selected_categories, true ), true, false ),
												esc_html( $cat->name )
											);
										}
									}
									?>
								</select>
								<p><?php esc_html_e( 'Applies to Simple Products in these categories only. Variable Products in a selected category still use their own Product Edit settings.', 'quantity-ceiling' ); ?></p>
							</div>
						</div>
					</section>

					<section class="qc-panel">
						<div class="qc-panel-header">
							<h2><?php esc_html_e( 'Customer Notice', 'quantity-ceiling' ); ?></h2>
							<p><?php esc_html_e( 'Customize the message shown above the add-to-cart form.', 'quantity-ceiling' ); ?></p>
						</div>

						<div class="qc-field-row">
							<div class="qc-field">
								<label for="qc_label_text"><?php esc_html_e( 'Notice text', 'quantity-ceiling' ); ?></label>
								<input type="text" id="qc_label_text" name="qc_label_text" class="qc-preview-source" value="<?php echo esc_attr( $label_text ); ?>" />
							</div>
						</div>

						<div class="qc-field-row qc-field-row-split">
							<div class="qc-field qc-color-field">
								<label for="qc_text_color"><?php esc_html_e( 'Text color', 'quantity-ceiling' ); ?></label>
								<input type="color" id="qc_text_color" name="qc_text_color" class="qc-preview-source" value="<?php echo esc_attr( $text_color ); ?>" />
							</div>

							<div class="qc-field qc-color-field">
								<label for="qc_bg_color"><?php esc_html_e( 'Background color', 'quantity-ceiling' ); ?></label>
								<input type="color" id="qc_bg_color" name="qc_bg_color" class="qc-preview-source" value="<?php echo esc_attr( $bg_color ); ?>" />
							</div>
						</div>

						<div class="qc-preview-box">
							<span><?php esc_html_e( 'Preview', 'quantity-ceiling' ); ?></span>
							<div class="qc-preview" style="background-color: <?php echo esc_attr( $bg_color ); ?>; color: <?php echo esc_attr( $text_color ); ?>;">
								<?php echo esc_html( $label_text ); ?>
							</div>
						</div>
					</section>
				</div>

				<div class="qc-actions">
					<?php submit_button( __( 'Save Quantity Rules', 'quantity-ceiling' ), 'primary', 'submit', false ); ?>
				</div>
			</form>
		</div>
		<?php
	}
}
