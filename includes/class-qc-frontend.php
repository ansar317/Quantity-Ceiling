<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( class_exists( 'QC_Frontend' ) ) {
    return;
}

class QC_Frontend {

    private $allowed_products = null;
    private $allowed_categories = null;
    private $quantity_rules = null;

    public function __construct() {
        add_filter( 'woocommerce_quantity_input_args', array( $this, 'apply_quantity_input_args' ), 10, 2 );
        add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), 10, 5 );
        add_action( 'woocommerce_check_cart_items', array( $this, 'validate_cart_contents' ) );
        add_action( 'woocommerce_before_add_to_cart_form', array( $this, 'display_custom_quantity_banner' ) );
    }

    private function get_allowed_products() {
        if ( null === $this->allowed_products ) {
            $this->allowed_products = array_map( 'absint', (array) get_option( 'qc_products', array() ) );
        }

        return $this->allowed_products;
    }

    private function get_allowed_categories() {
        if ( null === $this->allowed_categories ) {
            $this->allowed_categories = array_map( 'absint', (array) get_option( 'qc_categories', array() ) );
        }

        return $this->allowed_categories;
    }

    private function should_apply_rules( $product_id ) {
        $allowed_products   = $this->get_allowed_products();
        $allowed_categories = $this->get_allowed_categories();
        $product_id         = absint( $product_id );
        $product            = wc_get_product( $product_id );
        $parent_id          = $product && $product->is_type( 'variation' ) ? absint( $product->get_parent_id() ) : 0;
        $product_ids        = array_filter( array( $product_id, $parent_id ) );

        if ( empty( $allowed_products ) && empty( $allowed_categories ) ) {
            return false;
        }

        if ( array_intersect( $product_ids, $allowed_products ) ) {
            return true;
        }

        foreach ( $product_ids as $target_product_id ) {
            if ( ! empty( $allowed_categories ) && has_term( $allowed_categories, 'product_cat', $target_product_id ) ) {
                return true;
            }
        }

        return false;
    }

    private function get_quantity_rules() {
        if ( null !== $this->quantity_rules ) {
            return $this->quantity_rules;
        }

        $min_qty = absint( get_option( 'qc_min_qty', 1 ) );
        $max_qty = get_option( 'qc_max_qty', '' );
        $max_qty = '' === $max_qty ? '' : absint( $max_qty );

        $this->quantity_rules = array(
            'min' => $min_qty,
            'max' => $max_qty,
        );

        return $this->quantity_rules;
    }

    private function get_product_display_name( $product_id ) {
        $product = wc_get_product( $product_id );

        if ( $product ) {
            return $product->get_name();
        }

        return __( 'this product', 'quantity-ceiling' );
    }

    private function get_cart_product_id( $cart_item ) {
        if ( ! empty( $cart_item['variation_id'] ) ) {
            return absint( $cart_item['variation_id'] );
        }

        if ( ! empty( $cart_item['product_id'] ) ) {
            return absint( $cart_item['product_id'] );
        }

        return 0;
    }

    public function apply_quantity_input_args( $args, $product ) {
        if ( ! $product || ! $this->should_apply_rules( $product->get_id() ) ) {
            return $args;
        }

        $rules = $this->get_quantity_rules();

        if ( ! empty( $rules['min'] ) ) {
            $args['min_value'] = $rules['min'];
        }

        if ( ! empty( $rules['max'] ) ) {
            $args['max_value'] = $rules['max'];
        }

        return $args;
    }

    public function display_custom_quantity_banner() {
        global $product;

        if ( ! $product || ! $this->should_apply_rules( $product->get_id() ) ) {
            return;
        }

        $label_text = get_option( 'qc_label_text', 'Limits apply to this item' );
        $text_color = get_option( 'qc_text_color', '#ffffff' );
        $bg_color   = get_option( 'qc_bg_color', '#2271b1' );

        printf(
            '<div class="qc-quantity-alert-banner" style="background-color:%1$s; color:%2$s; padding:10px 12px; margin-bottom:15px; border-radius:6px; font-weight:700; display:inline-block;">%3$s</div>',
            esc_attr( $bg_color ),
            esc_attr( $text_color ),
            esc_html( $label_text )
        );
    }

    public function validate_add_to_cart( $passed, $product_id, $quantity, $variation_id = 0 ) {
        $target_product_id = $variation_id ? absint( $variation_id ) : absint( $product_id );

        if ( ! $this->should_apply_rules( $target_product_id ) ) {
            return $passed;
        }

        $rules        = $this->get_quantity_rules();
        $product_name = $this->get_product_display_name( $target_product_id );

        if ( ! empty( $rules['min'] ) && $quantity < $rules['min'] ) {
            wc_add_notice( sprintf( __( 'You must add a minimum of %1$d items of %2$s to the cart.', 'quantity-ceiling' ), $rules['min'], $product_name ), 'error' );
            return false;
        }

        if ( ! empty( $rules['max'] ) && $quantity > $rules['max'] ) {
            wc_add_notice( sprintf( __( 'You cannot add more than %1$d items of %2$s to your cart.', 'quantity-ceiling' ), $rules['max'], $product_name ), 'error' );
            return false;
        }

        return $passed;
    }

    public function validate_cart_contents() {
        if ( ! WC()->cart ) {
            return;
        }

        foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
            $product_id = $this->get_cart_product_id( $cart_item );

            if ( ! $this->should_apply_rules( $product_id ) ) {
                continue;
            }

            $rules        = $this->get_quantity_rules();
            $quantity     = absint( $cart_item['quantity'] );
            $product_name = $this->get_product_display_name( $product_id );

            if ( ! empty( $rules['min'] ) && $quantity < $rules['min'] ) {
                wc_add_notice( sprintf( __( 'The quantity for "%1$s" has been adjusted to meet the minimum item purchase rule of %2$d.', 'quantity-ceiling' ), $product_name, $rules['min'] ), 'error' );
                WC()->cart->set_quantity( $cart_item_key, $rules['min'] );
            }

            if ( ! empty( $rules['max'] ) && $quantity > $rules['max'] ) {
                wc_add_notice( sprintf( __( 'The quantity for "%1$s" exceeded the allowed threshold limits. Reset to safety limit of %2$d.', 'quantity-ceiling' ), $product_name, $rules['max'] ), 'error' );
                WC()->cart->set_quantity( $cart_item_key, $rules['max'] );
            }
        }
    }
}
