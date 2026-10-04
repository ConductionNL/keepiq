/**
 * Build the Keepiq MV3 extension with esbuild, once per browser
 * (clients-browser-builds): `dist/chromium` (Chrome, Edge; also the input of
 * the Safari converter) and `dist/firefox`. Each entry is bundled to a single
 * self-contained file (MV3 forbids remote code + runtime chunk loading),
 * inlining the shared `src/crypto` and `src/totp` modules verbatim so the
 * PHP↔JS↔extension crypto stays in lockstep (ADR-003).
 *
 * Usage: node browser-extension/build.mjs [--watch]
 *          [--target chrome|firefox | --browser chromium|firefox] [--outdir <dir>]
 *
 * `--target chrome` builds the Chromium package (Chrome and Edge), `--target
 * firefox` the Firefox one (extension-store-release D1). EXTENSION_VERSION
 * (from the `extension-v<version>` tag) becomes the manifest version.
 */
import { build, context } from 'esbuild'
import { cp, mkdir, readFile, rm, writeFile } from 'node:fs/promises'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { BROWSERS, manifestFor } from './manifests/browsers.mjs'

const root = dirname(fileURLToPath(import.meta.url))
const argValue = (name) =>
	process.argv.includes(name) ? process.argv[process.argv.indexOf(name) + 1] : null
const dist = argValue('--outdir')
	? resolve(process.cwd(), argValue('--outdir'))
	: resolve(root, 'dist')
const watch = process.argv.includes('--watch')
// `--target chrome` is the store-facing name of the Chromium package.
const TARGETS = { chrome: 'chromium', chromium: 'chromium', firefox: 'firefox' }
const requested = argValue('--target') || argValue('--browser')
if (requested && !TARGETS[requested]) {
	console.error('unknown target: ' + requested + ' (chrome or firefox)')
	process.exit(2)
}
const only = requested ? TARGETS[requested] : null

const common = {
	bundle: true,
	target: ['chrome110', 'firefox115'],
	logLevel: 'info',
	sourcemap: false,
	legalComments: 'none',
	// Argon2id's WebAssembly is bundled as bytes (password-protected sends).
	loader: { '.wasm': 'binary' },
	// The emscripten glue of argon2-browser has Node-only branches; they never
	// run in a browser, so their modules stay unresolved.
	external: ['fs', 'path', 'crypto'],
}

/**
 * The bundles for one browser. The content script is always a classic IIFE
 * (content scripts cannot import); the worker is a module on Chromium and a
 * classic background script on Firefox.
 *
 * @param {string} browser chromium or firefox.
 * @return {Array<{in: string, out: string, format: string}>} The entries.
 */
function entriesFor(browser) {
	return [
		{
			in: resolve(root, 'src/background/service-worker.js'),
			out: 'service-worker',
			format: browser === 'firefox' ? 'iife' : 'esm',
		},
		{ in: resolve(root, 'src/popup/popup.js'), out: 'popup', format: 'esm' },
		{
			in: resolve(root, 'src/content/content-script.js'),
			out: 'content-script',
			format: 'iife',
		},
		{
			in: resolve(root, 'src/content/inpage-shim.js'),
			out: 'inpage-shim',
			format: 'iife',
		},
		{
			in: resolve(root, 'src/passkey/consent.js'),
			out: 'consent',
			format: 'esm',
		},
		{
			in: resolve(root, 'src/unlock/unlock.js'),
			out: 'unlock',
			format: 'esm',
		},
		{
			in: resolve(root, 'src/offscreen/offscreen.js'),
			out: 'offscreen',
			format: 'esm',
		},
	]
}

async function buildBrowser(browser, base) {
	const outdir = resolve(dist, browser)
	// Clear only this browser's package, never the whole output directory.
	await rm(outdir, { recursive: true, force: true })
	await mkdir(outdir, { recursive: true })
	for (const e of entriesFor(browser)) {
		const opts = {
			...common,
			entryPoints: [e.in],
			outfile: resolve(outdir, e.out + '.js'),
			format: e.format,
		}
		if (watch) {
			const ctx = await context(opts)
			await ctx.watch()
		} else {
			await build(opts)
		}
	}
	await writeFile(
		resolve(outdir, 'manifest.json'),
		JSON.stringify(manifestFor(base, browser), null, 2) + '\n',
	)
	await cp(resolve(root, 'src/popup/popup.html'), resolve(outdir, 'popup.html'))
	await cp(resolve(root, 'src/popup/popup.css'), resolve(outdir, 'popup.css'))
	await cp(
		resolve(root, 'src/passkey/consent.html'),
		resolve(outdir, 'consent.html'),
	)
	await cp(resolve(root, 'src/unlock/unlock.html'), resolve(outdir, 'unlock.html'))
	await cp(
		resolve(root, 'src/offscreen/offscreen.html'),
		resolve(outdir, 'offscreen.html'),
	)
}

async function run() {
	const base = JSON.parse(await readFile(resolve(root, 'manifest.json'), 'utf8'))
	const version = process.env.EXTENSION_VERSION
	if (version) {
		// Store manifests take one to four dot-separated integers.
		if (!/^\d+(\.\d+){0,3}$/.test(version)) {
			throw new Error('EXTENSION_VERSION must look like 1.2.0, got ' + version)
		}
		base.version = version
	}
	for (const browser of BROWSERS) {
		if (only && only !== browser) continue
		await buildBrowser(browser, base)
	}
}

run().catch((e) => {
	console.error(e)
	process.exit(1)
})
