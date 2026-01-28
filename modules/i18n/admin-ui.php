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
		add_filter( 'plugin_action_links_' . plugin_basename( CEL_AI_PATH . 'cel-ai.php' ), [ $this, 'add_plugin_action_links' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_cel_ai_test_connection', [ $this, 'ajax_test_connection' ] );
		add_action( 'add_meta_boxes', [ $this, 'add_translation_meta_box' ] );
		add_action( 'wp_ajax_cel_ai_trigger_translation', [ $this, 'ajax_trigger_translation' ] );
		add_action( 'wp_ajax_cel_ai_get_job_status', [ $this, 'ajax_get_job_status' ] );
		add_action( 'wp_ajax_cel_ai_cancel_job', [ $this, 'ajax_cancel_job' ] );
		add_action( 'wp_ajax_cel_ai_retry_job', [ $this, 'ajax_retry_job' ] );
		add_action( 'wp_ajax_cel_ai_delete_job', [ $this, 'ajax_delete_job' ] );
		add_action( 'wp_ajax_cel_ai_clear_queue', [ $this, 'ajax_clear_queue' ] );
		add_action( 'wp_ajax_cel_ai_process_queue_manual', [ $this, 'ajax_process_queue_manual' ] );
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

	public function add_plugin_action_links( $links ) {
		$settings_link = '<a href="' . admin_url( 'options-general.php?page=cel-ai-settings' ) . '">' . __( 'Settings', 'cel-ai' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	public function register_settings() {
		register_setting( 'cel_ai_settings_group', 'cel_ai_openrouter_key' );
		register_setting( 'cel_ai_settings_group', 'cel_ai_model_id' );
		register_setting( 'cel_ai_settings_group', 'cel_ai_manual_model_id' );
		register_setting( 'cel_ai_settings_group', 'cel_ai_active_languages' );
		register_setting( 'cel_ai_settings_group', 'cel_ai_switcher_format' );
		register_setting( 'cel_ai_settings_group', 'cel_ai_auto_switcher' );
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
			'cel_ai_active_languages',
			__( 'Active Languages', 'cel-ai' ),
			[ $this, 'render_active_languages_field' ],
			'cel-ai-settings',
			'cel_ai_main_section'
		);

		add_settings_field(
			'cel_ai_auto_switcher',
			__( 'Enable Language Switcher', 'cel-ai' ),
			[ $this, 'render_auto_switcher_field' ],
			'cel-ai-settings',
			'cel_ai_main_section'
		);

		add_settings_field(
			'cel_ai_switcher_format',
			__( 'Switcher Format', 'cel-ai' ),
			[ $this, 'render_switcher_format_field' ],
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
			'x-ai/grok-2-1212' => 'Grok 2 (xAI) - PAID',
			'anthropic/claude-3.5-sonnet' => 'Claude 3.5 Sonnet (Anthropic) - PAID',
			'openai/gpt-4o' => 'GPT-4o (OpenAI) - PAID',
		];

		echo '<select name="cel_ai_model_id">';
		foreach ( $models as $id => $name ) {
			echo '<option value="' . esc_attr( $id ) . '" ' . selected( $selected_model, $id, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select>';
	}

	public function render_manual_model_id_field() {
		$val = get_option( 'cel_ai_manual_model_id', '' );
		echo '<input type="text" name="cel_ai_manual_model_id" value="' . esc_attr( $val ) . '" class="regular-text" placeholder="e.g. google/gemini-pro" />';
	}

	public function render_active_languages_field() {
		$all_langs = CEL_AI_I18N_Controller::get_supported_languages();
		$active_langs = get_option( 'cel_ai_active_languages', array_keys( $all_langs ) );
		if ( ! is_array( $active_langs ) ) $active_langs = [];

		echo '<div style="max-height: 150px; overflow: auto; border: 1px solid #ddd; padding: 10px; background: #fff; border-radius: 4px;">';
		foreach ( $all_langs as $code => $lang ) {
			echo '<label style="display:block; margin-bottom:5px;">';
			echo '<input type="checkbox" name="cel_ai_active_languages[]" value="' . esc_attr( $code ) . '" ' . checked( in_array( $code, $active_langs ), true, false ) . ' /> ';
			echo esc_html( $lang['name'] );
			echo '</label>';
		}
		echo '</div>';
		echo '<p class="description">' . __( 'Only selected languages will appear in the frontend switcher.', 'cel-ai' ) . '</p>';
	}

	public function render_auto_switcher_field() {
		$val = get_option( 'cel_ai_auto_switcher', '1' );
		echo '<input type="checkbox" name="cel_ai_auto_switcher" value="1" ' . checked( $val, '1', false ) . ' />';
		echo '<p class="description">' . __( 'Show a compact language switcher in the top-right corner of the site.', 'cel-ai' ) . '</p>';
		echo '<div style="margin-top: 15px; padding: 10px; background: #e7f3ff; border-left: 4px solid #2196F3; border-radius: 4px;">';
		echo '<strong>' . __( 'Shortcode available:', 'cel-ai' ) . '</strong><br>';
		echo '<code>[cel_ai_switcher]</code>';
		echo '<p class="description" style="margin-top:5px;">' . __( 'Copy and paste this shortcode into any HTML field or text block to display the language switcher anywhere on your site.', 'cel-ai' ) . '</p>';
		echo '</div>';
	}

	public function render_switcher_format_field() {
		$val = get_option( 'cel_ai_switcher_format', 'code' );
		$options = [
			'code' => __( 'Short Code (e.g. EN)', 'cel-ai' ),
			'name' => __( 'Full Name (e.g. English)', 'cel-ai' ),
			'flag' => __( 'Flag Icon', 'cel-ai' ),
		];
		echo '<select name="cel_ai_switcher_format">';
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
		?>
		<div class="wrap">
			<h1><?php _e( 'AI Translate Settings', 'cel-ai' ); ?></h1>
			<h2 class="nav-tab-wrapper">
				<a href="?page=cel-ai-settings&tab=general" class="nav-tab <?php echo $active_tab == 'general' ? 'nav-tab-active' : ''; ?>"><?php _e( 'General', 'cel-ai' ); ?></a>
				<a href="?page=cel-ai-settings&tab=translations" class="nav-tab <?php echo $active_tab == 'translations' ? 'nav-tab-active' : ''; ?>"><?php _e( 'Translations', 'cel-ai' ); ?></a>
				<a href="?page=cel-ai-settings&tab=tools" class="nav-tab <?php echo $active_tab == 'tools' ? 'nav-tab-active' : ''; ?>"><?php _e( 'Queue', 'cel-ai' ); ?></a>
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

			<?php elseif ( $active_tab == 'translations' ) : ?>
				<h2><?php _e( 'Content Overview', 'cel-ai' ); ?></h2>
				<p><?php _e( 'Overview of your pages and their translated versions. Click a language code to Edit or Regenerate.', 'cel-ai' ); ?></p>
				<?php
				$active_langs = get_option( 'cel_ai_active_languages', [] );
				$all_supported = CEL_AI_I18N_Controller::get_supported_languages();
				
				$paged = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
				$per_page = 20;

				$args = [
					'post_type'      => [ 'post', 'page', 'product' ],
					'posts_per_page' => $per_page,
					'paged'          => $paged,
					'meta_query'     => [
						'relation' => 'OR',
						[ 'key' => CEL_AI_I18N_Controller::META_IS_ORIGINAL, 'value' => '1', 'compare' => '=' ],
						[ 'key' => CEL_AI_I18N_Controller::META_GROUP_ID, 'compare' => 'NOT EXISTS' ],
					],
				];
				$originals = new WP_Query( $args );
				?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php _e( 'Page / Product', 'cel-ai' ); ?></th>
							<th><?php _e( 'Type', 'cel-ai' ); ?></th>
							<th><?php _e( 'Translations', 'cel-ai' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( $originals->have_posts() ) : while ( $originals->have_posts() ) : $originals->the_post(); 
							$id = get_the_ID();
							$translations = CEL_AI_I18N_Controller::get_translations( $id );
							$site_lang = substr( get_locale(), 0, 2 );
						?>
						<tr>
							<td>
								<strong><a href="<?php echo get_edit_post_link( $id ); ?>"><?php the_title(); ?></a></strong>
								<div class="row-actions"><span class="view"><a href="<?php the_permalink(); ?>"><?php _e( 'View Original', 'cel-ai' ); ?></a></span></div>
							</td>
							<td><?php echo ucfirst( get_post_type() ); ?></td>
							<td>
								<?php foreach ( $active_langs as $code ) : 
									if ( $code === $site_lang ) continue;
									
									$trans_post = isset( $translations[ $code ] ) ? $translations[ $code ] : null;
									$status = $trans_post ? get_post_meta( $trans_post->ID, CEL_AI_I18N_Controller::META_STATUS, true ) : '';

									if ( $trans_post ) : ?>
										<div style="display: inline-block; margin-right: 15px; border-right: 1px solid #ddd; padding-right: 15px;">
											<a href="<?php echo get_edit_post_link( $trans_post->ID ); ?>" 
											   style="color: <?php echo ($status === 'failed' ? 'red' : 'green'); ?>; font-weight: bold; text-decoration: none;" 
											   title="<?php echo esc_attr( $all_supported[$code]['name'] ); ?>">
												<?php echo strtoupper( $code ); ?> <?php echo ($status === 'failed' ? '✗' : '✓'); ?>
											</a>
											<button type="button" class="button-link cel-ai-translate-btn" data-post-id="<?php echo $id; ?>" data-lang="<?php echo $code; ?>" style="font-size: 10px; color: #666; margin-left:5px;">[<?php _e('Regen', 'cel-ai'); ?>]</button>
										</div>
									<?php else : ?>
										<button type="button" class="button button-small cel-ai-translate-btn" data-post-id="<?php echo $id; ?>" data-lang="<?php echo $code; ?>" style="margin-right: 5px;">
											<?php echo strtoupper( $code ); ?>
										</button>
									<?php endif; ?>
								<?php endforeach; ?>
								<div class="cel-ai-dashboard-progress" id="progress-dashboard-<?php the_ID(); ?>" style="margin-top:5px;"></div>
							</td>
						</tr>
						<?php endwhile; wp_reset_postdata(); else : ?>
							<tr><td colspan="3"><?php _e( 'No content found.', 'cel-ai' ); ?></td></tr>
						<?php endif; ?>
					</tbody>
				</table>

				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<?php
						echo paginate_links( [
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'prev_text' => __( '&laquo;' ),
							'next_text' => __( '&raquo;' ),
							'total'     => $originals->max_num_pages,
							'current'   => $paged,
						] );
						?>
					</div>
				</div>

			<?php elseif ( $active_tab == 'tools' ) : ?>
				<div style="display:flex; justify-content:space-between; align-items:center;">
					<h2><?php _e( 'Current Translation Queue', 'cel-ai' ); ?></h2>
					<div>
						<button type="button" id="cel-ai-process-now" class="button button-primary"><?php _e( 'Process Next Step Now', 'cel-ai' ); ?></button>
						<button type="button" id="cel-ai-clear-queue" class="button"><?php _e( 'Clear All Jobs', 'cel-ai' ); ?></button>
					</div>
				</div>
				<div id="cel-ai-global-queue">
					<?php 
					$all_jobs = get_option( CEL_AI_Job_Queue::OPTION_NAME, [] );
					$visible_jobs = array_reverse( array_filter( $all_jobs, function( $job ) {
						return in_array( $job['status'], [ 'pending', 'running', 'retry', 'failed' ] );
					} ) );
					$visible_jobs = array_slice( $visible_jobs, 0, 50 ); // Limit to 50 most recent visible jobs
					
					if ( empty( $visible_jobs ) ) : ?>
						<p><?php _e( 'No active or failed jobs in the queue.', 'cel-ai' ); ?></p>
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
								<?php foreach ( $visible_jobs as $job ) : 
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
												<div class="cel-ai-bar" style="width: <?php echo $pct; ?>%; background-color: <?php echo ($job['status'] === 'failed' ? '#e74c3c' : '#3498db'); ?>;"></div>
											</div>
											<small class="cel-ai-status-text" data-job-id="<?php echo $job['id']; ?>"><?php echo ucfirst( $job['status'] ); ?> (<?php echo $pct; ?>%)</small>
										</div>
									</td>
									<td>
										<?php if ( $job['status'] === 'failed' ) : ?>
											<button type="button" class="button button-small cel-ai-retry-btn" data-job-id="<?php echo $job['id']; ?>"><?php _e( 'Retry', 'cel-ai' ); ?></button>
											<button type="button" class="button button-small cel-ai-delete-btn" data-job-id="<?php echo $job['id']; ?>" style="color:#a00; border-color:#a00; margin-left:5px;"><?php _e( 'Delete', 'cel-ai' ); ?></button>
										<?php else : ?>
											<button type="button" class="button-link cel-ai-cancel-btn" data-job-id="<?php echo $job['id']; ?>" style="color:red;"><?php _e( 'Cancel', 'cel-ai' ); ?></button>
										<?php endif; ?>
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
							// Limit to 200 most recent to prevent UI crash on large sites
							$posts = get_posts( [ 'post_type' => [ 'post', 'page', 'product' ], 'posts_per_page' => 200 ] );
							echo '<select id="cel_ai_bulk_post_id" class="regular-text">';
							foreach ( $posts as $p ) {
								echo '<option value="' . $p->ID . '">' . esc_html( $p->post_title ) . ' (' . $p->post_type . ')</option>';
							}
							echo '</select>';
							?>
							<p class="description"><?php _e( 'Showing 200 most recent items. For older items, use the translation button on the post edit page.', 'cel-ai' ); ?></p>
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
				<pre style="background: #f4f4f4; padding: 15px; border: 1px solid #ddd; max-height: 400px; overflow: auto; white-space: pre-wrap;"><?php
					$log_file = CEL_AI_PATH . 'logs/plugin.log';
					if ( file_exists( $log_file ) && is_readable( $log_file ) ) {
						$size = filesize($log_file);
						$max_read = 50 * 1024; // 50KB
						if ($size > $max_read) {
							$fp = @fopen($log_file, 'r');
							if ($fp) {
								fseek($fp, -$max_read, SEEK_END);
								$data = fread($fp, $max_read);
								fclose($fp);
								echo "... [Showing last 50KB of logs] ...\n\n";
								echo esc_html($data);
							} else {
								_e( 'Could not open log file.', 'cel-ai' );
							}
						} else {
							$data = @file_get_contents( $log_file );
							echo $data ? esc_html( $data ) : __( 'Log file is empty.', 'cel-ai' );
						}
					} else {
						if ( ! file_exists( $log_file ) ) {
							_e( 'Log file does not exist yet.', 'cel-ai' );
						} else {
							_e( 'Log file is not readable. Check permissions of the /logs directory.', 'cel-ai' );
						}
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
		$active_langs = get_option( 'cel_ai_active_languages', array_keys( $languages ) );
		$translations = CEL_AI_I18N_Controller::get_translations( $post->ID );
		$site_lang = substr( get_locale(), 0, 2 );
		$queue = get_option( CEL_AI_Job_Queue::OPTION_NAME, [] );

		CEL_AI_I18N_Controller::ensure_group_id( $post->ID );

		echo '<div class="cel-ai-translation-list">';
		foreach ( $languages as $code => $lang ) {
			if ( $code === $site_lang || ! in_array( $code, $active_langs ) ) {
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

	public function ajax_retry_job() {
		check_ajax_referer( 'cel_ai_job_status_nonce', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized', 'cel-ai' ) ] );
		}
		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( $_POST['job_id'] ) : '';
		$queue = get_option( CEL_AI_Job_Queue::OPTION_NAME, [] );
		if ( isset( $queue[ $job_id ] ) ) {
			$queue[ $job_id ]['status'] = 'pending';
			$queue[ $job_id ]['retries'] = 0;
			$queue[ $job_id ]['log'][] = 'Manually retried by user.';
			update_option( CEL_AI_Job_Queue::OPTION_NAME, $queue, false );
			
			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action( 'cel_ai_process_job', [ 'job_id' => $job_id ], 'cel_ai_jobs' );
			}
			
			wp_send_json_success();
		} else {
			wp_send_json_error( [ 'message' => 'Job not found' ] );
		}
	}

	public function ajax_delete_job() {
		check_ajax_referer( 'cel_ai_job_status_nonce', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized', 'cel-ai' ) ] );
		}
		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( $_POST['job_id'] ) : '';
		$queue = get_option( CEL_AI_Job_Queue::OPTION_NAME, [] );
		if ( isset( $queue[ $job_id ] ) ) {
			unset( $queue[ $job_id ] );
			update_option( CEL_AI_Job_Queue::OPTION_NAME, $queue, false );
			wp_send_json_success();
		} else {
			wp_send_json_error( [ 'message' => 'Job not found' ] );
		}
	}

	public function ajax_clear_queue() {
		check_ajax_referer( 'cel_ai_job_status_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized', 'cel-ai' ) ] );
		}
		update_option( CEL_AI_Job_Queue::OPTION_NAME, [], false );
		wp_send_json_success();
	}

	public function ajax_process_queue_manual() {
		check_ajax_referer( 'cel_ai_job_status_nonce', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized', 'cel-ai' ) ] );
		}
		
		$queue_manager = new CEL_AI_Job_Queue();
		$queue_manager->process_queue();
		
		wp_send_json_success();
	}

	public function ajax_check_updates() {
		check_ajax_referer( 'cel_ai_update_nonce', 'nonce' );
		$repo = 'thebtcbox-svg/C_EL_translator';
		$url = "https://api.github.com/repos/{$repo}/tags";
		$response = wp_remote_get( $url, [ 'headers' => [ 'User-Agent' => 'WordPress/' . get_bloginfo('version') ] ] );
		if ( is_wp_error( $response ) ) {
			wp_send_json_error( [ 'message' => 'Could not connect to GitHub API.' ] );
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array($body) || empty($body) ) {
			wp_send_json_error( [ 'message' => 'No tags found in this repository.' ] );
		}
		$latest_version = isset( $body[0]['name'] ) ? ltrim( $body[0]['name'], 'v' ) : '';
		
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
