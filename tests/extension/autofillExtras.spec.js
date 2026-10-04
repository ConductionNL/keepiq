/**
 * @spec openspec/changes/clients-extension-gaps/specs/extension-autofill-extras/spec.md
 *
 * Autofill extras: fields in shadow roots and by label, fill from the context
 * menu and a shortcut, never offer to save on a site, save into a folder, and
 * say when nothing was filled. On the REAL router and popup.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { showSavePrompt } from '../../browser-extension/src/content/save-prompt.js'
import { findLoginFields } from '../../browser-extension/src/lib/field-detect.js'
import {
	installChrome,
	installServer,
	makeVault,
	pageSender,
	POPUP,
} from './fixtures/fakeBrowser.js'

/**
 * Give every input a size, as jsdom lays nothing out.
 *
 * @param {Document|ShadowRoot} root Where the inputs are.
 */
function sized(root) {
	for (const input of root.querySelectorAll('input')) {
		input.getBoundingClientRect = () => ({ width: 100, height: 20 })
	}
}

describe('finding fields', () => {
	afterEach(() => {
		document.body.innerHTML = ''
	})

	it('finds the fields inside an open shadow root', () => {
		const host = document.createElement('div')
		document.body.appendChild(host)
		const shadow = host.attachShadow({ mode: 'open' })
		shadow.innerHTML =
			'<input type="email" id="mail"><input type="password" id="pw">'
		sized(shadow)
		const { username, password } = findLoginFields(document)
		expect(username?.id).toBe('mail')
		expect(password?.id).toBe('pw')
	})

	it('skips a field under aria-hidden and a hidden one, and knows a username by its label', () => {
		document.body.innerHTML = `
			<div aria-hidden="true"><input type="email" id="decoy"></div>
			<input type="hidden" name="user" id="hidden">
			<label for="who">E-mailadres</label><input type="text" id="who">
			<input type="text" id="other" placeholder="Search">
			<input type="password" id="pw">`
		sized(document)
		const { username, password } = findLoginFields(document)
		expect(username?.id).toBe('who')
		expect(password?.id).toBe('pw')
	})

	it('offers Never for this site on a new login only, and answers never', async () => {
		const seams = { mode: 'open', isUserEvent: () => true }
		const choice = showSavePrompt(
			{ action: 'save', name: 'x' },
			'example.com',
			document,
			seams,
		)
		const buttons = [
			...document.body.lastElementChild.shadowRoot.querySelectorAll('button'),
		]
		buttons.find((b) => b.textContent === 'Never for this site').click()
		expect(await choice).toBe('never')
		showSavePrompt(
			{ action: 'update', name: 'x' },
			'example.com',
			document,
			seams,
		)
		const update = document.body.lastElementChild
		expect(
			[...update.shadowRoot.querySelectorAll('button')].map(
				(b) => b.textContent,
			),
		).not.toContain('Never for this site')
		update.remove()
	})
})

const SERVER = 'https://one.example'
let router
let browser
let server
let state
let menuClicks
let commands

/**
 * Send a message to the router as the popup, or as a page.
 *
 * @param {string} type The type.
 * @param {object} [payload] The payload.
 * @param {object} [sender] The sender.
 * @return {Promise<object>}
 */
function send(type, payload = {}, sender = POPUP) {
	return router.handleMessage({ type, payload }, sender)
}

beforeEach(async () => {
	vi.resetModules()
	browser = installChrome({ tabUrl: 'https://example.com/login' })
	menuClicks = []
	commands = []
	Object.assign(globalThis.chrome, {
		action: { openPopup: vi.fn(async () => {}) },
		contextMenus: {
			create: vi.fn(),
			removeAll: vi.fn((cb) => cb()),
			onClicked: { addListener: (fn) => menuClicks.push(fn) },
		},
		commands: {
			onCommand: { addListener: (fn) => commands.push(fn) },
			getAll: vi.fn(async () => [
				{ name: 'fill-login', shortcut: 'Ctrl+Shift+L' },
			]),
		},
	})
	globalThis.chrome.runtime.onInstalled = {
		addListener: (fn) => {
			globalThis.chrome.runtime.installed = fn
		},
	}
	const fixture = await makeVault('m', 'x')
	state = {
		...fixture,
		types: [{ id: 't1', name: 'login' }],
		folders: [{ id: 'f1', name: 'Work', parentId: null }],
	}
	server = installServer({ [SERVER]: state })
	router = await import('../../browser-extension/src/background/router.js')
	await send('pair', { url: SERVER, user: 'ann', appPassword: 'p' })
})

afterEach(async () => {
	await new Promise((r) => setTimeout(r, 300))
	document.body.innerHTML = ''
})

describe('context menu and shortcut', () => {
	it('adds the menu item on install', () => {
		globalThis.chrome.runtime.installed()
		expect(globalThis.chrome.contextMenus.create).toHaveBeenCalledWith({
			id: 'keepiq-fill',
			title: 'Fill a login with Keepiq',
			contexts: ['editable'],
		})
	})

	it('fills the only login for the site from the menu and from the shortcut', async () => {
		await send('unlock', { masterPassword: 'm' })
		const tab = { id: 1, url: 'https://example.com/login' }
		await menuClicks[0]({ menuItemId: 'keepiq-fill' }, tab)
		await vi.waitFor(() => expect(browser.filled).toHaveLength(1))
		await commands[0]('fill-login', tab)
		await vi.waitFor(() => expect(browser.filled).toHaveLength(2))
		expect(globalThis.chrome.action.openPopup).not.toHaveBeenCalled()
	})

	it('opens the popup instead when the vault is locked or the site has several logins', async () => {
		const tab = { id: 1, url: 'https://example.com/login' }
		expect(await router.fillFromShortcut(tab)).toEqual({
			filled: false,
			opened: true,
		})
		await send('unlock', { masterPassword: 'm' })
		state.rows = [
			...state.rows,
			{ ...state.rows[0], id: 'x-s3', name: 'Example 2' },
		]
		expect(await router.fillFromShortcut(tab)).toEqual({
			filled: false,
			opened: true,
		})
		expect(globalThis.chrome.action.openPopup).toHaveBeenCalledTimes(2)
		expect(browser.filled).toHaveLength(0)
	})
})

describe('saving', () => {
	beforeEach(async () => {
		await send('unlock', { masterPassword: 'm' })
	})

	/**
	 * Submit a login in tab 9 on new.example.
	 *
	 * @return {Promise<object>} The offer.
	 */
	function submit() {
		return send(
			'capture-credential',
			{ login: 'ann', secret: 'correct horse battery staple violin 7%Q' },
			pageSender('https://new.example/login'),
		)
	}

	it('never offers to save on a site the user excluded, until they take it off the list', async () => {
		expect((await submit()).action).toBe('save')
		await send(
			'capture-decision',
			{ choice: 'never' },
			pageSender('https://new.example/login'),
		)
		expect((await send('never-sites')).sites).toEqual(['new.example'])
		expect((await submit()).action).toBe('none')
		await send('never-remove', { host: 'new.example' })
		expect((await submit()).action).toBe('save')
	})

	it('saves a new login into the folder the user picked', async () => {
		browser.setTab('https://new.example/login', 9)
		await submit()
		await send('save-capture', { folderId: 'f1' })
		const post = server.calls.find(
			(c) => c.method === 'POST' && c.url.endsWith('/api/v1/secrets'),
		)
		expect(post.body.folderId).toBe('f1')
	})
})

describe('in the popup', () => {
	/** Open the real popup. */
	async function openPopup() {
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
		vi.resetModules()
		await import('../../browser-extension/src/popup/popup.js')
	}

	/**
	 * Element by id.
	 *
	 * @param {string} id The id.
	 * @return {HTMLElement}
	 */
	function $(id) {
		return document.getElementById(id)
	}

	it('says so when no form was filled, and stays open', async () => {
		await send('unlock', { masterPassword: 'm' })
		browser.tabs.sendMessage.mockImplementation(async () => ({ filled: false }))
		await openPopup()
		await vi.waitFor(() =>
			expect($('candidates').querySelector('button')).toBeTruthy(),
		)
		$('candidates').querySelector('button').click()
		await vi.waitFor(() =>
			expect($('unlock-error').textContent).toBe(
				'Keepiq found no login form on this page to fill.',
			),
		)
		expect(window.close).not.toHaveBeenCalled()
	})

	it('offers a folder and Never in the save prompt, and lists the excluded sites in Settings', async () => {
		await send('unlock', { masterPassword: 'm' })
		browser.setTab('https://new.example/login', 9)
		await send(
			'capture-credential',
			{ login: 'ann', secret: 'correct horse battery staple violin 7%Q' },
			pageSender('https://new.example/login'),
		)
		await openPopup()
		await vi.waitFor(() => expect($('save-prompt').hidden).toBe(false))
		await vi.waitFor(() =>
			expect([...$('save-folder').options].map((o) => o.textContent)).toEqual([
				'No folder',
				'Work',
			]),
		)
		$('save-never').click()
		await vi.waitFor(async () =>
			expect((await send('never-sites')).sites).toEqual(['new.example']),
		)
		$('tab-settings').click()
		await vi.waitFor(() =>
			expect($('never-list').textContent).toContain('new.example'),
		)
		expect($('shortcut-text').textContent).toContain('Ctrl+Shift+L')
		$('never-list').querySelector('button').click()
		await vi.waitFor(() => expect($('never-empty').hidden).toBe(false))
	})
})
