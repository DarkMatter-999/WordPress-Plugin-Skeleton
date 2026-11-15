<?php
/**
 * Main Assets Class File
 *
 * Main Theme Asset class file for the Plugin. This class enqueues the necessary scripts and styles.
 *
 * @package DarkMatter_Package
 **/

namespace DarkMatter_Plugin;

use DarkMatter_Plugin\Helpers\Vite_Assets;
use DarkMatter_Plugin\Traits\Singleton;

require_once __DIR__ . '/../helpers/vite-assets.php';

/**
 * Main Assets Class File
 *
 * Main Theme Asset class file for the Plugin. This class enqueues the necessary scripts and styles.
 *
 * @since 1.0.0
 **/
class Assets {

	/**
	 * Existing WordPress style handles for the built-in entries.
	 *
	 * @var array
	 */
	private const STYLE_HANDLES = array(
		'main-js'   => 'main-css',
		'block-js'  => 'block-css',
		'editor-js' => 'editor-css',
	);

	use Singleton;

	/**
	 * Vite assets resolver.
	 *
	 * @var Vite_Assets|null
	 */
	private $vite_assets;

	/**
	 * Whether the Vite client has been enqueued for this request.
	 *
	 * @var bool
	 */
	private $vite_client_enqueued = false;

	/**
	 * Handles that must be emitted as ES modules.
	 *
	 * @var array
	 */
	private $vite_module_handles = array();

	/**
	 * Whether the script tag filter has been registered.
	 *
	 * @var bool
	 */
	private $script_tag_filter_registered = false;

	/**
	 * Constructor for the Assets class.
	 *
	 * @return void
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'enqueue_block_assets', array( $this, 'enqueue_block_assets' ) );
		add_action(
			'enqueue_block_editor_assets',
			array(
				$this,
				'enqueue_block_editor_assets',
			)
		);
	}

	/**
	 * Enqueues styles and scripts for the theme.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		$this->enqueue_entry( 'assets/src/js/main.ts', 'main-js', array(), true );
	}

	/**
	 * Enqueues styles and scripts for the frontend.
	 *
	 * @return void
	 */
	public function enqueue_block_assets() {
		$this->enqueue_entry( 'assets/src/js/screen.ts', 'block-js', array(), true );
	}

	/**
	 * Enqueues styles and scripts for the block editor.
	 *
	 * @return void
	 */
	public function enqueue_block_editor_assets() {
		$this->enqueue_entry( 'assets/src/js/editor.ts', 'editor-js', array(), true );
	}

	/**
	 * Enqueue one Vite entry and its emitted styles.
	 *
	 * @param string $entry        Vite manifest key/source entry.
	 * @param string $handle       WordPress script handle.
	 * @param array  $dependencies WordPress script dependencies.
	 * @param bool   $in_footer    Whether the script belongs in the footer.
	 * @return void
	 */
	public function enqueue_entry( string $entry, string $handle, array $dependencies, bool $in_footer ): void {
		$assets = $this->get_vite_assets();
		$origin = $assets->get_dev_server_origin();

		if ( null !== $origin ) {
			$source_path = $assets->get_plugin_path() . $entry;
			if ( ! is_file( $source_path ) ) {
				$this->log_missing_entry( $entry );
				return;
			}

			if ( ! $this->vite_client_enqueued ) {
				$this->register_module_handle( 'vite-client' );
				wp_enqueue_script( 'vite-client', $origin . '/@vite/client', array(), 'dev', true );
				$this->vite_client_enqueued = true;
			}

			$this->register_module_handle( $handle );
			wp_enqueue_script( $handle, $origin . '/' . ltrim( $entry, '/' ), $dependencies, 'dev', $in_footer );
			return;
		}

		$manifest_entry = $assets->get_entry( $entry );
		if ( null === $manifest_entry ) {
			$this->log_missing_entry( $entry );
			return;
		}

		$build_path  = $assets->get_plugin_path() . 'assets/build/';
		$script_path = $build_path . $manifest_entry['file'];
		if ( ! is_file( $script_path ) ) {
			$this->log_missing_entry( $entry );
			return;
		}

		$asset_url = $assets->get_plugin_url() . 'assets/build/';
		foreach ( $manifest_entry['css'] as $index => $css_file ) {
			$css_path = $build_path . $css_file;
			if ( is_file( $css_path ) ) {
				wp_enqueue_style(
					$this->get_style_handle( $handle, $index ),
					$asset_url . $css_file,
					array(),
					(string) filemtime( $css_path )
				);
			}
		}

		$this->register_module_handle( $handle );
		wp_enqueue_script(
			$handle,
			$asset_url . $manifest_entry['file'],
			$dependencies,
			(string) filemtime( $script_path ),
			$in_footer
		);
	}

	/**
	 * Register a Vite script handle for module output.
	 *
	 * @param string $handle WordPress script handle.
	 * @return void
	 */
	private function register_module_handle( string $handle ): void {
		if ( ! in_array( $handle, $this->vite_module_handles, true ) ) {
			$this->vite_module_handles[] = $handle;
		}

		if ( ! $this->script_tag_filter_registered ) {
			add_filter( 'script_loader_tag', array( $this, 'filter_vite_script_tag' ), 10, 3 );
			$this->script_tag_filter_registered = true;
		}
	}

	/**
	 * Get a stable WordPress handle for an entry stylesheet.
	 *
	 * @param string $script_handle Script handle associated with the entry.
	 * @param int    $index         Stylesheet index in the manifest entry.
	 * @return string
	 */
	private function get_style_handle( string $script_handle, int $index ): string {
		if ( isset( self::STYLE_HANDLES[ $script_handle ] ) ) {
			return 0 === $index
				? self::STYLE_HANDLES[ $script_handle ]
				: self::STYLE_HANDLES[ $script_handle ] . '-' . $index;
		}

		return $script_handle . '-style-' . $index;
	}

	/**
	 * Add the ES module type to Vite scripts only.
	 *
	 * @param string $tag    Script HTML.
	 * @param string $handle Script handle.
	 * @param string $src    Script URL.
	 * @return string
	 */
	public function filter_vite_script_tag( string $tag, string $handle, string $src ): string {
		unset( $src );
		if ( ! in_array( $handle, $this->vite_module_handles, true ) ) {
			return $tag;
		}

		if ( preg_match( '/\btype\s*=/', $tag ) ) {
			return preg_replace( '/\btype=(["\']).*?\1/', 'type="module"', $tag, 1 );
		}

		return preg_replace( '/<script\b/', '<script type="module"', $tag, 1 );
	}

	/**
	 * Construct the resolver from plugin constants.
	 *
	 * @return Vite_Assets
	 */
	private function get_vite_assets(): Vite_Assets {
		if ( null === $this->vite_assets ) {
			$this->vite_assets = new Vite_Assets(
				DMP_PLUGIN_PATH,
				DMP_PLUGIN_URL,
				DMP_PLUGIN_PATH . 'assets/build/.vite/manifest.json',
				DMP_PLUGIN_PATH . 'assets/build/hot'
			);
		}

		return $this->vite_assets;
	}

	/**
	 * Log a missing production entry when debugging is enabled.
	 *
	 * @param string $entry Manifest key that could not be loaded.
	 * @return void
	 */
	private function log_missing_entry( string $entry ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			_doing_it_wrong(
				__METHOD__,
				sprintf( 'Vite asset entry "%s" is unavailable. Run pnpm build.', esc_html( $entry ) ),
				'1.0.0'
			);
		}
	}
}
