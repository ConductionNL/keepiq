/**
 * @spec openspec/changes/clients-extension-gaps/specs/extension-fill-and-capture/spec.md
 *
 * Fill and capture hardening on the REAL router and popup: a fill reaches
 * only frames on the matched site, blocked rows are never offered, an https
 * login asks before it fills an http page, and the save prompt trusts the
 * browser (sender, tab, five minutes), never the page.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
	installChrome,
	installServer,
	makeVault,
	pageSender,
	POPUP,
} from './fixtures/fakeBrowser.js'

const SERVER = 'https://one.example'
let router
let browser
let server
let state

/**
 * Send a message to the router as the popup, or as a page.
 *
 * @param {string} type The type.
 * @param {object} [payload] The payload.
 * @param {object} [sender] The sender.
 * @return {Promise<object>}
 */
const send = (type, payload = {}, sender = POPUP) =>
	router.handleMessage({ type, payload }, sender)

/**
 * A content script in a frame of tab 1.
 *
 * @param {string} url The frame's page.
 * @param {number} frameId The frame.
 * @return {object}
 */
const frame = (url, frameId) => ({
	...pageSender(url),
	tab: { id: 1, url: 'https://example.com/login' },
	frameId,
})

beforeEach(async () => {
	vi.resetModules()
	browser = installChrome({ tabUrl: 'https://example.com/login' })
	const fixture = await makeVault('m', 'x')
	state = { ...fixture, types: [{ id: 't1', name: 'login' }], folders: [] }
	server = installServer({ [SERVER]: state })
	router = await import('../../browser-extension/src/background/router.js')
	await send('pair', { url: SERVER, user: 'ann', appPassword: 'p' })
	await send('unlock', { masterPassword: 'm' })
})

afterEach(async () => {
	vi.restoreAllMocks()
	await new Promise((r) => setTimeout(r, 300))
	document.body.innerHTML = ''
})

describe('fill', () => {
	it('reaches only the frames the browser places on the matched site', async () => {
		await send('frame-ready', {}, frame('https://example.com/login', 0))
		await send('frame-ready', {}, frame('https://ads.example/slot', 5))
		// A frame that claims the site in its payload is still placed by its sender.
		await send(
			'frame-ready',
			{ host: 'example.com' },
			frame('https://evil.example/', 7),
		)
		await send('frame-ready', {}, frame('https://example.com/sso', 6))
		const [offered] = await send('match', { host: 'example.com' })
		const res = await send('fill', {
			id: offered.id,
			accountId: offered.accountId,
		})
		expect(res.filled).toBe(true)
		const frames = browser.filled
			.filter((m) => m.type === 'fill-credential')
			.map((m) => m.options.frameId)
			.sort()
		expect(frames).toEqual([0, 6])
	})

	it('keeps a frame that reported before the top frame, and forgets them all when the tab loads a new page', async () => {
		await send('frame-ready', {}, frame('https://example.com/sso', 4))
		await send('frame-ready', {}, frame('https://example.com/login', 0))
		let [offered] = await send('match', { host: 'example.com' })
		await send('fill', { id: offered.id, accountId: offered.accountId })
		expect(browser.filled.map((m) => m.options.frameId).sort()).toEqual([0, 4])

		for (const listener of browser.tabs.onUpdated.listeners) {
			listener(1, { status: 'loading' })
		}
		await new Promise((r) => setTimeout(r, 20))
		browser.filled.length = 0
		;[offered] = await send('match', { host: 'example.com' })
		await send('fill', { id: offered.id, accountId: offered.accountId })
		expect(browser.filled.map((m) => m.options.frameId)).toEqual([0])
	})

	it('falls back to the top frame alone when no frame was recorded', async () => {
		const [offered] = await send('match', { host: 'example.com' })
		await send('fill', { id: offered.id, accountId: offered.accountId })
		expect(browser.filled.map((m) => m.options)).toEqual([{ frameId: 0 }])
	})

	it('never offers a blocked row, even when the server returns it', async () => {
		state.rows = [
			...state.rows,
			{
				...state.rows[0],
				id: 'blocked-1',
				name: 'Blocked example',
				blocked: true,
			},
		]
		const offered = await send('match', { host: 'example.com' })
		expect(offered.map((o) => o.name)).not.toContain('Blocked example')
		expect(offered.length).toBeGreaterThan(0)
	})

	it('asks before filling an https login into a plain http page', async () => {
		browser.setTab('http://example.com/login')
		const [offered] = await send('match', { host: 'example.com' })
		const first = await send('fill', {
			id: offered.id,
			accountId: offered.accountId,
		})
		expect(first).toEqual({ filled: false, confirm: 'http-page' })
		expect(browser.filled).toHaveLength(0)
		const yes = await send('fill', {
			id: offered.id,
			accountId: offered.accountId,
			allowHttp: true,
		})
		expect(yes.filled).toBe(true)
	})

	it('shows the question in the popup and fills nothing on no', async () => {
		browser.setTab('http://example.com/login')
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
		window.close = vi.fn()
		window.confirm = vi.fn(() => false)
		vi.resetModules()
		await import('../../browser-extension/src/popup/popup.js')
		const $ = (id) => document.getElementById(id)
		await vi.waitFor(() =>
			expect($('candidates').querySelector('button')).toBeTruthy(),
		)
		$('candidates').querySelector('button').click()
		await vi.waitFor(() => expect(window.confirm).toHaveBeenCalled())
		expect(window.confirm.mock.calls[0][0]).toMatch(/this page is not secure/)
		await new Promise((r) => setTimeout(r, 100))
		expect(browser.filled).toHaveLength(0)
	})
})

describe('the save prompt', () => {
	const WEAKISH = 'correct horse battery staple violin 7%Q'

	it('takes the site from the browser, not from what the page says', async () => {
		browser.setTab('https://evil.example/login', 9)
		await send(
			'capture-credential',
			{
				host: 'bank.example',
				url: 'https://bank.example',
				login: 'ann',
				secret: WEAKISH,
			},
			pageSender('https://evil.example/login'),
		)
		const { capture } = await send('pending-capture')
		expect(capture.host).toBe('evil.example')
		expect(JSON.stringify(capture)).not.toContain(WEAKISH)
	})

	it('holds a capture for its own tab only', async () => {
		await send(
			'capture-credential',
			{ login: 'ann', secret: WEAKISH },
			pageSender('https://new.example/login'),
		)
		// The popup over tab 1 does not see the capture made in tab 9.
		expect((await send('pending-capture')).capture).toBeNull()
		browser.setTab('https://new.example/login', 9)
		expect((await send('pending-capture')).capture.host).toBe('new.example')
	})

	it('lets a capture expire after five minutes', async () => {
		browser.setTab('https://new.example/login', 9)
		await send(
			'capture-credential',
			{ login: 'ann', secret: WEAKISH },
			pageSender('https://new.example/login'),
		)
		const now = Date.now()
		vi.spyOn(Date, 'now').mockReturnValue(now + router.CAPTURE_TTL_MS + 1)
		expect((await send('pending-capture')).capture).toBeNull()
	})

	it('drops a capture when its tab closes', async () => {
		browser.setTab('https://new.example/login', 9)
		await send(
			'capture-credential',
			{ login: 'ann', secret: WEAKISH },
			pageSender('https://new.example/login'),
		)
		for (const listener of browser.tabs.onRemoved.listeners) listener(9)
		expect((await send('pending-capture')).capture).toBeNull()
	})

	it('saves only what the browser saw submitted, and nothing without a capture', async () => {
		const none = await send('save-capture', { secret: 'smuggled', host: 'x' })
		expect(none.error).toMatch(/no login waiting/)
		expect(
			server.calls.some(
				(c) => c.method === 'POST' && c.url.endsWith('/api/v1/secrets'),
			),
		).toBe(false)
		browser.setTab('https://new.example/login', 9)
		await send(
			'capture-credential',
			{ login: 'ann', secret: WEAKISH },
			pageSender('https://new.example/login'),
		)
		const res = await send('save-capture', { secret: 'smuggled' })
		expect(res).toEqual({ ok: true })
		const post = server.calls.find(
			(c) => c.method === 'POST' && c.url.endsWith('/api/v1/secrets'),
		)
		expect(post.body).toMatchObject({
			name: 'new.example',
			url: 'https://new.example',
		})
		expect(JSON.stringify(post.body)).not.toContain('smuggled')
	})
})
