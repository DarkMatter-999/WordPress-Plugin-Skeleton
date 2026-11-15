<?php
/**
 * Tests for Vite manifest and dev-server resolution.
 *
 * @package DarkMatter_Package
 */

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_mkdir, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Tests use local temporary fixture files.

use DarkMatter_Plugin\Helpers\Vite_Assets;

require_once __DIR__ . '/../../include/helpers/vite-assets.php';
require_once __DIR__ . '/../mocks/wp-functions.php';

beforeEach(
	function () {
		$this->plugin_root                     = sys_get_temp_dir() . '/dmp-vite-' . uniqid();
		$GLOBALS['dmp_json_file_decode_calls'] = 0;
		mkdir( $this->plugin_root . '/assets/build/.vite', 0777, true );
	}
);

afterEach(
	function () {
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

		$remove_directory( $this->plugin_root );
	}
);

it(
	'accepts only a fresh HTTP(S) dev-server origin',
	function () {
		$hot_file = $this->plugin_root . '/assets/build/hot';
		$assets   = new Vite_Assets(
			$this->plugin_root,
			'https://plugin.test/wp-content/plugins/example/',
			$this->plugin_root . '/assets/build/.vite/manifest.json',
			$hot_file
		);

		file_put_contents( $hot_file, 'http://localhost:5173' );
		expect( $assets->get_dev_server_origin() )->toBe( 'http://localhost:5173' );

		file_put_contents( $hot_file, 'javascript:alert(1)' );
		expect( $assets->get_dev_server_origin() )->toBeNull();

		file_put_contents( $hot_file, 'https://user@localhost:5173' );
		expect( $assets->get_dev_server_origin() )->toBeNull();

		file_put_contents( $hot_file, 'https://localhost:5173/path' );
		expect( $assets->get_dev_server_origin() )->toBeNull();

		file_put_contents( $hot_file, '' );
		expect( $assets->get_dev_server_origin() )->toBeNull();

		file_put_contents( $hot_file, 'http://localhost:5173' );
		touch( $hot_file, time() - 16 );
		expect( $assets->get_dev_server_origin() )->toBeNull();

		file_put_contents( $hot_file, 'http://localhost:5173' );
		touch( $hot_file, time() + 16 );
		expect( $assets->get_dev_server_origin() )->toBeNull();
	}
);

it(
	'returns null when the dev marker is missing',
	function () {
		$assets = new Vite_Assets(
			$this->plugin_root,
			'https://plugin.test/wp-content/plugins/example/',
			$this->plugin_root . '/assets/build/.vite/manifest.json',
			$this->plugin_root . '/assets/build/hot'
		);

		expect( $assets->get_dev_server_origin() )->toBeNull();
	}
);

it(
	'resolves manifest JavaScript and CSS for an existing entry',
	function () {
		$manifest_path = $this->plugin_root . '/assets/build/.vite/manifest.json';
		file_put_contents(
			$manifest_path,
			wp_json_encode(
				array(
					'assets/src/js/frontend.ts' => array(
						'file' => 'assets/frontend-abc.js',
						'css'  => array( 'assets/frontend-abc.css' ),
					),
				)
			)
		);

		$assets = new Vite_Assets(
			$this->plugin_root,
			'https://plugin.test/wp-content/plugins/example/',
			$manifest_path,
			$this->plugin_root . '/assets/build/hot'
		);

		expect( $assets->get_entry( 'assets/src/js/frontend.ts' ) )->toBe(
			array(
				'file' => 'assets/frontend-abc.js',
				'css'  => array( 'assets/frontend-abc.css' ),
			)
		);

		expect( $GLOBALS['dmp_json_file_decode_calls'] )->toBe( 1 );
		file_put_contents(
			$manifest_path,
			wp_json_encode( array( 'assets/src/js/frontend.ts' => array( 'file' => 'assets/rebuilt.js' ) ) )
		);
		expect( $assets->get_entry( 'assets/src/js/frontend.ts' )['file'] )->toBe( 'assets/frontend-abc.js' );
		expect( $GLOBALS['dmp_json_file_decode_calls'] )->toBe( 1 );
	}
);

it(
	'returns null for missing or malformed manifest entries',
	function () {
		$manifest_path = $this->plugin_root . '/assets/build/.vite/manifest.json';
		$assets        = new Vite_Assets(
			$this->plugin_root,
			'https://plugin.test/wp-content/plugins/example/',
			$manifest_path,
			$this->plugin_root . '/assets/build/hot'
		);

		expect( $assets->get_entry( 'assets/src/js/missing.ts' ) )->toBeNull();

		file_put_contents( $manifest_path, '{invalid json' );
		expect( $assets->get_entry( 'assets/src/js/frontend.ts' ) )->toBeNull();

		file_put_contents(
			$manifest_path,
			'{"assets/src/js/frontend.ts":{"file":"","css":"not-an-array"}}'
		);
		expect( $assets->get_entry( 'assets/src/js/frontend.ts' ) )->toBeNull();

		file_put_contents(
			$manifest_path,
			'{"assets/src/js/frontend.ts":{"file":"assets/%2e%2e/outside.js","css":[]}}'
		);
		expect( $assets->get_entry( 'assets/src/js/frontend.ts' ) )->toBeNull();
	}
);
