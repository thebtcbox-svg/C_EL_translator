<?php
/**
 * Plugin Limits Configuration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'max_chars_per_request' => 3000,
	'max_requests_per_min'  => 5,
	'max_concurrent_jobs'   => 1,
	'request_timeout'       => 120, // Increased timeout for larger chunks
];
