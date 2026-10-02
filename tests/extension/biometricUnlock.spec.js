/**
 * @spec openspec/specs/extension-biometric-unlock/spec.md
 *
 * Fingerprint or face unlock in the extension (keepiq#784), with a fake
 * platform authenticator whose PRF output is a keyed hash of the salt, real
 * crypto, and the worker's REAL router. The server sees only the wrapped
 * unlock key, the PRF salt and the credential metadata; the raw unlock key
 * never lands in extension storage; a browser without PRF gets no enrolment.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
	decodeEnvelope,
	deriveKekFromPrf,
	deriveUnlockKeyRaw,
	unwrapUnlockKey,
	wrapUnlockKey,
} from '../../browser-extension/src/crypto/index.js'
import {
	BiometricUnavailable,
	enrolBiometric,
	platformAuthenticatorAvailable,
	unlockWithBiometric,
} from '../../browser-extension/src/unlock/ceremony.js'
import {
	EXTENSION_BASE,
	EXTENSION_ID,
	installChrome,
	installServer,
	makeVault,
	pageSender,
} from './fixtures/fakeBrowser.js'

const WORK = 'https://cloud.work.example'
const UNLOCK_WINDOW = Object.freeze({
	id: EXTENSION_ID,
	url: EXTENSION_BASE + 'unlock.html?mode=unlock',
})

let browser
let server
let serverState
let router
let vault
let fixture
let accountId

/**
 * Message the worker as the unlock window.
 *
 * @param {string} type The message type.
 * @param {object} payload The payload.
 * @return {Promise<object>} The answer.
 */
function send(type, payload = {}) {
	return router.handleMessage({ type, payload }, UNLOCK_WINDOW)
}

/**
 * A fake platform authenticator with PRF. Its PRF output is SHA-256 over a
 * device secret and the salt, so the same salt gives the same output.
 *
 * @param {{prf?: boolean}} options Whether the authenticator supports PRF.
 * @return {object} A navigator.credentials stand-in that records its calls.
 */
function fakeAuthenticator({ prf = true } = {}) {
	const rawId = new Uint8Array([
		1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16,
	]).buffer
	const deviceSecret = new TextEncoder().encode('device-secret')
	const prfOf = async (salt) => {
		const input = new Uint8Array(deviceSecret.length + salt.length)
		input.set(deviceSecret, 0)
		input.set(salt, deviceSecret.length)
		return new Uint8Array(await crypto.subtle.digest('SHA-256', input)).buffer
	}
	const calls = { create: [], get: [] }
	return {
		calls,
		create: vi.fn(async (options) => {
			calls.create.push(options)
			return {
				rawId,
				response: { getTransports: () => ['internal'] },
				getClientExtensionResults: () => ({ prf: { enabled: prf } }),
			}
		}),
		get: vi.fn(async (options) => {
			calls.get.push(options)
			const p = options.publicKey.extensions.prf
			const salt = p.eval
				? p.eval.first
				: Object.values(p.evalByCredential)[0].first
			const output = await prfOf(salt)
			return {
				rawId,
				getClientExtensionResults: () =>
					prf ? { prf: { results: { first: output } } } : {},
			}
		}),
	}
}

beforeEach(async () => {
	vi.resetModules()
	browser = installChrome()
	if (!fixture) fixture = await makeVault('work-master', 'work')
	serverState = { ...fixture }
	server = installServer({ [WORK]: serverState })
	vault = await import('../../browser-extension/src/lib/vault.js')
	router = await import('../../browser-extension/src/background/router.js')
	await send('pair', { url: WORK, user: 'alice', appPassword: 'a1' })
	accountId = (await send('get-state')).activeAccountId
})

describe('enrolment', () => {
	it('stores only the wrapped key, the PRF salt and metadata, with the extension as client', async () => {
		await send('unlock', { masterPassword: 'work-master' })
		const authenticator = fakeAuthenticator()

		await enrolBiometric({
			credentials: authenticator,
			send,
			accountId,
			masterPassword: 'work-master',
			label: 'Laptop',
		})

		const created = authenticator.calls.create[0].publicKey
		expect(created.rp.id).toBe(EXTENSION_ID)
		expect(created.authenticatorSelection).toMatchObject({
			authenticatorAttachment: 'platform',
			userVerification: 'required',
		})

		const posts = server.calls.filter(
			(c) => c.method === 'POST' && c.url.endsWith('/api/v1/passkeys'),
		)
		expect(posts).toHaveLength(1)
		const body = posts[0].body
		expect(Object.keys(body).sort()).toEqual(
			[
				'clientKind',
				'credentialId',
				'label',
				'prfSalt',
				'rpId',
				'transports',
				'wrappedUnlockKey',
			].sort(),
		)
		expect(body.clientKind).toBe('extension')
		expect(body.rpId).toBe(EXTENSION_ID)

		// Nothing secret in the request: not the master password, the raw
		// unlock key, nor the PRF output.
		const { salt } = decodeEnvelope(fixture.suite.privateKey)
		const raw = await deriveUnlockKeyRaw('work-master', salt)
		const rawB64 = btoa(String.fromCharCode(...raw))
		const sent = JSON.stringify(body)
		expect(sent).not.toContain('work-master')
		expect(sent).not.toContain(rawB64)
		expect(sent).not.toContain(JSON.stringify(Array.from(raw)))
	})

	it('refuses a wrong master password before any prompt', async () => {
		await send('unlock', { masterPassword: 'work-master' })
		const authenticator = fakeAuthenticator()
		await expect(
			enrolBiometric({
				credentials: authenticator,
				send,
				accountId,
				masterPassword: 'nope',
				label: '',
			}),
		).rejects.toThrow('not correct')
		expect(authenticator.create).not.toHaveBeenCalled()
	})

	it('enrols nothing when the authenticator has no PRF', async () => {
		await send('unlock', { masterPassword: 'work-master' })
		await expect(
			enrolBiometric({
				credentials: fakeAuthenticator({ prf: false }),
				send,
				accountId,
				masterPassword: 'work-master',
				label: '',
			}),
		).rejects.toBeInstanceOf(BiometricUnavailable)
		expect(
			server.calls.some(
				(c) => c.method === 'POST' && c.url.endsWith('/api/v1/passkeys'),
			),
		).toBe(false)
	})

	it('needs the account unlocked', async () => {
		const res = await send('biometric-enrol-context', { accountId })
		expect(res.error).toContain('locked')
	})
})

describe('unlock', () => {
	async function enrolled() {
		await send('unlock', { masterPassword: 'work-master' })
		const authenticator = fakeAuthenticator()
		await enrolBiometric({
			credentials: authenticator,
			send,
			accountId,
			masterPassword: 'work-master',
			label: 'Laptop',
		})
		const body = server.calls.find(
			(c) => c.method === 'POST' && c.url.endsWith('/api/v1/passkeys'),
		).body
		serverState.passkeyOptions = {
			challenge: 'Y2hhbGxlbmdl',
			credentials: [
				{
					id: 'pk1',
					credentialId: body.credentialId,
					prfSalt: body.prfSalt,
					wrappedUnlockKey: body.wrappedUnlockKey,
				},
			],
		}
		await send('lock')
		return authenticator
	}

	it('unlocks with the passkey, fills a login, and leaves no raw key in storage', async () => {
		const authenticator = await enrolled()
		expect(vault.isUnlocked(accountId)).toBe(false)

		await unlockWithBiometric({ credentials: authenticator, send, accountId })

		expect(vault.isUnlocked(accountId)).toBe(true)
		const optionsCall = server.calls.find((c) =>
			c.url.includes('/api/v1/passkeys/login-options'),
		)
		expect(optionsCall.url).toContain('client=extension')
		expect(optionsCall.url).toContain('rpId=' + EXTENSION_ID)
		expect(authenticator.calls.get.at(-1).publicKey.userVerification).toBe(
			'required',
		)

		const candidates = await router.handleMessage(
			{ type: 'match', payload: { host: 'example.com' } },
			{ id: EXTENSION_ID, url: EXTENSION_BASE + 'popup.html' },
		)
		const res = await router.handleMessage(
			{ type: 'fill', payload: { id: candidates[0].id, accountId } },
			{ id: EXTENSION_ID, url: EXTENSION_BASE + 'popup.html' },
		)
		expect(res.filled).toBe(true)
		expect(browser.filled[0].payload.secret).toBe('work-password')

		const { salt } = decodeEnvelope(fixture.suite.privateKey)
		const raw = await deriveUnlockKeyRaw('work-master', salt)
		const stored = JSON.stringify([...browser.storage.values()])
		expect(stored).not.toContain(btoa(String.fromCharCode(...raw)))
		expect(stored).not.toContain(Array.from(raw).join(','))
	})

	it('offers no unlock when no extension passkey is enrolled', async () => {
		await expect(
			unlockWithBiometric({
				credentials: fakeAuthenticator(),
				send,
				accountId,
			}),
		).rejects.toBeInstanceOf(BiometricUnavailable)
	})
})

describe('the raw-key unlock message', () => {
	it('round-trips: wrap a raw key, unwrap it, unlock the worker, decrypt a secret', async () => {
		const { salt } = decodeEnvelope(fixture.suite.privateKey)
		const raw = await deriveUnlockKeyRaw('work-master', salt)
		const prf = crypto.getRandomValues(new Uint8Array(32))
		const kek = await deriveKekFromPrf(prf.buffer, 'cred-1')
		const unwrapped = await unwrapUnlockKey(kek, await wrapUnlockKey(kek, raw))

		const res = await send('unlock-raw', {
			accountId,
			rawKey: Array.from(unwrapped),
		})

		expect(res).toEqual({ ok: true })
		expect(await vault.decryptField(accountId, fixture.rows[0].key)).toBe(
			'work-password',
		)
	})

	it('refuses a wrong key and stays locked', async () => {
		const res = await send('unlock-raw', {
			accountId,
			rawKey: Array.from(new Uint8Array(32)),
		})
		expect(res.error).toBeTruthy()
		expect(vault.isUnlocked(accountId)).toBe(false)
	})

	it('refuses a raw key sent from a web page', async () => {
		const { salt } = decodeEnvelope(fixture.suite.privateKey)
		const raw = await deriveUnlockKeyRaw('work-master', salt)
		const res = await router.handleMessage(
			{ type: 'unlock-raw', payload: { accountId, rawKey: Array.from(raw) } },
			pageSender('https://evil.example/'),
		)
		expect(res.error).toBe('not allowed from a web page')
		expect(vault.isUnlocked(accountId)).toBe(false)
	})
})

describe('feature detection', () => {
	it('offers biometric unlock only with a user-verifying platform authenticator', async () => {
		expect(await platformAuthenticatorAvailable({})).toBe(false)
		expect(
			await platformAuthenticatorAvailable({
				PublicKeyCredential: {
					isUserVerifyingPlatformAuthenticatorAvailable: async () => false,
				},
			}),
		).toBe(false)
		expect(
			await platformAuthenticatorAvailable({
				PublicKeyCredential: {
					isUserVerifyingPlatformAuthenticatorAvailable: async () => {
						throw new Error('not allowed')
					},
				},
			}),
		).toBe(false)
		expect(
			await platformAuthenticatorAvailable({
				PublicKeyCredential: {
					isUserVerifyingPlatformAuthenticatorAvailable: async () => true,
				},
			}),
		).toBe(true)
	})
})
