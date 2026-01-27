<?php
/**
 * Frontend Language Switcher for CEL AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CEL_AI_Switcher {

	public function __construct() {
		add_shortcode( 'cel_ai_switcher', [ $this, 'render_switcher' ] );
	}

	/**
	 * Render the language switcher dropdown
	 *
	 * @return string
	 */
	public function render_switcher() {
		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return '';
		}

		$translations = CEL_AI_I18N_Controller::get_translations( $post_id );
		$all_supported = CEL_AI_I18N_Controller::get_supported_languages();
		$active_codes = get_option( 'cel_ai_active_languages', array_keys( $all_supported ) );
		$current_lang = get_post_meta( $post_id, CEL_AI_I18N_Controller::META_LANGUAGE, true );

		// Filter supported to only active ones
		$supported_langs = [];
		foreach ( $all_supported as $code => $data ) {
			if ( in_array( $code, $active_codes ) ) {
				$supported_langs[ $code ] = $data;
			}
		}

		if ( empty( $translations ) && count($supported_langs) <= 1 ) {
			return '';
		}

		ob_start();
		?>
		<div class="cel-ai-language-switcher">
			<select onchange="window.location.href=this.value;">
				<?php foreach ( $supported_langs as $code => $lang ) : 
					$trans_post_id = 0;
					if ( isset( $translations[ $code ] ) ) {
						$trans_post_id = $translations[ $code ]->ID;
					} elseif ( $code === $current_lang ) {
						$trans_post_id = $post_id;
					}

					if ( $trans_post_id ) : ?>
						<option value="<?php echo get_permalink( $trans_post_id ); ?>" <?php selected( $current_lang, $code ); ?>>
							<?php echo esc_html( $lang['name'] ); ?>
						</option>
					<?php endif; ?>
				<?php endforeach; ?>
			</select>
		</div>
		<?php
		return ob_get_clean();
	}
}

new CEL_AI_Switcher();
