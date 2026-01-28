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
		add_action( 'cel_ai_process_job', [ $this, 'process_specific_job' ] );
		
		if ( ! wp_next_scheduled( 'cel_ai_cron_process_queue' ) ) {
			wp_schedule_event( time(), 'minute', 'cel_ai_cron_process_queue' );
		}
	}

	/**
	 * Enqueue a new translation job
	 */
	public function enqueue( $data ) {
		$queue = get_option( self::OPTION_NAME, [] );
		$job_id = wp_generate_uuid4();

		$processor = new CEL_AI_Translation_Processor();
		$steps = $processor->prepare_steps( $data['post_id'], $data['target_language'], isset($data['mode']) ? $data['mode'] : 'full' );

		$queue[ $job_id ] = [
			'id'              => $job_id,
			'post_id'         => $data['post_id'],
			'target_language' => $data['target_language'],
			'mode'            => isset($data['mode']) ? $data['mode'] : 'full',
			'status'          => 'pending',
			'created_at'      => current_time( 'mysql' ),
			'updated_at'      => current_time( 'mysql' ),
			'retries'         => 0,
			'log'             => [ 'Job enqueued.' ],
			'progress'        => [ 'current' => 0, 'total' => count($steps), 'percent' => 0 ],
			'steps'           => $steps, // Prepared strings to translate
			'results'         => [],      // Translated strings
		];

		update_option( self::OPTION_NAME, $queue, false );
		CEL_AI_Logger::info( "Enqueued iterative translation job {$job_id} with " . count($steps) . " steps." );

		// Use Action Scheduler for faster processing if available
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'cel_ai_process_job', [ 'job_id' => $job_id ], 'cel_ai_jobs' );
		}

		return $job_id;
	}

	/**
	 * Process a specific job (called by Action Scheduler)
	 */
	public function process_specific_job( $job_id ) {
		$queue = get_option( self::OPTION_NAME, [] );
		if ( ! isset( $queue[$job_id] ) ) return;

		$status = $queue[$job_id]['status'];
		if ( ! in_array( $status, [ 'pending', 'running', 'retry' ] ) ) return;

		$this->process_single_job_step( $job_id );
	}

	/**
	 * Process queue (called by WP Cron)
	 * Handles recovery and triggers processing of multiple jobs if possible
	 */
	public function process_queue() {
		$queue = get_option( self::OPTION_NAME, [] );
		if ( empty( $queue ) ) return;

		// 0. Prune old completed/failed jobs (older than 3 days)
		$changed_prune = false;
		$three_days_ago = time() - ( 3 * DAY_IN_SECONDS );
		foreach ( $queue as $id => $job ) {
			if ( in_array( $job['status'], [ 'completed', 'failed' ] ) ) {
				$updated_at = isset( $job['updated_at'] ) ? strtotime( $job['updated_at'] ) : 0;
				if ( $updated_at < $three_days_ago ) {
					unset( $queue[ $id ] );
					$changed_prune = true;
				}
			}
		}
		if ( $changed_prune ) {
			update_option( self::OPTION_NAME, $queue, false );
		}

		// 1. Stuck Job Recovery: Reset jobs that haven't moved for 5 minutes
		$changed = false;
		foreach ( $queue as $id => $job ) {
			if ( $job['status'] === 'running' ) {
				$last_update = isset( $job['updated_at'] ) ? strtotime( $job['updated_at'] ) : 0;
				if ( ( time() - $last_update ) > 300 ) { // 5 minutes
					$queue[$id]['status'] = 'retry';
					$queue[$id]['log'][] = 'Recovered from stuck state.';
					$changed = true;
				}
			}
		}
		if ( $changed ) update_option( self::OPTION_NAME, $queue, false );

		// 2. Process jobs in parallel up to limit
		$limits = include CEL_AI_PATH . 'config/limits.php';
		$max_concurrent = isset($limits['max_concurrent_jobs']) ? $limits['max_concurrent_jobs'] : 1;
		
		$jobs_to_process = [];
		foreach ( $queue as $id => $job ) {
			if ( in_array( $job['status'], [ 'pending', 'retry', 'running' ] ) ) {
				$jobs_to_process[] = $id;
				if ( count( $jobs_to_process ) >= $max_concurrent ) break;
			}
		}

		foreach ( $jobs_to_process as $job_id ) {
			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action( 'cel_ai_process_job', [ 'job_id' => $job_id ], 'cel_ai_jobs' );
			} else {
				$this->process_single_job_step( $job_id );
				break; // Only one per tick for standard cron to avoid timeouts
			}
		}
	}

	/**
	 * Process a single step for a specific job
	 */
	private function process_single_job_step( $job_id ) {
		$queue = get_option( self::OPTION_NAME, [] );
		if ( ! isset( $queue[ $job_id ] ) ) return;

		$queue[ $job_id ]['status'] = 'running';
		$queue[ $job_id ]['updated_at'] = current_time( 'mysql' );
		update_option( self::OPTION_NAME, $queue, false );

		$processor = new CEL_AI_Translation_Processor();
		$result = $processor->process_next_step( $queue[ $job_id ], $job_id );

		$queue = get_option( self::OPTION_NAME, [] ); // Refresh
		if ( ! is_array( $result ) ) return;

		if ( isset( $result['finished'] ) && $result['finished'] ) {
			$queue[ $job_id ]['status'] = 'completed';
			$queue[ $job_id ]['log'][] = 'Successfully finished at ' . current_time( 'mysql' );
			$queue[ $job_id ]['progress']['percent'] = 100;
		} elseif ( isset( $result['success'] ) && $result['success'] ) {
			$queue[ $job_id ]['status'] = 'running';
			// Schedule next step with a small delay to avoid overwhelming the server (Error 503 prevention)
			$delay = 3; // 3 seconds between steps
			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action( 'cel_ai_process_job', [ 'job_id' => $job_id ], 'cel_ai_jobs', false, time() + $delay );
			} else {
				wp_schedule_single_event( time() + $delay, 'cel_ai_cron_process_queue' );
			}
		} else {
			$error_message = isset( $result['message'] ) ? $result['message'] : 'Unknown error';
			$queue[ $job_id ]['log'][] = 'STEP ERROR: ' . $error_message;
			$status_code = isset( $result['status'] ) ? $result['status'] : 0;
			
			if ( ( 429 === $status_code || $status_code >= 500 ) && $queue[ $job_id ]['retries'] < 3 ) {
				$queue[ $job_id ]['status'] = 'retry';
				$queue[ $job_id ]['retries']++;
			} else {
				$queue[ $job_id ]['status'] = 'failed';
			}
		}

		$queue[ $job_id ]['updated_at'] = current_time( 'mysql' );
		update_option( self::OPTION_NAME, $queue, false );
	}

	public static function get_job( $job_id ) {
		$queue = get_option( self::OPTION_NAME, [] );
		return isset( $queue[ $job_id ] ) ? $queue[ $job_id ] : null;
	}
}

class CEL_AI_Translation_Processor {

	public function prepare_steps( $post_id, $target_lang, $mode ) {
		$post = get_post( $post_id );
		if ( ! $post ) return [];

		$steps = [];
		if ( $mode === 'full' || $mode === 'content-only' ) {
			$steps[] = [ 'type' => 'title', 'content' => $post->post_title ];
			
			// Chunk content
			$chunks = $this->get_content_chunks( $post->post_content );
			foreach ( $chunks as $c ) {
				$steps[] = [ 'type' => 'content', 'content' => $c ];
			}

			if ( ! empty( $post->post_excerpt ) ) {
				$steps[] = [ 'type' => 'excerpt', 'content' => $post->post_excerpt ];
			}
		}
		return $steps;
	}

	public function process_next_step( $job, $job_id ) {
		$current_index = count( $job['results'] );
		$total_steps   = count( $job['steps'] );

		if ( $current_index >= $total_steps ) {
			return $this->finalize_job( $job, $job_id );
		}

		$step = $job['steps'][ $current_index ];
		$source_lang = get_post_meta( $job['post_id'], CEL_AI_I18N_Controller::META_LANGUAGE, true ) ?: substr( get_locale(), 0, 2 );

		$client = new CEL_AI_AI_Client();
		$this->log_to_job( $job_id, "Processing step " . ($current_index + 1) . " of {$total_steps} ({$step['type']})..." );
		
		$res = $client->translate( $step['content'], $source_lang, $job['target_language'] );

		if ( is_array($res) && $res['success'] ) {
			$job['results'][] = $res['translated_text'];
			$this->update_job_state( $job_id, $job['results'] );
			
			// If that was the last translation step, finalize now or wait for next tick?
			// Let's finalized in the same tick if it was the last translation
			if ( count($job['results']) >= $total_steps ) {
				return $this->finalize_job( $job, $job_id );
			}
			return [ 'success' => true ];
		}

		return $res;
	}

	private function finalize_job( $job, $job_id ) {
		$this->log_to_job( $job_id, 'Finalizing: Writing to database...' );
		
		$post_id = $job['post_id'];
		$post = get_post( $post_id );
		$results = $job['results'];
		
		// Map results back
		$translated_title   = '';
		$translated_content = '';
		$translated_excerpt = '';
		
		$content_parts = [];
		foreach ( $job['steps'] as $i => $step ) {
			if ( $step['type'] === 'title' ) $translated_title = $results[$i];
			if ( $step['type'] === 'content' ) $content_parts[] = $results[$i];
			if ( $step['type'] === 'excerpt' ) $translated_excerpt = $results[$i];
		}
		$translated_content = implode( "\n\n", $content_parts );

		$publish_status = get_option( 'cel_ai_publish_status', 'draft' );
		$translations = CEL_AI_I18N_Controller::get_translations( $post_id );
		$group_id     = CEL_AI_I18N_Controller::ensure_group_id( $post_id );

		$post_data = [
			'post_title'   => $translated_title,
			'post_content' => $translated_content,
			'post_excerpt' => $translated_excerpt,
			'post_status'  => $publish_status,
			'post_type'    => $post->post_type,
		];

		if ( isset( $translations[ $job['target_language'] ] ) ) {
			$post_data['ID'] = $translations[ $job['target_language'] ]->ID;
			$trans_id = wp_update_post( $post_data );
		} else {
			$trans_id = wp_insert_post( $post_data );
		}

		if ( is_wp_error( $trans_id ) ) return [ 'success' => false, 'message' => $trans_id->get_error_message() ];

		update_post_meta( $trans_id, CEL_AI_I18N_Controller::META_GROUP_ID, $group_id );
		update_post_meta( $trans_id, CEL_AI_I18N_Controller::META_LANGUAGE, $job['target_language'] );
		update_post_meta( $trans_id, CEL_AI_I18N_Controller::META_ORIGINAL_ID, $post_id );
		update_post_meta( $trans_id, CEL_AI_I18N_Controller::META_IS_ORIGINAL, '0' );
		update_post_meta( $trans_id, CEL_AI_I18N_Controller::META_STATUS, $publish_status );

		$this->copy_essential_meta( $post_id, $trans_id );
		$this->log_to_job( $job_id, "Successfully posted as ID {$trans_id}" );

		return [ 'success' => true, 'finished' => true ];
	}

	private function get_content_chunks( $content ) {
		$limits = include CEL_AI_PATH . 'config/limits.php';
		$max_len = $limits['max_chars_per_request'];
		if ( mb_strlen( $content ) <= $max_len ) {
			return [ $content ];
		}

		// Improved chunking: try to split by block-level HTML tags first to preserve structure
		$content = str_replace( [ '</h1>', '</h2>', '</h3>', '</h4>', '</h5>', '</h6>', '</p>', '</div>', '</li>' ], 
								[ "</h1>\n\n", "</h2>\n\n", "</h3>\n\n", "</h4>\n\n", "</h5>\n\n", "</h6>\n\n", "</p>\n\n", "</div>\n\n", "</li>\n\n" ], 
								$content );

		$paragraphs = explode( "\n\n", $content );
		$chunks = [];
		$current = "";

		foreach ( $paragraphs as $p ) {
			$p = trim($p);
			if ( empty($p) ) continue;

			if ( mb_strlen( $current . $p ) > $max_len ) {
				if ( ! empty( $current ) ) {
					$chunks[] = trim( $current );
				}
				
				if ( mb_strlen( $p ) > $max_len ) {
					// Hard split if a single paragraph is too long
					$sub = $this->mb_str_split( $p, $max_len );
					foreach ( $sub as $s ) {
						$chunks[] = $s;
					}
					$current = "";
				} else {
					$current = $p;
				}
			} else {
				$current .= ( empty( $current ) ? "" : "\n\n" ) . $p;
			}
		}

		if ( ! empty( $current ) ) {
			$chunks[] = trim( $current );
		}

		return $chunks;
	}

	private function mb_str_split($string, $length) {
		$result = [];
		$strlen = mb_strlen($string);
		for ($i = 0; $i < $strlen; $i += $length) $result[] = mb_substr($string, $i, $length);
		return $result;
	}

	private function update_job_state( $job_id, $results ) {
		$queue = get_option( CEL_AI_Job_Queue::OPTION_NAME, [] );
		if ( isset( $queue[ $job_id ] ) ) {
			$queue[ $job_id ]['results'] = $results;
			$current = count($results);
			$total = count($queue[ $job_id ]['steps']);
			$queue[ $job_id ]['progress'] = [
				'current' => $current,
				'total'   => $total,
				'percent' => round( ($current / $total) * 100 ),
			];
			$queue[ $job_id ]['updated_at'] = current_time( 'mysql' );
			update_option( CEL_AI_Job_Queue::OPTION_NAME, $queue, false );
		}
	}

	private function log_to_job( $job_id, $message ) {
		$queue = get_option( CEL_AI_Job_Queue::OPTION_NAME, [] );
		if ( isset( $queue[ $job_id ] ) ) {
			$queue[ $job_id ]['log'][] = $message;
			$queue[ $job_id ]['updated_at'] = current_time( 'mysql' );
			update_option( CEL_AI_Job_Queue::OPTION_NAME, $queue, false );
		}
	}

	private function copy_essential_meta( $source_id, $target_id ) {
		$keys = [ '_thumbnail_id', '_product_image_gallery', '_price', '_regular_price', '_sale_price', '_sku', '_stock', '_stock_status', '_product_attributes' ];
		foreach ( $keys as $key ) {
			$val = get_post_meta( $source_id, $key, true );
			if ( $val !== '' ) update_post_meta( $target_id, $key, $val );
		}
	}
}

new CEL_AI_Job_Queue();
