/**
 * @spec openspec/specs/extension-store-release/spec.md#requirement-the-extension-checks-the-server-version-on-pairing
 *
 * A store-updated extension paired with an older Keepiq server says the
 * server needs an update and asks it for nothing else (keepiq#783).
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
	isServerSupported,
	MIN_SERVER_VERSION,
} from '../../browser-extension/src/lib/version.js'
import {
	installChrome,
	installServer,
	makeVault,
	POPUP,
} from './fixtures/fakeBrowser.js'

const OLD = 'https://old.example'
let fixture

describe('isServerSupported', () => {
	it('compares the version core and ignores a pre-release tail', () => {
		expect(isServerSupported(MIN_SERVER_VERSION)).toBe(true)
		expect(isServerSupported('0.3.4-unstable.20261002180000')).toBe(true)
		expect(isServerSupported('0.4.0')).toBe(true)
		expect(isServerSupported('0.3.3')).toBe(false)
		expect(isServerSupported(null)).toBe(false)
		expect(isServerSupported(undefined)).toBe(false)
		expect(isServerSupported('banana')).toBe(false)
	})
})

describe('an older server', () => {
	let router
	let server

	beforeEach(async () => {
		vi.resetModules()
		installChrome()
		if (!fixture) fixture = await makeVault('m', 'x')
		// An older server reports no version on pairing.
		server = installServer({ [OLD]: { ...fixture, serverVersion: undefined } })
		router = await import('../../browser-extension/src/background/router.js')
		await router.handleMessage(
			{ type: 'pair', payload: { url: OLD, user: 'alice', appPassword: 'p' } },
			POPUP,
		)
	})

	it('shows the update message in the popup and sends no match request', async () => {
		const html = readFileSync(
			resolve(__dirname, '../../browser-extension/src/popup/popup.html'),
			'utf8',
		)
		document.body.innerHTML = html
			.replace(/^[\s\S]*<body>/, '')
			.replace(/<\/body>[\s\S]*$/, '')
		globalThis.chrome.runtime.sendMessage = (msg, cb) => {
			const pending = router.handleMessage(msg, POPUP)
			if (pending) pending.then(cb)
		}
		await import('../../browser-extension/src/popup/popup.js')

		await vi.waitFor(() =>
			expect(document.getElementById('view-update').hidden).toBe(false),
		)
		expect(document.getElementById('view-update').textContent).toContain(
			'Update Keepiq on your server',
		)
		expect(document.getElementById('view-unlocked').hidden).toBe(true)
		expect(server.calls.some((c) => c.url.includes('/extension/match'))).toBe(
			false,
		)
	})

	it('refuses a match in the worker too', async () => {
		const res = await router.handleMessage(
			{ type: 'match', payload: { host: 'example.com' } },
			POPUP,
		)
		expect(res.error).toContain('Update Keepiq on your server')
		expect(server.calls.some((c) => c.url.includes('/extension/match'))).toBe(
			false,
		)
	})
})
