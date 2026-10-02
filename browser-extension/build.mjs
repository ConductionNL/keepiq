/**
 * Build the Keepiq MV3 extension with esbuild, once per browser
 * (clients-browser-builds): `dist/chromium` (Chrome, Edge; also the input of
 * the Safari converter) and `dist/firefox`. Each entry is bundled to a single
 * self-contained file (MV3 forbids remote code + runtime chunk loading),
 * inlining the shared `src/crypto` and `src/totp` modules verbatim so the
 * PHP↔JS↔extension crypto stays in lockstep (ADR-003).
 *
 * Usage: node browser-extension/build.mjs [--watch] [--browser chromium|firefox]
 */
import { build, context } from 'esbuild'
import { cp, mkdir, readFile, rm, writeFile } from 'node:fs/promises'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { BROWSERS, manifestFor } from './manifests/browsers.mjs'

const root = dirname(fileURLToPath(import.meta.url))
const dist = resolve(root, 'dist')
const watch = process.argv.includes('--watch')
const only = process.argv.includes('--browser')
	? process.argv[process.argv.indexOf('--browser') + 1]
	: null

const common = {
	bundle: true,
	target: ['chrome110', 'firefox115'],
	logLevel: 'info',
	sourcemap: false,
	legalComments: 'none',
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
	]
}

async function buildBrowser(browser, base) {
	const outdir = resolve(dist, browser)
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
}

async function run() {
	await rm(dist, { recursive: true, force: true })
	const base = JSON.parse(await readFile(resolve(root, 'manifest.json'), 'utf8'))
	for (const browser of BROWSERS) {
		if (only && only !== browser) continue
		await buildBrowser(browser, base)
	}
}

run().catch((e) => {
	console.error(e)
	process.exit(1)
})
