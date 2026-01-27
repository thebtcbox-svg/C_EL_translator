<?php
/**
 * Main Controller for I18N logic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CEL_AI_I18N_Controller {

	const META_GROUP_ID    = '_cel_ai_trans_group_id';
	const META_LANGUAGE    = '_cel_ai_trans_language';
	const META_SOURCE_LANG = '_cel_ai_trans_source_lang';
	const META_ORIGINAL_ID = '_cel_ai_trans_original_id';
	const META_IS_ORIGINAL = '_cel_ai_trans_is_original';
	const META_STATUS      = '_cel_ai_trans_status';

	/**
	 * Get all translations for a given post
	 *
	 * @param int $post_id
	 * @return array Array of post objects indexed by language code
	 */
	public static function get_translations( $post_id ) {
		$group_id = get_post_meta( $post_id, self::META_GROUP_ID, true );
		if ( ! $group_id ) {
			return [];
		}

		$args = [
			'post_type'      => get_post_type( $post_id ),
			'posts_per_page' => -1,
			'meta_query'     => [
				[
					'key'   => self::META_GROUP_ID,
					'value' => $group_id,
				],
			],
		];

		$query = new WP_Query( $args );
		$translations = [];

		foreach ( $query->posts as $post ) {
			$lang = get_post_meta( $post->ID, self::META_LANGUAGE, true );
			if ( $lang ) {
				$translations[ $lang ] = $post;
			}
		}

		return $translations;
	}

	/**
	 * Ensure a post has a group ID
	 *
	 * @param int $post_id
	 * @return string Group ID
	 */
	public static function ensure_group_id( $post_id ) {
		$group_id = get_post_meta( $post_id, self::META_GROUP_ID, true );
		if ( ! $group_id ) {
			// Only update if it's the original post (no source lang meta yet)
			$is_trans = get_post_meta( $post_id, self::META_ORIGINAL_ID, true );
			if ( $is_trans ) {
				return $group_id; // Should not happen if data model is followed
			}

			$group_id = wp_generate_uuid4();
			update_post_meta( $post_id, self::META_GROUP_ID, $group_id );
			update_post_meta( $post_id, self::META_IS_ORIGINAL, '1' );
			
			// Default source language to site language or 'en'
			$site_lang = substr( get_locale(), 0, 2 );
			update_post_meta( $post_id, self::META_LANGUAGE, $site_lang );
		}
		return $group_id;
	}

	/**
	 * Get supported languages
	 *
	 * @return array
	 */
	public static function get_supported_languages() {
		return include CEL_AI_PATH . 'config/languages.php';
	}
}
