/**
 * @spec openspec/specs/extension-send-details/spec.md
 *
 * Send details on the REAL router and popup: no send while offline, a
 * progress note during Argon2id, Send for logins only, what each send is,
 * asking before ending one, and the server's own message.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { sendProblem } from '../../browser-extension/src/background/vault-handlers.js'
import { expiresIn } from '../../browser-extension/src/lib/send-form.js'
import {
	installChrome,
	installServer,
	makeVault,
	POPUP,
} from './fixtures/fakeBrowser.js'

describe('words', () => {
	it('says offline, the server message, or the error', () => {
		expect(sendProblem(new TypeError('Failed to fetch'))).toBe(
			'You are offline. A send needs a connection to Keepiq.',
		)
		expect(
			sendProblem({ status: 400, body: '{"message":"Too many sends today"}' }),
		).toBe('Too many sends today')
		expect(
			sendProblem({ status: 500, body: 'oops', message: 'failed (500)' }),
		).toBe('failed (500)')
	})

	it('says when a send expires', () => {
		const now = Date.parse('2026-10-04T10:00:00Z')
		expect(expiresIn('2026-10-04T10:30:00Z', now)).toBe('expires in 30 minutes')
		expect(expiresIn('2026-10-04T13:00:00Z', now)).toBe('expires in 3 hours')
		expect(expiresIn('2026-10-07T10:00:00Z', now)).toBe('expires in 3 days')
		expect(expiresIn('2026-10-04T09:00:00Z', now)).toBe('expired')
		expect(expiresIn(null, now)).toBe('')
	})
})

const SERVER = 'https://one.example'
let router
let state

/**
 * Send a message to the router as the popup.
 *
 * @param {string} type The type.
 * @param {object} [payload] The payload.
 * @return {Promise<object>}
 */
function send(type, payload = {}) {
	return router.handleMessage({ type, payload }, POPUP)
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

/** Open the real popup on the Send tab. */
async function openSend() {
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
	$('tab-send').click()
	await vi.waitFor(() => expect($('panel-send').hidden).toBe(false))
}

beforeEach(async () => {
	vi.resetModules()
	installChrome()
	const fixture = await makeVault('m', 'x')
	state = {
		...fixture,
		types: [
			{ id: 't1', name: 'login' },
			{ id: 't2', name: 'note' },
		],
		folders: [],
		sends: [
			{
				id: 's1',
				payloadType: 'text',
				createdAt: '2026-10-04T08:00:00+00:00',
				expiresAt: new Date(Date.now() + 3 * 3600000).toISOString(),
				viewCount: 1,
				maxViews: 2,
				hasPassword: true,
			},
		],
	}
	installServer({ [SERVER]: state })
	router = await import('../../browser-extension/src/background/router.js')
	await send('pair', { url: SERVER, user: 'ann', appPassword: 'p' })
	await send('unlock', { masterPassword: 'm' })
})

afterEach(async () => {
	vi.unstubAllGlobals()
	await new Promise((r) => setTimeout(r, 300))
	document.body.innerHTML = ''
})

describe('in the worker', () => {
	it("passes on the server's own message when a send is refused", async () => {
		state.sendError = { status: 400, body: { message: 'Too many sends today' } }
		const res = await send('send-create', {
			payloadType: 'text',
			text: 'hi',
			maxViews: 1,
			expiry: '1d',
		})
		expect(res.error).toBe('Too many sends today')
	})

	it('treats a send that is already gone as ended', async () => {
		state.sendGone = true
		expect(await send('send-revoke', { id: 's1' })).toEqual({
			ok: true,
			gone: true,
		})
	})

	it('refuses a password-protected send where Argon2id cannot run', async () => {
		vi.stubGlobal('WebAssembly', undefined)
		const res = await send('send-create', {
			payloadType: 'text',
			text: 'hi',
			maxViews: 1,
			expiry: '1d',
			sendPassword: 'secret',
		})
		expect(res.error).toBe('This browser cannot protect a send with a password.')
	})
})

describe('in the popup', () => {
	it('lists what each send is, and asks before ending one', async () => {
		await openSend()
		await vi.waitFor(() => expect($('send-list').children).toHaveLength(1))
		expect($('send-list').textContent).toMatch(
			/1 of 2 opened, expires in 3 hours, password/,
		)
		window.confirm = vi.fn(() => false)
		$('send-list').querySelector('button.danger').click()
		expect(window.confirm.mock.calls[0][0]).toMatch(/Its link stops working\.$/)
	})

	it('offers to try again when the list fails', async () => {
		const online = globalThis.fetch
		await openSend()
		await vi.waitFor(() => expect($('send-list').children).toHaveLength(1))
		globalThis.fetch = vi.fn(async () => {
			throw new TypeError('Failed to fetch')
		})
		$('tab-vault').click()
		$('tab-send').click()
		await vi.waitFor(() => expect($('send-retry').hidden).toBe(false))
		globalThis.fetch = online
		$('send-retry').click()
		await vi.waitFor(() => expect($('send-retry').hidden).toBe(true))
	})

	it('allows no send while offline', async () => {
		globalThis.fetch = vi.fn(async () => {
			throw new TypeError('Failed to fetch')
		})
		await send('vault-sync-now')
		await openSend()
		await vi.waitFor(() => expect($('send-offline').hidden).toBe(false))
		expect($('send-create').disabled).toBe(true)
	})

	it('offers Send on a login only', async () => {
		state.rows = [
			...state.rows,
			{ ...state.rows[0], id: 'n1', name: 'A note', typeId: 't2', url: '' },
		]
		await send('vault-sync-now')
		await openSend()
		$('tab-vault').click()
		await vi.waitFor(() =>
			expect($('vault-list').children.length).toBeGreaterThan(1),
		)
		const open = async (name) => {
			;[...$('vault-list').querySelectorAll('.candidate-fill')]
				.find((b) => b.textContent.startsWith(name))
				.click()
			await vi.waitFor(() => expect($('vault-detail').hidden).toBe(false))
		}
		await open('A note')
		expect($('detail-send').hidden).toBe(true)
		$('detail-back').click()
		await open('Example')
		expect($('detail-send').hidden).toBe(false)
	})
})
