/**
 * @spec openspec/specs/extension-popup-shell/spec.md
 *
 * The popup shell on the REAL popup and router: the last tab, the Settings
 * tab, the pop-out window and the tab it stays pinned to, and the hint when
 * the tab is not a website.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
	installChrome,
	installServer,
	makeVault,
	POPUP,
} from './fixtures/fakeBrowser.js'

const SERVER = 'https://one.example'
let router
let browser

/**
 * Element by id.
 *
 * @param {string} id The id.
 * @return {HTMLElement}
 */
const $ = (id) => document.getElementById(id)

/**
 * Open the popup, optionally as the popped-out window.
 *
 * @param {string} [query] The page's query string.
 * @return {Promise<void>}
 */
async function openPopup(query = '') {
	window.history.replaceState({}, '', '/popup.html' + query)
	const html = readFileSync(
		resolve(__dirname, '../../browser-extension/src/popup/popup.html'),
		'utf8',
	)
	document.body.className = ''
	document.body.innerHTML = html
		.replace(/^[\s\S]*<body>/, '')
		.replace(/<\/body>[\s\S]*$/, '')
	globalThis.chrome.runtime.sendMessage = (msg, cb) => {
		const pending = router.handleMessage(msg, POPUP)
		if (pending) pending.then(cb)
	}
	window.close = vi.fn()
	vi.resetModules()
	await import('../../browser-extension/src/popup/popup.js')
	await vi.waitFor(() => expect($('view-unlocked').hidden).toBe(false))
}

beforeEach(async () => {
	vi.resetModules()
	browser = installChrome()
	const fixture = await makeVault('m', 'x')
	installServer({
		[SERVER]: { ...fixture, types: [{ id: 't1', name: 'login' }], folders: [] },
	})
	router = await import('../../browser-extension/src/background/router.js')
	await router.handleMessage(
		{ type: 'pair', payload: { url: SERVER, user: 'ann', appPassword: 'p' } },
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
	window.history.replaceState({}, '', '/')
})

describe('popup shell', () => {
	it('reopens on the last tab, and on This site after a lock', async () => {
		await openPopup()
		expect($('tab-site').getAttribute('aria-selected')).toBe('true')
		$('tab-generator').click()
		await vi.waitFor(() =>
			expect(browser.session.get('popup:lastTab')).toBe('generator'),
		)
		await openPopup()
		expect($('tab-generator').getAttribute('aria-selected')).toBe('true')
		expect($('panel-generator').hidden).toBe(false)

		await router.handleMessage({ type: 'lock', payload: {} }, POPUP)
		await vi.waitFor(() =>
			expect(browser.session.has('popup:lastTab')).toBe(false),
		)
		await router.handleMessage(
			{ type: 'unlock', payload: { masterPassword: 'm' } },
			POPUP,
		)
		await openPopup()
		expect($('tab-site').getAttribute('aria-selected')).toBe('true')
	})

	it('opens Settings from the tab bar', async () => {
		await openPopup()
		$('tab-settings').click()
		await vi.waitFor(() => expect($('view-settings').hidden).toBe(false))
	})

	it('says to open a website when the tab is not one', async () => {
		browser.setTab('chrome://newtab/')
		await openPopup()
		await vi.waitFor(() =>
			expect($('no-candidates').textContent).toBe(
				'Open a website to see its logins.',
			),
		)
		expect($('no-candidates').hidden).toBe(false)
		expect($('candidates').children).toHaveLength(0)
	})

	it('pops out into a window pinned to the current tab', async () => {
		await openPopup()
		expect($('popout-btn').hidden).toBe(false)
		$('popout-btn').click()
		await vi.waitFor(() => expect(browser.windows.create).toHaveBeenCalled())
		const opened = browser.windows.create.mock.calls[0][0]
		expect(opened).toMatchObject({ type: 'popup', width: 380 })
		expect(opened.url).toMatch(/popup\.html\?popout=1&tabId=1$/)
		expect(window.close).toHaveBeenCalled()
	})

	it('fills the pinned tab when popped out, not the active one', async () => {
		browser.otherTabs.set(7, { id: 7, url: 'https://example.com/login' })
		browser.setTab('https://elsewhere.test/', 2)
		await openPopup('?popout=1&tabId=7')
		expect($('popout-btn').hidden).toBe(true)
		expect(document.body.classList.contains('popped-out')).toBe(true)
		await vi.waitFor(() =>
			expect($('active-host').textContent).toBe('example.com'),
		)
		await vi.waitFor(() =>
			expect($('candidates').querySelector('button')).toBeTruthy(),
		)
		$('candidates').querySelector('button').click()
		await vi.waitFor(() => expect(browser.tabs.sendMessage).toHaveBeenCalled())
		expect(browser.tabs.sendMessage.mock.calls[0][0]).toBe(7)
	})
})

describe('router', () => {
	it('answers the popped-out window, as the top frame of its own tab only', async () => {
		const window = {
			...POPUP,
			url: POPUP.url + '?popout=1&tabId=7',
			tab: { id: 9, url: POPUP.url + '?popout=1&tabId=7' },
		}
		const ok = await router.handleMessage(
			{ type: 'get-state', payload: {} },
			{ ...window, frameId: 0 },
		)
		expect(ok.error).toBeUndefined()
		const framed = await router.handleMessage(
			{ type: 'get-state', payload: {} },
			{ ...window, frameId: 3 },
		)
		expect(framed.error).toBe('not allowed from a web page')
	})

	it('refuses to fill a pinned tab that moved to another site', async () => {
		const [offered] = await router.handleMessage(
			{ type: 'match', payload: { host: 'example.com' } },
			POPUP,
		)
		browser.otherTabs.set(7, { id: 7, url: 'https://evil.test/' })
		const res = await router.handleMessage(
			{
				type: 'fill',
				payload: { id: offered.id, accountId: offered.accountId, tabId: 7 },
			},
			POPUP,
		)
		expect(res.error ?? res.message ?? String(res)).toMatch(/page changed/)
		expect(browser.tabs.sendMessage).not.toHaveBeenCalled()
	})
})
