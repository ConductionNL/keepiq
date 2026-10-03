/**
 * @spec openspec/changes/clients-extension-complete/specs/extension-vault/spec.md#requirement-detail-sections-for-every-kind-of-item
 * @spec openspec/changes/clients-extension-complete/specs/extension-vault/spec.md#requirement-edit-every-kind-of-item
 * @spec openspec/changes/clients-extension-complete/specs/extension-vault/spec.md#requirement-clone-and-move
 *
 * The REAL popup and worker router with a real RSA vault: detail sections for
 * logins with extra fields and notes, authenticator codes, cards and blocked
 * items; clone, move, the unsaved-changes guard and creating a note.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
	importPublicKey,
	rsaEncrypt,
} from '../../browser-extension/src/crypto/index.js'
import { RSA4096_PUBLIC_KEY_SPKI_PEM } from '../vitest/fixtures/rsa-fixtures.js'
import {
	installChrome,
	installServer,
	makeVault,
	POPUP,
} from './fixtures/fakeBrowser.js'

const SERVER = 'https://one.example'
let router
let server
let rows

/**
 * Element by id.
 *
 * @param {string} id The id.
 * @return {HTMLElement}
 */
const $ = (id) => document.getElementById(id)

/**
 * Load the popup page, its messages going to the router.
 *
 * @return {Promise<void>}
 */
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
	globalThis.chrome.tabs.create = vi.fn()
	vi.resetModules()
	await import('../../browser-extension/src/popup/popup.js')
	await vi.waitFor(() => expect($('view-unlocked').hidden).toBe(false))
	$('tab-vault').click()
	await vi.waitFor(() =>
		expect($('vault-list').children.length).toBeGreaterThan(0),
	)
}

/**
 * Open the item with this name from the list.
 *
 * @param {string} name The item's name.
 */
async function openByName(name) {
	const button = [...$('vault-list').querySelectorAll('button')].find((b) =>
		b.textContent.startsWith(name),
	)
	button.click()
	await vi.waitFor(() => expect($('vault-detail').hidden).toBe(false))
	await vi.waitFor(() => expect($('detail-name').textContent).toBe(name))
}

beforeEach(async () => {
	vi.resetModules()
	installChrome()
	const fixture = await makeVault('m', 'x')
	const publicKey = await importPublicKey(RSA4096_PUBLIC_KEY_SPKI_PEM)
	const enc = (v) => rsaEncrypt(v, publicKey)
	rows = [
		{
			id: 'l1',
			name: 'Mail',
			url: 'https://mail.example',
			typeId: 't1',
			folderId: 'f1',
			login: await enc('ann'),
			key: await enc('mail-password'),
			additionalFields: await enc(
				'{"pin":"1234","notes":"Remember the gate"}',
			),
			createdAt: '2026-10-01T10:00:00+00:00',
			updatedAt: '2026-10-02T10:00:00+00:00',
		},
		{
			id: 'o1',
			name: 'Bank code',
			url: '',
			typeId: 't2',
			folderId: null,
			login: await enc(''),
			key: await enc('JBSWY3DPEHPK3PXP'),
		},
		{
			id: 'c1',
			name: 'Visa',
			url: '',
			typeId: 't3',
			folderId: null,
			login: await enc(''),
			key: await enc(
				'{"number":"4111111111111111","expiry":"12/30","cvv":"123","pin":"","cardholder":"Ann"}',
			),
		},
		{
			id: 'b1',
			name: 'Old',
			url: '',
			typeId: 't1',
			folderId: null,
			blocked: true,
			blockedReason: 'Encrypted for a revoked key',
		},
	]
	server = installServer({
		[SERVER]: {
			...fixture,
			rows,
			types: [
				{ id: 't1', name: 'login' },
				{ id: 't2', name: 'totp' },
				{ id: 't3', name: 'card' },
				{ id: 't4', name: 'note' },
				{ id: 't5', name: 'passkey' },
			],
			folders: [
				{ id: 'f1', name: 'Work', parentId: null },
				{ id: 'f2', name: 'Clients', parentId: 'f1' },
			],
		},
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
})

describe('detail sections', () => {
	it('shows a login with its folder path, extra fields masked, notes and dates', async () => {
		await openPopup()
		await openByName('Mail')
		expect($('detail-meta').textContent).toBe('login · Work')
		expect($('detail-login').textContent).toBe('ann')
		const sections = $('detail-sections').textContent
		expect(sections).toContain('Additional fields')
		expect(sections).toContain('pin')
		expect(sections).not.toContain('1234')
		expect($('detail-notes').textContent).toBe('Remember the gate')
		expect(sections).toContain('Created')
		const reveal = [...$('detail-sections').querySelectorAll('button')].find(
			(b) => b.getAttribute('aria-label') === 'Show pin',
		)
		reveal.click()
		expect($('detail-sections').textContent).toContain('1234')
	})

	it('shows an authenticator code with a countdown', async () => {
		await openPopup()
		await openByName('Bank code')
		await vi.waitFor(() =>
			expect($('detail-totp-code').textContent).toMatch(/^\d{3} \d{3}$/),
		)
		expect($('detail-totp-count').textContent).toMatch(/^\d+s$/)
	})

	it('shows a card with brand and last four, its number masked', async () => {
		await openPopup()
		await openByName('Visa')
		const text = $('detail-sections').textContent
		expect(text).toContain('ending in 1111')
		expect(text).toContain('Ann')
		expect(text).not.toContain('4111111111111111')
	})

	it('shows why a blocked item cannot open, without decrypting or editing', async () => {
		await openPopup()
		await openByName('Old')
		expect($('detail-blocked').hidden).toBe(false)
		expect($('detail-blocked-reason').textContent).toBe(
			'Encrypted for a revoked key',
		)
		expect($('detail-sections').children).toHaveLength(0)
		expect($('detail-edit').disabled).toBe(true)
	})
})

describe('changing items', () => {
	it('clones an item as a new one, leaving the original alone', async () => {
		await openPopup()
		await openByName('Mail')
		$('detail-clone').click()
		await vi.waitFor(() => expect($('edit-name').value).toBe('Mail - Clone'))
		expect($('edit-login').value).toBe('ann')
		$('vault-edit').dispatchEvent(new Event('submit', { cancelable: true }))
		await vi.waitFor(() =>
			expect(
				server.calls.some(
					(c) => c.method === 'POST' && c.url.endsWith('/api/v1/secrets'),
				),
			).toBe(true),
		)
		const post = server.calls.find(
			(c) => c.method === 'POST' && c.url.endsWith('/api/v1/secrets'),
		)
		expect(post.body).toMatchObject({
			name: 'Mail - Clone',
			folderId: 'f1',
			typeId: 't1',
		})
		expect(post.body.additionalFields).toBeTruthy()
		expect(JSON.stringify(post.body)).not.toContain('mail-password')
		expect(server.calls.some((c) => c.method === 'PUT')).toBe(false)
	})

	it('moves an item by changing only its folder', async () => {
		await openPopup()
		await openByName('Mail')
		$('detail-move').click()
		$('detail-move-folder').value = 'f2'
		$('detail-move-save').click()
		await vi.waitFor(() =>
			expect(server.calls.some((c) => c.method === 'PUT')).toBe(true),
		)
		expect(server.calls.find((c) => c.method === 'PUT').body).toEqual({
			folderId: 'f2',
		})
	})

	it('asks before leaving a form with changes', async () => {
		await openPopup()
		await openByName('Mail')
		$('detail-edit').click()
		await vi.waitFor(() => expect($('edit-name').value).toBe('Mail'))
		$('edit-name').value = 'Mail (work)'
		window.confirm = vi.fn(() => false)
		$('tab-send').click()
		expect(window.confirm).toHaveBeenCalledWith(
			'You have unsaved changes. Discard them?',
		)
		expect($('panel-vault').hidden).toBe(false)
		expect($('edit-name').value).toBe('Mail (work)')
		window.confirm = vi.fn(() => true)
		$('tab-send').click()
		await vi.waitFor(() => expect($('panel-send').hidden).toBe(false))
	})

	it('creates a note whose text is stored encrypted in the key', async () => {
		await openPopup()
		$('vault-new').click()
		$('edit-type').value = 't4'
		$('edit-type').dispatchEvent(new Event('change'))
		expect($('edit-login-block').hidden).toBe(true)
		expect($('edit-secret-block').hidden).toBe(true)
		$('edit-name').value = 'Wifi'
		$('edit-notes').value = 'The guest network is called Lobby'
		$('vault-edit').dispatchEvent(new Event('submit', { cancelable: true }))
		await vi.waitFor(() =>
			expect(
				server.calls.some(
					(c) => c.method === 'POST' && c.url.endsWith('/api/v1/secrets'),
				),
			).toBe(true),
		)
		const post = server.calls.find(
			(c) => c.method === 'POST' && c.url.endsWith('/api/v1/secrets'),
		)
		expect(post.body).toMatchObject({ name: 'Wifi', typeId: 't4' })
		expect(post.body.key).toBeTruthy()
		expect(JSON.stringify(post.body)).not.toContain('Lobby')
	})
})

describe('passkeys', () => {
	const PRIVATE_KEY = 'MIGHAgEAMBMGByqGSM49AgEGCCqGSM49AwEHBG0wawIBAQQgPRIVATEKEY'

	/** Add a passkey row to the fake server and sync it into the snapshot. */
	async function addPasskey() {
		const publicKey = await importPublicKey(RSA4096_PUBLIC_KEY_SPKI_PEM)
		rows.push({
			id: 'p1',
			name: 'GitHub passkey',
			url: 'github.com',
			typeId: 't5',
			folderId: null,
			login: await rsaEncrypt('', publicKey),
			key: await rsaEncrypt(
				JSON.stringify({
					credentialId: 'cred-1',
					rpId: 'github.com',
					rpName: 'GitHub',
					userName: 'ann',
					userHandle: 'aGFuZGxl',
					privateKey: PRIVATE_KEY,
					algorithm: -7,
					counter: 3,
					createdAt: '2026-09-01T10:00:00.000Z',
				}),
				publicKey,
			),
		})
		await router.handleMessage({ type: 'vault-sync-now', payload: {} }, POPUP)
	}

	it('keeps the private key in the worker: the popup gets site and account only', async () => {
		await addPasskey()
		const item = await router.handleMessage(
			{ type: 'vault-item', payload: { id: 'p1' } },
			POPUP,
		)
		expect(item.passkey).toMatchObject({ rpId: 'github.com', userName: 'ann' })
		expect(item.secret).toBe('')
		expect(JSON.stringify(item)).not.toContain(PRIVATE_KEY)
	})

	it('shows a passkey without clone or Send, and its edit form has no key field', async () => {
		await addPasskey()
		await openPopup()
		await openByName('GitHub passkey')
		expect($('detail-sections').textContent).toContain('GitHub (github.com)')
		expect($('detail-clone').hidden).toBe(true)
		expect($('detail-send').hidden).toBe(true)
		$('detail-edit').click()
		await vi.waitFor(() => expect($('vault-edit').hidden).toBe(false))
		expect($('edit-secret-block').hidden).toBe(true)
		expect(document.body.innerHTML).not.toContain(PRIVATE_KEY)
		expect($('edit-secret').value).toBe('')
	})

	it('refuses to create a passkey or change its key, and offers no passkey type for a new item', async () => {
		await addPasskey()
		const create = await router.handleMessage(
			{
				type: 'vault-save',
				payload: {
					typeId: 't5',
					typeName: 'passkey',
					changes: { name: 'X' },
				},
			},
			POPUP,
		)
		expect(create.error).toMatch(/created by the website/)
		const rekey = await router.handleMessage(
			{
				type: 'vault-save',
				payload: {
					id: 'p1',
					typeId: 't5',
					typeName: 'passkey',
					changes: { key: '{}' },
				},
			},
			POPUP,
		)
		expect(rekey.error).toMatch(/key cannot be edited/)
		await openPopup()
		$('vault-new').click()
		await vi.waitFor(() => expect($('vault-edit').hidden).toBe(false))
		expect([...$('edit-type').options].map((o) => o.textContent)).not.toContain(
			'passkey',
		)
	})
})
