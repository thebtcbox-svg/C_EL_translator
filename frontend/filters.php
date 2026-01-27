<?php
/**
 * Frontend Filters and Routing for CEL AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CEL_AI_Frontend_Filters {

	public function __construct() {
		add_action( 'template_redirect', [ $this, 'handle_language_routing' ] );
		add_filter( 'query_vars', [ $this, 'add_query_vars' ] );
		add_filter( 'the_content', [ $this, 'maybe_inject_switcher' ] );
	}

	/**
	 * Inject language switcher to the bottom of the content if option is enabled
	 */
	public function maybe_inject_switcher( $content ) {
		if ( ! is_main_query() || ! is_singular() ) {
			return $content;
		}

		$auto_inject = get_option( 'cel_ai_auto_switcher', '0' );
		if ( '1' !== $auto_inject ) {
			return $content;
		}

		$switcher = new CEL_AI_Switcher();
		return $content . $switcher->render_switcher();
	}

	/**
	 * Add 'lang' to query vars
	 */
	public function add_query_vars( $vars ) {
		$vars[] = 'lang';
		return $vars;
	}

	/**
	 * Handle language routing
	 * Resolves the correct post version based on the language requested.
	 */
	public function handle_language_routing() {
		$lang = get_query_var( 'lang' );
		if ( ! $lang ) {
			return;
		}

		$current_post = get_queried_object();
		if ( ! $current_post || ! is_a( $current_post, 'WP_Post' ) ) {
			return;
		}

		$current_lang = get_post_meta( $current_post->ID, CEL_AI_I18N_Controller::META_LANGUAGE, true );
		if ( $current_lang === $lang ) {
			return; // Already on the correct language
		}

		$translations = CEL_AI_I18N_Controller::get_translations( $current_post->ID );
		
		if ( isset( $translations[ $lang ] ) ) {
			// Redirect to the translated post
			wp_redirect( get_permalink( $translations[ $lang ]->ID ) );
			exit;
		}
	}
}

new CEL_AI_Frontend_Filters();
