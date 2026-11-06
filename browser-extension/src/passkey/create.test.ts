import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import { writeSettings } from '@/src/accounts/settings'
import { addAccount, updateAccount } from '@/src/accounts/store'
import { importPrivateKey, pemToPkcs8, rsaDecrypt, toBase64, verifyFederatedCertificate } from '@/src/crypto'
import { fromBase64Url, toBase64Url } from '@/src/crypto/base64'
import { decodePem, extractSpki } from '@/src/crypto/rsa'
import { recordLastUsed } from '@/src/last-used'
import type { BackgroundToContent, PasskeyAttestationJSON, PasskeyPopupRequest, PasskeyResult, PasskeyWindowReply } from '@/src/messages'
import { encrypt, folderRow, secretRow, typeRows } from '@/src/testing/vault'
import { envelope, federationChain, suiteRow } from '@/src/testing/vectors'
import { putKey } from '@/src/vault/key-store'
import { writeSnapshot } from '@/src/vault/store'
import { syncAfterWrite } from '@/src/vault/sync'
import { lock } from '@/src/vault/unlock'
import { ceremonyKey, handlePasskeyContent, handlePasskeyWindow, registerPasskeyCeremonies, type Ceremony } from './ceremony'
import { readSaveRequest } from './create'
import { FLAGS } from './webauthn'

vi.mock('@/src/crypto', async (importOriginal) => {
	const actual = await importOriginal<typeof import('@/src/crypto')>()
	return { ...actual, rsaDecrypt: vi.fn(actual.rsaDecrypt), verifyFederatedCertificate: vi.fn(actual.verifyFederatedCertificate) }
})

const TAB = 7
const ORIGIN = 'https://example.test'
const CHALLENGE = toBase64Url(new Uint8Array(32).fill(9))
const USER_ID = toBase64Url(new Uint8Array([1, 2]))
const F = federationChain()
let accountId: string
let vaultKey: CryptoKey
let rows: ReturnType<typeof secretRow>[]
let results: Array<Extract<BackgroundToContent, { kind: 'passkey.result' }>>
let removed: number[]

const fetchMock = vi.fn<typeof fetch>()
const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status })
interface Sent { method: string; path: string; body: Record<string, unknown> | undefined }
type Routes = Record<string, (sent: Sent) => Response | Promise<Response>>

/** Serves `rows`; `viewer` is a view-only copy, every other row its own source shared with nobody. */
function server(routes: Routes = {}) {
	fetchMock.mockImplementation(async (input, init) => {
		const url = new URL(String(input))
		const sent: Sent = { method: init?.method ?? 'GET', path: url.pathname.replace('/index.php/apps/keepiq', '') + url.search, body: init?.body ? JSON.parse(String(init.body)) : undefined }
		const route = routes[`${sent.method} ${sent.path}`]
		if (route) return route(sent)
		if (sent.path === '/api/v1/suites') return json([suiteRow])
		if (sent.path === '/api/v1/offline/manifest') return json({ suite: suiteRow, secrets: rows, folders: [folderRow()], types: typeRows, syncedAt: '' })
		if (sent.method === 'POST' && sent.path === '/api/v1/secrets') return json(secretRow({ id: 'new' }), 201)
		const item = /^\/api\/v1\/secrets\/([^/]+)(\/write-context|\/share-save)?$/.exec(sent.path)
		const row = rows.find((candidate) => candidate.id === item?.[1])
		if (row && !item![2]) return json(row)
		if (row && item![2] === '/write-context') {
			return json({ sourceSecretId: row.id === 'viewer' ? 'src' : row.id, effectiveGrade: row.id === 'viewer' ? 'read' : 'owner', ownerCertificate: envelope.certificatePem, sourceUpdatedAt: row.updatedAt })
		}
		if (row && sent.method === 'PUT') return json({ secret: row, federated: { ownerOnly: false } })
		return json({ message: 'Not found' }, 404)
	})
}

const lines = () => fetchMock.mock.calls.map(([input, init]) => `${init?.method ?? 'GET'} ${new URL(String(input)).pathname.replace('/index.php/apps/keepiq', '')}`)
const bodiesOf = (line: string) => fetchMock.mock.calls.filter((_, i) => lines()[i] === line).map(([, init]) => JSON.parse(String(init!.body)) as Record<string, unknown>)
const writes = () => lines().filter((line) => line.startsWith('POST /api/v1/secrets') || line.endsWith('/share-save'))
const decrypt = (ciphertext: unknown) => rsaDecrypt(ciphertext as string, vaultKey)

const passkey = (credentialId: string, overrides: Record<string, unknown> = {}) => ({
	credentialId, rpId: 'example.test', rpName: 'Example', userName: 'someone', userDisplayName: 'Someone', userHandle: 'b3RoZXI',
	privateKey: `pk-${credentialId}`, algorithm: -7, counter: 0, transports: ['internal'], createdAt: '2026-01-01T00:00:00.000Z', ...overrides,
})
const WORK = [passkey('QQ')]
const PERSONAL = [passkey('Qg', { rpId: 'other.test' }), passkey('Uw', { userHandle: USER_ID, userName: 'alice@example.test' })]

async function vault() {
	rows = [
		secretRow({ id: 'work', name: 'Work', url: 'https://example.test/login', key: await encrypt('pw'), login: await encrypt('alice'), additionalFields: await encrypt('{"notes":"x"}'), passkeys: await encrypt(JSON.stringify(WORK)) }),
		secretRow({ id: 'personal', name: 'Personal', url: 'https://www.example.test', key: await encrypt('pw2'), login: await encrypt('alice2'), passkeys: await encrypt(JSON.stringify(PERSONAL)) }),
		secretRow({ id: 'team', name: 'Team', url: 'https://example.test', key: await encrypt('pw'), useOnly: true }),
		secretRow({ id: 'partner', name: 'Partner', url: 'https://example.test', key: await encrypt('pw'), readOnly: true }),
		secretRow({ id: 'viewer', name: 'Viewer', url: 'https://example.test', key: await encrypt('pw') }),
		secretRow({ id: 'elsewhere', name: 'Elsewhere', url: 'https://other.test', key: await encrypt('pw') }),
		secretRow({ id: 'note', name: 'Note', url: 'https://example.test', typeId: 't-note', key: await encrypt('n') }),
	]
	const at = new Date().toISOString()
	await writeSnapshot(accountId, { suite: { id: suiteRow.id, unlockKeyEpoch: 1 }, secrets: rows, folders: [folderRow()], types: typeRows, syncedAt: at, listsSyncedAt: at, newestUpdatedAt: null, total: rows.length })
}

const createRequest = (options: Record<string, unknown> = {}) => ({
	type: 'create',
	options: {
		rp: { name: 'Example' }, user: { id: USER_ID, name: 'alice@example.test', displayName: 'Alice' }, challenge: CHALLENGE,
		pubKeyCredParams: [{ type: 'public-key', alg: -7 }], excludeCredentials: [], ...options,
	},
})
const content = (overrides: object = {}) => ({ id: browser.runtime.id, url: `${ORIGIN}/signup`, origin: ORIGIN, tab: { id: TAB }, frameId: 0, ...overrides }) as never
const windowOf = (overrides: object = {}) => ({ id: browser.runtime.id, url: browser.runtime.getURL('/popup.html'), tab: { id: 550 }, frameId: 0, ...overrides }) as never

const settle = async () => {
	for (let i = 0; i < 5; i++) await new Promise((resolve) => setTimeout(resolve, 0))
}

async function start(request: unknown = createRequest()): Promise<string> {
	const reply = await handlePasskeyContent({ kind: 'passkey.start', request, crossOriginAncestor: false } as never, content()) as { ceremonyId: string }
	await settle()
	return reply.ceremonyId
}

const window = (ceremonyId: string, message: Record<string, unknown>, sender = windowOf()) => handlePasskeyWindow({ ceremonyId, ...message } as PasskeyPopupRequest, sender)
const stateOf = async (ceremonyId: string) => (await window(ceremonyId, { kind: 'passkey.state' }) as Extract<PasskeyWindowReply, { ok: true }>).view!
const record = async () => (await browser.storage.session.get(ceremonyKey(TAB)))[ceremonyKey(TAB)] as Ceremony | undefined
const attestationOf = (result: PasskeyResult | undefined) => (result as Extract<PasskeyResult, { type: 'credential' }>).credential

beforeEach(async () => {
	fakeBrowser.reset()
	vi.stubGlobal('fetch', fetchMock)
	fetchMock.mockReset()
	vi.mocked(rsaDecrypt).mockClear()
	vi.spyOn(console, 'error').mockImplementation(() => {})
	accountId = (await addAccount({
		serverUrl: 'https://cloud.example.org', uid: 'alice', loginName: 'alice', displayName: 'Alice', email: null, avatarDataUrl: null, appPassword: 'pw', keepiqVersion: '0.3.12',
	}, { ...suiteRow })).id
	vaultKey = await importPrivateKey(pemToPkcs8(envelope.privateKeyPem))
	await putKey(accountId, toBase64(pemToPkcs8(envelope.privateKeyPem)))
	await vault()
	server()
	results = []
	removed = []
	vi.spyOn(browser.tabs, 'sendMessage').mockImplementation(async (_tabId: number, message: unknown) => {
		const m = structuredClone(message) as BackgroundToContent
		if (m.kind === 'passkey.result') results.push(m)
		return undefined as never
	})
	vi.spyOn(browser.windows, 'create').mockImplementation(async () => ({ id: 50, tabs: [{ id: 550 }] }) as never)
	vi.spyOn(browser.windows, 'remove').mockImplementation(async (id: number) => void removed.push(id))
	registerPasskeyCeremonies()
})

// Each save starts a sync it does not wait for; it ends before the next test resets the fakes.
afterEach(() => syncAfterWrite(accountId))

describe('the Save view', () => {
	it('shows a locked vault nothing of the vault, decrypting nothing, also for an excluded credential', async () => {
		await lock(accountId)
		const id = await start(createRequest({ excludeCredentials: [{ type: 'public-key', id: 'QQ' }] }))
		vi.mocked(rsaDecrypt).mockClear()
		expect(await stateOf(id)).toEqual({
			kind: 'create', conditional: false, rpId: 'example.test', host: 'example.test', rpName: 'Example', userName: 'alice@example.test', locked: true, verify: false,
			rows: [], targets: [], folders: [], alreadySaved: false, failure: null, pending: null,
		})
		expect(rsaDecrypt).not.toHaveBeenCalled()
		expect(fetchMock).not.toHaveBeenCalled()
		expect(await window(id, { kind: 'passkey.save', target: { name: 'Example', folderId: null } })).toEqual({ ok: false, code: 'locked' })
	})

	it('offers the matching editable logins, last used first, with their usernames, the Replace question and the folders', async () => {
		await recordLastUsed(accountId, 'personal')
		const id = await start()
		const view = await stateOf(id)
		expect(view.targets).toEqual([
			{ itemId: 'personal', name: 'Personal', username: 'alice2', replace: { userName: 'alice@example.test' } },
			{ itemId: 'work', name: 'Work', username: 'alice', replace: null },
		])
		expect(view.folders).toEqual([{ id: 'f1', name: 'Work' }])
		expect(view).toMatchObject({ locked: false, alreadySaved: false, failure: null, rows: [] })
		expect(JSON.stringify(view)).not.toContain('pk-')
	})

	it('matches logins on the frame’s URL under the URI match rule, as autofill does', async () => {
		await writeSettings(accountId, { uriMatch: 'startsWith' })
		const reply = await handlePasskeyContent({ kind: 'passkey.start', request: createRequest(), crossOriginAncestor: false } as never, content({ url: `${ORIGIN}/login/step?next=1#top` })) as { ceremonyId: string }
		await settle()
		expect((await record())?.url).toBe(`${ORIGIN}/login/step?next=1`)
		expect((await stateOf(reply.ceremonyId)).targets.map((target) => target.itemId)).toEqual(['work'])
		expect(await window(reply.ceremonyId, { kind: 'passkey.save', target: { itemId: 'work' } })).toEqual({ ok: true })
	})

	it('asks write-context once per login in a ceremony, however often the view shows', async () => {
		const id = await start()
		await stateOf(id)
		await stateOf(id)
		expect(lines().filter((line) => line.endsWith('/write-context')).sort()).toEqual([
			'GET /api/v1/secrets/personal/write-context', 'GET /api/v1/secrets/viewer/write-context', 'GET /api/v1/secrets/work/write-context',
		])
	})

	it('asks write-context again after a failed answer', async () => {
		server({ 'GET /api/v1/secrets/work/write-context': () => Promise.reject(new TypeError('Failed to fetch')) })
		const id = await start()
		expect((await stateOf(id)).targets.map((target) => target.itemId)).toContain('work')
		await stateOf(id)
		expect(lines().filter((line) => line === 'GET /api/v1/secrets/work/write-context')).toHaveLength(2)
	})

	it('reads "already saved" for an excluded credential the vault holds, refuses a save, and answers InvalidStateError on Close', async () => {
		const id = await start(createRequest({ excludeCredentials: [{ type: 'public-key', id: 'QQ' }] }))
		expect(await stateOf(id)).toMatchObject({ alreadySaved: true, targets: [], folders: [] })
		expect(await window(id, { kind: 'passkey.save', target: { name: 'Example', folderId: null } })).toEqual({ ok: false, code: 'already_saved' })
		expect(writes()).toEqual([])
		expect(results).toEqual([])
		expect(await window(id, { kind: 'passkey.cancel' })).toEqual({ ok: true })
		expect(results.map((m) => m.result)).toEqual([{ type: 'error', name: 'InvalidStateError' }])
	})

	it('answers InvalidStateError when the user closes the window on "already saved"', async () => {
		const id = await start(createRequest({ excludeCredentials: [{ type: 'public-key', id: 'QQ' }] }))
		await stateOf(id)
		await fakeBrowser.windows.onRemoved.trigger(50)
		await settle()
		expect(results.map((m) => [m.ceremonyId, m.result])).toEqual([[id, { type: 'error', name: 'InvalidStateError' }]])
	})

	it('refuses a save to an excluded credential even before the view showed it', async () => {
		const id = await start(createRequest({ excludeCredentials: [{ type: 'public-key', id: 'QQ' }] }))
		expect(await window(id, { kind: 'passkey.save', target: { itemId: 'work' } })).toEqual({ ok: false, code: 'already_saved' })
		expect(writes()).toEqual([])
	})

	it('saves as usual when the excluded credential is not in the vault', async () => {
		const id = await start(createRequest({ excludeCredentials: [{ type: 'public-key', id: 'Zm9v' }] }))
		expect(await stateOf(id)).toMatchObject({ alreadySaved: false })
		expect(await window(id, { kind: 'passkey.save', target: { name: 'Example', folderId: null } })).toEqual({ ok: true })
	})
})

describe.each(['0.3.12', '0.3.9-unstable.20261007125844', undefined])('saving on Keepiq %s', (keepiqVersion) => {
	beforeEach(() => updateAccount(accountId, { keepiqVersion }))

	it('saves as a new login, then gives the page an attestation whose key is the saved one', async () => {
		const id = await start(createRequest({ extensions: { credProps: true } }))
		expect(await window(id, { kind: 'passkey.save', target: { name: ' Example ', folderId: 'f1' } })).toEqual({ ok: true })
		await settle()
		const [body] = bodiesOf('POST /api/v1/secrets')
		expect(body).toMatchObject({ name: 'Example', url: ORIGIN, typeId: 't-login', folderId: 'f1' })
		expect(await decrypt(body!.key)).toBe('')
		const [saved] = JSON.parse(await decrypt(body!.passkeys))
		expect(saved).toMatchObject({ rpId: 'example.test', rpName: 'Example', userName: 'alice@example.test', userDisplayName: 'Alice', userHandle: USER_ID, algorithm: -7, counter: 0, transports: ['internal'] })
		expect(Object.keys(saved)).toEqual(['credentialId', 'rpId', 'rpName', 'userName', 'userDisplayName', 'userHandle', 'privateKey', 'algorithm', 'counter', 'transports', 'createdAt'])

		expect(results).toHaveLength(1)
		const credential = attestationOf(results[0]!.result)
		const response = credential.response as PasskeyAttestationJSON
		expect(credential).toMatchObject({ id: saved.credentialId, rawId: saved.credentialId, type: 'public-key', authenticatorAttachment: 'platform', clientExtensionResults: { credProps: { rk: true } } })
		expect(response).toMatchObject({ publicKeyAlgorithm: -7, transports: ['internal'] })
		expect(JSON.parse(new TextDecoder().decode(fromBase64Url(response.clientDataJSON)))).toEqual({ type: 'webauthn.create', challenge: CHALLENGE, origin: ORIGIN, crossOrigin: false })
		expect(fromBase64Url(response.authenticatorData)[32]! & FLAGS.AT).toBe(FLAGS.AT)
		const signer = await crypto.subtle.importKey('pkcs8', fromBase64Url(saved.privateKey), { name: 'ECDSA', namedCurve: 'P-256' }, false, ['sign'])
		const verifier = await crypto.subtle.importKey('spki', fromBase64Url(response.publicKey), { name: 'ECDSA', namedCurve: 'P-256' }, false, ['verify'])
		const data = new TextEncoder().encode('later assertion')
		expect(await crypto.subtle.verify({ name: 'ECDSA', hash: 'SHA-256' }, verifier, await crypto.subtle.sign({ name: 'ECDSA', hash: 'SHA-256' }, signer, data), data)).toBe(true)

		// The private key reached the server encrypted only, and nothing else.
		const elsewhere = JSON.stringify([results, fetchMock.mock.calls.map(([, init]) => init?.body ?? null), await browser.storage.local.get(null), await browser.storage.session.get(null)])
		expect(elsewhere).not.toContain(saved.privateKey)
		expect(removed).toEqual([50])
		expect(await record()).toBeUndefined()
	})

	it('appends to an existing login, its other passkey and other fields unchanged', async () => {
		const id = await start()
		expect(await window(id, { kind: 'passkey.save', target: { itemId: 'work' } })).toEqual({ ok: true })
		const [body] = bodiesOf('PUT /api/v1/secrets/work/share-save')
		expect(Object.keys(body!.patch as object)).toEqual(['passkeys'])
		const saved = await decrypt((body!.patch as { passkeys: string }).passkeys)
		expect(saved.startsWith(JSON.stringify(WORK).slice(0, -1) + ',')).toBe(true)
		expect(JSON.parse(saved)).toHaveLength(2)
		expect(attestationOf(results[0]!.result).id).toBe(JSON.parse(saved)[1].credentialId)
	})
})

describe('Replace', () => {
	it('asks first, writing nothing, then puts the new passkey in the old one’s place', async () => {
		const id = await start()
		expect(await window(id, { kind: 'passkey.save', target: { itemId: 'personal' } })).toEqual({ ok: false, code: 'replace_required' })
		expect(writes()).toEqual([])
		expect(await window(id, { kind: 'passkey.save', target: { itemId: 'personal', replace: true } })).toEqual({ ok: true })
		const saved = JSON.parse(await decrypt((bodiesOf('PUT /api/v1/secrets/personal/share-save')[0]!.patch as { passkeys: string }).passkeys))
		expect(saved).toHaveLength(2)
		expect(saved[0]).toEqual(PERSONAL[0])
		expect(saved[1]).toMatchObject({ userHandle: USER_ID, userName: 'alice@example.test' })
		expect(saved[1].credentialId).not.toBe('Uw')
		expect(attestationOf(results[0]!.result).id).toBe(saved[1].credentialId)
	})
})

describe('a failed save', () => {
	it.each([
		['a 423', { 'PUT /api/v1/secrets/work/share-save': () => json({ message: 'Locked' }, 423) }, 'write_locked'],
		['no server', { 'PUT /api/v1/secrets/work/share-save': () => Promise.reject(new TypeError('Failed to fetch')) }, 'offline'],
	])('keeps the ceremony open on %s, sends the page nothing, and Retry saves', async (_, routes, code) => {
		server(routes)
		const id = await start()
		expect(await window(id, { kind: 'passkey.save', target: { itemId: 'work' } })).toEqual({ ok: false, code: 'write_failed', error: { code } })
		await settle()
		expect(results).toEqual([])
		expect(removed).toEqual([])
		expect(await stateOf(id)).toMatchObject({ failure: { code }, targets: [{ itemId: 'personal' }, { itemId: 'work' }] })
		server()
		expect(await window(id, { kind: 'passkey.retry' })).toEqual({ ok: true })
		expect(results.map((m) => m.result.type)).toEqual(['credential'])
	})

	it('keeps a new login’s name and folder for Retry', async () => {
		server({ 'POST /api/v1/secrets': () => json({ message: 'Locked' }, 423) })
		const id = await start()
		await window(id, { kind: 'passkey.save', target: { name: 'Example', folderId: 'f1' } })
		server()
		expect(await window(id, { kind: 'passkey.retry' })).toEqual({ ok: true })
		expect(bodiesOf('POST /api/v1/secrets').map(({ name, folderId }) => [name, folderId])).toEqual([['Example', 'f1'], ['Example', 'f1']])
	})

	it('creates one login when Retry follows a real failure', async () => {
		server({ 'POST /api/v1/secrets': () => json({ message: 'Locked' }, 423) })
		const id = await start()
		await window(id, { kind: 'passkey.save', target: { name: 'Example', folderId: null } })
		server()
		expect(await window(id, { kind: 'passkey.retry' })).toEqual({ ok: true })
		expect(bodiesOf('POST /api/v1/secrets')).toHaveLength(2)
		expect(writes().filter((line) => line.endsWith('/share-save'))).toEqual([])
		expect(results.map((m) => m.result.type)).toEqual(['credential'])
	})

	/** The server stores the write, then the reply is lost; the vault then holds what was sent. */
	const lostReply = (store: (sent: Sent) => void) => (sent: Sent) => {
		store(sent)
		return Promise.reject(new TypeError('Failed to fetch'))
	}

	it('creates no second login when Retry follows a lost reply to the new login', async () => {
		server({
			'POST /api/v1/secrets': lostReply(({ body }) => rows.push(secretRow({ id: 'new', name: 'Example', url: ORIGIN, key: body!.key as string, passkeys: body!.passkeys as string }))),
		})
		const id = await start()
		expect(await window(id, { kind: 'passkey.save', target: { name: 'Example', folderId: null } })).toMatchObject({ code: 'write_failed', error: { code: 'offline' } })
		const [first] = JSON.parse(await decrypt(bodiesOf('POST /api/v1/secrets')[0]!.passkeys))
		server()
		expect(await window(id, { kind: 'passkey.retry' })).toEqual({ ok: true })
		expect(bodiesOf('POST /api/v1/secrets')).toHaveLength(1)
		const [body] = bodiesOf('PUT /api/v1/secrets/new/share-save')
		const saved = JSON.parse(await decrypt((body!.patch as { passkeys: string }).passkeys))
		// The page gets the passkey that took the lost one's place, the only one the login holds.
		expect(saved).toHaveLength(1)
		expect(saved[0].credentialId).not.toBe(first.credentialId)
		expect(results.map((m) => attestationOf(m.result).id)).toEqual([saved[0].credentialId])
	})

	const storeNewLogin = lostReply(({ body }) => rows.push(secretRow({ id: 'new', name: 'Example', url: ORIGIN, key: body!.key as string, passkeys: body!.passkeys as string })))

	it('creates no second login when a fresh save to the same new login follows a lost reply', async () => {
		server({ 'POST /api/v1/secrets': storeNewLogin })
		const id = await start()
		await window(id, { kind: 'passkey.save', target: { name: 'Example', folderId: null } })
		server()
		expect(await window(id, { kind: 'passkey.save', target: { name: ' Example ', folderId: null } })).toEqual({ ok: true })
		expect(bodiesOf('POST /api/v1/secrets')).toHaveLength(1)
		expect(bodiesOf('PUT /api/v1/secrets/new/share-save')).toHaveLength(1)
	})

	it('saves a fresh save to another target after a lost reply where it was asked', async () => {
		server({ 'POST /api/v1/secrets': storeNewLogin })
		const id = await start()
		await window(id, { kind: 'passkey.save', target: { name: 'Example', folderId: null } })
		server()
		expect(await window(id, { kind: 'passkey.save', target: { name: 'Example', folderId: 'f1' } })).toEqual({ ok: true })
		expect(bodiesOf('POST /api/v1/secrets').map(({ folderId }) => folderId)).toEqual([null, 'f1'])
		expect(writes().filter((line) => line.endsWith('/share-save'))).toEqual([])
	})

	it('reads an attempt naming no passkey as one that stored nothing, never as every passkey for the RP ID', async () => {
		server({ 'POST /api/v1/secrets': () => json({ message: 'Locked' }, 423) })
		const id = await start()
		await window(id, { kind: 'passkey.save', target: { name: 'Example', folderId: null } })
		const ceremony = (await record())!
		await browser.storage.session.set({ [ceremonyKey(TAB)]: { ...ceremony, failed: { ...ceremony.failed!, attempt: { credentialIds: [], itemId: null } } } })
		server()
		expect(await window(id, { kind: 'passkey.retry' })).toEqual({ ok: true })
		expect(bodiesOf('POST /api/v1/secrets')).toHaveLength(2)
		expect(writes().filter((line) => line.endsWith('/share-save'))).toEqual([])
	})

	it('adds no second passkey when Retry follows a lost reply to a save to a login', async () => {
		server({
			'PUT /api/v1/secrets/work/share-save': lostReply(({ body }) => {
				rows = rows.map((row) => (row.id === 'work' ? { ...row, passkeys: (body!.patch as { passkeys: string }).passkeys, updatedAt: '2026-03-03T10:00:00+00:00' } : row))
			}),
		})
		const id = await start()
		expect(await window(id, { kind: 'passkey.save', target: { itemId: 'work' } })).toMatchObject({ code: 'write_failed', error: { code: 'offline' } })
		server()
		expect(await window(id, { kind: 'passkey.retry' })).toEqual({ ok: true })
		const bodies = bodiesOf('PUT /api/v1/secrets/work/share-save')
		const [lost, saved] = await Promise.all(bodies.map(async (body) => JSON.parse(await decrypt((body.patch as { passkeys: string }).passkeys))))
		expect(saved).toHaveLength(2)
		expect(saved[0]).toEqual(WORK[0])
		expect(saved[1].credentialId).not.toBe(lost[1].credentialId)
		expect(results.map((m) => attestationOf(m.result).id)).toEqual([saved[1].credentialId])
	})

	it('saves nothing on Retry while the sync that would find a lost save fails', async () => {
		server({ 'POST /api/v1/secrets': lostReply(() => {}) })
		const id = await start()
		await window(id, { kind: 'passkey.save', target: { name: 'Example', folderId: null } })
		server({ 'GET /api/v1/offline/manifest': () => Promise.reject(new TypeError('Failed to fetch')) })
		expect(await window(id, { kind: 'passkey.retry' })).toEqual({ ok: false, code: 'write_failed', error: { code: 'offline' } })
		expect(bodiesOf('POST /api/v1/secrets')).toHaveLength(1)
		expect(results).toEqual([])
	})

	it('drops the failure once a later save asks Replace or finds the login gone', async () => {
		server({ 'POST /api/v1/secrets': () => json({ message: 'Locked' }, 423) })
		const id = await start()
		await window(id, { kind: 'passkey.save', target: { name: 'Example', folderId: null } })
		expect(await stateOf(id)).toMatchObject({ failure: { code: 'write_locked' } })
		expect(await window(id, { kind: 'passkey.save', target: { itemId: 'personal' } })).toEqual({ ok: false, code: 'replace_required' })
		expect(await stateOf(id)).toMatchObject({ failure: null })
		expect(await window(id, { kind: 'passkey.retry' })).toEqual({ ok: false, code: 'stale' })
		await window(id, { kind: 'passkey.save', target: { name: 'Example', folderId: null } })
		expect(await window(id, { kind: 'passkey.save', target: { itemId: 'elsewhere' } })).toEqual({ ok: false, code: 'stale' })
		expect(await stateOf(id)).toMatchObject({ failure: null })
	})

	it('hides the failure while locked', async () => {
		server({ 'POST /api/v1/secrets': () => json({ message: 'Locked' }, 423) })
		const id = await start()
		await window(id, { kind: 'passkey.save', target: { name: 'Example', folderId: null } })
		await lock(accountId)
		expect(await stateOf(id)).toMatchObject({ locked: true, failure: null })
		expect(await window(id, { kind: 'passkey.retry' })).toEqual({ ok: false, code: 'locked' })
	})

	it('reports a fetch whose passkeys the cache does not hold as conflict, writing nothing', async () => {
		const swapped = { ...rows[0]!, passkeys: await encrypt(JSON.stringify(PERSONAL)) }
		server({ 'GET /api/v1/secrets/work': () => json(swapped) })
		const id = await start()
		expect(await window(id, { kind: 'passkey.save', target: { itemId: 'work' } })).toEqual({ ok: false, code: 'write_failed', error: { code: 'conflict' } })
		expect(writes()).toEqual([])
		expect(results).toEqual([])
	})

	it('passes on partner_upgrade_required for a partner that does not store passkeys', async () => {
		// Nothing the save never showed, so only the partner's missing field stops it.
		const empty = secretRow({ id: 'work', name: 'Work', url: 'https://example.test/login', key: await encrypt(''), login: null, additionalFields: null, passkeys: null })
		rows = [empty, ...rows.filter((row) => row.id !== 'work')]
		const at = new Date().toISOString()
		await writeSnapshot(accountId, { suite: { id: suiteRow.id, unlockKeyEpoch: 1 }, secrets: rows, folders: [folderRow()], types: typeRows, syncedAt: at, listsSyncedAt: at, newestUpdatedAt: null, total: rows.length })
		const BOB = { shareId: 'fs-bob', recipientCloudId: 'bob@cloud.partner.example' }
		server({
			'POST /api/v1/federation/recipient-certificate': ({ body }) => json({ cloudId: body!.cloudId, certificate: F.bob, chain: [F.intermediateA, F.rootA], partnerRootFingerprint: F.rootAFingerprint, valueFields: ['totp'] }),
			'PUT /api/v1/secrets/work/share-save': () => json({ error: 'incomplete', copies: { required: [], skipRefused: [] }, federated: { required: [BOB] } }, 409),
		})
		vi.mocked(verifyFederatedCertificate).mockResolvedValueOnce({ fingerprint: 'ab'.repeat(32), spki: extractSpki(decodePem(envelope.certificatePem).der) })
		const id = await start()
		expect(await window(id, { kind: 'passkey.save', target: { itemId: 'work' } })).toEqual({
			ok: false, code: 'write_failed', error: { code: 'partner_upgrade_required', partners: [{ shareId: 'fs-bob', cloudId: 'bob@cloud.partner.example' }], requiredVersion: '0.3.12' },
		})
		expect(bodiesOf('PUT /api/v1/secrets/work/share-save')).toHaveLength(1)
		expect(results).toEqual([])
	})
})

describe('refusals', () => {
	it.each([
		['a use-only copy', { itemId: 'team' }],
		['a read-only copy', { itemId: 'partner' }],
		['a login for another site', { itemId: 'elsewhere' }],
		['an item that is not a login', { itemId: 'note' }],
		['a login and a new one at once', { itemId: 'work', name: 'Example', folderId: null }],
		['a replace flag that is not a boolean', { itemId: 'personal', replace: 'yes' }],
		['a new login without a folder field', { name: 'Example' }],
		['no target', undefined],
	])('refuses %s as stale, writing nothing', async (_, target) => {
		const id = await start()
		expect(await window(id, { kind: 'passkey.save', target })).toEqual({ ok: false, code: 'stale' })
		expect(writes()).toEqual([])
		expect(results).toEqual([])
	})

	it('leaves a view-only copy out of the targets and refuses a save to it', async () => {
		const id = await start()
		expect((await stateOf(id)).targets.map((target) => target.itemId)).not.toContain('viewer')
		expect(await window(id, { kind: 'passkey.save', target: { itemId: 'viewer' } })).toEqual({ ok: false, code: 'write_failed', error: { code: 'view_only' } })
		expect(writes()).toEqual([])
	})

	it('asks to confirm first where the page requires verification', async () => {
		const id = await start(createRequest({ authenticatorSelection: { userVerification: 'required' } }))
		expect(await window(id, { kind: 'passkey.save', target: { name: 'Example', folderId: null } })).toEqual({ ok: false, code: 'verify_required' })
		expect(writes()).toEqual([])
	})

	it('refuses a save on a sign-in ceremony and a retry with nothing failed', async () => {
		const id = await start({ type: 'get', options: { challenge: CHALLENGE, rpId: 'example.test', allowCredentials: [] } })
		expect(await window(id, { kind: 'passkey.save', target: { name: 'Example', folderId: null } })).toEqual({ ok: false, code: 'stale' })
		expect(await window(id, { kind: 'passkey.retry' })).toEqual({ ok: false, code: 'stale' })
		expect(writes()).toEqual([])
	})

	it('saves once when the user clicks twice', async () => {
		const id = await start()
		const replies = await Promise.all([
			window(id, { kind: 'passkey.save', target: { name: 'Example', folderId: null } }),
			window(id, { kind: 'passkey.save', target: { name: 'Example', folderId: null } }),
		])
		expect(replies).toEqual([{ ok: true }, { ok: true }])
		expect(bodiesOf('POST /api/v1/secrets')).toHaveLength(1)
		expect(results).toHaveLength(1)
	})

	describe.each([
		{ kind: 'passkey.save', target: { itemId: 'work' } }, { kind: 'passkey.save', target: { name: 'Example', folderId: null } },
		{ kind: 'passkey.retry' }, { kind: 'passkey.state' }, { kind: 'passkey.cancel' },
	])('$kind on a create', (message) => {
		it.each([
			['the toolbar popup', windowOf({ tab: undefined })],
			['another tab', windowOf({ tab: { id: 999 } })],
			['the page’s own tab', windowOf({ tab: { id: TAB } })],
		])('is refused from %s, changing nothing', async (_, sender) => {
			server({ 'POST /api/v1/secrets': () => json({ message: 'Locked' }, 423) })
			const id = await start()
			await window(id, { kind: 'passkey.save', target: { name: 'Example', folderId: null } })
			const before = await record()
			fetchMock.mockClear()
			expect(await window(id, message, sender)).toEqual({ ok: false, code: 'ended' })
			expect(await window('other', message)).toEqual({ ok: false, code: 'ended' })
			await settle()
			expect(await record()).toEqual(before)
			expect(fetchMock).not.toHaveBeenCalled()
			expect(results).toEqual([])
		})
	})
})

describe('passkey.retry of a counter save', () => {
	it('signs with the saved counter once the server answers again', async () => {
		const COUNTED = [passkey('Yw', { counter: 5, privateKey: toBase64Url(new Uint8Array(await crypto.subtle.exportKey('pkcs8', (await crypto.subtle.generateKey({ name: 'ECDSA', namedCurve: 'P-256' }, true, ['sign'])).privateKey))) })]
		const counted = secretRow({ id: 'counted', name: 'Counted', url: 'https://example.test', key: await encrypt('pw'), passkeys: await encrypt(JSON.stringify(COUNTED)) })
		rows = [...rows, counted]
		const at = new Date().toISOString()
		await writeSnapshot(accountId, { suite: { id: suiteRow.id, unlockKeyEpoch: 1 }, secrets: rows, folders: [folderRow()], types: typeRows, syncedAt: at, listsSyncedAt: at, newestUpdatedAt: null, total: rows.length })
		server({ 'PUT /api/v1/secrets/counted/share-save': () => Promise.reject(new TypeError('Failed to fetch')) })
		const id = await start({ type: 'get', options: { challenge: CHALLENGE, rpId: 'example.test', allowCredentials: [] } })
		expect(await window(id, { kind: 'passkey.pick', itemId: 'counted', credentialId: 'Yw' })).toEqual({ ok: false, code: 'write_failed', error: { code: 'offline' } })
		expect(results).toEqual([])
		expect(await stateOf(id)).toMatchObject({ failure: { code: 'offline' } })
		server()
		expect(await window(id, { kind: 'passkey.retry' })).toEqual({ ok: true })
		const authData = fromBase64Url((attestationOf(results[0]!.result).response as { authenticatorData: string }).authenticatorData)
		expect(new DataView(authData.buffer).getUint32(33)).toBe(6)
	})
})

describe('readSaveRequest', () => {
	it('reads a login, a confirmed Replace and a new login', () => {
		expect(readSaveRequest({ itemId: 'a' })).toEqual({ itemId: 'a', replace: false })
		expect(readSaveRequest({ itemId: 'a', replace: true })).toEqual({ itemId: 'a', replace: true })
		expect(readSaveRequest({ name: 'n', folderId: null })).toEqual({ name: 'n', folderId: null })
	})

	it.each([
		['null', null],
		['a string', 'a'],
		['a login with a folder', { itemId: 'a', folderId: null }],
		['a login with a name', { itemId: 'a', name: 'n' }],
		['a login with a replace that is not a boolean', { itemId: 'a', replace: 1 }],
		['a login id that is not a string', { itemId: 1 }],
		['a new login with a replace', { name: 'n', folderId: null, replace: false }],
		['a new login with a folder that is not a string', { name: 'n', folderId: 1 }],
		['a new login whose name is not a string', { name: 1, folderId: null }],
	])('refuses %s', (_, target) => {
		expect(readSaveRequest(target)).toBeNull()
	})
})
