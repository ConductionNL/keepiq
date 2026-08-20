/**
 * Build the Doriath MV3 extension with esbuild. Each entry is bundled to a
 * single self-contained ESM/IIFE file (MV3 forbids remote code + runtime chunk
 * loading), inlining the shared `src/crypto` and `src/totp` modules verbatim so
 * the PHP↔JS↔extension crypto stays in lockstep (ADR-003).
 *
 * Usage: node browser-extension/build.mjs [--watch]
 */
import { build, context } from 'esbuild'
import { cp, mkdir, rm } from 'node:fs/promises'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = dirname(fileURLToPath(import.meta.url))
const outdir = resolve(root, 'dist')
const watch = process.argv.includes('--watch')

const common = {
	bundle: true,
	format: 'esm',
	target: ['chrome110', 'firefox110'],
	logLevel: 'info',
	sourcemap: false,
	legalComments: 'none',
}

// The content script must be a classic (non-module) IIFE — content scripts do
// not support ES module imports.
const entries = [
	{ in: resolve(root, 'src/background/service-worker.js'), out: 'service-worker', format: 'esm' },
	{ in: resolve(root, 'src/popup/popup.js'), out: 'popup', format: 'esm' },
	{ in: resolve(root, 'src/content/content-script.js'), out: 'content-script', format: 'iife' },
	{ in: resolve(root, 'src/content/inpage-shim.js'), out: 'inpage-shim', format: 'iife' },
	{ in: resolve(root, 'src/passkey/consent.js'), out: 'consent', format: 'esm' },
]

async function run() {
	await rm(outdir, { recursive: true, force: true })
	await mkdir(outdir, { recursive: true })

	for (const e of entries) {
		const opts = { ...common, entryPoints: [e.in], outfile: resolve(outdir, e.out + '.js'), format: e.format }
		if (watch) {
			const ctx = await context(opts)
			await ctx.watch()
		} else {
			await build(opts)
		}
	}

	// Static assets: manifest + popup html/css (service-worker/popup ESM live in dist/).
	await cp(resolve(root, 'manifest.json'), resolve(outdir, 'manifest.json'))
	await cp(resolve(root, 'src/popup/popup.html'), resolve(outdir, 'popup.html'))
	await cp(resolve(root, 'src/popup/popup.css'), resolve(outdir, 'popup.css'))
	await cp(resolve(root, 'src/passkey/consent.html'), resolve(outdir, 'consent.html'))
}

run().catch((e) => { console.error(e); process.exit(1) })
