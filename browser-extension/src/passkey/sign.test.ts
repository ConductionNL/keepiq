import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import { addAccount, updateAccount } from '@/src/accounts/store'
import { importPrivateKey, pemToPkcs8, rsaDecrypt, toBase64, verifyFederatedCertificate } from '@/src/crypto'
import { fromBase64Url, toBase64Url } from '@/src/crypto/base64'
import { decodePem, extractSpki } from '@/src/crypto/rsa'
import { lastUsedKey } from '@/src/last-used'
import type { PasskeyAssertionJSON, PasskeyResult } from '@/src/messages'
import { encrypt, folderRow, secretRow, typeRows } from '@/src/testing/vault'
import { envelope, federationChain, suiteRow } from '@/src/testing/vectors'
import { putKey } from '@/src/vault/key-store'
import { writeSnapshot } from '@/src/vault/store'
import { syncAfterWrite } from '@/src/vault/sync'
import { signIn, type SignIn } from './sign'
import { FLAGS } from './webauthn'

vi.mock('@/src/crypto', async (importOriginal) => {
	const actual = await importOriginal<typeof import('@/src/crypto')>()
	return { ...actual, verifyFederatedCertificate: vi.fn(actual.verifyFederatedCertificate) }
})

const CHALLENGE = toBase64Url(new Uint8Array(32).fill(9))
const SHARE_SAVE = 'PUT /api/v1/secrets/work/share-save'
const F = federationChain()
let accountId: string
let vaultKey: CryptoKey
let publicKey: CryptoKey
let privateKey: string
let row: ReturnType<typeof secretRow>

const fetchMock = vi.fn<typeof fetch>()
const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status })
interface Sent { method: string; path: string; body: Record<string, unknown> | undefined }
type Routes = Record<string, (sent: Sent) => Response | Promise<Response>>

/** Serves the login `work`; unrouted, it is its own source and shared with nobody. */
function server(routes: Routes = {}) {
	fetchMock.mockImplementation(async (input, init) => {
		const url = new URL(String(input))
		const sent: Sent = { method: init?.method ?? 'GET', path: url.pathname.replace('/index.php/apps/keepiq', '') + url.search, body: init?.body ? JSON.parse(String(init.body)) : undefined }
		const route = routes[`${sent.method} ${sent.path}`]
		if (route) return route(sent)
		if (sent.path === '/api/v1/suites') return json([suiteRow])
		if (sent.path === '/api/v1/offline/manifest') return json({ suite: suiteRow, secrets: [row], folders: [folderRow()], types: typeRows, syncedAt: '' })
		if (sent.path === '/api/v1/secrets/work') return json(row)
		if (sent.path === '/api/v1/secrets/work/write-context') return json({ sourceSecretId: 'work', effectiveGrade: 'owner', ownerCertificate: envelope.certificatePem, sourceUpdatedAt: row.updatedAt })
		if (`${sent.method} ${sent.path}` === SHARE_SAVE) return json({ secret: row, federated: { ownerOnly: false } })
		return json({ message: 'Not found' }, 404)
	})
}

const lines = () => fetchMock.mock.calls.map(([input, init]) => `${init?.method ?? 'GET'} ${new URL(String(input)).pathname.replace('/index.php/apps/keepiq', '')}`)
const lastSave = () => JSON.parse(String(fetchMock.mock.calls.filter((_, i) => lines()[i] === SHARE_SAVE).at(-1)![1]!.body)) as Record<string, unknown>
const decrypt = (ciphertext: unknown) => rsaDecrypt(ciphertext as string, vaultKey)

/** A share-save that answers `incomplete` until its lists name `copies` and `shares`, and the certificate lookups it needs. */
const sharing = ({ copies = [] as object[], shares = [] as object[] } = {}): Routes => ({
	'POST /api/v1/shares/recipient-certificates': ({ body }) => json({ recipients: (body!.userIds as string[]).map((userId) => ({ userId, shareable: true, certificate: envelope.certificatePem })) }),
	'POST /api/v1/federation/recipient-certificate': ({ body }) => json({ cloudId: body!.cloudId, certificate: F.bob, chain: [F.intermediateA, F.rootA], partnerRootFingerprint: F.rootAFingerprint, valueFields: ['totp', 'passkeys'] }),
	[SHARE_SAVE]: ({ body }) => {
		const named = [...body!.copies as object[], ...body!.skipped as object[]].length
		const partners = [...(body!.federated ?? []) as object[], ...(body!.suspend ?? []) as object[]].length
		if (named !== copies.length || partners !== shares.length) return json({ error: 'incomplete', copies: { required: copies, skipRefused: [] }, federated: { required: shares } }, 409)
		return json({ secret: row, federated: { ownerOnly: false } })
	},
})
const BOB_COPY = { secretId: 'c-bob', holder: 'recipient', targetUserId: 'bob' }
const BOB = { shareId: 'fs-bob', recipientCloudId: 'bob@cloud.partner.example' }
const partnerOpens = () => vi.mocked(verifyFederatedCertificate).mockResolvedValueOnce({ fingerprint: 'ab'.repeat(32), spki: extractSpki(decodePem(envelope.certificatePem).der) })

/** A relying party's own reading of a DER `Ecdsa-Sig-Value`, apart from the module under test. */
function derToP1363(der: Uint8Array): Uint8Array<ArrayBuffer> {
	const out = new Uint8Array(64)
	let at = 2
	for (const offset of [0, 32]) {
		const length = der[at + 1]!
		const int = der.slice(at + 2, at + 2 + length)
		const trimmed = int.slice(Math.max(0, int.length - 32))
		out.set(trimmed, offset + 32 - trimmed.length)
		at += 2 + length
	}
	return out
}

async function verifies(response: PasskeyAssertionJSON): Promise<boolean> {
	const authData = fromBase64Url(response.authenticatorData)
	const clientHash = new Uint8Array(await crypto.subtle.digest('SHA-256', fromBase64Url(response.clientDataJSON)))
	const signed = new Uint8Array([...authData, ...clientHash])
	return crypto.subtle.verify({ name: 'ECDSA', hash: 'SHA-256' }, publicKey, derToP1363(fromBase64Url(response.signature)), signed)
}

const responseOf = (result: PasskeyResult | undefined) => (result as Extract<PasskeyResult, { type: 'credential' }>).credential.response as PasskeyAssertionJSON
const counterOf = (result: PasskeyResult | undefined) => new DataView(fromBase64Url(responseOf(result).authenticatorData).buffer).getUint32(33)

const entry = (credentialId: string, overrides: Record<string, unknown> = {}) => ({
	credentialId, rpId: 'example.test', rpName: 'Example', userName: 'alice', userDisplayName: 'Alice', userHandle: 'dXNlcg',
	privateKey, algorithm: -7, counter: 0, transports: ['internal'], createdAt: '2026-01-01T00:00:00.000Z', ...overrides,
})

/** The login `work` holding a passkey for another RP and `QQ` with `counter`, cached and served alike. */
async function login(counter: number, plain: { key?: string; login?: string | null; additionalFields?: string | null } = {}, overrides: Partial<ReturnType<typeof secretRow>> = {}) {
	const { key = 'pw', login: username = 'alice', additionalFields = null } = plain
	const passkeys = JSON.stringify([entry('Qg', { rpId: 'other.test' }), entry('QQ', { counter })])
	row = secretRow({
		id: 'work', name: 'Work', typeId: 't-login', key: await encrypt(key), login: username === null ? null : await encrypt(username),
		additionalFields: additionalFields === null ? null : await encrypt(additionalFields), passkeys: await encrypt(passkeys), ...overrides,
	})
	const syncedAt = new Date().toISOString()
	await writeSnapshot(accountId, {
		suite: { id: suiteRow.id, unlockKeyEpoch: 1 }, secrets: [row], folders: [folderRow()], types: typeRows, syncedAt, listsSyncedAt: syncedAt, newestUpdatedAt: null, total: 1,
	})
	return passkeys
}

function input(overrides: Partial<SignIn> = {}): SignIn & { delivered: PasskeyResult[] } {
	const delivered: PasskeyResult[] = []
	return {
		ceremony: { accountId, rpId: 'example.test', origin: 'https://login.example.test', challenge: CHALLENGE, credentialIds: [], verified: false },
		key: vaultKey,
		itemId: 'work',
		credentialId: 'QQ',
		deliver: async (result) => {
			delivered.push(result)
			return true
		},
		delivered,
		...overrides,
	}
}

beforeAll(async () => {
	vaultKey = await importPrivateKey(pemToPkcs8(envelope.privateKeyPem))
	const pair = await crypto.subtle.generateKey({ name: 'ECDSA', namedCurve: 'P-256' }, true, ['sign', 'verify'])
	publicKey = pair.publicKey
	privateKey = toBase64Url(new Uint8Array(await crypto.subtle.exportKey('pkcs8', pair.privateKey)))
})

beforeEach(async () => {
	fakeBrowser.reset()
	vi.stubGlobal('fetch', fetchMock)
	fetchMock.mockReset()
	vi.spyOn(console, 'error').mockImplementation(() => {})
	accountId = (await addAccount({
		serverUrl: 'https://cloud.example.org', uid: 'alice', loginName: 'alice', displayName: 'Alice', email: null, avatarDataUrl: null, appPassword: 'pw', keepiqVersion: '0.3.12',
	}, { ...suiteRow })).id
	await putKey(accountId, toBase64(pemToPkcs8(envelope.privateKeyPem)))
	await login(0)
	server()
})

// A counter save starts a sync it does not wait for; it ends before the next test resets the fakes.
afterEach(() => syncAfterWrite(accountId))

describe('signIn', () => {
	it('delivers an assertion the passkey’s public key verifies, for the frame’s origin, and records the login’s use', async () => {
		const call = input()
		expect(await signIn(call)).toBe('delivered')
		const [result] = call.delivered
		expect(result).toMatchObject({ type: 'credential', credential: { id: 'QQ', rawId: 'QQ', type: 'public-key', authenticatorAttachment: 'platform', clientExtensionResults: {} } })
		const response = responseOf(result)
		expect(await verifies(response)).toBe(true)
		expect(JSON.parse(new TextDecoder().decode(fromBase64Url(response.clientDataJSON))))
			.toEqual({ type: 'webauthn.get', challenge: CHALLENGE, origin: 'https://login.example.test', crossOrigin: false })
		expect(response.userHandle).toBe('dXNlcg')
		expect(Object.keys((await browser.storage.local.get(lastUsedKey(accountId)))[lastUsedKey(accountId)] as object)).toEqual(['work'])
	})

	it.each([[false, 0], [true, FLAGS.UV]])('sets the user-verified flag only when verified (%s)', async (verified, uv) => {
		const call = input()
		call.ceremony.verified = verified
		await signIn(call)
		expect(fromBase64Url(responseOf(call.delivered[0]).authenticatorData)[32]).toBe(FLAGS.UP | FLAGS.BE | FLAGS.BS | uv)
	})

	it.each([
		['another login', { itemId: 'personal' }],
		['a passkey for another RP ID', { credentialId: 'Qg' }],
		['an id that is not base64', { credentialId: '!!' }],
	])('refuses %s as stale, delivering nothing', async (_, overrides) => {
		const call = input(overrides)
		expect(await signIn(call)).toBe('stale')
		expect(call.delivered).toEqual([])
	})

	it('refuses a passkey outside the request’s allow list', async () => {
		const call = input()
		call.ceremony.credentialIds = ['Qw']
		expect(await signIn(call)).toBe('stale')
	})

	it('records nothing when the ceremony ended before delivery', async () => {
		const deliver = vi.fn(async () => false)
		expect(await signIn(input({ deliver }))).toBe('ended')
		expect(await browser.storage.local.get(lastUsedKey(accountId))).toEqual({})
	})
})

describe.each(['0.3.12', '0.3.9-unstable.20261007125844', undefined])('the signature counter on Keepiq %s', (keepiqVersion) => {
	beforeEach(() => updateAccount(accountId, { keepiqVersion }))

	it('signs a stored 0 with 0 and sends nothing', async () => {
		const call = input()
		expect(await signIn(call)).toBe('delivered')
		expect(counterOf(call.delivered[0])).toBe(0)
		expect(fetchMock).not.toHaveBeenCalled()
	})

	it('saves an own login’s counter one up, then signs with the saved value, the other entry byte for byte', async () => {
		await login(41)
		const call = input()
		expect(await signIn(call)).toBe('delivered')
		expect(counterOf(call.delivered[0])).toBe(42)
		expect(await verifies(responseOf(call.delivered[0]))).toBe(true)
		expect(await decrypt((lastSave().patch as { passkeys: string }).passkeys)).toBe(JSON.stringify([entry('Qg', { rpId: 'other.test' }), entry('QQ', { counter: 42 })]))
	})

	it('signs with the server’s higher counter plus one', async () => {
		await login(41)
		// Another client counted up since the last sync; the server alone holds it.
		row = { ...row, passkeys: await encrypt(JSON.stringify([entry('Qg', { rpId: 'other.test' }), entry('QQ', { counter: 45 })])), updatedAt: '2026-03-03T10:00:00+00:00' }
		const call = input()
		expect(await signIn(call)).toBe('delivered')
		expect(counterOf(call.delivered[0])).toBe(46)
	})

	it('gives a shared login’s local copy the new counter', async () => {
		const stored = await login(41)
		server(sharing({ copies: [BOB_COPY] }))
		const call = input()
		expect(await signIn(call)).toBe('delivered')
		expect(counterOf(call.delivered[0])).toBe(42)
		const [copy] = lastSave().copies as Array<Record<string, string>>
		expect(await decrypt(copy!.passkeys)).toBe(stored.replace('"counter":41', '"counter":42'))
	})

	it('saves to a partner share a login with an empty password, no username and no fields', async () => {
		await login(41, { key: '', login: null, additionalFields: '{}' })
		server(sharing({ shares: [BOB] }))
		partnerOpens()
		const call = input()
		expect(await signIn(call)).toBe('delivered')
		expect(counterOf(call.delivered[0])).toBe(42)
		const [partner] = lastSave().federated as Array<Record<string, string>>
		expect(JSON.parse(await decrypt(partner!.passkeys))[1].counter).toBe(42)
	})

	it('signs nothing for a partner-shared login with a username, which the save never showed', async () => {
		await login(41, { key: '', login: 'alice' })
		server(sharing({ shares: [BOB] }))
		const call = input()
		expect(await signIn(call)).toEqual({ error: { code: 'unverified' } })
		expect(call.delivered).toEqual([])
		expect(await browser.storage.local.get(lastUsedKey(accountId))).toEqual({})
	})

	it('signs nothing for a use-only copy above 0, and signs one at 0 without a request', async () => {
		await login(41, {}, { useOnly: true })
		const refused = input()
		expect(await signIn(refused)).toEqual({ error: { code: 'restricted' } })
		expect(refused.delivered).toEqual([])
		expect(fetchMock).not.toHaveBeenCalled()
		await login(0, {}, { useOnly: true })
		const signed = input()
		expect(await signIn(signed)).toBe('delivered')
		expect(counterOf(signed.delivered[0])).toBe(0)
	})

	it('raises no counter for a key that does not import', async () => {
		row = secretRow({ id: 'work', name: 'Work', typeId: 't-login', key: await encrypt('pw'), passkeys: await encrypt(JSON.stringify([entry('QQ', { counter: 41, privateKey: 'AAAA' })])) })
		const syncedAt = new Date().toISOString()
		await writeSnapshot(accountId, { suite: { id: suiteRow.id, unlockKeyEpoch: 1 }, secrets: [row], folders: [folderRow()], types: typeRows, syncedAt, listsSyncedAt: syncedAt, newestUpdatedAt: null, total: 1 })
		const call = input()
		await expect(signIn(call)).rejects.toThrow()
		expect(fetchMock).not.toHaveBeenCalled()
		expect(call.delivered).toEqual([])
	})

	it.each([
		['offline', { [SHARE_SAVE]: () => Promise.reject(new TypeError('Failed to fetch')) }, 'offline'],
		['a 423', { [SHARE_SAVE]: () => json({ message: 'Locked' }, 423) }, 'write_locked'],
	])('signs nothing when %s stops the save', async (_, routes, code) => {
		await login(5)
		server(routes)
		const call = input()
		expect(await signIn(call)).toEqual({ error: { code } })
		expect(call.delivered).toEqual([])
	})
})
