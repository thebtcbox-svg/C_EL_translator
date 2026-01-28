<?php
/**
 * REST API Endpoints for CEL AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CEL_AI_API {

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes() {
		register_rest_route( 'cel-ai/v1', '/translate', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_translate_request' ],
			'permission_callback' => [ $this, 'check_permission' ],
		] );

		register_rest_route( 'cel-ai/v1', '/job/(?P<job_id>[a-f0-9\-]+)', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'handle_job_status_request' ],
			'permission_callback' => [ $this, 'check_permission' ],
		] );

		register_rest_route( 'cel-ai/v1', '/link', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_link_request' ],
			'permission_callback' => [ $this, 'check_permission' ],
		] );
	}

	/**
	 * Check permissions for API access
	 */
	public function check_permission() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Handle translation trigger request
	 */
	public function handle_translate_request( $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			return new WP_Error( 'invalid_json', 'Invalid JSON payload', [ 'status' => 400 ] );
		}

		$post_id     = isset( $params['post_id'] ) ? intval( $params['post_id'] ) : 0;
		$target_lang = isset( $params['target_language'] ) ? sanitize_text_field( $params['target_language'] ) : '';
		$mode        = isset( $params['mode'] ) ? sanitize_text_field( $params['mode'] ) : 'full';

		if ( ! $post_id || ! $target_lang ) {
			return new WP_Error( 'missing_params', 'Missing post_id or target_language', [ 'status' => 400 ] );
		}

		$queue = new CEL_AI_Job_Queue();
		$job_id = $queue->enqueue( [
			'post_id'         => $post_id,
			'target_language' => $target_lang,
			'mode'            => $mode,
		] );

		return rest_ensure_response( [
			'job_id' => $job_id,
			'status' => 'queued',
		] );
	}

	/**
	 * Handle job status request
	 */
	public function handle_job_status_request( $request ) {
		$job_id = $request['job_id'];
		$job = CEL_AI_Job_Queue::get_job( $job_id );

		if ( ! $job ) {
			return new WP_Error( 'not_found', 'Job not found', [ 'status' => 404 ] );
		}

		return rest_ensure_response( [
			'status'   => $job['status'],
			'log'      => $job['log'],
			'progress' => isset( $job['progress'] ) ? $job['progress'] : null,
		] );
	}

	/**
	 * Handle request to manually link an existing translation
	 */
	public function handle_link_request( $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			return new WP_Error( 'invalid_json', 'Invalid JSON payload', [ 'status' => 400 ] );
		}

		$post_id        = isset( $params['post_id'] ) ? intval( $params['post_id'] ) : 0;
		$translation_id = isset( $params['translation_id'] ) ? intval( $params['translation_id'] ) : 0;
		$language       = isset( $params['language'] ) ? sanitize_text_field( $params['language'] ) : '';

		if ( ! $post_id || ! $translation_id || ! $language ) {
			return new WP_Error( 'missing_params', 'Missing post_id, translation_id or language', [ 'status' => 400 ] );
		}

		$group_id = CEL_AI_I18N_Controller::ensure_group_id( $post_id );

		update_post_meta( $translation_id, CEL_AI_I18N_Controller::META_GROUP_ID, $group_id );
		update_post_meta( $translation_id, CEL_AI_I18N_Controller::META_LANGUAGE, $language );
		update_post_meta( $translation_id, CEL_AI_I18N_Controller::META_ORIGINAL_ID, $post_id );
		update_post_meta( $translation_id, CEL_AI_I18N_Controller::META_IS_ORIGINAL, '0' );
		update_post_meta( $translation_id, CEL_AI_I18N_Controller::META_STATUS, get_post_status( $translation_id ) );

		return rest_ensure_response( [
			'success'  => true,
			'group_id' => $group_id,
		] );
	}
}

new CEL_AI_API();
