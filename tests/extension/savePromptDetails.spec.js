/**
 * @spec openspec/changes/clients-extension-finish/specs/extension-save-prompt-details/spec.md
 *
 * Save prompt details on the REAL router and content script: one login to
 * update or none, an update that changes the password only, the offer after
 * a redirect on the same site, and a save that says how it went.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { showSaveResult } from '../../browser-extension/src/content/save-prompt.js'
import { classifyCapture } from '../../browser-extension/src/lib/capture.js'
import {
	installChrome,
	installServer,
	makeVault,
	pageSender,
	POPUP,
} from './fixtures/fakeBrowser.js'

describe('which login to update', () => {
	const rows = [
		{ id: 'a', name: 'Mail', url: 'https://mail.example' },
		{ id: 'b', name: 'Mail 2', url: 'https://mail.example' },
	]

	it('updates the one saved login with the username, and offers nothing when several match', async () => {
		const capture = { host: 'mail.example', login: 'ann', secret: 'new' }
		const one = await classifyCapture(capture, rows, async (row) =>
			row.id === 'a'
				? { login: 'ann', secret: 'old' }
				: { login: 'bob', secret: 'x' },
		)
		expect(one).toMatchObject({ action: 'update', id: 'a' })
		const two = await classifyCapture(capture, rows, async () => ({
			login: 'ann',
			secret: 'old',
		}))
		expect(two).toEqual({ action: 'none' })
	})
})

describe('the result in the page', () => {
	afterEach(() => {
		document.body.innerHTML = ''
	})

	it('says saved, updated, or why not', () => {
		expect(showSaveResult({ ok: true, saved: 'saved' }, document)).toBe(
			'Login saved to Keepiq.',
		)
		expect(showSaveResult({ ok: true, saved: 'updated' }, document)).toBe(
			'Password updated in Keepiq.',
		)
		expect(showSaveResult({ error: 'vault is locked' }, document)).toBe(
			'Keepiq could not save this login: vault is locked',
		)
		expect(document.querySelectorAll('#keepiq-save-prompt')).toHaveLength(1)
	})
})

const SERVER = 'https://one.example'
let router
let browser
let server
let state
const PASSWORD = 'correct horse battery staple violin 7%Q'

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
	browser = installChrome()
	const fixture = await makeVault('m', 'x')
	state = { ...fixture, types: [{ id: 't-login', name: 'login' }], folders: [] }
	server = installServer({ [SERVER]: state })
	router = await import('../../browser-extension/src/background/router.js')
	await send('pair', { url: SERVER, user: 'ann', appPassword: 'p' })
	await send('unlock', { masterPassword: 'm' })
})

afterEach(async () => {
	await new Promise((r) => setTimeout(r, 300))
	document.body.innerHTML = ''
})

/**
 * The POSTs and PUTs to the secrets API.
 *
 * @param {string} method POST or PUT.
 * @return {Array<object>}
 */
function writes(method) {
	return server.calls.filter(
		(c) => c.method === method && c.url.includes('/api/v1/secrets'),
	)
}

describe('saving', () => {
	it('updates the password only, after reading the login again', async () => {
		browser.setTab('https://example.com/login', 9)
		const offer = await send(
			'capture-credential',
			{ login: 'x-user', secret: PASSWORD },
			pageSender('https://example.com/login'),
		)
		expect(offer.action).toBe('update')
		const res = await send('save-capture', {})
		expect(res).toEqual({ ok: true, saved: 'updated' })
		const put = writes('PUT')[0]
		expect(Object.keys(put.body).sort()).toEqual(['encryptionSuiteId', 'key'])
		const reads = server.calls.filter(
			(c) =>
				c.method === 'GET'
				&& c.url.endsWith('/api/v1/secrets/' + put.url.split('/').pop()),
		)
		expect(reads.length).toBeGreaterThan(0)
	})

	it('saves a new login, with its type, when the one to update is gone', async () => {
		browser.setTab('https://example.com/login', 9)
		await send(
			'capture-credential',
			{ login: 'x-user', secret: PASSWORD },
			pageSender('https://example.com/login'),
		)
		state.rows = []
		const res = await send('save-capture', {})
		expect(res).toEqual({ ok: true, saved: 'saved' })
		expect(writes('PUT')).toHaveLength(0)
		expect(writes('POST')[0].body.typeId).toBe('t-login')
		// And syncs, so the vault shows it at once.
		const post = server.calls.findIndex((c) => c.method === 'POST')
		expect(
			server.calls
				.slice(post)
				.some((c) => c.url.endsWith('/api/v1/offline/manifest')),
		).toBe(true)
	})
})

describe('after a redirect', () => {
	beforeEach(async () => {
		await send(
			'capture-credential',
			{ login: 'new-user', secret: PASSWORD },
			pageSender('https://login.new.example/form'),
		)
	})

	it('offers again on the next page of the same site', async () => {
		const offer = await send(
			'capture-offer',
			{},
			{ ...pageSender('https://app.new.example/home'), frameId: 0 },
		)
		expect(offer.action).toBe('save')
	})

	it('drops the offer when the tab moves to another site, and gives frames none', async () => {
		expect(
			(
				await send(
					'capture-offer',
					{},
					{ ...pageSender('https://new.example/'), frameId: 3 },
				)
			).action,
		).toBe('none')
		expect(
			(
				await send(
					'capture-offer',
					{},
					{ ...pageSender('https://elsewhere.test/'), frameId: 0 },
				)
			).action,
		).toBe('none')
		expect(
			(
				await send(
					'capture-offer',
					{},
					{ ...pageSender('https://new.example/'), frameId: 0 },
				)
			).action,
		).toBe('none')
	})

	it('shows the bar again when the next page loads', async () => {
		vi.resetModules()
		document.body.innerHTML = '<p>Welcome</p>'
		globalThis.chrome = {
			runtime: {
				onMessage: { addListener: () => {} },
				sendMessage: vi.fn(async (msg) =>
					msg.type === 'capture-offer'
						? { action: 'save', name: 'new.example' }
						: null,
				),
				getURL: (p) => p,
			},
		}
		await import('../../browser-extension/src/content/content-script.js')
		await vi.waitFor(() =>
			expect(document.getElementById('keepiq-save-prompt')).toBeTruthy(),
		)
	})
})
