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
		$post_id     = isset( $params['post_id'] ) ? intval( $params['post_id'] ) : 0;
		$target_lang = isset( $params['target_language'] ) ? sanitize_text_field( $params['target_language'] ) : '';

		if ( ! $post_id || ! $target_lang ) {
			return new WP_Error( 'missing_params', 'Missing post_id or target_language', [ 'status' => 400 ] );
		}

		$queue = new CEL_AI_Job_Queue();
		$job_id = $queue->enqueue( [
			'post_id'         => $post_id,
			'target_language' => $target_lang,
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
			'status' => $job['status'],
			'log'    => $job['log'],
		] );
	}
}

new CEL_AI_API();
