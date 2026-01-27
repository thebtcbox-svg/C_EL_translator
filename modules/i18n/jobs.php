<?php
/**
 * Job Queue System for CEL AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CEL_AI_Job_Queue {

	const OPTION_NAME = 'cel_ai_job_queue';

	public function __construct() {
		add_action( 'cel_ai_cron_process_queue', [ $this, 'process_queue' ] );
		
		if ( ! wp_next_scheduled( 'cel_ai_cron_process_queue' ) ) {
			wp_schedule_event( time(), 'minute', 'cel_ai_cron_process_queue' );
		}
	}

	/**
	 * Enqueue a new translation job
	 *
	 * @param array $data
	 * @return string Job ID (UUID)
	 */
	public function enqueue( $data ) {
		$queue = get_option( self::OPTION_NAME, [] );
		$job_id = wp_generate_uuid4();

		$queue[ $job_id ] = [
			'id'              => $job_id,
			'post_id'         => $data['post_id'],
			'target_language' => $data['target_language'],
			'mode'            => isset($data['mode']) ? $data['mode'] : 'full',
			'status'          => 'pending',
			'created_at'      => current_time( 'mysql' ),
			'retries'         => 0,
			'log'             => [ 'Job enqueued.' ],
			'progress'        => [ 'current' => 0, 'total' => 0, 'percent' => 0 ],
		];

		update_option( self::OPTION_NAME, $queue, false );
		CEL_AI_Logger::info( "Enqueued translation job {$job_id} for post {$data['post_id']} to {$data['target_language']} (Mode: " . $queue[$job_id]['mode'] . ")" );

		return $job_id;
	}

	/**
	 * Process one job from the queue
	 */
	public function process_queue() {
		$queue = get_option( self::OPTION_NAME, [] );

		if ( empty( $queue ) ) {
			return;
		}

		// Find the first pending or retry-able job
		$job_id = null;
		foreach ( $queue as $id => $job ) {
			if ( 'pending' === $job['status'] || 'retry' === $job['status'] ) {
				$job_id = $id;
				break;
			}
		}

		if ( ! $job_id ) {
			return;
		}

		$queue[ $job_id ]['status'] = 'running';
		$queue[ $job_id ]['log'][]  = 'Processing started at ' . current_time( 'mysql' );
		update_option( self::OPTION_NAME, $queue, false );

		$processor = new CEL_AI_Translation_Processor();
		$result = $processor->process_job( $queue[ $job_id ], $job_id );

		// Refresh queue as it might have changed during processing
		$queue = get_option( self::OPTION_NAME, [] );

		if ( is_array($result) && isset($result['success']) && $result['success'] ) {
			$queue[ $job_id ]['status'] = 'completed';
			$queue[ $job_id ]['log'][] = 'Successfully completed at ' . current_time( 'mysql' );
			$queue[ $job_id ]['progress']['percent'] = 100;
		} else {
			$error_message = is_array($result) && isset( $result['message'] ) ? $result['message'] : 'Unknown error';
			$queue[ $job_id ]['log'][] = 'ERROR: ' . $error_message;

			// Handle retries for specific errors (e.g. 429, 500+)
			$status_code = is_array($result) && isset( $result['status'] ) ? $result['status'] : 0;
			if ( ( 429 === $status_code || $status_code >= 500 ) && $queue[ $job_id ]['retries'] < 3 ) {
				$queue[ $job_id ]['status']  = 'retry';
				$queue[ $job_id ]['retries']++;
				$queue[ $job_id ]['log'][] = "Retry scheduled (Attempt {$queue[$job_id]['retries']})";
				CEL_AI_Logger::info( "Retrying job {$job_id} (Attempt {$queue[$job_id]['retries']})" );
			} else {
				$queue[ $job_id ]['status'] = 'failed';
				$queue[ $job_id ]['log'][] = 'Permanent failure.';
				CEL_AI_Logger::error( "Job {$job_id} failed: " . $error_message );
			}
		}

		update_option( self::OPTION_NAME, $queue, false );
	}

	/**
	 * Get job status
	 *
	 * @param string $job_id
	 * @return array|null
	 */
	public static function get_job( $job_id ) {
		$queue = get_option( self::OPTION_NAME, [] );
		return isset( $queue[ $job_id ] ) ? $queue[ $job_id ] : null;
	}
}

class CEL_AI_Translation_Processor {

	public function process_job( $job, $job_id = null ) {
		$post_id     = $job['post_id'];
		$target_lang = $job['target_language'];
		$mode        = isset($job['mode']) ? $job['mode'] : 'full';
		$post        = get_post( $post_id );

		if ( ! $post ) {
			return [ 'success' => false, 'message' => 'Original post not found.' ];
		}

		$source_lang = get_post_meta( $post_id, CEL_AI_I18N_Controller::META_LANGUAGE, true );
		if ( ! $source_lang ) {
			$source_lang = substr( get_locale(), 0, 2 );
		}

		$client = new CEL_AI_AI_Client();

		$title_res   = [ 'success' => true, 'translated_text' => $post->post_title ];
		$content_res = [ 'success' => true, 'translated_text' => $post->post_content ];
		$excerpt_res = [ 'success' => true, 'translated_text' => $post->post_excerpt ];

		// Translate Title (if full or content-only)
		if ( $mode === 'full' || $mode === 'content-only' ) {
			$this->log_to_job($job_id, 'Translating title...');
			$title_res = $client->translate( $post->post_title, $source_lang, $target_lang );
			if ( ! is_array($title_res) || ! $title_res['success'] ) return $title_res;
		}

		// Translate Content (if full or content-only)
		if ( $mode === 'full' || $mode === 'content-only' ) {
			$this->log_to_job($job_id, 'Translating content...');
			$content_res = $this->translate_with_chunking( $post->post_content, $source_lang, $target_lang, $client, $job_id );
			if ( ! is_array($content_res) || ! $content_res['success'] ) return $content_res;
		}

		// Translate Excerpt (if full or content-only)
		if ( ( $mode === 'full' || $mode === 'content-only' ) && ! empty( $post->post_excerpt ) ) {
			$this->log_to_job($job_id, 'Translating excerpt...');
			$excerpt_res = $client->translate( $post->post_excerpt, $source_lang, $target_lang );
			if ( ! is_array($excerpt_res) || ! $excerpt_res['success'] ) return $excerpt_res;
		}

		// Create or Update translated post
		$this->log_to_job($job_id, 'Writing to database...');
		$translations = CEL_AI_I18N_Controller::get_translations( $post_id );
		$group_id     = CEL_AI_I18N_Controller::ensure_group_id( $post_id );

		$publish_status = get_option( 'cel_ai_publish_status', 'draft' );

		$post_data = [
			'post_title'   => $title_res['translated_text'],
			'post_content' => $content_res['translated_text'],
			'post_excerpt' => $excerpt_res['translated_text'],
			'post_status'  => $publish_status,
			'post_type'    => $post->post_type,
		];

		if ( isset( $translations[ $target_lang ] ) ) {
			$post_data['ID'] = $translations[ $target_lang ]->ID;
			$translated_post_id = wp_update_post( $post_data );
		} else {
			$translated_post_id = wp_insert_post( $post_data );
		}

		if ( is_wp_error( $translated_post_id ) ) {
			return [ 'success' => false, 'message' => $translated_post_id->get_error_message() ];
		}

		// Update Meta
		update_post_meta( $translated_post_id, CEL_AI_I18N_Controller::META_GROUP_ID, $group_id );
		update_post_meta( $translated_post_id, CEL_AI_I18N_Controller::META_LANGUAGE, $target_lang );
		update_post_meta( $translated_post_id, CEL_AI_I18N_Controller::META_SOURCE_LANG, $source_lang );
		update_post_meta( $translated_post_id, CEL_AI_I18N_Controller::META_ORIGINAL_ID, $post_id );
		update_post_meta( $translated_post_id, CEL_AI_I18N_Controller::META_IS_ORIGINAL, '0' );
		update_post_meta( $translated_post_id, CEL_AI_I18N_Controller::META_STATUS, $publish_status );

		// Copy essential metadata (Images, Prices, etc.)
		$this->copy_essential_meta( $post_id, $translated_post_id );

		$this->log_to_job($job_id, "Successfully posted as ID {$translated_post_id} ({$publish_status})");
		return [ 'success' => true ];
	}

	private function log_to_job( $job_id, $message ) {
		if ( ! $job_id ) return;
		$queue = get_option( CEL_AI_Job_Queue::OPTION_NAME, [] );
		if ( isset( $queue[ $job_id ] ) ) {
			$queue[ $job_id ]['log'][] = $message;
			update_option( CEL_AI_Job_Queue::OPTION_NAME, $queue, false );
		}
	}

	/**
	 * Copy essential metadata from original to translation
	 */
	private function copy_essential_meta( $source_id, $target_id ) {
		$keys_to_copy = [
			'_thumbnail_id',
			'_product_image_gallery',
			'_price',
			'_regular_price',
			'_sale_price',
			'_sku',
			'_stock',
			'_stock_status',
			'_manage_stock',
			'_weight',
			'_length',
			'_width',
			'_height',
			'_product_attributes',
			'_upsell_ids',
			'_crosssell_ids',
			'_purchase_note',
			'_default_attributes',
			'_virtual',
			'_downloadable',
			'_product_version',
		];

		foreach ( $keys_to_copy as $key ) {
			$val = get_post_meta( $source_id, $key, true );
			if ( $val !== '' ) {
				update_post_meta( $target_id, $key, $val );
			}
		}
	}

	/**
	 * Translate long content by splitting it into chunks
	 */
	private function translate_with_chunking( $content, $source_lang, $target_lang, $client, $job_id = null ) {
		$limits = include CEL_AI_PATH . 'config/limits.php';
		$max_len = $limits['max_chars_per_request'];

		if ( mb_strlen( $content ) <= $max_len ) {
			if ( $job_id ) $this->update_job_progress( $job_id, 1, 1 );
			return $client->translate( $content, $source_lang, $target_lang );
		}

		$paragraphs = explode( "\n\n", $content );
		$chunks = [];
		$current_chunk = "";

		foreach ( $paragraphs as $para ) {
			if ( mb_strlen( $current_chunk . $para ) > $max_len ) {
				if ( ! empty( $current_chunk ) ) {
					$chunks[] = trim( $current_chunk );
					$current_chunk = "";
				}
				if ( mb_strlen( $para ) > $max_len ) {
					$para_chunks = $this->mb_str_split( $para, $max_len );
					foreach ( $para_chunks as $pc ) $chunks[] = $pc;
				} else {
					$current_chunk = $para;
				}
			} else {
				$current_chunk .= ( empty($current_chunk) ? "" : "\n\n" ) . $para;
			}
		}
		if ( ! empty( $current_chunk ) ) $chunks[] = trim( $current_chunk );

		$total_chunks = count( $chunks );
		$translated_chunks = [];
		$processed_count = 0;

		foreach ( $chunks as $chunk ) {
			$processed_count++;
			if ( $job_id ) {
				$this->log_to_job($job_id, "Sending chunk {$processed_count} of {$total_chunks} to AI...");
				$this->update_job_progress( $job_id, $processed_count, $total_chunks );
			}

			$res = $client->translate( $chunk, $source_lang, $target_lang );
			if ( ! is_array($res) || ! $res['success'] ) {
				return $res;
			}
			$translated_chunks[] = $res['translated_text'];
			if ( $job_id ) $this->log_to_job($job_id, "Received translation for chunk {$processed_count}.");
		}

		return [
			'success'         => true,
			'translated_text' => implode( "\n\n", $translated_chunks ),
		];
	}

	private function mb_str_split( $string, $length ) {
		$result = [];
		$strlen = mb_strlen( $string );
		for ( $i = 0; $i < $strlen; $i += $length ) {
			$result[] = mb_substr( $string, $i, $length );
		}
		return $result;
	}

	private function update_job_progress( $job_id, $current, $total ) {
		$queue = get_option( CEL_AI_Job_Queue::OPTION_NAME, [] );
		if ( isset( $queue[ $job_id ] ) ) {
			$new_percent = round( ( $current / $total ) * 100 );
			$old_percent = isset($queue[ $job_id ]['progress']['percent']) ? $queue[ $job_id ]['progress']['percent'] : -1;
			if ( $new_percent !== $old_percent || $current === 1 || $current === $total ) {
				$queue[ $job_id ]['progress'] = [
					'current' => $current,
					'total'   => $total,
					'percent' => $new_percent,
				];
				update_option( CEL_AI_Job_Queue::OPTION_NAME, $queue, false );
			}
		}
	}
}

add_filter( 'cron_schedules', function( $schedules ) {
    $schedules['minute'] = [
        'interval' => 60,
        'display'  => __( 'Every Minute' ),
    ];
    return $schedules;
} );

new CEL_AI_Job_Queue();
