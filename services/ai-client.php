<?php
/**
 * AI Client for OpenRouter integration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CEL_AI_AI_Client {

	private $api_key;
	private $model_id;
	private $endpoint = 'https://openrouter.ai/api/v1/chat/completions';

	public function __construct() {
		$this->api_key  = defined( 'CEL_AI_OPENROUTER_KEY' ) ? CEL_AI_OPENROUTER_KEY : get_option( 'cel_ai_openrouter_key' );
		
		$manual_model = get_option( 'cel_ai_manual_model_id' );
		if ( ! empty( $manual_model ) ) {
			$this->model_id = $manual_model;
		} else {
			$this->model_id = get_option( 'cel_ai_model_id', 'mistralai/mistral-7b-instruct' );
		}
	}

	/**
	 * Translate content using OpenRouter
	 *
	 * @param string $content Content to translate
	 * @param string $source_lang Source language code
	 * @param string $target_lang Target language code
	 * @return array
	 */
	public function translate( $content, $source_lang, $target_lang ) {
		$limits = include CEL_AI_PATH . 'config/limits.php';

		if ( empty( $this->api_key ) ) {
			return [ 'success' => false, 'message' => __( 'API Key is missing.', 'cel-ai' ) ];
		}

		// Higher limit for raw AI call, as processor handles chunking
		if ( mb_strlen( $content ) > 32000 ) {
			return [ 
				'success' => false, 
				'message' => __( 'Content too large for a single AI request (max 32k).', 'cel-ai' )
			];
		}

		$system_prompt = $this->get_system_prompt( $source_lang, $target_lang );

		$response = wp_remote_post( $this->endpoint, [
			'headers' => $this->get_headers(),
			'body'    => json_encode( [
				'model'    => $this->model_id,
				'messages' => [
					[
						'role'    => 'system',
						'content' => $system_prompt,
					],
					[
						'role'    => 'user',
						'content' => $content,
					],
				],
			] ),
			'timeout' => $limits['request_timeout'],
		] );

		if ( is_wp_error( $response ) ) {
			CEL_AI_Logger::error( 'Translation request failed: ' . $response->get_error_message() );
			return [ 'success' => false, 'message' => $response->get_error_message() ];
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body_text   = wp_remote_retrieve_body( $response );
		$body        = json_decode( $body_text, true );

		if ( 200 === $status_code ) {
			$translated_text = isset( $body['choices'][0]['message']['content'] ) ? trim( $body['choices'][0]['message']['content'] ) : '';
			if ( ! empty( $translated_text ) ) {
				return [ 'success' => true, 'translated_text' => $translated_text ];
			}
			return [ 'success' => false, 'message' => __( 'Empty response from AI.', 'cel-ai' ) ];
		}

		$error_message = isset( $body['error']['message'] ) ? $body['error']['message'] : ( ! empty($body_text) ? $body_text : __( 'Unknown error', 'cel-ai' ) );
		CEL_AI_Logger::error( "OpenRouter Error ({$status_code}): {$error_message}" );

		return [
			'success' => false,
			'message' => sprintf( __( 'Error: %s', 'cel-ai' ), $error_message ),
			'status'  => $status_code,
		];
	}

	/**
	 * Get the system prompt with variables replaced
	 */
	private function get_system_prompt( $source_lang, $target_lang ) {
		$default_prompt = "You are a professional technical translator.\n" .
						"Translate the following content from {SOURCE_LANGUAGE} to {TARGET_LANGUAGE}.\n" .
						"Preserve meaning, formatting, HTML tags, units, and WooCommerce structure.\n" .
						"Do not add explanations or comments.\n" .
						"Return only the translated content.";

		$prompt = apply_filters( 'cel_ai_translation_system_prompt', $default_prompt, $source_lang, $target_lang );

		return str_replace(
			[ '{SOURCE_LANGUAGE}', '{TARGET_LANGUAGE}' ],
			[ $source_lang, $target_lang ],
			$prompt
		);
	}

	/**
	 * Test connection to OpenRouter
	 */
	public function test_connection() {
		if ( empty( $this->api_key ) ) {
			return [
				'success' => false,
				'message' => __( 'API Key is missing.', 'cel-ai' ),
			];
		}

		$response = wp_remote_post( $this->endpoint, [
			'headers' => $this->get_headers(),
			'body'    => json_encode( [
				'model'    => $this->model_id,
				'messages' => [
					[
						'role'    => 'user',
						'content' => 'Respond with "Connected"',
					],
				],
			] ),
			'timeout' => 30,
		] );

		if ( is_wp_error( $response ) ) {
			return [
				'success' => false,
				'message' => $response->get_error_message(),
			];
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body_text   = wp_remote_retrieve_body( $response );
		$body        = json_decode( $body_text, true );

		if ( 200 === $status_code ) {
			return [
				'success' => true,
				'message' => __( 'Success (connection valid)', 'cel-ai' ),
			];
		}

		$error_message = isset( $body['error']['message'] ) ? $body['error']['message'] : ( ! empty($body_text) ? $body_text : __( 'Unknown error', 'cel-ai' ) );
		
		if ( 401 === $status_code ) {
			return [ 'success' => false, 'message' => __( 'Invalid API key', 'cel-ai' ) ];
		} elseif ( 429 === $status_code ) {
			return [ 'success' => false, 'message' => __( 'Rate limit exceeded', 'cel-ai' ) ];
		}

		return [
			'success' => false,
			'message' => sprintf( __( 'Error: %s', 'cel-ai' ), $error_message ),
		];
	}

	/**
	 * Get required headers for OpenRouter
	 */
	private function get_headers() {
		return [
			'Authorization' => 'Bearer ' . $this->api_key,
			'Content-Type'  => 'application/json',
			'HTTP-Referer'  => get_site_url(),
			'X-Title'       => get_bloginfo( 'name' ),
		];
	}
}
