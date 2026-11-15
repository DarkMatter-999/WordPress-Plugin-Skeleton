import { existsSync, readFileSync, readdirSync } from 'node:fs';
import path from 'node:path';
import react from '@vitejs/plugin-react';
import externalGlobals from 'rollup-plugin-external-globals';
import viteExternal from 'vite-plugin-external';
import { defineConfig } from 'vite';

export function discoverEntries( root = process.cwd() ) {
	const sourceRoot = path.resolve( root, 'assets/src' );
	const javascriptRoot = path.join( sourceRoot, 'js' );
	const entries = {};

	if ( existsSync( javascriptRoot ) ) {
		const javascriptEntries = readdirSync( javascriptRoot, {
			withFileTypes: true,
		} )
			.filter(
				( item ) =>
					item.isFile() && /\.(?:js|jsx|ts|tsx)$/.test( item.name )
			)
			.sort( ( left, right ) => left.name.localeCompare( right.name ) );

		for ( const entry of javascriptEntries ) {
			const manifestKey = path.posix.join( 'assets/src/js', entry.name );
			entries[ manifestKey ] = path.join( javascriptRoot, entry.name );
		}
	}

	const blocksRoot = path.join( sourceRoot, 'blocks' );
	if ( existsSync( blocksRoot ) ) {
		const blockDirectories = readdirSync( blocksRoot, {
			withFileTypes: true,
		} )
			.filter( ( item ) => item.isDirectory() )
			.sort( ( left, right ) => left.name.localeCompare( right.name ) );

		for ( const block of blockDirectories ) {
			const relativeEntry = path.posix.join(
				'assets/src/blocks',
				block.name,
				'index.js'
			);
			const absoluteEntry = path.join(
				blocksRoot,
				block.name,
				'index.js'
			);

			if ( existsSync( absoluteEntry ) ) {
				entries[ relativeEntry ] = absoluteEntry;
			}
		}
	}

	return entries;
}

function sourceFiles( directory ) {
	if ( ! existsSync( directory ) ) {
		return [];
	}

	return readdirSync( directory, { withFileTypes: true } ).flatMap(
		( item ) => {
			const itemPath = path.join( directory, item.name );
			if ( item.isDirectory() ) {
				return sourceFiles( itemPath );
			}

			return /\.[jt]sx?$/.test( item.name ) ? [ itemPath ] : [];
		}
	);
}

function camelCaseDashes( value ) {
	return value.replace( /-([a-z])/g, ( _, letter ) => letter.toUpperCase() );
}

export function discoverWordPressGlobals( root = process.cwd() ) {
	const imports = new Set();
	const importPattern = /\b(?:from\s*|import\s*(?:\(\s*)?)['"]([^'"]+)['"]/g;

	for ( const file of sourceFiles( path.resolve( root, 'assets/src' ) ) ) {
		const source = readFileSync( file, 'utf8' );
		for ( const match of source.matchAll( importPattern ) ) {
			const moduleName = match[ 1 ];
			if ( /^@wordpress\/[a-z0-9-]+$/.test( moduleName ) ) {
				imports.add( moduleName );
			}
		}
	}

	return Object.fromEntries(
		[ ...imports ]
			.sort()
			.map( ( moduleName ) => [
				moduleName,
				`wp.${ camelCaseDashes(
					moduleName.slice( '@wordpress/'.length )
				) }`,
			] )
	);
}

export function createWordPressExternalPlugins( root = process.cwd() ) {
	const globals = {
		jquery: 'jQuery',
		lodash: 'lodash',
		react: 'React',
		'react-dom': 'ReactDOM',
		'react/jsx-runtime': 'ReactJSXRuntime',
		...discoverWordPressGlobals( root ),
	};

	return [
		{
			name: 'wordpress-build-globals',
			apply: 'build',
			config() {
				return {
					build: {
						rollupOptions: {
							external: Object.keys( globals ),
							output: { globals },
						},
					},
				};
			},
		},
		externalGlobals( globals ),
		viteExternal( { development: { externals: globals } } ),
	];
}

function reloadBlocksOnChange() {
	return {
		name: 'wordpress-block-full-reload',
		handleHotUpdate( { file, server } ) {
			const blocksRoot = `${ path.resolve(
				process.cwd(),
				'assets/src/blocks'
			) }${ path.sep }`;

			if ( file.startsWith( blocksRoot ) ) {
				server.ws.send( { type: 'full-reload' } );
				return [];
			}
		},
	};
}

export default defineConfig( async () => ( {
	base: './',
	plugins: [
		react(),
		...createWordPressExternalPlugins(),
		reloadBlocksOnChange(),
	],
	server: {
		host: '0.0.0.0',
		port: 5173,
		strictPort: true,
		origin: 'http://localhost:5173',
	},
	build: {
		outDir: path.resolve( process.cwd(), 'assets/build' ),
		manifest: '.vite/manifest.json',
		emptyOutDir: true,
		rollupOptions: {
			input: discoverEntries(),
		},
	},
} ) );
