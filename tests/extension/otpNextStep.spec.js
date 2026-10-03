/**
 * @spec openspec/changes/clients-extension-store-release/specs/extension-totp-autofill/spec.md#requirement-one-time-code-fill-on-the-step-after-the-login
 *
 * The one-time code fills on the step after the login (keepiq#783): a login
 * fill leaves a one-shot intent in session storage, holding no seed and no
 * code, and a code field that shows up later is filled only for that tab,
 * that site, within five minutes, once, and while the vault is unlocked.
 * Driven through the worker's REAL router with real crypto.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { watchForOtpField } from '../../browser-extension/src/content/otp-watch.js'
import {
	importPublicKey,
	rsaEncrypt,
} from '../../browser-extension/src/crypto/index.js'
import { RSA4096_PUBLIC_KEY_SPKI_PEM } from '../vitest/fixtures/rsa-fixtures.js'
import {
	installChrome,
	installServer,
	makeVault,
	pageSender,
	POPUP,
} from './fixtures/fakeBrowser.js'

const SERVER = 'https://cloud.example'
const SEED = 'JBSWY3DPEHPK3PXP'
let base
let browser
let router
let accountId

const popup = (type, payload = {}) => router.handleMessage({ type, payload }, POPUP)
function detect(url, tabId = 1, frameId = 0) {
	return router.handleMessage(
		{ type: 'otp-field-detected', payload: {} },
		{ ...pageSender(url), tab: { id: tabId, url }, frameId },
	)
}
const otpFills = () => browser.filled.filter((m) => m.type === 'fill-otp')

beforeEach(async () => {
	vi.resetModules()
	browser = installChrome({ tabUrl: 'https://login.example.com/signin' })
	if (!base) {
		base = await makeVault('m', 'x')
		const pub = await importPublicKey(RSA4096_PUBLIC_KEY_SPKI_PEM)
		base.rows = [
			...base.rows.map((r) => ({ ...r, url: 'https://login.example.com' })),
			{
				id: 'totp-1',
				name: 'Example 2FA',
				url: 'https://login.example.com',
				typeId: 't-totp',
				key: await rsaEncrypt(SEED, pub),
			},
		]
	}
	installServer({ [SERVER]: { ...base, types: [{ id: 't-totp', name: 'totp' }] } })
	router = await import('../../browser-extension/src/background/router.js')
	await popup('pair', { url: SERVER, user: 'alice', appPassword: 'p' })
	await popup('unlock', { masterPassword: 'm' })
	accountId = (await popup('get-state')).activeAccountId
})

afterEach(() => {
	vi.restoreAllMocks()
})

async function fillLogin() {
	const candidates = await popup('match', { host: 'login.example.com' })
	const login = candidates.find((c) => c.id === 'x-s1')
	const res = await popup('fill', { id: login.id, accountId })
	expect(res.filled).toBe(true)
	return res
}

describe('after a login fill without a code field on the page', () => {
	it('keeps an intent with no seed and no code, in session storage only', async () => {
		const res = await fillLogin()
		const intents = browser.session.get('keepiq.otpIntents')
		expect(Object.keys(intents)).toEqual(['1'])
		expect(Object.keys(intents[1]).sort()).toEqual([
			'expiresAt',
			'site',
			'tabId',
			'totpSecretId',
		])
		expect(intents[1]).toMatchObject({
			tabId: 1,
			site: 'example.com',
			totpSecretId: 'totp-1',
		})
		const stored = JSON.stringify(intents)
		expect(stored).not.toContain(SEED)
		expect(stored).not.toContain(res.totpCode)
		// The intent stays out of persistent storage. The vault snapshot is
		// there by design (ciphertext and metadata, ADR-002) and lists every
		// row's id, so it is left out of this check; no seed or code may be
		// anywhere on disk.
		const persistent = [...browser.storage.entries()].filter(
			([key]) => !key.startsWith('vault-snapshot:'),
		)
		expect(JSON.stringify(persistent)).not.toContain('totp-1')
		expect(JSON.stringify([...browser.storage.values()])).not.toContain(SEED)
		expect(JSON.stringify([...browser.storage.values()])).not.toContain(
			res.totpCode,
		)
	})

	it('fills the code field on the next step once, in the reporting frame', async () => {
		await fillLogin()
		const first = await detect('https://login.example.com/2fa', 1, 3)
		expect(first.filled).toBe(false) // the fake page has no field to fill
		expect(otpFills()).toHaveLength(2) // the attempt at fill time and this one
		expect(otpFills()[1].options).toEqual({ frameId: 3 })
		expect(otpFills()[1].payload.code).toMatch(/^\d{6}$/)
		expect(browser.session.has('keepiq.otpIntents')).toBe(false)

		await detect('https://login.example.com/2fa-again', 1)
		expect(otpFills()).toHaveLength(2)
	})

	it.each([
		['another tab', () => detect('https://login.example.com/2fa', 2)],
		['another site', () => detect('https://attacker.example.net/2fa', 1)],
	])('refuses %s', async (_name, run) => {
		await fillLogin()
		await run()
		expect(otpFills()).toHaveLength(1)
	})

	it('refuses after five minutes', async () => {
		await fillLogin()
		const now = Date.now()
		vi.spyOn(Date, 'now').mockReturnValue(now + 5 * 60 * 1000)
		await detect('https://login.example.com/2fa', 1)
		expect(otpFills()).toHaveLength(1)
	})

	it('a lock deletes the intent and nothing fills', async () => {
		await fillLogin()
		await popup('lock')
		await vi.waitFor(() =>
			expect(browser.session.has('keepiq.otpIntents')).toBe(false),
		)
		await detect('https://login.example.com/2fa', 1)
		expect(otpFills()).toHaveLength(1)
	})

	it('keeps no intent when the code filled on the login page itself', async () => {
		browser.otpFieldOnPage = true
		await fillLogin()
		expect(browser.session.has('keepiq.otpIntents')).toBe(false)
	})
})

describe('the content script watcher', () => {
	afterEach(() => {
		document.body.innerHTML = ''
	})

	it('reports a code field that appears after load exactly once', async () => {
		document.body.innerHTML =
			'<form><input name="password" type="password"></form>'
		const report = vi.fn()
		const stop = watchForOtpField({
			doc: document,
			find: () =>
				document.querySelector('input[autocomplete="one-time-code"]'),
			report,
			throttleMs: 10,
		})
		expect(report).not.toHaveBeenCalled()

		setTimeout(() => {
			document.body.innerHTML = '<input autocomplete="one-time-code">'
		}, 20)
		await vi.waitFor(() => expect(report).toHaveBeenCalledTimes(1))
		document.body.appendChild(document.createElement('span'))
		await new Promise((r) => setTimeout(r, 50))
		expect(report).toHaveBeenCalledTimes(1)
		stop()
	})
})
