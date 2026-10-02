/**
 * @spec openspec/changes/crypto-new-device-approval/specs/new-device-approval/spec.md#requirement-pickup-is-one-time-and-unlocks-one-session
 *
 * "Approve from another device" in the extension (keepiq#787 task 3.1), with
 * the worker's REAL router, real crypto and a fake server. The test plays the
 * approving web app: it derives the raw unlock key from the master password
 * and seals it to the request's one-time key with the web app's own
 * `sealUnlockKey`. The worker must unlock from it without the master password
 * ever reaching the extension, and must refuse anything not sealed for its
 * own request.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
	decodeEnvelope,
	deriveUnlockKeyRaw,
	sealUnlockKey,
	verificationPhraseFromBase64,
} from '../../browser-extension/src/crypto/index.js'
import {
	installChrome,
	installServer,
	makeVault,
	pageSender,
	POPUP,
} from './fixtures/fakeBrowser.js'

const WORK = 'https://cloud.work.example'

let browser
let server
let serverState
let router
let fixture

/**
 * Message the worker as the popup.
 *
 * @param {string} type The message type.
 * @param {object} payload The payload.
 * @return {Promise<object>} The answer.
 */
function send(type, payload = {}) {
	return router.handleMessage({ type, payload }, POPUP)
}

/**
 * Approve the open request the way the web app does: master password to raw
 * unlock key, sealed to the request's public key, bound to an id.
 *
 * @param {string} [boundToId] The request id to bind the seal to.
 * @return {Promise<void>}
 */
async function approveOnOtherDevice(boundToId) {
	const request = serverState.deviceApproval
	const { salt } = decodeEnvelope(fixture.suite.privateKey)
	const raw = await deriveUnlockKeyRaw('work-master', salt)
	request.sealedUnlockKey = await sealUnlockKey(
		raw,
		request.publicKey,
		boundToId ?? request.id,
	)
	request.status = 'approved'
}

beforeEach(async () => {
	vi.resetModules()
	browser = installChrome()
	if (!fixture) fixture = await makeVault('work-master', 'work')
	serverState = { ...fixture }
	server = installServer({ [WORK]: serverState })
	router = await import('../../browser-extension/src/background/router.js')
	await send('pair', { url: WORK, user: 'alice', appPassword: 'a1' })
})

describe('approve from another device', () => {
	it('unlocks the worker from an approved request, without the master password', async () => {
		const state = await send('device-approval-state')
		expect(state).toEqual({ enabled: true, request: null })

		const { request } = await send('device-approval-start')
		const sent = serverState.deviceApproval
		expect(sent.clientKind).toBe('extension')
		expect(sent.deviceLabel).toMatch(/^Keepiq extension/)
		// Both screens show the same five words, derived from the one-time key.
		expect(request.phrase).toBe(
			await verificationPhraseFromBase64(sent.publicKey),
		)
		expect(request.phrase.split(' ')).toHaveLength(5)
		// The popup sees no key and no secret.
		expect(Object.keys(request).sort()).toEqual(
			['expiresAt', 'id', 'phrase', 'status'].sort(),
		)

		expect(await send('device-approval-poll')).toEqual({ status: 'pending' })
		expect((await send('get-state')).unlocked).toBe(false)

		await approveOnOtherDevice()
		expect(await send('device-approval-poll')).toEqual({ status: 'unlocked' })
		expect((await send('get-state')).unlocked).toBe(true)

		// The unlocked worker decrypts: a fill hands the page the stored login.
		const { activeAccountId } = await send('get-state')
		await send('match', { host: 'example.com' })
		const fill = await send('fill', {
			id: 'work-s1',
			accountId: activeAccountId,
		})
		expect(fill.filled).toBe(true)
		expect(browser.filled[0].payload).toMatchObject({
			login: 'work-user',
			secret: 'work-password',
		})

		// The pickup carried the request secret; nothing sent held the
		// master password, and the request is gone from the worker.
		const pickups = server.calls.filter((c) =>
			c.url.endsWith('/api/v1/device-approvals/' + sent.id),
		)
		expect(pickups.length).toBeGreaterThan(0)
		for (const call of pickups) {
			expect(call.headers['X-Keepiq-Request-Secret']).toBe(sent.secret)
		}
		expect(JSON.stringify(server.calls)).not.toContain('work-master')
		expect((await send('device-approval-state')).request).toBeNull()
	})

	it('refuses a sealed key bound to another request and stays locked', async () => {
		await send('device-approval-start')
		await approveOnOtherDevice('some-other-request')

		const res = await send('device-approval-poll')
		expect(res.error).toMatch(/could not be opened/)
		expect((await send('get-state')).unlocked).toBe(false)
		// The one-time key is dropped: nothing left to poll.
		expect(await send('device-approval-poll')).toEqual({ status: 'none' })
	})

	it('ends on a denial and drops the one-time key', async () => {
		await send('device-approval-start')
		serverState.deviceApproval.status = 'denied'

		expect(await send('device-approval-poll')).toEqual({ status: 'denied' })
		expect(await send('device-approval-poll')).toEqual({ status: 'none' })
		expect((await send('get-state')).unlocked).toBe(false)
	})

	it('reuses the open request when the popup opens again', async () => {
		const first = await send('device-approval-start')
		const second = await send('device-approval-start')
		expect(second.request.id).toBe(first.request.id)
		expect(serverState.deviceApprovalCount).toBe(1)
		expect((await send('device-approval-state')).request.id).toBe(
			first.request.id,
		)
	})

	it('cancel ends the request on the server and in the worker', async () => {
		await send('device-approval-start')
		expect(await send('device-approval-cancel')).toEqual({ ok: true })
		expect(serverState.deviceApproval.status).toBe('denied')
		expect((await send('device-approval-state')).request).toBeNull()
	})

	it('is not offered when the organisation turned it off', async () => {
		serverState.deviceApprovalEnabled = false
		expect((await send('device-approval-state')).enabled).toBe(false)
		const res = await send('device-approval-start')
		expect(res.error).toMatch(/turned off/)
		expect(
			server.calls.some(
				(c) =>
					c.method === 'POST'
					&& c.url.endsWith('/api/v1/device-approvals'),
			),
		).toBe(false)
	})

	it('cannot be started or polled from a web page', async () => {
		const page = pageSender('https://evil.example/')
		for (const type of ['device-approval-start', 'device-approval-poll']) {
			const res = await router.handleMessage({ type, payload: {} }, page)
			expect(res).toEqual({ error: 'not allowed from a web page' })
		}
	})
})

describe('the locked popup', () => {
	/**
	 * Load the real popup page, its messages going to the real router.
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
		await import('../../browser-extension/src/popup/popup.js')
		await vi.waitFor(() =>
			expect(document.getElementById('view-locked').hidden).toBe(false),
		)
	}

	it('offers approval from another device and shows the phrase while it waits', async () => {
		await openPopup()
		const button = document.getElementById('unlock-device')
		await vi.waitFor(() => expect(button.hidden).toBe(false))

		button.click()
		await vi.waitFor(() =>
			expect(document.getElementById('view-device-approval').hidden).toBe(
				false,
			),
		)
		expect(document.getElementById('device-phrase').textContent).toBe(
			await verificationPhraseFromBase64(serverState.deviceApproval.publicKey),
		)
		expect(document.getElementById('view-locked').hidden).toBe(true)
	})

	it('hides the option when the organisation turned it off', async () => {
		serverState.deviceApprovalEnabled = false
		await openPopup()
		await vi.waitFor(() =>
			expect(
				server.calls.some((c) =>
					c.url.endsWith('/api/v1/device-approvals/status'),
				),
			).toBe(true),
		)
		expect(document.getElementById('unlock-device').hidden).toBe(true)
	})
})
