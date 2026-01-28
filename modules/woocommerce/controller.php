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
		add_action( 'woocommerce_update_product', [ $this, 'sync_translation_meta' ], 10, 1 );
	}

	/**
	 * Sync essential metadata to translations when original product is updated
	 *
	 * @param int $product_id
	 */
	public function sync_translation_meta( $product_id ) {
		// Prevent infinite loops
		remove_action( 'woocommerce_update_product', [ $this, 'sync_translation_meta' ], 10 );

		$is_original = get_post_meta( $product_id, CEL_AI_I18N_Controller::META_IS_ORIGINAL, true );
		if ( '1' !== $is_original ) {
			return;
		}

		$translations = CEL_AI_I18N_Controller::get_translations( $product_id );
		if ( empty( $translations ) ) {
			return;
		}

		$keys_to_sync = [
			'_price',
			'_regular_price',
			'_sale_price',
			'_sku',
			'_stock',
			'_stock_status',
			'_manage_stock',
			'_backorders',
			'_sold_individually',
			'_weight',
			'_length',
			'_width',
			'_height',
			'_upsell_ids',
			'_crosssell_ids',
			'_purchase_note',
			'_default_attributes',
			'_virtual',
			'_downloadable',
			'_download_limit',
			'_download_expiry',
			'_thumbnail_id',
			'_product_image_gallery',
		];

		foreach ( $translations as $lang => $trans_post ) {
			// Don't sync to self
			if ( $trans_post->ID == $product_id ) {
				continue;
			}

			foreach ( $keys_to_sync as $key ) {
				$value = get_post_meta( $product_id, $key, true );
				update_post_meta( $trans_post->ID, $key, $value );
			}

			// Clean WooCommerce product cache
			$product = wc_get_product( $trans_post->ID );
			if ( $product ) {
				$product->save();
			}
		}

		add_action( 'woocommerce_update_product', [ $this, 'sync_translation_meta' ], 10, 1 );
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
