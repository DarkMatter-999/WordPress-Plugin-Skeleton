// @vitest-environment node

import { afterEach, describe, expect, it } from 'vitest';
import {
	readFileSync,
	mkdtempSync,
	mkdirSync,
	rmSync,
	writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { build } from 'vite';
import {
	discoverEntries,
	discoverWordPressGlobals,
	createWordPressExternalPlugins,
} from '../../vite.config.mjs';

describe( 'discoverEntries', () => {
	let fixtureRoot;

	afterEach( () => {
		if ( fixtureRoot ) {
			rmSync( fixtureRoot, { recursive: true, force: true } );
			fixtureRoot = undefined;
		}
	} );

	it( 'discovers only present JS entries without requiring default names', () => {
		fixtureRoot = mkdtempSync( path.join( tmpdir(), 'vite-entries-' ) );

		for ( const file of [
			'js/frontend.ts',
			'js/admin.tsx',
			'blocks/example/index.js',
			'blocks/example/index.scss',
		] ) {
			const filePath = path.join( fixtureRoot, 'assets/src', file );
			mkdirSync( path.dirname( filePath ), { recursive: true } );
			writeFileSync( filePath, '' );
		}

		mkdirSync( path.join( fixtureRoot, 'assets/src/blocks/empty' ), {
			recursive: true,
		} );

		const entries = discoverEntries( fixtureRoot );

		expect( entries ).toMatchObject( {
			'assets/src/js/frontend.ts': expect.any( String ),
			'assets/src/js/admin.tsx': expect.any( String ),
			'assets/src/blocks/example/index.js': expect.any( String ),
		} );
		expect( entries ).not.toHaveProperty( 'assets/src/js/main.ts' );
		expect( entries ).not.toHaveProperty( 'assets/src/js/screen.ts' );
		expect( entries ).not.toHaveProperty( 'assets/src/js/editor.ts' );
		expect( entries ).not.toHaveProperty(
			'assets/src/blocks/empty/index.js'
		);
	} );

	it( 'maps imported WordPress packages to their WordPress globals', () => {
		fixtureRoot = mkdtempSync( path.join( tmpdir(), 'vite-wordpress-' ) );
		const sourcePath = path.join( fixtureRoot, 'assets/src/js/main.ts' );
		mkdirSync( path.dirname( sourcePath ), { recursive: true } );
		writeFileSync(
			sourcePath,
			"import { registerBlockType } from '@wordpress/blocks';\nimport { useSelect } from '@wordpress/data';\nimport { useBlockProps } from '@wordpress/block-editor';"
		);

		expect( discoverWordPressGlobals( fixtureRoot ) ).toMatchObject( {
			'@wordpress/blocks': 'wp.blocks',
			'@wordpress/data': 'wp.data',
			'@wordpress/block-editor': 'wp.blockEditor',
		} );
	} );

	it( 'leaves production WordPress imports external to the matching globals', async () => {
		fixtureRoot = mkdtempSync( path.join( tmpdir(), 'vite-external-' ) );
		const sourcePath = path.join( fixtureRoot, 'assets/src/js/main.ts' );
		const outDir = path.join( fixtureRoot, 'build' );
		mkdirSync( path.dirname( sourcePath ), { recursive: true } );
		writeFileSync(
			sourcePath,
			"import { registerBlockType } from '@wordpress/blocks';\nglobalThis.testRegister = registerBlockType;"
		);

		await build( {
			configFile: false,
			root: fixtureRoot,
			logLevel: 'silent',
			plugins: createWordPressExternalPlugins( fixtureRoot ),
			build: {
				outDir,
				manifest: '.vite/manifest.json',
				emptyOutDir: true,
				rollupOptions: { input: sourcePath },
			},
		} );

		const manifest = JSON.parse(
			readFileSync( path.join( outDir, '.vite/manifest.json' ), 'utf8' )
		);
		const outputPath = path.join(
			outDir,
			manifest[ 'assets/src/js/main.ts' ].file
		);
		const output = readFileSync( outputPath, 'utf8' );

		expect( output ).toContain( 'wp.blocks' );
		expect( output ).not.toContain( '@wordpress/blocks' );
	} );
} );
