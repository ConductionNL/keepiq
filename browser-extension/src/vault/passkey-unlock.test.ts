import { describe, expect, it, vi } from 'vitest'
import { toBase64, toBase64Url } from '@/src/crypto/base64'
import { decodeEnvelope } from '@/src/crypto/envelope'
import { deriveUnlockKeyBits, InvalidMasterPassword } from '@/src/crypto/kdf'
import { envelope } from '@/src/testing/vectors'
// @ts-expect-error The web app's module is plain JavaScript without types.
import * as web from '../../../src/crypto/passkey.js'
import {
	creationOptions, deriveKek, enrolPasskey, PrfUnavailable, rawUnlockKey, unlockWithPasskey, unwrapUnlockKey, wrapUnlockKey,
	type CeremonyDeps, type EnrolmentBody, type EnrolOptions,
} from './passkey-unlock'

const b64url = (text: string) => toBase64Url(new TextEncoder().encode(text))
const enrolOptions: EnrolOptions = {
	challenge: b64url('create-challenge'), assertionChallenge: b64url('proof-challenge'), rpId: 'cloud.example.org', userHandle: b64url('random-handle'),
	privateKey: envelope.envelope,
}
const rawId = new TextEncoder().encode('credential-1').buffer
const prfFirst = new Uint8Array(32).fill(7).buffer
const bytesOf = (value: unknown) => new Uint8Array(value as ArrayBuffer)

/** A software platform authenticator: create() and get() answers with PRF, and every request it saw. */
function authenticator({ prfEnabled = true, prfResult = true }: { prfEnabled?: boolean; prfResult?: boolean } = {}) {
	const create = vi.fn(async () => ({
		rawId,
		response: { clientDataJSON: new Uint8Array([1]).buffer, attestationObject: new Uint8Array([2]).buffer, getTransports: () => ['internal'] },
		getClientExtensionResults: () => ({ prf: { enabled: prfEnabled } }),
	}))
	const get = vi.fn(async () => ({
		rawId,
		response: { clientDataJSON: new Uint8Array([3]).buffer, authenticatorData: new Uint8Array([4]).buffer, signature: new Uint8Array([5]).buffer, userHandle: new Uint8Array([6]).buffer },
		getClientExtensionResults: () => (prfResult ? { prf: { results: { first: prfFirst } } } : {}),
	}))
	return { create, get } as unknown as CeremonyDeps['credentials'] & { create: typeof create; get: typeof get }
}

function deps(credentials = authenticator(), overrides: Partial<CeremonyDeps> = {}): CeremonyDeps & { enrolled: EnrolmentBody[] } {
	const enrolled: EnrolmentBody[] = []
	return {
		credentials,
		enrolOptions: async () => enrolOptions,
		enrol: async (body) => { enrolled.push(body) },
		unlockOptions: async () => ({ challenge: b64url('unlock-challenge'), rpId: 'cloud.example.org', credentials: [{ credentialId: toBase64Url(bytesOf(rawId)), prfSalt: toBase64(new Uint8Array(32).fill(9)) }] }),
		release: async () => { throw new Error('not set') },
		enrolled,
		...overrides,
	}
}

describe('creationOptions', () => {
	it('uses the server host and the issued handle, and makes a non-discoverable PRF credential with user verification', () => {
		const { publicKey } = creationOptions(enrolOptions, 'Laptop')
		expect(publicKey!.rp.id).toBe('cloud.example.org')
		expect(new TextDecoder().decode(publicKey!.user.id as Uint8Array)).toBe('random-handle')
		expect(publicKey!.authenticatorSelection).toMatchObject({ residentKey: 'discouraged', requireResidentKey: false, userVerification: 'required', authenticatorAttachment: 'platform' })
		expect(publicKey!.extensions).toEqual({ prf: {} })
	})
})

describe('enrolPasskey', () => {
	it('sends the ceremonies and the wrapped key, never the master password, raw key or PRF output', async () => {
		const run = deps()
		await enrolPasskey(run, envelope.password, 'Laptop')
		const [body] = run.enrolled
		expect(body).toMatchObject({ credentialId: toBase64Url(bytesOf(rawId)), label: 'Laptop', transports: 'internal', clientKind: 'extension' })
		expect(Object.keys(body!).sort()).toEqual(['assertion', 'attestation', 'clientKind', 'credentialId', 'label', 'prfSalt', 'transports', 'wrappedUnlockKey'])
		const sent = JSON.stringify(body)
		const raw = await deriveUnlockKeyBits(envelope.password, decodeEnvelope(envelope.envelope).salt)
		for (const secret of [envelope.password, toBase64(raw), toBase64Url(raw), toBase64(bytesOf(prfFirst)), toBase64Url(bytesOf(prfFirst))]) expect(sent).not.toContain(secret)
	})

	it('proves the new credential with its own PRF salt against the server host and the proof challenge', async () => {
		const credentials = authenticator()
		const run = deps(credentials)
		await enrolPasskey(run, envelope.password, 'Laptop')
		const request = (credentials.get.mock.calls[0] as unknown as [CredentialRequestOptions])[0].publicKey!
		expect(request.rpId).toBe('cloud.example.org')
		expect(new TextDecoder().decode(request.challenge as Uint8Array)).toBe('proof-challenge')
		expect(request.userVerification).toBe('required')
		const salt = (request.extensions as { prf: { evalByCredential: Record<string, { first: Uint8Array }> } }).prf.evalByCredential[toBase64Url(bytesOf(rawId))]!.first
		expect(toBase64(salt)).toBe(run.enrolled[0]!.prfSalt)
	})

	it('wraps a key that unwraps to the raw unlock key with the PRF output', async () => {
		const run = deps()
		await enrolPasskey(run, envelope.password, 'Laptop')
		const [body] = run.enrolled
		const raw = await unwrapUnlockKey(await deriveKek(prfFirst, body!.credentialId), body!.wrappedUnlockKey)
		expect(raw).toEqual(await deriveUnlockKeyBits(envelope.password, decodeEnvelope(envelope.envelope).salt))
	})

	it('refuses a wrong master password before any ceremony', async () => {
		const credentials = authenticator()
		await expect(enrolPasskey(deps(credentials), 'wrong', 'Laptop')).rejects.toBeInstanceOf(InvalidMasterPassword)
		expect(credentials.create).not.toHaveBeenCalled()
	})

	it('stores nothing when the authenticator has no PRF', async () => {
		const run = deps(authenticator({ prfEnabled: false }))
		await expect(enrolPasskey(run, envelope.password, 'Laptop')).rejects.toBeInstanceOf(PrfUnavailable)
		const noResult = deps(authenticator({ prfResult: false }))
		await expect(enrolPasskey(noResult, envelope.password, 'Laptop')).rejects.toBeInstanceOf(PrfUnavailable)
		expect([...run.enrolled, ...noResult.enrolled]).toEqual([])
	})
})

describe('unlockWithPasskey', () => {
	it('posts the assertion without the PRF output and unwraps only the released key', async () => {
		const raw = crypto.getRandomValues(new Uint8Array(32))
		const credentialId = toBase64Url(bytesOf(rawId))
		const wrappedUnlockKey = await wrapUnlockKey(await deriveKek(prfFirst, credentialId), raw)
		const release = vi.fn(async () => ({ credentialId, wrappedUnlockKey }))
		const credentials = authenticator()
		expect(await unlockWithPasskey(deps(credentials, { release }))).toEqual(raw)
		const request = (credentials.get.mock.calls[0] as unknown as [CredentialRequestOptions])[0].publicKey!
		expect(request).toMatchObject({ rpId: 'cloud.example.org', userVerification: 'required' })
		expect(request.allowCredentials!.map((c) => toBase64Url(c.id as Uint8Array))).toEqual([credentialId])
		const posted = JSON.stringify(release.mock.calls[0])
		expect(posted).not.toContain(toBase64(bytesOf(prfFirst)))
		expect(posted).not.toContain(toBase64Url(bytesOf(prfFirst)))
	})

	it('runs no ceremony when the server offers no passkey or no challenge', async () => {
		const credentials = authenticator()
		expect(await unlockWithPasskey(deps(credentials, { unlockOptions: async () => ({ challenge: null, rpId: 'cloud.example.org', credentials: [] }) }))).toBeNull()
		expect(credentials.get).not.toHaveBeenCalled()
	})

	it('fails on a released key the PRF output does not open', async () => {
		const wrappedUnlockKey = await wrapUnlockKey(await deriveKek(new Uint8Array(32).fill(1).buffer, 'other'), new Uint8Array(32))
		await expect(unlockWithPasskey(deps(authenticator(), { release: async () => ({ credentialId: 'other', wrappedUnlockKey }) }))).rejects.toThrow()
	})
})

describe('the web app format', () => {
	it('unwraps what the web app wraps, and the other way round', async () => {
		const raw = crypto.getRandomValues(new Uint8Array(32))
		const webWrapped = await web.wrapUnlockKey(await web.deriveKekFromPrf(prfFirst, 'cred'), raw)
		expect(await unwrapUnlockKey(await deriveKek(prfFirst, 'cred'), webWrapped)).toEqual(raw)
		const ours = await wrapUnlockKey(await deriveKek(prfFirst, 'cred'), raw)
		expect(await web.unwrapUnlockKey(await web.deriveKekFromPrf(prfFirst, 'cred'), ours)).toEqual(raw)
	})

	it('derives the same raw unlock key as the master password unlock', async () => {
		expect(await rawUnlockKey(envelope.password, envelope.envelope)).toEqual(await deriveUnlockKeyBits(envelope.password, decodeEnvelope(envelope.envelope).salt))
	})
})
