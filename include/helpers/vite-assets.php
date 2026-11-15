<?php
/**
 * Vite asset manifest and dev-server resolver.
 *
 * @package DarkMatter_Package
 */

namespace DarkMatter_Plugin\Helpers;

/**
 * Resolves Vite development and production assets for WordPress.
 */
class Vite_Assets {

	/**
	 * Plugin filesystem root.
	 *
	 * @var string
	 */
	private $plugin_path;

	/**
	 * Plugin public URL.
	 *
	 * @var string
	 */
	private $plugin_url;

	/**
	 * Vite manifest filesystem path.
	 *
	 * @var string
	 */
	private $manifest_path;

	/**
	 * Vite dev-server marker filesystem path.
	 *
	 * @var string
	 */
	private $hot_file;

	/**
	 * Decoded manifest cached for this request.
	 *
	 * @var array|null
	 */
	private $manifest;

	/**
	 * Whether the manifest has been loaded during this request.
	 *
	 * @var bool
	 */
	private $manifest_loaded = false;

	/**
	 * Constructor.
	 *
	 * @param string $plugin_path   Plugin filesystem root.
	 * @param string $plugin_url    Plugin public URL.
	 * @param string $manifest_path Vite manifest filesystem path.
	 * @param string $hot_file      Vite dev-server marker filesystem path.
	 */
	public function __construct( string $plugin_path, string $plugin_url, string $manifest_path, string $hot_file ) {
		$this->plugin_path   = rtrim( $plugin_path, '/\\' ) . DIRECTORY_SEPARATOR;
		$this->plugin_url    = rtrim( $plugin_url, '/' ) . '/';
		$this->manifest_path = $manifest_path;
		$this->hot_file      = $hot_file;
	}

	/**
	 * Get the validated origin of a fresh Vite dev server.
	 *
	 * @return string|null Dev-server origin, or null when unavailable or stale.
	 */
	public function get_dev_server_origin(): ?string {
		if ( ! is_file( $this->hot_file ) || ! is_readable( $this->hot_file ) ) {
			return null;
		}

		$modified = filemtime( $this->hot_file );
		$age      = false === $modified ? false : time() - $modified;
		if ( false === $age || $age < 0 || $age > 15 ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- This is a local dev marker, not a remote URL.
		$origin = file_get_contents( $this->hot_file );
		if ( ! is_string( $origin ) ) {
			return null;
		}

		$origin = trim( $origin );
		if ( ! $this->is_valid_dev_server_origin( $origin ) ) {
			return null;
		}

		return rtrim( $origin, '/' );
	}

	/**
	 * Get a normalized Vite manifest entry.
	 *
	 * @param string $manifest_key Vite manifest source key.
	 * @return array|null Entry containing a relative JS file and CSS paths.
	 */
	public function get_entry( string $manifest_key ): ?array {
		$manifest = $this->get_manifest();
		if ( ! is_array( $manifest ) || ! isset( $manifest[ $manifest_key ]['file'] ) ) {
			return null;
		}

		$file = $manifest[ $manifest_key ]['file'];
		if ( ! is_string( $file ) || ! $this->is_relative_asset_path( $file ) ) {
			return null;
		}

		$css = isset( $manifest[ $manifest_key ]['css'] ) && is_array( $manifest[ $manifest_key ]['css'] )
			? $manifest[ $manifest_key ]['css']
			: array();
		$css = array_values(
			array_filter(
				$css,
				function ( $path ) {
					return is_string( $path ) && $this->is_relative_asset_path( $path );
				}
			)
		);

		return array(
			'file' => $file,
			'css'  => $css,
		);
	}

	/**
	 * Load and cache the Vite manifest once per request.
	 *
	 * @return array|null Decoded manifest, or null when unavailable or invalid.
	 */
	private function get_manifest(): ?array {
		if ( $this->manifest_loaded ) {
			return $this->manifest;
		}

		$this->manifest_loaded = true;
		if ( ! is_file( $this->manifest_path ) || ! is_readable( $this->manifest_path ) ) {
			return null;
		}

		$manifest = wp_json_file_decode( $this->manifest_path, array( 'associative' => true ) );

		if ( is_array( $manifest ) ) {
			$this->manifest = $manifest;
		}

		return $this->manifest;
	}

	/**
	 * Validate that a URL is only an HTTP(S) origin without user-supplied path data.
	 *
	 * @param string $origin Candidate dev-server origin.
	 * @return bool Whether the URL is a plain HTTP(S) origin.
	 */
	private function is_valid_dev_server_origin( string $origin ): bool {
		if ( false === filter_var( $origin, FILTER_VALIDATE_URL ) ) {
			return false;
		}

		$parts = wp_parse_url( $origin );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}

		if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return false;
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return false;
		}

		return ! isset( $parts['path'] ) || '' === $parts['path'] || '/' === $parts['path'];
	}

	/**
	 * Check that a manifest path cannot escape the build directory.
	 *
	 * @param string $path Manifest file path.
	 * @return bool Whether the path is a safe relative path.
	 */
	private function is_relative_asset_path( string $path ): bool {
		$normalized_path = str_replace( '\\', '/', rawurldecode( $path ) );
		if (
			'' === $normalized_path ||
			false !== strpos( $normalized_path, "\0" ) ||
			'/' === $normalized_path[0] ||
			false !== filter_var( $normalized_path, FILTER_VALIDATE_URL )
		) {
			return false;
		}

		return ! in_array( '..', explode( '/', $normalized_path ), true );
	}

	/**
	 * Get the plugin filesystem root.
	 *
	 * @return string
	 */
	public function get_plugin_path(): string {
		return $this->plugin_path;
	}

	/**
	 * Get the plugin public URL.
	 *
	 * @return string
	 */
	public function get_plugin_url(): string {
		return $this->plugin_url;
	}
}
