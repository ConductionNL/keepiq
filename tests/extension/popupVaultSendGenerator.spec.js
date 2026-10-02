/**
 * @spec openspec/changes/clients-extension-generator-vault-send/specs/extension-vault/spec.md
 * @spec openspec/changes/clients-extension-generator-vault-send/specs/extension-generator/spec.md
 * @spec openspec/changes/clients-extension-generator-vault-send/specs/extension-send/spec.md
 *
 * The REAL popup (popup.html + popup.js) against the worker's REAL router and
 * a real RSA vault: the Generator, Vault and Send tabs end to end. Every
 * request the worker makes is recorded, so the tests can show that no
 * plaintext value ever leaves the extension.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
	installChrome,
	installServer,
	makeVault,
	POPUP,
} from './fixtures/fakeBrowser.js'

const SERVER = 'https://one.example'

let router
let server
let fixture

/**
 * Load the popup page into the document, its messages going to the router.
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
	window.confirm = () => true
	await import('../../browser-extension/src/popup/popup.js')
	await vi.waitFor(() =>
		expect(document.getElementById('view-unlocked').hidden).toBe(false),
	)
}

/**
 * Element by id.
 *
 * @param {string} id The id.
 * @return {HTMLElement}
 */
const $ = (id) => document.getElementById(id)

/**
 * Every request body the worker sent, as one string.
 *
 * @return {string}
 */
const sentBodies = () => JSON.stringify(server.calls.map((c) => c.body ?? null))

beforeEach(async () => {
	vi.resetModules()
	installChrome()
	if (!fixture) fixture = await makeVault('m', 'x')
	server = installServer({
		[SERVER]: {
			...fixture,
			types: [{ id: 't1', name: 'login' }],
			folders: [{ id: 'f1', name: 'Work', parentId: null }],
			rows: fixture.rows.map((r) => ({ ...r, typeId: 't1', folderId: 'f1' })),
		},
	})
	router = await import('../../browser-extension/src/background/router.js')
	await router.handleMessage(
		{ type: 'pair', payload: { url: SERVER, user: 'alice', appPassword: 'p' } },
		POPUP,
	)
	await router.handleMessage(
		{ type: 'unlock', payload: { masterPassword: 'm' } },
		POPUP,
	)
})

describe('Generator tab', () => {
	it('makes a password and a passphrase in the popup', async () => {
		await openPopup()
		$('tab-generator').click()
		await vi.waitFor(() => expect($('gen-output').textContent).toHaveLength(20))
		expect($('panel-generator').hidden).toBe(false)

		const passphrase = document.querySelector(
			'input[name="gen-mode"][value="passphrase"]',
		)
		passphrase.checked = true
		passphrase.dispatchEvent(new Event('change'))
		// Five words joined by hyphens; four list words carry a hyphen
		// themselves, so the shape is checked instead of a split count.
		expect($('gen-output').textContent).toMatch(/^[a-z]+(-[a-z]+){4,}$/)

		expect(server.calls.some((c) => c.url.includes('generate-key'))).toBe(false)
	})
})

describe('Vault tab', () => {
	it('browses, reveals, edits and creates items, sending only ciphertext', async () => {
		await openPopup()
		$('tab-vault').click()
		await vi.waitFor(() =>
			expect($('vault-list').textContent).toContain('Example'),
		)
		expect([...$('vault-folder').options].map((o) => o.textContent)).toEqual([
			'All folders',
			'Work',
		])

		$('vault-list').querySelector('button').click()
		await vi.waitFor(() => expect($('vault-detail').hidden).toBe(false))
		expect($('detail-login').textContent).toBe('x-user')
		expect($('detail-secret').textContent).toBe('••••••••')
		$('detail-reveal').click()
		expect($('detail-secret').textContent).toBe('x-password')

		// Edit: a new password, saved as ciphertext.
		$('detail-edit').click()
		expect($('edit-name').value).toBe('Example')
		$('edit-secret').value = 'Brand-new-Pa55word!'
		$('vault-edit').dispatchEvent(new Event('submit', { cancelable: true }))
		await vi.waitFor(() =>
			expect(
				server.calls.some(
					(c) =>
						c.method === 'PUT' && c.url.includes('/api/v1/secrets/x-s1'),
				),
			).toBe(true),
		)
		const put = server.calls.find((c) => c.method === 'PUT')
		expect(put.body).toMatchObject({ name: 'Example', folderId: 'f1' })
		expect(put.body.key).toBeTruthy()

		// New item with a generated password.
		await vi.waitFor(() => expect($('vault-detail').hidden).toBe(false))
		$('detail-back').click()
		$('vault-new').click()
		$('edit-name').value = 'New site'
		$('edit-login').value = 'bob'
		$('edit-folder').value = 'f1'
		$('edit-generate').click()
		const generated = $('edit-secret').value
		expect(generated).toHaveLength(20)
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
		expect(post.body).toMatchObject({ name: 'New site', folderId: 'f1' })

		expect(sentBodies()).not.toContain('Brand-new-Pa55word!')
		expect(sentBodies()).not.toContain(generated)
		expect(sentBodies()).not.toContain('"bob"')
	})

	it('moves an item to the trash after confirmation', async () => {
		await openPopup()
		$('tab-vault').click()
		await vi.waitFor(() =>
			expect($('vault-list').textContent).toContain('Example'),
		)
		$('vault-list').querySelector('button').click()
		await vi.waitFor(() => expect($('vault-detail').hidden).toBe(false))
		$('detail-delete').click()
		await vi.waitFor(() =>
			expect(
				server.calls.some(
					(c) =>
						c.method === 'DELETE'
						&& c.url.includes('/api/v1/secrets/x-s1'),
				),
			).toBe(true),
		)
	})
})

describe('Send tab', () => {
	it("sends an item's login as a credential, and shows the link once", async () => {
		await openPopup()
		$('tab-vault').click()
		await vi.waitFor(() =>
			expect($('vault-list').textContent).toContain('Example'),
		)
		$('vault-list').querySelector('button').click()
		await vi.waitFor(() => expect($('vault-detail').hidden).toBe(false))

		$('detail-send').click()
		await vi.waitFor(() => expect($('panel-send').hidden).toBe(false))
		expect($('send-username').value).toBe('x-user')
		expect($('send-credential').hidden).toBe(false)

		$('send-expiry').value = '3d'
		$('send-form').dispatchEvent(new Event('submit', { cancelable: true }))
		await vi.waitFor(() => expect($('send-result').hidden).toBe(false))
		expect($('send-link').value).toMatch(
			/^https:\/\/one\.example\/index\.php\/apps\/keepiq\/public\/send\/tok-1#k=/,
		)

		const create = server.calls.find(
			(c) => c.method === 'POST' && c.url.endsWith('/api/v1/sends'),
		)
		expect(create.body).toMatchObject({
			payloadType: 'credential',
			maxViews: 1,
			ttlSeconds: 259200,
			hasPassword: false,
		})
		expect(sentBodies()).not.toContain('x-password')
		expect(sentBodies()).not.toContain('#k=')
	})
})
