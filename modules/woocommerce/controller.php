<?php
/**
 * WooCommerce Integration Controller
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CEL_AI_WooCommerce_Controller {

	public function __construct() {
		add_filter( 'woocommerce_product_get_name', [ $this, 'filter_product_name' ], 10, 2 );
		add_filter( 'woocommerce_variation_option_name', [ $this, 'filter_variation_option_name' ] );
	}

	/**
	 * Filter product name for translated products
	 *
	 * @param string $name
	 * @param WC_Product $product
	 * @return string
	 */
	public function filter_product_name( $name, $product ) {
		// If it's a variation, use the template-based title
		if ( $product->is_type( 'variation' ) ) {
			return $this->get_variation_name_template( $product );
		}
		return $name;
	}

	/**
	 * Generate template-based variation name
	 * Template: "{product_name} - {attribute}: {value}"
	 *
	 * @param WC_Product_Variation $variation
	 * @return string
	 */
	private function get_variation_name_template( $variation ) {
		$parent_id = $variation->get_parent_id();
		$parent    = wc_get_product( $parent_id );
		
		if ( ! $parent ) {
			return $variation->get_name();
		}

		$attributes = $variation->get_attributes();
		$attr_strings = [];

		foreach ( $attributes as $attr => $value ) {
			$taxonomy = str_replace( 'attribute_', '', $attr );
			$label    = wc_attribute_label( $taxonomy, $parent );
			$attr_strings[] = "{$label}: {$value}";
		}

		return $parent->get_name() . ' - ' . implode( ', ', $attr_strings );
	}

	/**
	 * Filter variation option name (optional, depends on needs)
	 */
	public function filter_variation_option_name( $name ) {
		return $name;
	}
}

new CEL_AI_WooCommerce_Controller();
