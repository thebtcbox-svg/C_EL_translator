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
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_assets' ] );
		add_action( 'wp_body_open', [ $this, 'inject_header_switcher' ] );
		add_filter( 'body_class', [ $this, 'add_body_classes' ] );
		add_action( 'pre_get_posts', [ $this, 'filter_by_language' ] );
	}

	public function enqueue_frontend_assets() {
		wp_register_style( 'cel-ai-frontend-style', CEL_AI_URL . 'assets/frontend.css', [], CEL_AI_VERSION );
		if ( get_option( 'cel_ai_switcher_location', 'none' ) !== 'none' ) {
			wp_enqueue_style( 'cel-ai-frontend-style' );
		}
	}

	/**
	 * Inject language switcher based on settings
	 */
	public function maybe_inject_switcher( $content ) {
		if ( ! is_main_query() || ! is_singular() ) {
			return $content;
		}

		$location = get_option( 'cel_ai_switcher_location', 'none' );
		if ( in_array( $location, [ 'none', 'header' ] ) ) {
			return $content;
		}

		$switcher_obj = new CEL_AI_Switcher();
		$switcher_html = $switcher_obj->render_switcher();

		switch ( $location ) {
			case 'top':
				return $switcher_html . $content;
			case 'bottom':
				return $content . $switcher_html;
			case 'both':
				return $switcher_html . $content . $switcher_html;
			default:
				return $content;
		}
	}

	/**
	 * Inject header switcher
	 */
	public function inject_header_switcher() {
		if ( get_option( 'cel_ai_switcher_location' ) === 'header' ) {
			$switcher_obj = new CEL_AI_Switcher();
			echo '<div class="cel-ai-header-switcher">' . $switcher_obj->render_switcher() . '</div>';
		}
	}

	/**
	 * Add body classes
	 */
	public function add_body_classes( $classes ) {
		if ( get_option( 'cel_ai_switcher_location' ) === 'header' ) {
			$classes[] = 'cel-ai-has-header-switcher';
		}
		return $classes;
	}

	/**
	 * Add 'lang' to query vars
	 */
	public function add_query_vars( $vars ) {
		$vars[] = 'lang';
		return $vars;
	}

	/**
	 * Filter main query by language to avoid duplicates
	 *
	 * @param WP_Query $query
	 */
	public function filter_by_language( $query ) {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		// Only filter archive pages where duplicates usually appear
		if ( ! $query->is_archive() && ! $query->is_home() && ! $query->is_search() ) {
			return;
		}

		$lang = get_query_var( 'lang' ) ?: substr( get_locale(), 0, 2 );

		$meta_query = $query->get( 'meta_query' ) ?: [];

		// If we are looking for the default site language, 
		// we should also include posts that don't have the meta yet.
		$site_lang = substr( get_locale(), 0, 2 );

		if ( $lang === $site_lang ) {
			$meta_query[] = [
				'relation' => 'OR',
				[
					'key'     => CEL_AI_I18N_Controller::META_LANGUAGE,
					'value'   => $lang,
					'compare' => '=',
				],
				[
					'key'     => CEL_AI_I18N_Controller::META_LANGUAGE,
					'compare' => 'NOT EXISTS',
				],
			];
		} else {
			$meta_query[] = [
				'key'     => CEL_AI_I18N_Controller::META_LANGUAGE,
				'value'   => $lang,
				'compare' => '=',
			];
		}

		$query->set( 'meta_query', $meta_query );
	}

	/**
	 * Handle language routing
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
			return;
		}

		$translations = CEL_AI_I18N_Controller::get_translations( $current_post->ID );
		
		if ( isset( $translations[ $lang ] ) ) {
			wp_redirect( get_permalink( $translations[ $lang ]->ID ) );
			exit;
		}
	}
}

new CEL_AI_Frontend_Filters();
