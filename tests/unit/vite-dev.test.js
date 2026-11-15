// @vitest-environment node

import { EventEmitter } from 'node:events';
import {
	existsSync,
	mkdirSync,
	mkdtempSync,
	readFileSync,
	rmSync,
	statSync,
	utimesSync,
	writeFileSync,
} from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { afterEach, describe, expect, it } from 'vitest';
import { startViteDev } from '../../bin/vite-dev.js';

describe( 'Vite development lifecycle', () => {
	let fixtureRoot;

	afterEach( () => {
		if ( fixtureRoot ) {
			rmSync( fixtureRoot, { recursive: true, force: true } );
			fixtureRoot = undefined;
		}
	} );

	it( 'writes the server origin after listening and removes the marker on SIGTERM', async () => {
		fixtureRoot = mkdtempSync( path.join( os.tmpdir(), 'vite-dev-' ) );
		const markerPath = path.join( fixtureRoot, 'assets/build/hot' );
		mkdirSync( path.dirname( markerPath ), { recursive: true } );
		writeFileSync( markerPath, 'http://old-server:5173' );
		const staleTime = new Date( Date.now() - 60_000 );
		utimesSync( markerPath, staleTime, staleTime );
		const signals = new EventEmitter();
		let listened = false;
		let closed = false;
		const server = {
			config: { server: { origin: 'http://localhost:5173' } },
			async listen() {
				listened = true;
				expect( existsSync( markerPath ) ).toBe( false );
			},
			async close() {
				closed = true;
			},
		};

		const running = await startViteDev( {
			createServer: async () => server,
			markerPath,
			signals,
		} );

		expect( listened ).toBe( true );
		expect( readFileSync( markerPath, 'utf8' ) ).toBe(
			'http://localhost:5173'
		);
		signals.emit( 'SIGTERM' );
		await running.closed;

		expect( closed ).toBe( true );
		expect( existsSync( markerPath ) ).toBe( false );
	} );

	it( 'closes the server and leaves no marker when startup fails', async () => {
		fixtureRoot = mkdtempSync( path.join( os.tmpdir(), 'vite-dev-fail-' ) );
		const markerPath = path.join( fixtureRoot, 'assets/build/hot' );
		let closed = false;
		const server = {
			async listen() {
				throw new Error( 'listen failed' );
			},
			async close() {
				closed = true;
			},
		};

		await expect(
			startViteDev( {
				createServer: async () => server,
				markerPath,
				signals: new EventEmitter(),
			} )
		).rejects.toThrow( 'listen failed' );

		expect( closed ).toBe( true );
		expect( existsSync( markerPath ) ).toBe( false );
	} );

	it( 'preserves an active server marker when a second dev server cannot listen', async () => {
		fixtureRoot = mkdtempSync(
			path.join( os.tmpdir(), 'vite-dev-conflict-' )
		);
		const markerPath = path.join( fixtureRoot, 'assets/build/hot' );
		const signals = new EventEmitter();
		const activeServer = {
			config: { server: { origin: 'http://localhost:5173' } },
			async listen() {},
			async close() {},
		};
		const active = await startViteDev( {
			createServer: async () => activeServer,
			markerPath,
			signals,
		} );
		const competingServer = {
			async listen() {
				throw new Error( 'port is already in use' );
			},
			async close() {},
		};

		await expect(
			startViteDev( {
				createServer: async () => competingServer,
				markerPath,
				signals: new EventEmitter(),
			} )
		).rejects.toThrow( 'port is already in use' );
		expect( readFileSync( markerPath, 'utf8' ) ).toBe(
			'http://localhost:5173'
		);
		await active.shutdown();
	} );

	it( 'handles SIGTERM sent while the server is starting', async () => {
		fixtureRoot = mkdtempSync(
			path.join( os.tmpdir(), 'vite-dev-start-signal-' )
		);
		const markerPath = path.join( fixtureRoot, 'assets/build/hot' );
		const signals = new EventEmitter();
		let signalHandlerWasInstalled = false;
		let closed = false;
		const server = {
			config: { server: { origin: 'http://localhost:5173' } },
			async listen() {
				signalHandlerWasInstalled =
					signals.listenerCount( 'SIGTERM' ) > 0;
				signals.emit( 'SIGTERM' );
			},
			async close() {
				closed = true;
			},
		};

		const running = await startViteDev( {
			createServer: async () => server,
			markerPath,
			signals,
		} );

		expect( signalHandlerWasInstalled ).toBe( true );
		await running.closed;
		expect( closed ).toBe( true );
		expect( existsSync( markerPath ) ).toBe( false );
	} );

	it( 'cleans up if SIGTERM arrives while the marker is being written', async () => {
		fixtureRoot = mkdtempSync(
			path.join( os.tmpdir(), 'vite-dev-write-signal-' )
		);
		const markerPath = path.join( fixtureRoot, 'assets/build/hot' );
		const signals = new EventEmitter();
		let closed = false;
		const server = {
			config: { server: { origin: 'http://localhost:5173' } },
			async listen() {},
			async close() {
				closed = true;
			},
		};
		const running = await startViteDev( {
			createServer: async () => server,
			markerPath,
			signals,
			writeMarker( file, contents ) {
				signals.emit( 'SIGTERM' );
				writeFileSync( file, contents );
			},
		} );

		await running.closed;
		expect( closed ).toBe( true );
		expect( existsSync( markerPath ) ).toBe( false );
	} );

	it( 'keeps signal handlers through an idempotent asynchronous shutdown', async () => {
		fixtureRoot = mkdtempSync(
			path.join( os.tmpdir(), 'vite-dev-repeat-signal-' )
		);
		const markerPath = path.join( fixtureRoot, 'assets/build/hot' );
		const signals = new EventEmitter();
		let releaseClose;
		let closeStarted;
		let closeCalls = 0;
		const closeGate = new Promise( ( resolve ) => {
			releaseClose = resolve;
		} );
		const closeStartedPromise = new Promise( ( resolve ) => {
			closeStarted = resolve;
		} );
		const server = {
			config: { server: { origin: 'http://localhost:5173' } },
			async listen() {},
			async close() {
				closeCalls++;
				closeStarted();
				await closeGate;
			},
		};
		const running = await startViteDev( {
			createServer: async () => server,
			markerPath,
			signals,
		} );

		signals.emit( 'SIGTERM' );
		await closeStartedPromise;
		const listenersDuringShutdown = signals.listenerCount( 'SIGTERM' );
		signals.emit( 'SIGINT' );
		releaseClose();
		await running.closed;

		expect( listenersDuringShutdown ).toBe( 1 );
		expect( closeCalls ).toBe( 1 );
		expect( existsSync( markerPath ) ).toBe( false );
	} );

	it( 'refreshes the marker timestamp every five seconds', async () => {
		fixtureRoot = mkdtempSync(
			path.join( os.tmpdir(), 'vite-dev-heartbeat-' )
		);
		const markerPath = path.join( fixtureRoot, 'assets/build/hot' );
		const signals = new EventEmitter();
		let refresh;
		let interval;
		const server = {
			config: { server: { origin: 'http://localhost:5173' } },
			async listen() {},
			async close() {},
		};
		const running = await startViteDev( {
			createServer: async () => server,
			markerPath,
			signals,
			setInterval( callback, delay ) {
				refresh = callback;
				interval = delay;
				return { unref() {} };
			},
			clearInterval() {},
		} );
		const staleTime = new Date( Date.now() - 60_000 );
		utimesSync( markerPath, staleTime, staleTime );

		await refresh();

		expect( interval ).toBe( 5000 );
		expect( statSync( markerPath ).mtimeMs ).toBeGreaterThan(
			staleTime.getTime()
		);
		await running.shutdown();
	} );

	it( 'uses Vite directly for production builds without creating a dev marker', () => {
		const packageJson = JSON.parse(
			readFileSync( path.resolve( 'package.json' ), 'utf8' )
		);

		expect( packageJson.scripts.build ).toBe( 'vite build' );
		expect( packageJson.scripts.build ).not.toContain( 'vite-dev' );
	} );

	it( 'passes lint fix flags to the linters without treating them as paths', () => {
		const packageJson = JSON.parse(
			readFileSync( path.resolve( 'package.json' ), 'utf8' )
		);

		expect( packageJson.scripts[ 'lint:js:fix' ] ).toBe(
			'wp-scripts lint-js --fix'
		);
		expect( packageJson.scripts[ 'lint:css:fix' ] ).toBe(
			'wp-scripts lint-style --fix'
		);
	} );
} );
