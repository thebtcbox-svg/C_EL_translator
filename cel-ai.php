<?php
/**
 * Plugin Name: CEL Multilingual AI
 * Description: AI-powered multilingual functionality for WordPress and WooCommerce using OpenRouter.
 * Version: 1.1.1
 * Author: CEL Architect
 * Plugin URI: https://github.com/thebtcbox-svg/C_EL_translator
 * Text Domain: cel-ai
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

// Define constants
define( 'CEL_AI_PATH', plugin_dir_path( __FILE__ ) );
define( 'CEL_AI_URL', plugin_dir_url( __FILE__ ) );
define( 'CEL_AI_VERSION', '1.1.1' );

/**
 * Autoloader or manual inclusion
 */
require_once CEL_AI_PATH . 'services/logger.php';
require_once CEL_AI_PATH . 'services/ai-client.php';
require_once CEL_AI_PATH . 'services/updater.php';
require_once CEL_AI_PATH . 'modules/i18n/controller.php';
require_once CEL_AI_PATH . 'modules/i18n/admin-ui.php';
require_once CEL_AI_PATH . 'modules/i18n/jobs.php';
require_once CEL_AI_PATH . 'modules/i18n/api.php';
require_once CEL_AI_PATH . 'frontend/switcher.php';
require_once CEL_AI_PATH . 'frontend/filters.php';

// Initialize the plugin
add_action( 'plugins_loaded', 'cel_ai_init' );

function cel_ai_init() {
    new CEL_AI_Updater( __FILE__ );
    
    require_once CEL_AI_PATH . 'modules/seo/controller.php';

    // Check if WooCommerce is active
    if ( class_exists( 'WooCommerce' ) ) {
        require_once CEL_AI_PATH . 'modules/woocommerce/controller.php';
    }
}
