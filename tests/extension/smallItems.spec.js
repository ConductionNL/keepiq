/**
 * @spec openspec/changes/clients-extension-finish/specs/extension-small-items/spec.md
 *
 * The small items: suggestions by last use, a new item with the site's
 * address, a checked authenticator secret, folder changes off while
 * offline, the list's scroll position, the last tab without session
 * storage, and delete on a blocked item. On the REAL router and popup.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
	draftFromItem,
	validateDraft,
} from '../../browser-extension/src/lib/item-form.js'
import { matchSecrets } from '../../browser-extension/src/lib/match.js'
import {
	installChrome,
	installServer,
	makeVault,
	POPUP,
} from './fixtures/fakeBrowser.js'

describe('rules', () => {
	it('puts the login used last first among equally good matches', () => {
		const rows = [
			{
				id: 'a',
				name: 'A',
				url: 'https://example.com',
				lastUsedAt: '2026-10-01T10:00:00Z',
			},
			{
				id: 'b',
				name: 'B',
				url: 'https://example.com',
				lastUsedAt: '2026-10-03T10:00:00Z',
			},
			{ id: 'c', name: 'C', url: 'https://example.com' },
		]
		expect(matchSecrets(rows, 'example.com').map((r) => r.id)).toEqual([
			'b',
			'a',
			'c',
		])
	})

	it('refuses an authenticator secret the code generator cannot read', () => {
		const draft = draftFromItem(null, 'totp')
		draft.name = 'Bank'
		draft.secret = 'not base32 !!'
		expect(validateDraft(draft).secret).toBe(
			'This is not a valid authenticator secret',
		)
		draft.secret = 'JBSWY3DPEHPK3PXP'
		expect(validateDraft(draft).secret).toBeUndefined()
	})
})

const SERVER = 'https://one.example'
let router
let browser
let state

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

/** Open the Vault tab and wait for the list. */
async function openVault() {
	$('tab-vault').click()
	await vi.waitFor(() =>
		expect($('vault-list').children.length).toBeGreaterThan(0),
	)
}

beforeEach(async () => {
	vi.resetModules()
	browser = installChrome({ tabUrl: 'https://example.com/login?next=/home' })
	const fixture = await makeVault('m', 'x')
	state = {
		...fixture,
		types: [{ id: 't1', name: 'login' }],
		folders: [{ id: 'f1', name: 'Work', parentId: null }],
	}
	installServer({ [SERVER]: state })
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
})

describe('in the popup', () => {
	it('starts a new item with the address of the site, and lets it go without asking', async () => {
		await openPopup()
		await openVault()
		$('vault-new').click()
		expect($('edit-url').value).toBe('https://example.com')
		window.confirm = vi.fn(() => true)
		$('edit-cancel').click()
		expect(window.confirm).not.toHaveBeenCalled()
	})

	it('keeps the place in the list when going back from an item', async () => {
		await openPopup()
		await openVault()
		document.body.scrollTop = 120
		$('vault-list').querySelector('.candidate-fill').click()
		await vi.waitFor(() => expect($('vault-detail').hidden).toBe(false))
		document.body.scrollTop = 0
		$('detail-back').click()
		expect(document.body.scrollTop).toBe(120)
	})

	it('switches folder changes off while offline', async () => {
		await openPopup()
		await openVault()
		globalThis.fetch = vi.fn(async () => {
			throw new TypeError('Failed to fetch')
		})
		$('vault-sync-now').click()
		await vi.waitFor(() => expect($('vault-offline').hidden).toBe(false))
		$('vault-folders-open').click()
		await vi.waitFor(() =>
			expect($('folder-tree').children.length).toBeGreaterThan(0),
		)
		expect(
			[...$('folder-tree').querySelectorAll('button')].every(
				(b) => b.disabled,
			),
		).toBe(true)
		expect($('folder-add-save').disabled).toBe(true)
		expect($('folders-error').textContent).toBe(
			'You are offline. Changes need a connection to Keepiq.',
		)
	})

	it('remembers the last tab in local storage where the browser has no session storage', async () => {
		delete globalThis.chrome.storage.session
		await openPopup()
		$('tab-generator').click()
		await vi.waitFor(() =>
			expect(browser.storage.get('popup:lastTab')).toBe('generator'),
		)
		await openPopup()
		expect($('tab-generator').getAttribute('aria-selected')).toBe('true')
	})

	it('lets a blocked item go to the trash', async () => {
		state.rows = [
			...state.rows,
			{
				id: 'b1',
				name: 'Old',
				url: '',
				typeId: 't1',
				blocked: true,
				blockedReason: 'Revoked key',
			},
		]
		await router.handleMessage({ type: 'vault-sync-now', payload: {} }, POPUP)
		await openPopup()
		await openVault()
		;[...$('vault-list').querySelectorAll('.candidate-fill')]
			.find((b) => b.textContent.startsWith('Old'))
			.click()
		await vi.waitFor(() => expect($('detail-blocked').hidden).toBe(false))
		expect($('detail-edit').disabled).toBe(true)
		expect($('detail-delete').disabled).toBe(false)
	})
})
