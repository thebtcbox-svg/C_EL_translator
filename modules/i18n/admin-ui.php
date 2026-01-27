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
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_cel_ai_test_connection', [ $this, 'ajax_test_connection' ] );
		add_action( 'add_meta_boxes', [ $this, 'add_translation_meta_box' ] );
		add_action( 'wp_ajax_cel_ai_trigger_translation', [ $this, 'ajax_trigger_translation' ] );
		add_action( 'wp_ajax_cel_ai_get_job_status', [ $this, 'ajax_get_job_status' ] );
		add_action( 'wp_ajax_cel_ai_cancel_job', [ $this, 'ajax_cancel_job' ] );
		add_action( 'wp_ajax_cel_ai_check_updates', [ $this, 'ajax_check_updates' ] );
	}

	public function enqueue_assets( $hook ) {
		// Only enqueue on relevant pages
		$allowed_hooks = [ 'settings_page_cel-ai-settings', 'post.php', 'post-new.php' ];
		if ( ! in_array( $hook, $allowed_hooks ) ) {
			return;
		}

		wp_enqueue_style( 'cel-ai-admin-style', CEL_AI_URL . 'assets/admin.css', [], CEL_AI_VERSION );
		wp_enqueue_script( 'cel-ai-admin-script', CEL_AI_URL . 'assets/admin.js', [ 'jquery' ], CEL_AI_VERSION, true );

		wp_localize_script( 'cel-ai-admin-script', 'celAiAdmin', [
			'testNonce'       => wp_create_nonce( 'cel_ai_test_nonce' ),
			'transNonce'      => wp_create_nonce( 'cel_ai_trans_nonce' ),
			'jobStatusNonce'  => wp_create_nonce( 'cel_ai_job_status_nonce' ),
			'updateNonce'     => wp_create_nonce( 'cel_ai_update_nonce' ),
		] );
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
		register_setting( 'cel_ai_settings_group', 'cel_ai_manual_model_id' );
		register_setting( 'cel_ai_settings_group', 'cel_ai_switcher_location' );
		register_setting( 'cel_ai_settings_group', 'cel_ai_publish_status' );

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
			__( 'Model Selection', 'cel-ai' ),
			[ $this, 'render_model_id_field' ],
			'cel-ai-settings',
			'cel_ai_main_section'
		);

		add_settings_field(
			'cel_ai_manual_model_id',
			__( 'Manual Model ID (Optional)', 'cel-ai' ),
			[ $this, 'render_manual_model_id_field' ],
			'cel-ai-settings',
			'cel_ai_main_section'
		);

		add_settings_field(
			'cel_ai_switcher_location',
			__( 'Language Switcher Location', 'cel-ai' ),
			[ $this, 'render_switcher_location_field' ],
			'cel-ai-settings',
			'cel_ai_main_section'
		);

		add_settings_field(
			'cel_ai_publish_status',
			__( 'Default Translation Status', 'cel-ai' ),
			[ $this, 'render_publish_status_field' ],
			'cel-ai-settings',
			'cel_ai_main_section'
		);
	}

	public function render_api_key_field() {
		$key = get_option( 'cel_ai_openrouter_key' );
		$constant_key = defined( 'CEL_AI_OPENROUTER_KEY' ) ? CEL_AI_OPENROUTER_KEY : false;
		
		if ( $constant_key ) {
			echo '<input type="password" value="********" class="regular-text" readonly disabled />';
			echo '<p class="description">' . __( 'Key loaded from wp-config.php (read-only)', 'cel-ai' ) . '</p>';
		} else {
			echo '<input type="password" name="cel_ai_openrouter_key" value="' . esc_attr( $key ) . '" class="regular-text" />';
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
		echo '<p class="description">' . __( 'Choose a model or enter one manually below.', 'cel-ai' ) . '</p>';
	}

	public function render_manual_model_id_field() {
		$val = get_option( 'cel_ai_manual_model_id', '' );
		echo '<input type="text" name="cel_ai_manual_model_id" value="' . esc_attr( $val ) . '" class="regular-text" placeholder="e.g. google/gemini-pro" />';
		echo '<p class="description">' . __( 'If provided, this ID will take priority over the dropdown selection.', 'cel-ai' ) . '</p>';
	}

	public function render_switcher_location_field() {
		$val = get_option( 'cel_ai_switcher_location', 'none' );
		$options = [
			'none'   => __( 'None (Manual only)', 'cel-ai' ),
			'top'    => __( 'Top of Content', 'cel-ai' ),
			'bottom' => __( 'Bottom of Content', 'cel-ai' ),
			'both'   => __( 'Both Top and Bottom', 'cel-ai' ),
			'header' => __( 'Site Header (Fixed)', 'cel-ai' ),
		];
		echo '<select name="cel_ai_switcher_location">';
		foreach ( $options as $k => $v ) {
			echo '<option value="' . esc_attr( $k ) . '" ' . selected( $val, $k, false ) . '>' . esc_html( $v ) . '</option>';
		}
		echo '</select>';
	}

	public function render_publish_status_field() {
		$val = get_option( 'cel_ai_publish_status', 'draft' );
		$options = [
			'draft'   => __( 'Save as Draft', 'cel-ai' ),
			'publish' => __( 'Publish Immediately', 'cel-ai' ),
		];
		echo '<select name="cel_ai_publish_status">';
		foreach ( $options as $k => $v ) {
			echo '<option value="' . esc_attr( $k ) . '" ' . selected( $val, $k, false ) . '>' . esc_html( $v ) . '</option>';
		}
		echo '</select>';
	}

	public function render_settings_page() {
		$active_tab = isset( $_GET['tab'] ) ? $_GET['tab'] : 'general';
		$queue = get_option( CEL_AI_Job_Queue::OPTION_NAME, [] );
		$active_jobs = array_filter( $queue, function( $job ) {
			return in_array( $job['status'], [ 'pending', 'running', 'retry' ] );
		} );
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
				<p class="description"><?php _e( 'Linked Repository: thebtcbox-svg/C_EL_translator', 'cel-ai' ); ?></p>
				<button type="button" id="cel-ai-check-updates" class="button"><?php _e( 'Check for Updates from GitHub', 'cel-ai' ); ?></button>
				<div id="cel-ai-update-result" style="margin-top: 10px;"></div>
			<?php elseif ( $active_tab == 'tools' ) : ?>
				<h2><?php _e( 'Current Translation Queue', 'cel-ai' ); ?></h2>
				<div id="cel-ai-global-queue">
					<?php if ( empty( $active_jobs ) ) : ?>
						<p><?php _e( 'No active jobs in the queue.', 'cel-ai' ); ?></p>
					<?php else : ?>
						<table class="wp-list-table widefat fixed striped">
							<thead>
								<tr>
									<th><?php _e( 'Page/Product', 'cel-ai' ); ?></th>
									<th><?php _e( 'Language', 'cel-ai' ); ?></th>
									<th><?php _e( 'Progress', 'cel-ai' ); ?></th>
									<th><?php _e( 'Actions', 'cel-ai' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $active_jobs as $job ) : 
									$p = get_post( $job['post_id'] );
									$title = $p ? $p->post_title : 'Unknown';
									$pct = isset( $job['progress'] ) ? $job['progress']['percent'] : 0;
								?>
								<tr id="job-row-<?php echo $job['id']; ?>">
									<td><?php echo esc_html( $title ); ?></td>
									<td><?php echo esc_html( strtoupper( $job['target_language'] ) ); ?></td>
									<td>
										<div class="cel-ai-progress-container" id="progress-global-<?php echo $job['id']; ?>">
											<div class="cel-ai-bar-wrapper">
												<div class="cel-ai-bar" style="width: <?php echo $pct; ?>%;"></div>
											</div>
											<small class="cel-ai-status-text" data-job-id="<?php echo $job['id']; ?>"><?php echo ucfirst( $job['status'] ); ?> (<?php echo $pct; ?>%)</small>
										</div>
									</td>
									<td>
										<button type="button" class="button-link cel-ai-cancel-btn" data-job-id="<?php echo $job['id']; ?>" style="color:red;"><?php _e( 'Cancel', 'cel-ai' ); ?></button>
									</td>
								</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>

				<hr>
				<h2><?php _e( 'New Bulk Translation', 'cel-ai' ); ?></h2>
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
			echo '<div class="cel-ai-bar-wrapper">';
			$pct = $active_job && isset( $active_job['progress'] ) ? $active_job['progress']['percent'] : 0;
			echo '<div class="cel-ai-bar" style="width: ' . $pct . '%;"></div>';
			echo '</div>';
			echo '<div style="display:flex; justify-content:space-between; align-items:center; margin-top:2px;">';
			if ( $active_job ) {
				echo '<small class="cel-ai-status-text" data-job-id="' . $active_job['id'] . '">Status: ' . esc_html( ucfirst( $active_job['status'] ) ) . ' (' . $pct . '%)</small>';
				echo ' <button type="button" class="cel-ai-cancel-btn" data-job-id="' . $active_job['id'] . '">' . __( '[Cancel]', 'cel-ai' ) . '</button>';
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
		$repo = 'thebtcbox-svg/C_EL_translator';
		$url = "https://api.github.com/repos/{$repo}/releases/latest";
		$response = wp_remote_get( $url, [ 'headers' => [ 'User-Agent' => 'WordPress/' . get_bloginfo('version') ] ] );
		if ( is_wp_error( $response ) ) {
			wp_send_json_error( [ 'message' => 'Could not connect to GitHub API.' ] );
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array($body) ) {
			wp_send_json_error( [ 'message' => 'Invalid response from GitHub.' ] );
		}
		$latest_version = isset( $body['tag_name'] ) ? ltrim( $body['tag_name'], 'v' ) : '';
		if ( empty( $latest_version ) ) {
			wp_send_json_error( [ 'message' => 'No releases found in this repository.' ] );
		}
		if ( version_compare( CEL_AI_VERSION, $latest_version, '<' ) ) {
			$basename = plugin_basename( CEL_AI_PATH . 'cel-ai.php' );
			$update_url = wp_nonce_url( self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . urlencode( $basename ) ), 'upgrade-plugin_' . $basename );
			wp_send_json_success( [ 'message' => "New version available: <strong>{$latest_version}</strong>. <a href='{$update_url}' class='button button-primary'>Update Automatically Now</a>" ] );
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
		if ( is_array($result) && $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( is_array($result) ? $result : [ 'message' => 'Unknown error' ] );
		}
	}
}

new CEL_AI_Admin_UI();
