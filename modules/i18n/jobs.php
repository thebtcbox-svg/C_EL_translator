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
			'status'          => 'pending',
			'created_at'      => current_time( 'mysql' ),
			'retries'         => 0,
			'log'             => [],
			'progress'        => [ 'current' => 0, 'total' => 0, 'percent' => 0 ],
		];

		update_option( self::OPTION_NAME, $queue, false );
		CEL_AI_Logger::info( "Enqueued translation job {$job_id} for post {$data['post_id']} to {$data['target_language']}" );

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
		$job_to_process = null;
		foreach ( $queue as $id => $job ) {
			if ( 'pending' === $job['status'] || 'retry' === $job['status'] ) {
				$job_to_process = $job;
				break;
			}
		}

		if ( ! $job_to_process ) {
			return;
		}

		$job_id = $job_to_process['id'];
		$queue[ $job_id ]['status'] = 'running';
		update_option( self::OPTION_NAME, $queue, false );

		$processor = new CEL_AI_Translation_Processor();
		$result = $processor->process_job( $job_to_process, $job_id );

		// Refresh queue as it might have changed during processing
		$queue = get_option( self::OPTION_NAME, [] );

		if ( $result['success'] ) {
			$queue[ $job_id ]['status'] = 'completed';
			$queue[ $job_id ]['log'][] = 'Completed successfully.';
			$queue[ $job_id ]['progress']['percent'] = 100;
		} else {
			$error_message = isset( $result['message'] ) ? $result['message'] : 'Unknown error';
			$queue[ $job_id ]['log'][] = 'Error: ' . $error_message;

			// Handle retries for specific errors (e.g. 429, 500+)
			$status_code = isset( $result['status'] ) ? $result['status'] : 0;
			if ( ( 429 === $status_code || $status_code >= 500 ) && $queue[ $job_id ]['retries'] < 3 ) {
				$queue[ $job_id ]['status']  = 'retry';
				$queue[ $job_id ]['retries']++;
				CEL_AI_Logger::info( "Retrying job {$job_id} (Attempt {$queue[$job_id]['retries']})" );
			} else {
				$queue[ $job_id ]['status'] = 'failed';
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
		$post        = get_post( $post_id );

		if ( ! $post ) {
			return [ 'success' => false, 'message' => 'Original post not found.' ];
		}

		$source_lang = get_post_meta( $post_id, CEL_AI_I18N_Controller::META_LANGUAGE, true );
		if ( ! $source_lang ) {
			$source_lang = substr( get_locale(), 0, 2 );
		}

		$client = new CEL_AI_AI_Client();

		// Translate Title
		$title_res = $client->translate( $post->post_title, $source_lang, $target_lang );
		if ( ! $title_res['success'] ) {
			return $title_res;
		}

		// Translate Content (with chunking)
		$content_res = $this->translate_with_chunking( $post->post_content, $source_lang, $target_lang, $client, $job_id );
		if ( ! $content_res['success'] ) {
			return $content_res;
		}

		// Translate Excerpt
		$excerpt_res = [ 'success' => true, 'translated_text' => '' ];
		if ( ! empty( $post->post_excerpt ) ) {
			$excerpt_res = $client->translate( $post->post_excerpt, $source_lang, $target_lang );
			if ( ! $excerpt_res['success'] ) {
				return $excerpt_res;
			}
		}

		// Create or Update translated post
		$translations = CEL_AI_I18N_Controller::get_translations( $post_id );
		$group_id     = CEL_AI_I18N_Controller::ensure_group_id( $post_id );

		$post_data = [
			'post_title'   => $title_res['translated_text'],
			'post_content' => $content_res['translated_text'],
			'post_excerpt' => $excerpt_res['translated_text'],
			'post_status'  => 'draft',
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
		update_post_meta( $translated_post_id, CEL_AI_I18N_Controller::META_STATUS, 'draft' );

		return [ 'success' => true ];
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

		// Split by double newline (paragraphs)
		$chunks = explode( "\n\n", $content );
		$total_chunks = count( $chunks );
		$translated_chunks = [];
		$current_chunk = "";
		$processed_count = 0;

		foreach ( $chunks as $chunk ) {
			$processed_count++;
			if ( $job_id ) {
				$this->update_job_progress( $job_id, $processed_count, $total_chunks );
			}

			if ( mb_strlen( $current_chunk . $chunk ) > $max_len ) {
				if ( ! empty( $current_chunk ) ) {
					$res = $client->translate( $current_chunk, $source_lang, $target_lang );
					if ( ! $res['success'] ) {
						return $res;
					}
					$translated_chunks[] = $res['translated_text'];
					$current_chunk = "";
				}

				// If a single paragraph is too long, we must force split it
				if ( mb_strlen( $chunk ) > $max_len ) {
					$sub_chunks = str_split( $chunk, $max_len );
					foreach ( $sub_chunks as $sub ) {
						$res = $client->translate( $sub, $source_lang, $target_lang );
						if ( ! $res['success'] ) {
							return $res;
						}
						$translated_chunks[] = $res['translated_text'];
					}
				} else {
					$current_chunk = $chunk . "\n\n";
				}
			} else {
				$current_chunk .= $chunk . "\n\n";
			}
		}

		if ( ! empty( $current_chunk ) ) {
			$res = $client->translate( $current_chunk, $source_lang, $target_lang );
			if ( ! $res['success'] ) {
				return $res;
			}
			$translated_chunks[] = $res['translated_text'];
		}

		return [
			'success'         => true,
			'translated_text' => implode( "\n\n", $translated_chunks ),
		];
	}

	/**
	 * Update job progress in wp_options
	 */
	private function update_job_progress( $job_id, $current, $total ) {
		$queue = get_option( CEL_AI_Job_Queue::OPTION_NAME, [] );
		if ( isset( $queue[ $job_id ] ) ) {
			$queue[ $job_id ]['progress'] = [
				'current' => $current,
				'total'   => $total,
				'percent' => round( ( $current / $total ) * 100 ),
			];
			update_option( CEL_AI_Job_Queue::OPTION_NAME, $queue, false );
		}
	}
}

// Add custom cron schedule if not exists
add_filter( 'cron_schedules', function( $schedules ) {
    $schedules['minute'] = [
        'interval' => 60,
        'display'  => __( 'Every Minute' ),
    ];
    return $schedules;
} );

new CEL_AI_Job_Queue();
