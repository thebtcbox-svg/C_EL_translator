<?php
/**
 * Admin UI and Settings for CEL AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CEL_AI_Admin_UI {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_settings_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'wp_ajax_cel_ai_test_connection', [ $this, 'ajax_test_connection' ] );
		add_action( 'add_meta_boxes', [ $this, 'add_translation_meta_box' ] );
		add_action( 'wp_ajax_cel_ai_trigger_translation', [ $this, 'ajax_trigger_translation' ] );
		add_action( 'wp_ajax_cel_ai_get_job_status', [ $this, 'ajax_get_job_status' ] );
		add_action( 'wp_ajax_cel_ai_cancel_job', [ $this, 'ajax_cancel_job' ] );
		add_action( 'wp_ajax_cel_ai_check_updates', [ $this, 'ajax_check_updates' ] );
	}

	public function add_settings_menu() {
		add_options_page(
			__( 'AI Translate Settings', 'cel-ai' ),
			__( 'AI Translate', 'cel-ai' ),
			'manage_options',
			'cel-ai-settings',
			[ $this, 'render_settings_page' ]
		);
	}

	public function register_settings() {
		register_setting( 'cel_ai_settings_group', 'cel_ai_openrouter_key' );
		register_setting( 'cel_ai_settings_group', 'cel_ai_model_id' );
		register_setting( 'cel_ai_settings_group', 'cel_ai_auto_switcher' );
		register_setting( 'cel_ai_settings_group', 'cel_ai_github_repo' );

		add_settings_section(
			'cel_ai_main_section',
			__( 'OpenRouter Configuration', 'cel-ai' ),
			null,
			'cel-ai-settings'
		);

		add_settings_field(
			'cel_ai_openrouter_key',
			__( 'OpenRouter API Key', 'cel-ai' ),
			[ $this, 'render_api_key_field' ],
			'cel-ai-settings',
			'cel_ai_main_section'
		);

		add_settings_field(
			'cel_ai_model_id',
			__( 'Model ID', 'cel-ai' ),
			[ $this, 'render_model_id_field' ],
			'cel-ai-settings',
			'cel_ai_main_section'
		);

		add_settings_field(
			'cel_ai_auto_switcher',
			__( 'Auto-inject Switcher', 'cel-ai' ),
			[ $this, 'render_auto_switcher_field' ],
			'cel-ai-settings',
			'cel_ai_main_section'
		);

		add_settings_field(
			'cel_ai_github_repo',
			__( 'GitHub Repository', 'cel-ai' ),
			[ $this, 'render_github_repo_field' ],
			'cel-ai-settings',
			'cel_ai_main_section'
		);
	}

	public function render_api_key_field() {
		$key = get_option( 'cel_ai_openrouter_key' );
		$constant_key = defined( 'CEL_AI_OPENROUTER_KEY' ) ? CEL_AI_OPENROUTER_KEY : false;
		$readonly = $constant_key ? 'readonly' : '';
		$value = $constant_key ? $constant_key : $key;

		echo '<input type="password" name="cel_ai_openrouter_key" value="' . esc_attr( $value ) . '" class="regular-text" ' . $readonly . ' />';
		if ( $constant_key ) {
			echo '<p class="description">' . __( 'Key loaded from wp-config.php (read-only)', 'cel-ai' ) . '</p>';
		}
	}

	public function render_model_id_field() {
		$selected_model = get_option( 'cel_ai_model_id', 'mistralai/mistral-7b-instruct' );
		$models = [
			'mistralai/mistral-7b-instruct' => 'Mistral 7B Instruct (Mistral AI) - FREE',
			'meta-llama/llama-3-8b-instruct' => 'Llama 3 8B Instruct (Meta) - FREE',
			'anthropic/claude-3.5-sonnet' => 'Claude 3.5 Sonnet (Anthropic) - PAID',
			'openai/gpt-4o' => 'GPT-4o (OpenAI) - PAID',
		];

		echo '<select name="cel_ai_model_id">';
		foreach ( $models as $id => $name ) {
			echo '<option value="' . esc_attr( $id ) . '" ' . selected( $selected_model, $id, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select>';
	}

	public function render_auto_switcher_field() {
		$val = get_option( 'cel_ai_auto_switcher', '0' );
		echo '<input type="checkbox" name="cel_ai_auto_switcher" value="1" ' . checked( $val, '1', false ) . ' />';
		echo '<p class="description">' . __( 'Automatically add the language switcher to the bottom of pages/products.', 'cel-ai' ) . '</p>';
	}

	public function render_github_repo_field() {
		$val = get_option( 'cel_ai_github_repo', 'thebtcbox-svg/C_EL_translator' );
		echo '<input type="text" name="cel_ai_github_repo" value="' . esc_attr( $val ) . '" class="regular-text" />';
		echo '<p class="description">' . __( 'GitHub username/repository for updates (e.g. "thebtcbox-svg/C_EL_translator").', 'cel-ai' ) . '</p>';
	}

	public function render_settings_page() {
		$active_tab = isset( $_GET['tab'] ) ? $_GET['tab'] : 'general';
		?>
		<div class="wrap">
			<h1><?php _e( 'AI Translate Settings', 'cel-ai' ); ?></h1>
			<h2 class="nav-tab-wrapper">
				<a href="?page=cel-ai-settings&tab=general" class="nav-tab <?php echo $active_tab == 'general' ? 'nav-tab-active' : ''; ?>"><?php _e( 'General', 'cel-ai' ); ?></a>
				<a href="?page=cel-ai-settings&tab=tools" class="nav-tab <?php echo $active_tab == 'tools' ? 'nav-tab-active' : ''; ?>"><?php _e( 'Tools', 'cel-ai' ); ?></a>
				<a href="?page=cel-ai-settings&tab=logs" class="nav-tab <?php echo $active_tab == 'logs' ? 'nav-tab-active' : ''; ?>"><?php _e( 'Logs', 'cel-ai' ); ?></a>
			</h2>

			<?php if ( $active_tab == 'general' ) : ?>
				<form method="post" action="options.php">
					<?php
					settings_fields( 'cel_ai_settings_group' );
					do_settings_sections( 'cel-ai-settings' );
					submit_button();
					?>
				</form>
				<hr>
				<h2><?php _e( 'Connection Test', 'cel-ai' ); ?></h2>
				<button type="button" id="cel-ai-test-connection" class="button"><?php _e( 'Test OpenRouter Connection', 'cel-ai' ); ?></button>
				<div id="cel-ai-test-result" style="margin-top: 10px;"></div>
				<hr>
				<h2><?php _e( 'Plugin Updates', 'cel-ai' ); ?></h2>
				<button type="button" id="cel-ai-check-updates" class="button"><?php _e( 'Check for Updates from GitHub', 'cel-ai' ); ?></button>
				<div id="cel-ai-update-result" style="margin-top: 10px;"></div>
			<?php elseif ( $active_tab == 'tools' ) : ?>
				<h2><?php _e( 'Bulk Translation', 'cel-ai' ); ?></h2>
				<p><?php _e( 'Select a page and target language to translate.', 'cel-ai' ); ?></p>
				<table class="form-table">
					<tr>
						<th><?php _e( 'Select Page/Product', 'cel-ai' ); ?></th>
						<td>
							<?php
							$posts = get_posts( [ 'post_type' => [ 'post', 'page', 'product' ], 'posts_per_page' => -1 ] );
							echo '<select id="cel_ai_bulk_post_id" class="regular-text">';
							foreach ( $posts as $p ) {
								echo '<option value="' . $p->ID . '">' . esc_html( $p->post_title ) . ' (' . $p->post_type . ')</option>';
							}
							echo '</select>';
							?>
						</td>
					</tr>
					<tr>
						<th><?php _e( 'Target Language', 'cel-ai' ); ?></th>
						<td>
							<?php
							$langs = CEL_AI_I18N_Controller::get_supported_languages();
							echo '<select id="cel_ai_bulk_target_lang">';
							foreach ( $langs as $code => $l ) {
								echo '<option value="' . $code . '">' . esc_html( $l['name'] ) . '</option>';
							}
							echo '</select>';
							?>
						</td>
					</tr>
				</table>
				<button type="button" id="cel-ai-run-bulk" class="button button-primary"><?php _e( 'Start Translation', 'cel-ai' ); ?></button>
				<div id="cel-ai-bulk-progress" style="display:none; margin-top: 20px;">
					<div style="width: 100%; background: #eee; height: 20px; border-radius: 10px; overflow: hidden;">
						<div id="cel-ai-bulk-bar" style="width: 0%; background: #0073aa; height: 100%; transition: width 0.3s;"></div>
					</div>
					<p id="cel-ai-bulk-status"></p>
				</div>
				<div id="cel-ai-bulk-result" style="margin-top: 10px;"></div>
			<?php elseif ( $active_tab == 'logs' ) : ?>
				<h2><?php _e( 'Plugin Logs', 'cel-ai' ); ?></h2>
				<pre style="background: #f4f4f4; padding: 15px; border: 1px solid #ddd; max-height: 400px; overflow: auto;"><?php
					$log_file = CEL_AI_PATH . 'logs/plugin.log';
					if ( file_exists( $log_file ) ) {
						echo esc_html( file_get_contents( $log_file ) );
					} else {
						_e( 'No logs found.', 'cel-ai' );
					}
				?></pre>
				<button type="button" class="button" onclick="window.location.reload();"><?php _e( 'Refresh Logs', 'cel-ai' ); ?></button>
			<?php endif; ?>
		</div>

		<script>
		jQuery(document).ready(function($) {
			function pollJobStatus(jobId, progressBarId, statusId, resultId) {
				var interval = setInterval(function() {
					$.post(ajaxurl, {
						action: 'cel_ai_get_job_status',
						job_id: jobId,
						nonce: '<?php echo wp_create_nonce( 'cel_ai_job_status_nonce' ); ?>'
					}, function(response) {
						if (response.success) {
							var job = response.data;
							var percent = job.progress ? job.progress.percent : 0;
							if (progressBarId) $('#' + progressBarId).css('width', percent + '%');
							if (statusId) $('#' + statusId).text(job.status.charAt(0).toUpperCase() + job.status.slice(1) + ' (' + percent + '%)');
							
							if (job.status === 'completed' || job.status === 'failed') {
								clearInterval(interval);
								if (job.status === 'completed') {
									if (resultId) $('#' + resultId).html('<span style="color: green;">Translation Complete!</span>');
									window.location.reload();
								} else {
									if (resultId) $('#' + resultId).html('<span style="color: red;">Job Failed. Check logs.</span>');
								}
							}
						}
					});
				}, 3000);
			}

			$('#cel-ai-check-updates').on('click', function() {
				var btn = $(this);
				btn.prop('disabled', true).text('Checking...');
				$.post(ajaxurl, {
					action: 'cel_ai_check_updates',
					nonce: '<?php echo wp_create_nonce( 'cel_ai_update_nonce' ); ?>'
				}, function(response) {
					btn.prop('disabled', false).text('Check for Updates from GitHub');
					if (response.success) {
						$('#cel-ai-update-result').html('<span style="color: green;">' + response.data.message + '</span>');
					} else {
						$('#cel-ai-update-result').html('<span style="color: red;">' + response.data.message + '</span>');
					}
				});
			});

			$('#cel-ai-test-connection').on('click', function() {
				var btn = $(this);
				btn.prop('disabled', true);
				$('#cel-ai-test-result').html('Testing...');

				$.post(ajaxurl, {
					action: 'cel_ai_test_connection',
					nonce: '<?php echo wp_create_nonce( 'cel_ai_test_nonce' ); ?>'
				}, function(response) {
					btn.prop('disabled', false);
					if (response.success) {
						$('#cel-ai-test-result').html('<span style="color: green;">' + response.data.message + '</span>');
					} else {
						$('#cel-ai-test-result').html('<span style="color: red;">' + response.data.message + '</span>');
					}
				});
			});

			$('#cel-ai-run-bulk').on('click', function() {
				var btn = $(this);
				btn.prop('disabled', true).text('Queuing...');
				$('#cel-ai-bulk-progress').show();
				$('#cel-ai-bulk-bar').css('width', '0%');

				$.post(ajaxurl, {
					action: 'cel_ai_trigger_translation',
					post_id: $('#cel_ai_bulk_post_id').val(),
					target_lang: $('#cel_ai_bulk_target_lang').val(),
					nonce: '<?php echo wp_create_nonce( 'cel_ai_trans_nonce' ); ?>'
				}, function(response) {
					btn.prop('disabled', false).text('Start Translation');
					if (response.success) {
						pollJobStatus(response.data.job_id, 'cel_ai_bulk_bar', 'cel_ai_bulk_status', 'cel_ai_bulk_result');
					} else {
						$('#cel-ai-bulk-result').html('<span style="color: red;">' + response.data.message + '</span>');
					}
				});
			});

			// Auto-start polling for existing active jobs on sidebar
			$('.cel-ai-progress-container:visible').each(function() {
				var container = $(this);
				var jobId = container.find('.cel-ai-job-id').data('job-id');
				if (jobId) {
					pollJobStatus(jobId, container.attr('id') + ' .cel-ai-bar', container.attr('id') + ' .cel-ai-status-text');
				}
			});

			$(document).on('click', '.cel-ai-cancel-btn', function() {
				var btn = $(this);
				var jobId = btn.data('job-id');
				if (confirm('Cancel this job?')) {
					$.post(ajaxurl, {
						action: 'cel_ai_cancel_job',
						job_id: jobId,
						nonce: '<?php echo wp_create_nonce( 'cel_ai_job_status_nonce' ); ?>'
					}, function() {
						window.location.reload();
					});
				}
			});

			$('.cel-ai-translate-btn').on('click', function() {
				var btn = $(this);
				var lang = btn.data('lang');
				var originalText = btn.text();
				btn.prop('disabled', true).text('Queuing...');
				$('#progress-' + lang).show();

				$.post(ajaxurl, {
					action: 'cel_ai_trigger_translation',
					post_id: btn.data('post-id'),
					target_lang: lang,
					nonce: '<?php echo wp_create_nonce( 'cel_ai_trans_nonce' ); ?>'
				}, function(response) {
					if (response.success) {
						pollJobStatus(response.data.job_id, 'progress-' + lang + ' .cel-ai-bar', 'progress-' + lang + ' .cel-ai-status-text');
					} else {
						alert(response.data.message);
						btn.prop('disabled', false).text(originalText);
					}
				});
			});
		});
		</script>
		<?php
	}

	public function add_translation_meta_box() {
		$screens = [ 'post', 'page', 'product' ];
		foreach ( $screens as $screen ) {
			add_meta_box(
				'cel_ai_translations',
				__( 'AI Translations', 'cel-ai' ),
				[ $this, 'render_translation_meta_box' ],
				$screen,
				'side',
				'default'
			);
		}
	}

	public function render_translation_meta_box( $post ) {
		$languages = CEL_AI_I18N_Controller::get_supported_languages();
		$translations = CEL_AI_I18N_Controller::get_translations( $post->ID );
		$site_lang = substr( get_locale(), 0, 2 );
		$queue = get_option( CEL_AI_Job_Queue::OPTION_NAME, [] );

		CEL_AI_I18N_Controller::ensure_group_id( $post->ID );

		echo '<div class="cel-ai-translation-list">';
		foreach ( $languages as $code => $lang ) {
			if ( $code === $site_lang ) {
				continue;
			}

			echo '<div style="margin-bottom: 10px; border-bottom: 1px solid #eee; padding-bottom: 5px;">';
			echo '<strong>' . esc_html( $lang['name'] ) . '</strong>: ';

			if ( isset( $translations[ $code ] ) ) {
				$trans_post = $translations[ $code ];
				echo '<a href="' . get_edit_post_link( $trans_post->ID ) . '">' . __( 'Edit', 'cel-ai' ) . '</a> | ';
				echo '<button type="button" class="button-link cel-ai-translate-btn" data-post-id="' . $post->ID . '" data-lang="' . $code . '">' . __( 'Update', 'cel-ai' ) . '</button>';
			} else {
				echo '<button type="button" class="button button-small cel-ai-translate-btn" data-post-id="' . $post->ID . '" data-lang="' . $code . '">' . __( 'Translate', 'cel-ai' ) . '</button>';
			}

			$active_job = null;
			foreach ( $queue as $job ) {
				if ( $job['post_id'] == $post->ID && $job['target_language'] == $code && in_array( $job['status'], [ 'pending', 'running', 'retry' ] ) ) {
					$active_job = $job;
					break;
				}
			}

			echo '<div class="cel-ai-progress-container" id="progress-' . $code . '" style="' . ( $active_job ? '' : 'display:none;' ) . ' margin-top:5px;">';
			echo '<div style="width: 100%; background: #eee; height: 5px; border-radius: 3px; overflow: hidden;">';
			$pct = $active_job && isset( $active_job['progress'] ) ? $active_job['progress']['percent'] : 0;
			echo '<div class="cel-ai-bar" style="width: ' . $pct . '%; background: #0073aa; height: 100%;"></div>';
			echo '</div>';
			echo '<div style="display:flex; justify-content:space-between; align-items:center; margin-top:2px;">';
			if ( $active_job ) {
				echo '<small class="cel-ai-status-text" class="cel-ai-job-id" data-job-id="' . $active_job['id'] . '">Status: ' . esc_html( ucfirst( $active_job['status'] ) ) . '</small>';
				echo ' <button type="button" class="cel-ai-cancel-btn" data-job-id="' . $active_job['id'] . '" style="border:none; background:none; color:red; cursor:pointer; font-size:10px; padding:0;">[Cancel]</button>';
			} else {
				echo '<small class="cel-ai-status-text"></small>';
			}
			echo '</div>';
			echo '</div>';
			echo '</div>';
		}
		echo '</div>';
	}

	public function ajax_trigger_translation() {
		check_ajax_referer( 'cel_ai_trans_nonce', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized', 'cel-ai' ) ] );
		}
		$post_id     = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
		$target_lang = isset( $_POST['target_lang'] ) ? sanitize_text_field( $_POST['target_lang'] ) : '';
		if ( ! $post_id || ! $target_lang ) {
			wp_send_json_error( [ 'message' => __( 'Missing parameters.', 'cel-ai' ) ] );
		}
		$queue = new CEL_AI_Job_Queue();
		$job_id = $queue->enqueue( [
			'post_id'         => $post_id,
			'target_language' => $target_lang,
		] );
		wp_send_json_success( [ 'job_id' => $job_id ] );
	}

	public function ajax_get_job_status() {
		check_ajax_referer( 'cel_ai_job_status_nonce', 'nonce' );
		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( $_POST['job_id'] ) : '';
		$job = CEL_AI_Job_Queue::get_job( $job_id );
		if ( $job ) {
			wp_send_json_success( $job );
		} else {
			wp_send_json_error( [ 'message' => 'Job not found' ] );
		}
	}

	public function ajax_cancel_job() {
		check_ajax_referer( 'cel_ai_job_status_nonce', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized', 'cel-ai' ) ] );
		}
		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( $_POST['job_id'] ) : '';
		$queue = get_option( CEL_AI_Job_Queue::OPTION_NAME, [] );
		if ( isset( $queue[ $job_id ] ) ) {
			$queue[ $job_id ]['status'] = 'failed';
			$queue[ $job_id ]['log'][] = 'Cancelled by user.';
			update_option( CEL_AI_Job_Queue::OPTION_NAME, $queue, false );
			wp_send_json_success();
		} else {
			wp_send_json_error( [ 'message' => 'Job not found' ] );
		}
	}

	public function ajax_check_updates() {
		check_ajax_referer( 'cel_ai_update_nonce', 'nonce' );
		$repo = get_option( 'cel_ai_github_repo', 'thebtcbox-svg/C_EL_translator' );
		if ( empty( $repo ) ) {
			wp_send_json_error( [ 'message' => 'Please configure your GitHub repository path first.' ] );
		}
		$url = "https://api.github.com/repos/{$repo}/releases/latest";
		$response = wp_remote_get( $url, [ 'headers' => [ 'User-Agent' => 'WordPress/' . get_bloginfo('version') ] ] );
		if ( is_wp_error( $response ) ) {
			wp_send_json_error( [ 'message' => 'Could not connect to GitHub API.' ] );
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$latest_version = isset( $body['tag_name'] ) ? ltrim( $body['tag_name'], 'v' ) : '';
		if ( empty( $latest_version ) ) {
			wp_send_json_error( [ 'message' => 'No releases found in this repository.' ] );
		}
		if ( version_compare( CEL_AI_VERSION, $latest_version, '<' ) ) {
			$download_url = $body['zipball_url'];
			wp_send_json_success( [ 'message' => "New version available: <strong>{$latest_version}</strong>. <a href='{$download_url}' class='button'>Download ZIP</a>" ] );
		} else {
			wp_send_json_success( [ 'message' => 'You are using the latest version (' . CEL_AI_VERSION . ').' ] );
		}
	}

	public function ajax_test_connection() {
		check_ajax_referer( 'cel_ai_test_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized', 'cel-ai' ) ] );
		}
		$client = new CEL_AI_AI_Client();
		$result = $client->test_connection();
		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}
}

new CEL_AI_Admin_UI();
