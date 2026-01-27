<?php
/**
 * Plugin Updater for CEL AI (GitHub Integration)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CEL_AI_Updater {

	private $file;
	private $plugin;
	private $basename;
	private $username = 'thebtcbox-svg';
	private $repo     = 'C_EL_translator';
	private $github_response;

	public function __construct( $file ) {
		$this->file     = $file;
		$this->basename = plugin_basename( $this->file );
		$this->plugin   = get_plugin_data( $this->file );
		
		add_filter( 'site_transient_update_plugins', [ $this, 'check_update' ] );
		add_filter( 'plugins_api', [ $this, 'plugin_info' ], 20, 3 );
		add_filter( 'upgrader_post_install', [ $this, 'after_install' ], 10, 3 );
	}

	private function get_repository_info() {
		if ( is_null( $this->github_response ) ) {
			// Fetch tags instead of releases for better automation
			$url = "https://api.github.com/repos/{$this->username}/{$this->repo}/tags";
			$response = wp_remote_get( $url, [ 'headers' => [ 'User-Agent' => 'WordPress/' . get_bloginfo('version') ] ] );

			if ( is_wp_error( $response ) ) {
				return false;
			}

			$tags = json_decode( wp_remote_retrieve_body( $response ) );
			if ( ! is_array($tags) || empty($tags) ) {
				return false;
			}

			$this->github_response = $tags[0]; // Take the latest tag
		}
		return $this->github_response;
	}

	public function check_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$github_info = $this->get_repository_info();
		if ( ! $github_info ) {
			return $transient;
		}

		$remote_version = ltrim( $github_info->name, 'v' );

		if ( version_compare( $this->plugin['Version'], $remote_version, '<' ) ) {
			$obj              = new stdClass();
			$obj->slug        = $this->basename;
			$obj->new_version = $remote_version;
			$obj->url         = $this->plugin['PluginURI'];
			$obj->package     = $github_info->zipball_url;
			
			$transient->response[ $this->basename ] = $obj;
		}

		return $transient;
	}

	public function plugin_info( $res, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $res;
		}

		if ( $this->basename !== $args->slug ) {
			return $res;
		}

		$github_info = $this->get_repository_info();
		if ( ! $github_info ) {
			return $res;
		}

		$res = new stdClass();
		$res->name         = $this->plugin['Name'];
		$res->slug         = $this->basename;
		$res->version      = ltrim( $github_info->name, 'v' );
		$res->author       = $this->plugin['AuthorName'];
		$res->homepage     = $this->plugin['PluginURI'];
		$res->download_link = $github_info->zipball_url;
		$res->sections     = [
			'description' => $this->plugin['Description'],
			'changelog'   => 'Check the repository for recent changes.',
		];

		return $res;
	}

	public function after_install( $response, $hook_extra, $result ) {
		global $wp_filesystem;
		
		$install_directory = plugin_dir_path( $this->file );
		$wp_filesystem->move( $result['destination'], $install_directory );
		$result['destination'] = $install_directory;

		if ( is_plugin_active( $this->basename ) ) {
			activate_plugin( $this->basename );
		}

		return $result;
	}
}
