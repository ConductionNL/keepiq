/**
 * Load the Chromium package headless and check the service worker starts and
 * answers the popup (clients-browser-builds, "Chromium is unchanged").
 *
 * Usage: node browser-extension/load-check/chromium.mjs [dist/chromium]
 * Exits 0 when the worker answered get-state, 1 otherwise.
 *
 * @spec openspec/changes/clients-extension-firefox-and-safari-builds/specs/clients-browser-builds/spec.md
 */
import { chromium } from '@playwright/test'
import { mkdtemp, readFile, rm } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const here = dirname(fileURLToPath(import.meta.url))
const pkg = resolve(process.argv[2] || join(here, '..', 'dist', 'chromium'))
const profile = await mkdtemp(join(tmpdir(), 'keepiq-chromium-'))

let context
let cdp = null
try {
	context = await chromium.launchPersistentContext(profile, {
		channel: 'chromium',
		headless: true,
		args: [
			`--disable-extensions-except=${pkg}`,
			`--load-extension=${pkg}`,
			'--remote-debugging-port=0',
		],
	})
	const worker =
		context.serviceWorkers()[0]
		|| (await context.waitForEvent('serviceworker', { timeout: 15000 }))
	// The worker answers its own pages only, never a tab (#921), so the check
	// opens the REAL action popup over an ordinary page and reaches it over
	// the DevTools protocol: popup.html opened as a tab is refused, rightly.
	await (await context.newPage()).goto('about:blank')
	await worker.evaluate(() => chrome.action.openPopup())
	const port = (await readFile(join(profile, 'DevToolsActivePort'), 'utf8')).split(
		'\n',
	)[0]
	let popup = null
	for (let i = 0; i < 20 && !popup; i++) {
		await new Promise((r) => setTimeout(r, 250))
		cdp?.close().catch(() => {})
		cdp = await chromium.connectOverCDP(`http://127.0.0.1:${port}`)
		popup = cdp
			.contexts()
			.flatMap((c) => c.pages())
			.find((p) => p.url().endsWith('/popup.html'))
	}
	if (!popup) throw new Error('the action popup did not open')
	const state = await popup.evaluate(() =>
		chrome.runtime.sendMessage({ type: 'get-state' }),
	)
	if (!state || state.paired !== false) {
		throw new Error('unexpected state ' + JSON.stringify(state))
	}
	console.log(
		'chromium: service worker started, get-state answered',
		JSON.stringify(state),
	)
} catch (e) {
	console.error('chromium load check failed:', e.message || e)
	process.exitCode = 1
} finally {
	await cdp?.close().catch(() => {})
	await context?.close()
	await rm(profile, { recursive: true, force: true })
}
