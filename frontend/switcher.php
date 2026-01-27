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
	 * Render the language switcher
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
		$format = get_option( 'cel_ai_switcher_format', 'code' );

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
		<div class="cel-ai-switcher-wrapper">
			<div class="cel-ai-current-lang">
				<?php echo $this->format_label( $current_lang, $supported_langs[ $current_lang ], $format ); ?>
			</div>
			<ul class="cel-ai-lang-list">
				<?php foreach ( $supported_langs as $code => $lang ) : 
					$trans_post_id = 0;
					if ( isset( $translations[ $code ] ) ) {
						$trans_post_id = $translations[ $code ]->ID;
					} elseif ( $code === $current_lang ) {
						$trans_post_id = $post_id;
					}

					if ( $trans_post_id && $code !== $current_lang ) : ?>
						<li>
							<a href="<?php echo get_permalink( $trans_post_id ); ?>">
								<?php echo $this->format_label( $code, $lang, $format ); ?>
							</a>
						</li>
					<?php endif; ?>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Format the language label based on settings
	 */
	private function format_label( $code, $lang, $format ) {
		$flag = isset($lang['flag']) ? $lang['flag'] : '🌐';
		$name = $lang['name'];
		$short = strtoupper( $code );

		switch ( $format ) {
			case 'flag':
				return '<span class="cel-ai-flag">' . $flag . '</span>';
			case 'name':
				return '<span>' . esc_html( $name ) . '</span>';
			case 'code':
			default:
				return '<span>' . esc_html( $short ) . '</span>';
		}
	}
}

new CEL_AI_Switcher();
