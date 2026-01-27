<?php
/**
 * SEO and Meta Handling for CEL AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CEL_AI_SEO_Controller {

	public function __construct() {
		add_action( 'wp_head', [ $this, 'add_hreflang_tags' ] );
	}

	/**
	 * Add hreflang tags to the head
	 */
	public function add_hreflang_tags() {
		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return;
		}

		$translations = CEL_AI_I18N_Controller::get_translations( $post_id );
		$current_lang = get_post_meta( $post_id, CEL_AI_I18N_Controller::META_LANGUAGE, true );

		if ( empty( $translations ) ) {
			return;
		}

		// Include the current post as well
		foreach ( $translations as $code => $trans_post ) {
			printf(
				'<link rel="alternate" hreflang="%s" href="%s" />' . "\n",
				esc_attr( $code ),
				esc_url( get_permalink( $trans_post->ID ) )
			);
		}

		// X-default could be the original post
		$original_id = get_post_meta( $post_id, CEL_AI_I18N_Controller::META_ORIGINAL_ID, true );
		if ( ! $original_id ) {
			$original_id = $post_id;
		}
		printf(
			'<link rel="alternate" hreflang="x-default" href="%s" />' . "\n",
			esc_url( get_permalink( $original_id ) )
		);
	}
}

new CEL_AI_SEO_Controller();
