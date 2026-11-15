<?php
/**
 * Tests for Vite-backed WordPress asset enqueueing.
 *
 * @package DarkMatter_Package
 */

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_mkdir, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.unlink_unlink -- Tests use local temporary fixture files.
// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Tests verify the transformed script tag emitted by Vite.

use DarkMatter_Plugin\Assets;

/**
 * Stub add_action() so the Assets constructor can run in isolation.
 *
 * @param mixed ...$args Action registration arguments.
 */
function add_action( ...$args ) {}
/**
 * Stub add_filter() so the dev script tag filter can be registered.
 *
 * @param mixed ...$args Filter registration arguments.
 */
function add_filter( ...$args ) {}
/**
 * Capture script enqueues for assertions.
 *
 * @param mixed ...$args Enqueue arguments.
 */
function wp_enqueue_script( ...$args ) {
	$GLOBALS['dmp_asset_enqueues'][] = array( 'script', $args );
}
/**
 * Capture style enqueues for assertions.
 *
 * @param mixed ...$args Enqueue arguments.
 */
function wp_enqueue_style( ...$args ) {
	$GLOBALS['dmp_asset_enqueues'][] = array( 'style', $args );
}

require_once __DIR__ . '/../../include/traits/trait-singleton.php';
require_once __DIR__ . '/../mocks/wp-functions.php';
require_once __DIR__ . '/../../include/classes/class-assets.php';

$plugin_root = sys_get_temp_dir() . '/dmp-assets-enqueue-' . getmypid() . '/';
define( 'DMP_PLUGIN_PATH', $plugin_root );
define( 'DMP_PLUGIN_URL', 'https://plugin.test/wp-content/plugins/example/' );

beforeEach(
	function () use ( $plugin_root ) {
		$GLOBALS['dmp_asset_enqueues'] = array();
		mkdir( $plugin_root . 'assets/build/.vite', 0777, true );
	}
);

afterEach(
	function () use ( $plugin_root ) {
		$remove_directory = function ( $directory ) use ( &$remove_directory ) {
			foreach ( scandir( $directory ) as $item ) {
				if ( '.' === $item || '..' === $item ) {
					continue;
				}

				$path = $directory . '/' . $item;
				is_dir( $path ) ? $remove_directory( $path ) : unlink( $path );
			}

			rmdir( $directory );
		};

		$remove_directory( $plugin_root );
	}
);

it(
	'enqueues Vite client once and source modules in development',
	function () {
		file_put_contents( DMP_PLUGIN_PATH . 'assets/build/hot', 'http://localhost:5173' );
		mkdir( DMP_PLUGIN_PATH . 'assets/src/js', 0777, true );
		foreach ( array( 'main.ts', 'screen.ts', 'editor.ts' ) as $entry ) {
			file_put_contents( DMP_PLUGIN_PATH . 'assets/src/js/' . $entry, '' );
		}
		$assets = new Assets();

		$assets->enqueue_assets();
		$assets->enqueue_block_assets();
		$assets->enqueue_block_editor_assets();

		$scripts = array_values(
			array_filter(
				$GLOBALS['dmp_asset_enqueues'],
				function ( $item ) {
					return 'script' === $item[0];
				}
			)
		);

		expect( count( $scripts ) )->toBe( 4 );
		expect( $scripts[0][1][0] )->toBe( 'vite-client' );
		expect( $scripts[0][1][1] )->toBe( 'http://localhost:5173/@vite/client' );
		expect( $scripts[1][1][1] )->toBe( 'http://localhost:5173/assets/src/js/main.ts' );
		expect( $scripts[2][1][1] )->toBe( 'http://localhost:5173/assets/src/js/screen.ts' );
		expect( $scripts[3][1][1] )->toBe( 'http://localhost:5173/assets/src/js/editor.ts' );
		expect( $assets->filter_vite_script_tag( '<script src="dev.js"></script>', 'main-js', 'dev.js' ) )
			->toContain( 'type="module"' );
		expect( $assets->filter_vite_script_tag( '<script src="other.js"></script>', 'other-js', 'other.js' ) )
			->not->toContain( 'type="module"' );
		expect( $assets->filter_vite_script_tag( '<script type="text/javascript" src="dev.js"></script>', 'main-js', 'dev.js' ) )
			->toContain( 'type="module"' );
	}
);

it(
	'does not enqueue a missing development source entry',
	function () {
		file_put_contents( DMP_PLUGIN_PATH . 'assets/build/hot', 'http://localhost:5173' );
		$assets = new Assets();

		$assets->enqueue_entry( 'assets/src/js/omitted.ts', 'omitted-js', array(), true );

		expect( $GLOBALS['dmp_asset_enqueues'] )->toBeEmpty();
	}
);

it(
	'enqueues production manifest URLs with dependencies and footer placement',
	function () {
		$files = array(
			'assets/src/js/main.ts'   => array( 'assets/main.js', 'assets/main.css' ),
			'assets/src/js/screen.ts' => array( 'assets/screen.js', 'assets/screen.css' ),
			'assets/src/js/editor.ts' => array( 'assets/editor.js', 'assets/editor.css' ),
		);
		foreach ( $files as $source => $outputs ) {
			foreach ( $outputs as $output ) {
				$path = DMP_PLUGIN_PATH . 'assets/build/' . $output;
				if ( ! is_dir( dirname( $path ) ) ) {
					mkdir( dirname( $path ), 0777, true );
				}
				file_put_contents( $path, '' );
			}
		}
		$manifest = array(
			'assets/src/js/main.ts'   => array(
				'file' => 'assets/main.js',
				'css'  => array( 'assets/main.css' ),
			),
			'assets/src/js/screen.ts' => array(
				'file' => 'assets/screen.js',
				'css'  => array( 'assets/screen.css' ),
			),
			'assets/src/js/editor.ts' => array(
				'file' => 'assets/editor.js',
				'css'  => array( 'assets/editor.css' ),
			),
		);
		file_put_contents(
			DMP_PLUGIN_PATH . 'assets/build/.vite/manifest.json',
			wp_json_encode( $manifest )
		);

		$assets = new Assets();
		$assets->enqueue_entry( 'assets/src/js/main.ts', 'custom-js', array( 'wp-element' ), false );

		expect( $GLOBALS['dmp_asset_enqueues'] )->toBe(
			array(
				array(
					'style',
					array(
						'custom-js-style-0',
						'https://plugin.test/wp-content/plugins/example/assets/build/assets/main.css',
						array(),
						(string) filemtime( DMP_PLUGIN_PATH . 'assets/build/assets/main.css' ),
					),
				),
				array(
					'script',
					array(
						'custom-js',
						'https://plugin.test/wp-content/plugins/example/assets/build/assets/main.js',
						array( 'wp-element' ),
						(string) filemtime( DMP_PLUGIN_PATH . 'assets/build/assets/main.js' ),
						false,
					),
				),
			)
		);

		$GLOBALS['dmp_asset_enqueues'] = array();
		$assets->enqueue_assets();
		$assets->enqueue_block_assets();
		$assets->enqueue_block_editor_assets();
		$script_calls = array_values(
			array_filter(
				$GLOBALS['dmp_asset_enqueues'],
				function ( $item ) {
					return 'script' === $item[0];
				}
			)
		);
		expect( count( $script_calls ) )->toBe( 3 );
		foreach ( $script_calls as $call ) {
			expect( $call[1][1] )->toStartWith( DMP_PLUGIN_URL );
		}
		$style_handles = array_column(
			array_values(
				array_filter(
					$GLOBALS['dmp_asset_enqueues'],
					function ( $item ) {
						return 'style' === $item[0];
					}
				)
			),
			1
		);
		expect( array_column( $style_handles, 0 ) )->toBe( array( 'main-css', 'block-css', 'editor-css' ) );
		expect( $assets->filter_vite_script_tag( '<script src="built.js"></script>', 'main-js', 'built.js' ) )
			->toContain( 'type="module"' );
	}
);

it(
	'skips entries when the production manifest is missing',
	function () {
		$assets = new Assets();
		$assets->enqueue_entry( 'assets/src/js/not-built.ts', 'missing-js', array(), true );

		expect( $GLOBALS['dmp_asset_enqueues'] )->toBeEmpty();
	}
);
