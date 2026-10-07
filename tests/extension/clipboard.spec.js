/**
 * @spec openspec/specs/extension-clipboard/spec.md
 *
 * Every copy is cleared after the user's delay, by the worker, also when the
 * popup has closed; and a large vault is never cut off in silence.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
	buildClipboardClear,
	CLEAR_ALARM,
	clearClipboardNow,
	clearWithDocument,
} from '../../browser-extension/src/background/clipboard-clear.js'
import { areaOrMemory } from '../../browser-extension/src/background/generator-handlers.js'
import {
	listSecrets,
	MAX_SECRET_PAGES,
	SECRETS_PAGE_SIZE,
} from '../../browser-extension/src/lib/api.js'
import {
	installChrome,
	installServer,
	makeVault,
	POPUP,
} from './fixtures/fakeBrowser.js'

afterEach(() => {
	vi.useRealTimers()
	vi.restoreAllMocks()
})

describe('the clearer', () => {
	/**
	 * A clearer with fake alarms.
	 *
	 * @return {object}
	 */
	function setup() {
		const clear = vi.fn(async () => {})
		const alarms = { create: vi.fn(), clear: vi.fn() }
		const local = areaOrMemory(null)
		const clipboard = buildClipboardClear({
			local,
			clear,
			alarms,
			now: () => 1000,
		})
		return { clipboard, clear, alarms }
	}

	it('clears after 30 seconds by default, through an alarm', async () => {
		const { clipboard, alarms, clear } = setup()
		expect(await clipboard.copied()).toEqual({ clearsInSeconds: 30 })
		expect(alarms.create).toHaveBeenCalledWith(CLEAR_ALARM, { when: 31000 })
		expect(await clipboard.onAlarm({ name: CLEAR_ALARM })).toBe(true)
		expect(clear).toHaveBeenCalledTimes(1)
		expect(await clipboard.onAlarm({ name: 'keepiq-sync:a' })).toBe(false)
	})

	it('uses a timer for a delay shorter than an alarm allows, and restarts on a newer copy', async () => {
		vi.useFakeTimers()
		const { clipboard, alarms, clear } = setup()
		await clipboard.setSeconds(10)
		await clipboard.copied()
		vi.advanceTimersByTime(8000)
		await clipboard.copied()
		vi.advanceTimersByTime(8000)
		expect(clear).not.toHaveBeenCalled()
		vi.advanceTimersByTime(2000)
		expect(clear).toHaveBeenCalledTimes(1)
		expect(alarms.create).not.toHaveBeenCalled()
	})

	it('never clears when the user picked Never, and refuses an unknown delay', async () => {
		const { clipboard, alarms } = setup()
		await clipboard.setSeconds(0)
		expect(await clipboard.copied()).toEqual({ clearsInSeconds: 0 })
		expect(alarms.create).not.toHaveBeenCalled()
		await expect(clipboard.setSeconds(7)).rejects.toThrow('unsupported delay')
	})

	it('clears through an offscreen document in Chromium, made once', async () => {
		const sent = []
		let exists = false
		globalThis.chrome = {
			offscreen: {
				hasDocument: vi.fn(async () => exists),
				createDocument: vi.fn(async (opts) => {
					exists = true
					expect(opts.reasons).toEqual(['CLIPBOARD'])
				}),
			},
			runtime: { sendMessage: vi.fn(async (m) => sent.push(m)) },
		}
		await clearClipboardNow()
		await clearClipboardNow()
		expect(globalThis.chrome.offscreen.createDocument).toHaveBeenCalledTimes(1)
		expect(sent).toEqual([
			{ type: 'offscreen-clear-clipboard' },
			{ type: 'offscreen-clear-clipboard' },
		])
	})

	it('writes an empty text on a page with a document', () => {
		let listener = null
		const data = {}
		const doc = {
			addEventListener: (type, fn) => {
				listener = fn
			},
			removeEventListener: () => {
				listener = null
			},
			execCommand: () => {
				listener({
					clipboardData: { setData: (k, v) => (data[k] = v) },
					preventDefault: () => {},
				})
				return true
			},
		}
		expect(clearWithDocument(doc)).toBe(true)
		expect(data).toEqual({ 'text/plain': '' })
		expect(listener).toBeNull()
	})
})

const SERVER = 'https://one.example'

describe('in the worker and the popup', () => {
	let router
	let browser

	beforeEach(async () => {
		vi.resetModules()
		browser = installChrome()
		const fixture = await makeVault('m', 'x')
		installServer({
			[SERVER]: {
				...fixture,
				types: [{ id: 't1', name: 'login' }],
				folders: [],
			},
		})
		router = await import('../../browser-extension/src/background/router.js')
		await router.handleMessage(
			{
				type: 'pair',
				payload: { url: SERVER, user: 'ann', appPassword: 'p' },
			},
			POPUP,
		)
		await router.handleMessage(
			{ type: 'unlock', payload: { masterPassword: 'm' } },
			POPUP,
		)
	})

	afterEach(async () => {
		await new Promise((r) => setTimeout(r, 300))
		document.body.innerHTML = ''
	})

	it('copies a password from the item detail and has the worker clear it later', async () => {
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
		const writes = []
		Object.defineProperty(navigator, 'clipboard', {
			configurable: true,
			value: { writeText: vi.fn(async (t) => writes.push(t)) },
		})
		vi.resetModules()
		await import('../../browser-extension/src/popup/popup.js')
		const $ = (id) => document.getElementById(id)
		await vi.waitFor(() => expect($('view-unlocked').hidden).toBe(false))
		$('tab-vault').click()
		await vi.waitFor(() =>
			expect($('vault-list').querySelector('button')).toBeTruthy(),
		)
		$('vault-list').querySelector('button').click()
		await vi.waitFor(() =>
			expect(
				document.querySelector('button[aria-label="Copy Password"]'),
			).toBeTruthy(),
		)
		document.querySelector('button[aria-label="Copy Password"]').click()
		await vi.waitFor(() =>
			expect(browser.alarms.create).toHaveBeenCalledWith(CLEAR_ALARM, {
				when: expect.any(Number),
			}),
		)
		expect(writes).toHaveLength(1)

		// The setting: 30 seconds unless changed, for this browser.
		$('tab-settings').click()
		await vi.waitFor(() =>
			expect($('clipboard-clear').options.length).toBeGreaterThan(0),
		)
		expect($('clipboard-clear').selectedOptions[0].textContent).toBe(
			'30 seconds',
		)
		$('clipboard-clear').value = '10'
		$('clipboard-clear').dispatchEvent(new Event('change'))
		await vi.waitFor(async () =>
			expect(
				(
					await router.handleMessage(
						{ type: 'clipboard-settings', payload: {} },
						POPUP,
					)
				).seconds,
			).toBe(10),
		)
	})
})

describe('a large vault', () => {
	/**
	 * A server that answers the secrets list with full pages and a total.
	 *
	 * @param {number} total The vault size.
	 */
	function serve(total) {
		globalThis.fetch = vi.fn(async (url) => {
			const page = Number(new URL(url).searchParams.get('page'))
			const left = Math.max(0, total - (page - 1) * SECRETS_PAGE_SIZE)
			const items = Array.from(
				{ length: Math.min(SECRETS_PAGE_SIZE, left) },
				(_, i) => ({ id: `s${page}-${i}` }),
			)
			return { ok: true, status: 200, json: async () => ({ items, total }) }
		})
	}

	it('reads every page of a vault of 250 items', async () => {
		serve(250)
		const items = await listSecrets({ url: SERVER, user: 'a', appPassword: 'p' })
		expect(items).toHaveLength(250)
		expect(fetch).toHaveBeenCalledTimes(3)
	})

	it('says so instead of returning part of a vault larger than it reads', async () => {
		serve((MAX_SECRET_PAGES + 1) * SECRETS_PAGE_SIZE)
		await expect(
			listSecrets({ url: SERVER, user: 'a', appPassword: 'p' }),
		).rejects.toMatchObject({ status: 413 })
		expect(fetch).toHaveBeenCalledTimes(MAX_SECRET_PAGES)
	})
})
