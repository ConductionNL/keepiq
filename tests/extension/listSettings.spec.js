/**
 * @spec openspec/changes/clients-extension-gaps/specs/extension-list-and-settings/spec.md
 *
 * The vault list says what it shows and offers Copy and Open on each card;
 * Settings holds the autofill offers, the type of a new item, the theme, the
 * web app and About. On the REAL router and popup.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
	byName,
	filterIndex,
	listState,
	NO_FOLDER,
} from '../../browser-extension/src/lib/vault-index.js'
import {
	installChrome,
	installServer,
	makeVault,
	pageSender,
	POPUP,
} from './fixtures/fakeBrowser.js'

describe('the index', () => {
	const entries = [
		{ id: 'b', name: 'Mail', url: '', folderId: 'f1', blocked: false },
		{ id: 'a', name: 'Mail', url: '', folderId: null, blocked: false },
	]

	it('filters items in no folder, and orders equal names by id', () => {
		expect(
			filterIndex(entries, { folderId: NO_FOLDER }).map((e) => e.id),
		).toEqual(['a'])
		expect([...entries].sort(byName).map((e) => e.id)).toEqual(['a', 'b'])
	})

	it('names the state of the list', () => {
		expect(listState(null, [])).toBe('loading')
		expect(listState([], [])).toBe('empty')
		expect(listState(entries, [])).toBe('no-match')
		expect(
			listState(
				entries.map((e) => ({ ...e, blocked: true })),
				entries,
			),
		).toBe('all-blocked')
		expect(listState(entries, entries)).toBe('items')
	})
})

const SERVER = 'https://one.example'
let router
let browser
let state
let writes

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

/**
 * Element by id.
 *
 * @param {string} id The id.
 * @return {HTMLElement}
 */
function $(id) {
	return document.getElementById(id)
}

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
	vi.resetModules()
	await import('../../browser-extension/src/popup/popup.js')
	await vi.waitFor(() => expect($('view-unlocked').hidden).toBe(false))
}

beforeEach(async () => {
	vi.resetModules()
	browser = installChrome()
	globalThis.chrome.tabs.create = vi.fn()
	globalThis.chrome.runtime.getManifest = () => ({ version: '1.2.3' })
	writes = []
	Object.defineProperty(navigator, 'clipboard', {
		configurable: true,
		value: { writeText: vi.fn(async (t) => writes.push(t)) },
	})
	const fixture = await makeVault('m', 'x')
	state = {
		...fixture,
		types: [
			{ id: 't1', name: 'login' },
			{ id: 't2', name: 'note' },
		],
		folders: [{ id: 'f1', name: 'Work', parentId: null }],
	}
	installServer({ [SERVER]: state })
	router = await import('../../browser-extension/src/background/router.js')
	await send('pair', { url: SERVER, user: 'ann', appPassword: 'p' })
	await send('unlock', { masterPassword: 'm' })
})

afterEach(async () => {
	await new Promise((r) => setTimeout(r, 300))
	document.body.innerHTML = ''
	delete document.documentElement.dataset.theme
})

describe('the vault list', () => {
	it('shows type and site on each card, copies the password and opens the site', async () => {
		await openPopup()
		$('tab-vault').click()
		await vi.waitFor(() =>
			expect($('vault-list').children.length).toBeGreaterThan(0),
		)
		const first = $('vault-list').firstElementChild
		expect(first.querySelector('.vault-card-meta').textContent).toBe(
			'login · example.com',
		)
		first.querySelector('button[aria-label^="Copy the password of"]').click()
		await vi.waitFor(() => expect(writes).toHaveLength(1))
		expect(browser.alarms.create).toHaveBeenCalledWith(
			'keepiq-clipboard',
			expect.any(Object),
		)
		first.querySelector('button[aria-label="Open example.com"]').click()
		expect(globalThis.chrome.tabs.create).toHaveBeenCalledWith({
			url: 'https://example.com',
		})
	})

	it('says when nothing matches and clears the filters', async () => {
		await openPopup()
		$('tab-vault').click()
		await vi.waitFor(() =>
			expect($('vault-list').children.length).toBeGreaterThan(0),
		)
		$('vault-search').value = 'zzz'
		$('vault-search').dispatchEvent(new Event('input'))
		expect($('vault-status').textContent).toBe('Nothing matches.')
		expect($('vault-clear').hidden).toBe(false)
		$('vault-clear').click()
		expect($('vault-list').children.length).toBeGreaterThan(0)
		expect($('vault-clear').hidden).toBe(true)
	})

	it('says the vault is empty', async () => {
		state.rows = []
		await send('vault-sync-now')
		await openPopup()
		$('tab-vault').click()
		await vi.waitFor(() =>
			expect($('vault-status').textContent).toBe(
				'Your vault is empty. Add an item with New.',
			),
		)
	})
})

describe('settings', () => {
	it('switches the save offer and password suggestions off', async () => {
		await send('set-extension-settings', {
			offerSave: false,
			suggestPasswords: false,
		})
		const offer = await send(
			'capture-credential',
			{ login: 'ann', secret: 'correct horse battery staple violin 7%Q' },
			pageSender('https://new.example/login'),
		)
		expect(offer.action).toBe('none')
		expect(
			await send('page-settings', {}, pageSender('https://new.example/')),
		).toEqual({ suggestPasswords: false })
		expect(
			await send('generate-for-field', {}, pageSender('https://new.example/')),
		).toEqual({ value: null })
	})

	it('picks the type of a new item, the theme, and shows About and the web app', async () => {
		await openPopup()
		$('tab-settings').click()
		await vi.waitFor(() =>
			expect(
				[...$('setting-default-type').options].map((o) => o.value),
			).toEqual(['login', 'note']),
		)
		expect($('about-text').textContent).toBe(
			'Keepiq extension 1.2.3, Keepiq 0.3.4-unstable.20261002180000 on your server.',
		)
		$('setting-default-type').value = 'note'
		$('setting-default-type').dispatchEvent(new Event('change'))
		$('setting-theme').value = 'dark'
		$('setting-theme').dispatchEvent(new Event('change'))
		await vi.waitFor(() =>
			expect(document.documentElement.dataset.theme).toBe('dark'),
		)
		$('settings-notices').click()
		expect(globalThis.chrome.tabs.create).toHaveBeenCalledWith({
			url: expect.stringMatching(/THIRD-PARTY-NOTICES\.txt$/),
		})
		$('settings-open-web').click()
		expect(globalThis.chrome.tabs.create).toHaveBeenLastCalledWith({
			url: expect.stringMatching(
				/^https:\/\/one\.example\/index\.php\/apps\/keepiq/,
			),
		})

		// Reopened: the theme applies at once, and New starts as a note.
		delete document.documentElement.dataset.theme
		await openPopup()
		await vi.waitFor(() =>
			expect(document.documentElement.dataset.theme).toBe('dark'),
		)
		$('tab-vault').click()
		await vi.waitFor(() =>
			expect($('vault-list').children.length).toBeGreaterThan(0),
		)
		$('vault-new').click()
		expect($('edit-type').selectedOptions[0].textContent).toBe('note')
	})
})
