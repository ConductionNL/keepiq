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
import { mkdtemp, rm } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const here = dirname(fileURLToPath(import.meta.url))
const pkg = resolve(process.argv[2] || join(here, '..', 'dist', 'chromium'))
const profile = await mkdtemp(join(tmpdir(), 'keepiq-chromium-'))

let context
try {
	context = await chromium.launchPersistentContext(profile, {
		channel: 'chromium',
		headless: true,
		args: [`--disable-extensions-except=${pkg}`, `--load-extension=${pkg}`],
	})
	const worker =
		context.serviceWorkers()[0]
		|| (await context.waitForEvent('serviceworker', { timeout: 15000 }))
	const id = new URL(worker.url()).host
	const page = await context.newPage()
	await page.goto(`chrome-extension://${id}/popup.html`)
	const state = await page.evaluate(() =>
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
	await context?.close()
	await rm(profile, { recursive: true, force: true })
}
