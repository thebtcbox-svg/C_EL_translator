<?php
/**
 * Logger Service for CEL AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CEL_AI_Logger {

	/**
	 * Log a message to the plugin log file.
	 *
	 * @param string $message
	 * @param string $level (info|error|debug)
	 */
	public static function log( $message, $level = 'info' ) {
		$log_dir = CEL_AI_PATH . 'logs';
		$log_file = $log_dir . '/plugin.log';

		if ( ! file_exists( $log_dir ) ) {
			mkdir( $log_dir, 0755, true );
		}

		$timestamp = current_time( 'mysql' );
		$formatted_message = sprintf( "[%s] [%s] %s\n", $timestamp, strtoupper( $level ), $message );

		// Fail silently if file is not writable
		@error_log( $formatted_message, 3, $log_file );
	}

	public static function error( $message ) {
		self::log( $message, 'error' );
	}

	public static function info( $message ) {
		self::log( $message, 'info' );
	}
}
