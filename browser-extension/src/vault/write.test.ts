import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import { addAccount, suiteKey } from '@/src/accounts/store'
import { importPrivateKey, pemToPkcs8, rsaDecrypt, toBase64, verifyFederatedCertificate } from '@/src/crypto'
import { decodePem, extractSpki } from '@/src/crypto/rsa'
import type { EditableItem, ItemDraft, WriteResult } from '@/src/messages'
import { blockedRow, encrypt, folderRow, secretRow, typeRows } from '@/src/testing/vault'
import { envelope, federationChain, suiteRow } from '@/src/testing/vectors'
import { customField, diffDraft, formSections, fromItem, toDraft, type FormValues } from './fields'
import { editOpenedKey, hasKey, putKey } from './key-store'
import { readSnapshot, writeSnapshot } from './store'
import { sync, syncStatus } from './sync'
import type { VaultSnapshot } from './types'
import { lock } from './unlock'
import { createFolder, createItem, deleteFolder, deleteItem, fetchItem, folderChildren, itemEditable, renameFolder, updateItem } from './write'

vi.mock('@/src/crypto', async (importOriginal) => {
	const actual = await importOriginal<typeof import('@/src/crypto')>()
	return { ...actual, rsaDecrypt: vi.fn(actual.rsaDecrypt), rsaEncrypt: vi.fn(actual.rsaEncrypt), verifyFederatedCertificate: vi.fn(actual.verifyFederatedCertificate) }
})

const fetchMock = vi.fn<typeof fetch>()
const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status })

interface Sent { method: string; path: string; body: Record<string, unknown> | undefined }

const SOURCE_AT = '2026-03-01T09:00:00+00:00'

/**
 * Routes by `METHOD /path`; the suites and manifest answer with the rows given, so post-write syncs succeed.
 * Unrouted, `write-context` names the item its own source and a share-save finds it shared with nobody.
 */
function server(routes: Record<string, (sent: Sent) => Response | Promise<Response>> = {}, secrets = [secretRow()], suite = suiteRow) {
	fetchMock.mockImplementation(async (input, init) => {
		const url = new URL(String(input))
		const sent: Sent = {
			method: init?.method ?? 'GET',
			path: url.pathname.replace('/index.php/apps/keepiq', '') + url.search,
			body: init?.body ? JSON.parse(String(init.body)) : undefined,
		}
		const route = routes[`${sent.method} ${sent.path}`]
		if (route) return route(sent)
		if (sent.path === '/api/v1/suites') return json([suite])
		if (sent.path === '/api/v1/offline/manifest') return json({ suite, secrets, folders: [folderRow()], types: typeRows, syncedAt: '' })
		const own = /^\/api\/v1\/secrets\/([^/]+)\/(write-context|share-save)$/.exec(sent.path)
		if (own?.[2] === 'write-context' && sent.method === 'GET') return json({ sourceSecretId: decodeURIComponent(own[1]!), effectiveGrade: 'owner', ownerCertificate: envelope.certificatePem, sourceUpdatedAt: SOURCE_AT })
		if (own?.[2] === 'share-save' && sent.method === 'PUT') return json({ secret: secretRow(), federated: { ownerOnly: false } })
		return json({ message: 'Not found' }, 404)
	})
}

const sent = () => fetchMock.mock.calls.map(([input, init]) => `${init?.method ?? 'GET'} ${new URL(String(input)).pathname.replace('/index.php/apps/keepiq', '')}${new URL(String(input)).search}`)
const writes = () => sent().filter((line) => !['GET /api/v1/offline/manifest', 'GET /api/v1/extension/policy', 'GET /api/settings/policy', 'GET /api/v1/suites', 'GET /api/health'].includes(line))
const bodiesOf = (line: string) => fetchMock.mock.calls.filter((_, i) => sent()[i] === line).map(([, init]) => JSON.parse(String(init!.body)) as Record<string, unknown>)
const bodyOf = (line: string) => bodiesOf(line)[0]!
const lastBodyOf = (line: string) => bodiesOf(line).at(-1)!

let accountId: string
let privateKey: CryptoKey
const decrypt = (ciphertext: unknown) => rsaDecrypt(ciphertext as string, privateKey)

async function snapshot(secrets: VaultSnapshot['secrets'] = [secretRow()]) {
	const at = new Date().toISOString()
	await writeSnapshot(accountId, {
		suite: { id: suiteRow.id, unlockKeyEpoch: 1 }, secrets, folders: [folderRow()], types: typeRows,
		syncedAt: at, listsSyncedAt: at, newestUpdatedAt: null, total: secrets.length,
	})
}

/** What an edit fetch hands its form to save with. */
const recordOf = (reply: WriteResult<EditableItem>) => (reply.ok ? reply.value.recordId : undefined)

/** The edit forms' records in session storage, oldest first. */
async function records(): Promise<Array<Record<string, unknown> & { id: string }>> {
	const list = (await browser.storage.session.get(editOpenedKey(accountId)))[editOpenedKey(accountId)] as string | undefined
	return JSON.parse(list ?? '[]') as Array<Record<string, unknown> & { id: string }>
}
const recordIds = async () => (await records()).map((entry) => entry.id)

const draft = (overrides: Partial<ItemDraft> = {}): ItemDraft => ({
	typeId: 't-login', name: 'GitHub', url: 'https://github.com', folderId: 'f1', key: 'hunter2', login: 'alice', additionalFields: {}, ...overrides,
})

beforeEach(async () => {
	fakeBrowser.reset()
	vi.stubGlobal('fetch', fetchMock)
	fetchMock.mockReset()
	vi.mocked(rsaDecrypt).mockClear()
	vi.spyOn(console, 'error').mockImplementation(() => {})
	accountId = (await addAccount({
		serverUrl: 'https://cloud.example.org', uid: 'alice', loginName: 'alice', displayName: 'Alice', email: null, avatarDataUrl: null, appPassword: 'pw', keepiqVersion: '0.3.12',
	}, { ...suiteRow })).id
	const pkcs8 = pemToPkcs8(envelope.privateKeyPem)
	privateKey = await importPrivateKey(pkcs8)
	await putKey(accountId, toBase64(pkcs8))
	await snapshot()
})

describe('createItem', () => {
	it('encrypts key, login and additional fields and sends the rest as plaintext', async () => {
		server({ 'POST /api/v1/secrets': () => json(secretRow({ id: 'new' }), 201) })
		expect(await createItem(draft({ additionalFields: { notes: 'Recovery codes' } }))).toEqual({ ok: true, value: { id: 'new' } })
		const body = bodyOf('POST /api/v1/secrets')
		expect(body).toMatchObject({ name: 'GitHub', url: 'https://github.com', typeId: 't-login', folderId: 'f1' })
		expect(await decrypt(body.key)).toBe('hunter2')
		expect(await decrypt(body.login)).toBe('alice')
		expect(JSON.parse(await decrypt(body.additionalFields))).toEqual({ notes: 'Recovery codes' })
		expect(JSON.stringify(body)).not.toContain('hunter2')
	})

	it('omits an empty username, nulls an empty URL and encrypts empty fields as {}', async () => {
		server({ 'POST /api/v1/secrets': () => json(secretRow({ id: 'new' }), 201) })
		await createItem(draft({ login: null, url: null }))
		const body = bodyOf('POST /api/v1/secrets')
		expect(body).not.toHaveProperty('login')
		expect(body.url).toBeNull()
		expect(await decrypt(body.additionalFields)).toBe('{}')
	})

	it('replies once a sync started after the write holds the new row', async () => {
		let release!: () => void
		const gate = new Promise<void>((resolve) => (release = resolve))
		const before = [secretRow()]
		const after = [secretRow(), secretRow({ id: 'new', name: 'New' })]
		let created = false
		fetchMock.mockImplementation(async (input, init) => {
			const path = new URL(String(input)).pathname.replace('/index.php/apps/keepiq', '')
			if (init?.method === 'POST') {
				created = true
				return json(secretRow({ id: 'new' }), 201)
			}
			if (path === '/api/v1/suites') return json([suiteRow])
			if (path === '/api/v1/offline/manifest') {
				const rows = created ? after : before
				if (!created) await gate
				return json({ suite: suiteRow, secrets: rows, folders: [], types: typeRows, syncedAt: '' })
			}
			return json({}, 404)
		})
		// A sync that read the vault before the write must not count as the one after it.
		const running = sync(accountId, 'full')
		const reply = createItem(draft())
		await vi.waitFor(() => expect(created).toBe(true))
		release()
		await running
		expect(await reply).toMatchObject({ ok: true })
		expect((await readSnapshot(accountId))?.secrets.map((row) => row.id)).toEqual(['s1', 'new'])
	})

	it('refuses a certificate that does not match the unlocked key, without a write', async () => {
		const { publicKey } = await crypto.subtle.generateKey({ name: 'RSA-OAEP', modulusLength: 2048, publicExponent: new Uint8Array([1, 0, 1]), hash: 'SHA-256' }, true, ['encrypt', 'decrypt'])
		const spki = toBase64(new Uint8Array(await crypto.subtle.exportKey('spki', publicKey)))
		// What a sync stores when the server swapped the certificate but kept the suite id and epoch.
		await browser.storage.local.set({ [suiteKey(accountId)]: { ...suiteRow, certificate: `-----BEGIN PUBLIC KEY-----\n${spki}\n-----END PUBLIC KEY-----` } })
		server({ 'POST /api/v1/secrets': () => json(secretRow({ id: 'new' }), 201) })
		vi.spyOn(console, 'error').mockImplementation(() => {})
		expect(await createItem(draft())).toEqual({ ok: false, error: { code: 'key_mismatch' } })
		expect(await updateItem('s1', { key: 'new' }, secretRow().updatedAt)).toEqual({ ok: false, error: { code: 'key_mismatch' } })
		// updateItem reads the grade before it encrypts.
		expect(writes()).toEqual(['GET /api/v1/secrets/s1/write-context'])
	})

	it('locks instead of encrypting to a key the server replaced since the last sync', async () => {
		const rotated = { ...suiteRow, id: 'suite-rotated' }
		server({ 'POST /api/v1/secrets': () => json(secretRow({ id: 'new' }), 201) }, [], rotated)
		expect(await createItem(draft())).toEqual({ ok: false, error: { code: 'locked' } })
		expect(writes()).toEqual([])
		expect(await hasKey(accountId)).toBe(false)
	})

	it('refuses while locked without a request', async () => {
		await lock(accountId)
		server()
		expect(await createItem(draft())).toEqual({ ok: false, error: { code: 'locked' } })
		expect(fetchMock).not.toHaveBeenCalled()
	})
})

describe('fetchItem', () => {
	/** The server answers with `row`, and the cache already holds it. */
	async function serve(row: ReturnType<typeof secretRow>) {
		await snapshot([row])
		server({ 'GET /api/v1/secrets/s1': () => json(row) }, [row])
	}

	it('opens the server row without a sync when the cache holds its ciphertext', async () => {
		const row = secretRow({ key: await encrypt('pw'), login: await encrypt('bob'), additionalFields: await encrypt('{"notes":"n","pin":1234}') })
		await serve(row)
		expect(await fetchItem('s1')).toEqual({
			ok: true,
			value: {
				id: 's1', typeId: 't-login', typeName: 'login', name: 'GitHub', url: 'https://github.com', folderId: null,
				key: 'pw', login: 'bob', additionalFields: { notes: 'n', pin: '1234' }, updatedAt: row.updatedAt, recordId: expect.any(String),
			},
		})
		expect(sent()).toEqual(['GET /api/v1/secrets/s1', 'GET /api/v1/secrets/s1/write-context'])
	})

	it('records the ciphertext it showed, its version and the source version for an edit, and reads no source version for a clone', async () => {
		const row = secretRow({ key: await encrypt('pw') })
		await serve(row)
		await fetchItem('s1')
		expect(await records()).toEqual([{ id: 's1', recordId: expect.any(String), shown: { key: row.key, login: null, additionalFields: null }, updatedAt: row.updatedAt, sourceUpdatedAt: SOURCE_AT }])
		fetchMock.mockClear()
		await fetchItem('s1', 'clone')
		expect(sent()).toEqual(['GET /api/v1/secrets/s1'])
	})

	it('names a new record for each edit form, and none for a clone', async () => {
		await serve(secretRow({ key: await encrypt('pw') }))
		const [first, second] = [recordOf(await fetchItem('s1')), recordOf(await fetchItem('s1'))]
		expect(first).toEqual(expect.any(String))
		expect(second).not.toBe(first)
		expect(recordOf(await fetchItem('s1', 'clone'))).toBeUndefined()
		expect(await recordIds()).toEqual(['s1', 's1'])
	})

	it('opens a newer row once the sync it starts brings that row into the cache', async () => {
		const fresh = secretRow({ key: await encrypt('new password'), updatedAt: '2026-10-12T12:00:00+00:00' })
		server({ 'GET /api/v1/secrets/s1': () => json(fresh) }, [fresh])
		expect(await fetchItem('s1')).toMatchObject({ ok: true, value: { key: 'new password', updatedAt: fresh.updatedAt } })
		expect(sent()).toContain('GET /api/v1/offline/manifest')
	})

	it.each(['edit', 'clone'] as const)('for %s, refuses ciphertext the cache still does not hold after a sync, without opening it', async (purpose) => {
		// It would not decrypt, so opening it would read as `unreadable`.
		server({ 'GET /api/v1/secrets/s1': () => json(secretRow({ key: 'not-ciphertext' })) })
		expect(await fetchItem('s1', purpose)).toEqual({ ok: false, error: { code: 'conflict' } })
		expect(sent().filter((line) => line === 'GET /api/v1/offline/manifest')).toHaveLength(1)
	})

	it.each([
		['does not answer', () => Promise.reject(new TypeError('Failed to fetch')), 'offline'],
		['answers 503', () => json({ message: 'Unavailable' }, 503), 'server_error'],
		['finds the vault write-locked', () => json({ message: 'Vault is locked for writing' }, 423), 'write_locked'],
	])('reports an outage, not a swapped item, when the sync it starts %s', async (_, manifest, code) => {
		// It would not decrypt, so opening it would read as `unreadable`.
		server({ 'GET /api/v1/secrets/s1': () => json(secretRow({ key: 'not-ciphertext' })), 'GET /api/v1/offline/manifest': manifest })
		expect(await fetchItem('s1')).toEqual({ ok: false, error: { code } })
		expect(await browser.storage.session.get(editOpenedKey(accountId))).toEqual({})
	})

	it('fails an edit whose source version does not read, keeping nothing', async () => {
		const row = secretRow({ key: await encrypt('pw') })
		await snapshot([row])
		server({ 'GET /api/v1/secrets/s1': () => json(row), 'GET /api/v1/secrets/s1/write-context': () => json({ message: 'Unavailable' }, 503) }, [row])
		expect(await fetchItem('s1')).toEqual({ ok: false, error: { code: 'server_error' } })
		expect(await recordIds()).toEqual([])
		expect(vi.mocked(rsaDecrypt)).not.toHaveBeenCalled()
	})

	it.each([
		['a team-folder read grade', 'read'],
		['a direct share without a grade', null],
	])('refuses to edit a copy on %s with view_only before decrypting, keeping nothing, and still clones it', async (_, effectiveGrade) => {
		const row = secretRow({ key: await encrypt('pw') })
		await snapshot([row])
		server({
			'GET /api/v1/secrets/s1': () => json(row),
			'GET /api/v1/secrets/s1/write-context': () => json({ sourceSecretId: 'src', effectiveGrade, ownerCertificate: null, sourceUpdatedAt: SOURCE_AT }),
		}, [row])
		expect(await fetchItem('s1')).toEqual({ ok: false, error: { code: 'view_only' } })
		expect(vi.mocked(rsaDecrypt)).not.toHaveBeenCalled()
		expect(await recordIds()).toEqual([])
		expect(await fetchItem('s1', 'clone')).toMatchObject({ ok: true, value: { key: 'pw' } })
	})

	it.each(['write', 'manage', 'owner'])('opens a copy on the %s grade for editing', async (effectiveGrade) => {
		const row = secretRow({ key: await encrypt('pw') })
		await snapshot([row])
		server({
			'GET /api/v1/secrets/s1': () => json(row),
			'GET /api/v1/secrets/s1/write-context': () => json({ sourceSecretId: 'src', effectiveGrade, ownerCertificate: null, sourceUpdatedAt: SOURCE_AT }),
		}, [row])
		expect(await fetchItem('s1')).toMatchObject({ ok: true, value: { key: 'pw', recordId: expect.any(String) } })
	})

	it.each([
		['the cache does not hold the item', [], { code: 'not_found' }],
		['the cache holds it blocked', [secretRow({ encryptionSuiteId: 'suite-0' })], { code: 'blocked', reason: 'suite_revoked' }],
	])('refuses a row when %s', async (_, manifest, error) => {
		await snapshot([])
		server({
			'GET /api/v1/secrets/s1': () => json(secretRow()),
			'GET /api/v1/suites': () => json([suiteRow, { ...suiteRow, id: 'suite-0', status: 'revoked' }]),
		}, manifest)
		expect(await fetchItem('s1')).toEqual({ ok: false, error })
	})

	it('never lets another item\'s values reach an edit or its save', async () => {
		const x = secretRow({ key: await encrypt('x-password'), additionalFields: await encrypt('{"pin":"0000"}') })
		const y = secretRow({ id: 's2', key: await encrypt('y-password'), additionalFields: await encrypt('{"recovery":"Y-RECOVERY-CODES","pin":"1234"}') })
		await snapshot([x, y])
		// Item Y's ciphertext under X's id.
		server({ 'GET /api/v1/secrets/s1': () => json({ ...x, key: y.key, additionalFields: y.additionalFields }) }, [x, y])
		const opened = await fetchItem('s1')
		expect(opened).toEqual({ ok: false, error: { code: 'conflict' } })
		expect(JSON.stringify(opened)).not.toContain('Y-RECOVERY-CODES')
		expect(writes()).toEqual(['GET /api/v1/secrets/s1'])
		expect(await recordIds()).toEqual([])
	})

	it('keeps what two forms opening at once each opened', async () => {
		const x = secretRow({ key: await encrypt('x-password') })
		const y = secretRow({ id: 's2', key: await encrypt('y-password') })
		await snapshot([x, y])
		server({ 'GET /api/v1/secrets/s1': () => json(x), 'GET /api/v1/secrets/s2': () => json(y) }, [x, y])
		const set = browser.storage.session.set.bind(browser.storage.session)
		// Slow writes, so the second form reads the list before the first writes it back.
		vi.spyOn(browser.storage.session, 'set').mockImplementation(async (values) => {
			await new Promise((resolve) => setTimeout(resolve, 50))
			return set(values)
		})
		await Promise.all([fetchItem('s1'), fetchItem('s2')])
		expect((await recordIds()).sort()).toEqual(['s1', 's2'])
	})

	it('keeps what the 10 newest edit forms opened, one entry per form', async () => {
		const rows = await Promise.all(Array.from({ length: 11 }, async (_, i) => secretRow({ id: `s${i + 1}`, key: await encrypt(`password ${i + 1}`) })))
		await snapshot(rows)
		server(Object.fromEntries(rows.map((row) => [`GET /api/v1/secrets/${row.id}`, () => json(row)])), rows)
		for (const row of [...rows, rows[5]!]) expect(await fetchItem(row.id)).toMatchObject({ ok: true })
		expect(await recordIds()).toEqual(['s3', 's4', 's5', 's6', 's7', 's8', 's9', 's10', 's11', 's6'])
	})

	it('keeps nothing when a lock lands while it writes', async () => {
		const x = secretRow({ key: await encrypt('x-password') })
		await snapshot([x])
		server({ 'GET /api/v1/secrets/s1': () => json(x) }, [x])
		const set = browser.storage.session.set.bind(browser.storage.session)
		vi.spyOn(browser.storage.session, 'set').mockImplementation(async (values) => {
			if (editOpenedKey(accountId) in values) await lock(accountId)
			return set(values)
		})
		await fetchItem('s1')
		expect(await browser.storage.session.get(editOpenedKey(accountId))).toEqual({})
	})

	it('keeps nothing for a form that does not open', async () => {
		await snapshot([secretRow({ key: 'not-ciphertext' })])
		server({ 'GET /api/v1/secrets/s1': () => json(secretRow({ key: 'not-ciphertext' })) }, [secretRow({ key: 'not-ciphertext' })])
		expect(await fetchItem('s1')).toEqual({ ok: false, error: { code: 'unreadable' } })
		expect(await recordIds()).toEqual([])
	})

	it('keeps the id it was asked for, which the form saves to', async () => {
		const row = secretRow({ key: await encrypt('pw') })
		await snapshot([row])
		server({ 'GET /api/v1/secrets/s1': () => json({ ...row, id: 's2' }) }, [row])
		expect(await fetchItem('s1')).toMatchObject({ ok: true, value: { id: 's1' } })
	})

	it('withholds a passkey credential', async () => {
		await serve(secretRow({ typeId: 't-passkey', key: await encrypt('{"privateKey":"secret"}') }))
		const reply = await fetchItem('s1')
		expect(reply).toMatchObject({ ok: true, value: { typeName: 'passkey', key: '' } })
	})

	it('refuses to clone a row either side calls a passkey, before decrypting', async () => {
		const passkey = secretRow({ typeId: 't-passkey', key: await encrypt('{"privateKey":"secret"}') })
		await snapshot([{ ...passkey, typeId: 't-login' }])
		server({ 'GET /api/v1/secrets/s1': () => json(passkey) }, [{ ...passkey, typeId: 't-login' }])
		expect(await fetchItem('s1', 'clone')).toEqual({ ok: false, error: { code: 'passkey_clone' } })
		expect(vi.mocked(rsaDecrypt)).not.toHaveBeenCalled()
	})

	it('withholds a passkey credential the server sends under another type', async () => {
		const passkey = secretRow({ typeId: 't-passkey', key: await encrypt('{"privateKey":"secret"}') })
		await snapshot([passkey])
		server({ 'GET /api/v1/secrets/s1': () => json({ ...passkey, typeId: 't-login' }) }, [passkey])
		// The passkey form, which offers no password a save would drop.
		expect(await fetchItem('s1')).toMatchObject({ ok: true, value: { typeId: 't-login', typeName: 'passkey', key: '' } })
	})

	it('syncs on a refusal and names a row the sync found blocked by its reason', async () => {
		server({
			'GET /api/v1/secrets/s1': () => json({ message: 'Encryption suite is revoked', error: 'forbidden' }, 428),
			'GET /api/v1/suites': () => json([suiteRow, { ...suiteRow, id: 'suite-0', status: 'revoked' }]),
		}, [secretRow({ encryptionSuiteId: 'suite-0' })])
		expect(await fetchItem('s1')).toEqual({ ok: false, error: { code: 'blocked', reason: 'suite_revoked' } })
		expect(await readSnapshot(accountId)).toMatchObject({ secrets: [{ id: 's1', blocked: true }] })
	})

	it('reports any other refusal as one', async () => {
		server({ 'GET /api/v1/secrets/s1': () => json({ message: 'Not yours', error: 'forbidden' }, 428) })
		expect(await fetchItem('s1')).toEqual({ ok: false, error: { code: 'forbidden' } })
	})

	it('reports a row that does not open', async () => {
		await serve(secretRow({ key: await encrypt('k'), additionalFields: await encrypt('[1]') }))
		expect(await fetchItem('s1')).toEqual({ ok: false, error: { code: 'unreadable' } })
	})

	it.each([
		['a use-only copy', { useOnly: true }, 'restricted', 'restricted'],
		['a read-only copy from another organisation', { readOnly: true }, 'restricted', 'restricted'],
		['an expiring copy', { accessExpiresAt: '2026-12-01T00:00:00+00:00' }, 'ok', 'restricted'],
	])('opens %s for editing and cloning only as its limits allow', async (_, limits, edit, clone) => {
		await serve(secretRow({ key: await encrypt('pw'), ...limits }))
		const outcome = async (purpose: 'edit' | 'clone') => {
			const reply = await fetchItem('s1', purpose)
			return reply.ok ? 'ok' : reply.error.code
		}
		expect(await outcome('edit')).toBe(edit)
		expect(await outcome('clone')).toBe(clone)
	})

	it('syncs when the item is gone', async () => {
		server({ 'GET /api/v1/secrets/s1': () => json({ message: 'Secret not found' }, 404) }, [])
		expect(await fetchItem('s1')).toEqual({ ok: false, error: { code: 'not_found' } })
		await vi.waitFor(async () => expect((await readSnapshot(accountId))?.secrets).toEqual([]))
	})
})

describe('updateItem', () => {
	const BASE = secretRow().updatedAt

	it('sends only the changed plaintext with the base version', async () => {
		server({ 'PUT /api/v1/secrets/s1': () => json(secretRow()) })
		expect(await updateItem('s1', { name: 'GitHub work' }, BASE)).toEqual({ ok: true, value: { id: 's1' } })
		expect(bodyOf('PUT /api/v1/secrets/s1')).toEqual({ name: 'GitHub work', baseUpdatedAt: BASE })
	})

	it('clears with null and encrypts what changed, with the base version beside the patch', async () => {
		server()
		await updateItem('s1', { login: null, url: null, folderId: null, key: 'new', additionalFields: { pin: '1' } }, BASE)
		const body = bodyOf('PUT /api/v1/secrets/s1/share-save')
		expect(body.baseUpdatedAt).toBe(BASE)
		const patch = body.patch as Record<string, unknown>
		expect(patch).toMatchObject({ login: null, url: null, folderId: null })
		expect(patch).not.toHaveProperty('baseUpdatedAt')
		expect(await decrypt(patch.key)).toBe('new')
		expect(await decrypt(patch.additionalFields)).toBe('{"pin":"1"}')
	})

	it('never writes a passkey credential', async () => {
		await snapshot([secretRow({ typeId: 't-passkey' })])
		server({ 'PUT /api/v1/secrets/s1': () => json(secretRow()) })
		await updateItem('s1', { name: 'Renamed', key: '{}' })
		expect(bodyOf('PUT /api/v1/secrets/s1')).toEqual({ name: 'Renamed' })
	})

	it('refuses an item the cache does not hold, since its type and limits are unknown', async () => {
		server({ 'PUT /api/v1/secrets/elsewhere': () => json(secretRow()) })
		expect(await updateItem('elsewhere', { key: '{}' }, BASE)).toEqual({ ok: false, error: { code: 'not_found' } })
		expect(writes()).toEqual([])
	})

	it('refuses a password, username or fields change without the version it was made from, before any request', async () => {
		server()
		expect(await updateItem('s1', { login: 'bob' })).toEqual({ ok: false, error: { code: 'invalid' } })
		expect(fetchMock).not.toHaveBeenCalled()
	})

	it('checks the suite once for all it encrypts', async () => {
		server()
		await updateItem('s1', { key: 'new', login: 'bob', additionalFields: { pin: '1' } }, BASE)
		expect(sent().filter((line) => line === 'GET /api/v1/suites')).toHaveLength(1)
	})

	it('refuses any change to a use-only copy, and all but filing for a read-only one', async () => {
		server({ 'PUT /api/v1/secrets/s1': () => json(secretRow()) })
		await snapshot([secretRow({ useOnly: true })])
		expect(await updateItem('s1', { folderId: 'f1' })).toEqual({ ok: false, error: { code: 'restricted' } })
		await snapshot([secretRow({ readOnly: true })])
		expect(await updateItem('s1', { name: 'Mine now' })).toEqual({ ok: false, error: { code: 'restricted' } })
		expect(writes()).toEqual([])
		expect(await updateItem('s1', { folderId: 'f1' })).toMatchObject({ ok: true })
		expect(bodyOf('PUT /api/v1/secrets/s1')).toEqual({ folderId: 'f1' })
	})

	it('keeps those limits on a copy whose suite is blocked', async () => {
		server({ 'PUT /api/v1/secrets/s1': () => json(secretRow()) })
		await snapshot([blockedRow({ id: 's1', useOnly: true })])
		expect(await updateItem('s1', { name: 'Mine now' })).toEqual({ ok: false, error: { code: 'restricted' } })
		await snapshot([blockedRow({ id: 's1', readOnly: true })])
		expect(await updateItem('s1', { name: 'Mine now' })).toEqual({ ok: false, error: { code: 'restricted' } })
		expect(await updateItem('s1', { key: 'new' }, BASE)).toEqual({ ok: false, error: { code: 'restricted' } })
		expect(writes()).toEqual([])
	})

	it('refuses a blank name', async () => {
		server()
		expect(await updateItem('s1', { name: ' ' })).toEqual({ ok: false, error: { code: 'invalid' } })
		expect(fetchMock).not.toHaveBeenCalled()
	})

	it('uses up its form\'s record on a save that changes no password, username or field', async () => {
		const row = secretRow({ key: await encrypt('pw') })
		await snapshot([row])
		server({ 'GET /api/v1/secrets/s1': () => json(row), 'PUT /api/v1/secrets/s1': () => json(row) }, [row])
		const form = recordOf(await fetchItem('s1'))
		expect(await recordIds()).toEqual(['s1'])
		expect(await updateItem('s1', { name: 'Renamed' }, row.updatedAt, undefined, form)).toEqual({ ok: true, value: { id: 's1' } })
		expect(await recordIds()).toEqual([])
	})
})

describe('updateItem through share-save', () => {
	const F = federationChain()
	const BASE = secretRow().updatedAt
	const BOB_COPY = { secretId: 'c-bob', holder: 'recipient', targetUserId: 'bob' }
	const BOB = { shareId: 'fs-bob', recipientCloudId: 'bob@cloud.partner.example' }
	const SHARE_SAVE = 'PUT /api/v1/secrets/s1/share-save'
	const isShareSaveLine = (line: string) => line.includes('write-context') || line.includes('share-save') || line.includes('certificate')

	interface Sharing { copies?: object[]; shares?: object[]; ownerOnly?: boolean }
	interface Lists { copies: Array<{ secretId: string }>; skipped: Array<{ secretId: string }>; federated?: Array<{ shareId: string }>; suspend?: Array<{ shareId: string }> }

	/** The certificate lookups and a share-save that requires `copies` and `shares`, answering `incomplete` until the lists name them all. */
	const shared = ({ copies = [], shares = [], ownerOnly = false }: Sharing = {}) => ({
		'POST /api/v1/shares/recipient-certificates': ({ body }: Sent) => json({ recipients: (body!.userIds as string[]).map((userId) => ({ userId, shareable: true, certificate: envelope.certificatePem })) }),
		'POST /api/v1/federation/recipient-certificate': ({ body }: Sent) => json({ cloudId: body!.cloudId, certificate: F.bob, chain: [F.intermediateA, F.rootA], partnerRootFingerprint: F.rootAFingerprint }),
		[SHARE_SAVE]: ({ body }: Sent) => {
			const lists = body as unknown as Lists
			const named = [...lists.copies, ...lists.skipped].length
			const partners = [...lists.federated ?? [], ...lists.suspend ?? []].length
			if (named !== copies.length || partners !== shares.length) return json({ error: 'incomplete', copies: { required: copies, skipRefused: [] }, federated: { required: shares } }, 409)
			return json({ secret: secretRow(), federated: { ownerOnly } })
		},
	})

	/** The partner gets ciphertext the test vectors' key opens, as if Bob's certificate held it. */
	const partnerOpens = () => vi.mocked(verifyFederatedCertificate).mockResolvedValueOnce({ fingerprint: 'ab'.repeat(32), spki: extractSpki(decodePem(envelope.certificatePem).der) })
	const partnerValue = async () => {
		const [entry] = lastBodyOf(SHARE_SAVE).federated as Array<Record<string, unknown>>
		return { key: await decrypt(entry!.key), login: entry!.login && await decrypt(entry!.login), additionalFields: entry!.additionalFields && await decrypt(entry!.additionalFields) }
	}

	it('saves a password change of an unshared item in one share-save, and replies once the vault sync holds it', async () => {
		server()
		expect(await updateItem('s1', { key: 'new' }, BASE)).toEqual({ ok: true, value: { id: 's1' } })
		const order = sent()
		expect(order.filter(isShareSaveLine)).toEqual(['GET /api/v1/secrets/s1/write-context', SHARE_SAVE])
		expect(order.indexOf(SHARE_SAVE)).toBeLessThan(order.lastIndexOf('GET /api/v1/offline/manifest'))
		expect(order).not.toContain('PUT /api/v1/secrets/s1')
	})

	it('saves Bob\'s copy in the same request as the row, with no plain PUT and no sync of copies', async () => {
		server(shared({ copies: [BOB_COPY] }))
		expect(await updateItem('s1', { key: 'new' }, BASE)).toEqual({ ok: true, value: { id: 's1' } })
		const [first, second] = bodiesOf(SHARE_SAVE)
		expect(first!.copies).toEqual([])
		expect(await decrypt((second!.copies as Array<{ key: string }>)[0]!.key)).toBe('new')
		expect(sent().filter((line) => line === 'PUT /api/v1/secrets/s1' || line.endsWith('/sync'))).toEqual([])
	})

	it.each([
		['a rename', { name: 'Home wifi' }],
		['a URL change', { url: 'https://example.org' }],
	])('saves %s with write-context and a plain PUT, and no share-save', async (_, patch) => {
		server({ ...shared({ copies: [BOB_COPY] }), 'PUT /api/v1/secrets/s1': () => json(secretRow()) })
		expect(await updateItem('s1', patch, BASE)).toEqual({ ok: true, value: { id: 's1' } })
		expect(sent().filter(isShareSaveLine)).toEqual(['GET /api/v1/secrets/s1/write-context'])
		expect(sent()).toContain('PUT /api/v1/secrets/s1')
	})

	it('saves a move with a plain PUT alone, reading no grade', async () => {
		server({ ...shared({ copies: [BOB_COPY] }), 'PUT /api/v1/secrets/s1': () => json(secretRow()) })
		expect(await updateItem('s1', { folderId: 'f1' }, BASE)).toEqual({ ok: true, value: { id: 's1' } })
		expect(sent().filter(isShareSaveLine)).toEqual([])
	})

	it('saves a passkey\'s name with a plain PUT, never its credential', async () => {
		await snapshot([secretRow({ typeId: 't-passkey' })])
		server({ ...shared({ copies: [BOB_COPY] }), 'PUT /api/v1/secrets/s1': () => json(secretRow()) })
		expect(await updateItem('s1', { name: 'Renamed', key: '{}' }, BASE)).toEqual({ ok: true, value: { id: 's1' } })
		expect(sent().filter(isShareSaveLine)).toEqual(['GET /api/v1/secrets/s1/write-context'])
	})

	it('replies with the note when the copy-editor path names partners only the owner reaches', async () => {
		server({
			...shared({ ownerOnly: true }),
			'GET /api/v1/secrets/s1/write-context': () => json({ sourceSecretId: 'src', effectiveGrade: 'write', ownerCertificate: envelope.certificatePem, sourceUpdatedAt: SOURCE_AT }),
		})
		expect(await updateItem('s1', { key: 'new' }, BASE)).toEqual({ ok: true, value: { id: 's1', shareSave: { ownerOnly: true } } })
	})

	it('saves a group member\'s change on the write grade for Alice\'s source and every other member\'s copy, reading write-context once', async () => {
		const sourceAndMembers = [{ secretId: 'src', holder: 'owner', targetUserId: null }, { secretId: 'c-carol', holder: 'recipient', targetUserId: 'carol' }, { secretId: 'c-dan', holder: 'recipient', targetUserId: 'dan' }]
		server({
			...shared({ copies: sourceAndMembers }),
			'GET /api/v1/secrets/s1/write-context': () => json({ sourceSecretId: 'src', effectiveGrade: 'write', ownerCertificate: envelope.certificatePem, sourceUpdatedAt: SOURCE_AT }),
		})
		expect(await updateItem('s1', { key: 'new' }, BASE)).toEqual({ ok: true, value: { id: 's1' } })
		const body = lastBodyOf(SHARE_SAVE)
		expect(body.sourceBaseUpdatedAt).toBe(SOURCE_AT)
		expect((body.copies as Array<{ secretId: string }>).map((copy) => copy.secretId)).toEqual(['src', 'c-carol', 'c-dan'])
		expect(body).not.toHaveProperty('federated')
		expect(sent().filter((line) => line.endsWith('/write-context'))).toHaveLength(1)
	})

	describe('a view-only copy', () => {
		const VIEW_ONLY = { sourceSecretId: 'src', effectiveGrade: 'read', ownerCertificate: envelope.certificatePem, sourceUpdatedAt: SOURCE_AT }

		it.each([
			['a new password', { key: 'new' }],
			['a rename', { name: 'Mine now' }],
			['a username and a folder', { login: 'carol', folderId: 'f1' }],
		])('refuses %s with view_only, sending nothing but write-context', async (_, patch) => {
			server({ 'GET /api/v1/secrets/s1/write-context': () => json(VIEW_ONLY), 'PUT /api/v1/secrets/s1': () => json(secretRow()) })
			expect(await updateItem('s1', patch, BASE)).toEqual({ ok: false, error: { code: 'view_only' } })
			expect(sent()).toEqual(['GET /api/v1/secrets/s1/write-context'])
		})

		it('refuses a save from a form that opened before the grade was lowered, keeping its record', async () => {
			const row = secretRow({ key: await encrypt('pw') })
			await snapshot([row])
			let grade = 'write'
			server({ 'GET /api/v1/secrets/s1': () => json(row), 'GET /api/v1/secrets/s1/write-context': () => json({ ...VIEW_ONLY, effectiveGrade: grade }) }, [row])
			const form = recordOf(await fetchItem('s1'))
			expect(form).toEqual(expect.any(String))
			grade = 'read'
			fetchMock.mockClear()
			expect(await updateItem('s1', { key: 'new' }, row.updatedAt, undefined, form)).toEqual({ ok: false, error: { code: 'view_only' } })
			expect(sent()).toEqual(['GET /api/v1/secrets/s1/write-context'])
			expect(await recordIds()).toEqual(['s1'])
		})

		it('files it with a PUT of only its folder', async () => {
			server({ 'GET /api/v1/secrets/s1/write-context': () => json(VIEW_ONLY), 'PUT /api/v1/secrets/s1': () => json(secretRow()) })
			expect(await updateItem('s1', { folderId: 'f-network' }, BASE)).toEqual({ ok: true, value: { id: 's1' } })
			expect(bodyOf('PUT /api/v1/secrets/s1')).toEqual({ folderId: 'f-network', baseUpdatedAt: BASE })
			expect(sent()).not.toContain('GET /api/v1/secrets/s1/write-context')
		})
	})

	it('saves through a copy with the source version its edit fetch read, not the one at save time', async () => {
		const row = secretRow({ key: await encrypt('pw') })
		await snapshot([row])
		let source = '2026-02-01T00:00:00+00:00'
		server({
			...shared({ copies: [{ secretId: 'src', holder: 'owner', targetUserId: null }] }),
			'GET /api/v1/secrets/s1': () => json(row),
			'GET /api/v1/secrets/s1/write-context': () => json({ sourceSecretId: 'src', effectiveGrade: 'write', ownerCertificate: envelope.certificatePem, sourceUpdatedAt: source }),
		}, [row])
		const form = recordOf(await fetchItem('s1'))
		source = '2026-03-03T00:00:00+00:00'
		await updateItem('s1', { key: 'new' }, row.updatedAt, undefined, form)
		expect(bodiesOf(SHARE_SAVE).map((body) => body.sourceBaseUpdatedAt)).toEqual(['2026-02-01T00:00:00+00:00', '2026-02-01T00:00:00+00:00'])
	})

	it('replies to a save that reached Bob and suspended a partner with the partner\'s cloud id only, no ciphertext, plaintext or certificate', async () => {
		const x = secretRow({ key: await encrypt('x-password') })
		await snapshot([x])
		server({
			...shared({ copies: [BOB_COPY], shares: [BOB] }),
			'GET /api/v1/secrets/s1': () => json(x),
			'POST /api/v1/federation/recipient-certificate': () => json({ message: 'unknown_recipient' }, 404),
		}, [x])
		const form = recordOf(await fetchItem('s1'))
		expect(await updateItem('s1', { key: 'new' }, x.updatedAt, undefined, form)).toEqual({ ok: true, value: { id: 's1', shareSave: {suspended: [BOB.recipientCloudId] } } })
		expect(lastBodyOf(SHARE_SAVE).copies).toHaveLength(1)
	})

	describe('a failed save', () => {
		let x: ReturnType<typeof secretRow>
		beforeEach(async () => {
			x = secretRow({ key: await encrypt('x-password') })
			await snapshot([x])
		})

		/** Opens the edit form against `routes` and saves a new password from it. */
		async function saveFrom(routes: Record<string, (sent: Sent) => Response | Promise<Response>>, withoutUnreachable?: string[]) {
			server({ ...shared({ copies: [BOB_COPY], shares: [BOB] }), 'GET /api/v1/secrets/s1': () => json(x), ...routes }, [x])
			const form = recordOf(await fetchItem('s1'))
			return updateItem('s1', { key: 'new' }, x.updatedAt, undefined, form, withoutUnreachable)
		}

		it.each([
			['a newer version', { [SHARE_SAVE]: () => json({ error: 'stale', message: 'Stale', current: secretRow() }, 409) }, { code: 'conflict' }],
			['a newer source', { [SHARE_SAVE]: () => json({ error: 'stale_source', message: 'Stale source' }, 409) }, { code: 'stale_source' }],
			['a share list that keeps changing', { [SHARE_SAVE]: () => json({ error: 'incomplete', copies: { required: [{ ...BOB_COPY, secretId: crypto.randomUUID() }], skipRefused: [] }, federated: { required: [] } }, 409) }, { code: 'incomplete' }],
			['a certificate that does not import', { 'POST /api/v1/shares/recipient-certificates': () => json({ recipients: [{ userId: 'bob', shareable: true, certificate: 'garbage' }] }) }, { code: 'bad_certificate' }],
			['an unreachable partner', { 'POST /api/v1/federation/recipient-certificate': () => json({ message: 'partner_unreachable' }, 502) }, { code: 'partner_unreachable', partners: [{ shareId: BOB.shareId, cloudId: BOB.recipientCloudId }] }],
			['the partner lookup limit', { 'POST /api/v1/federation/recipient-certificate': () => json({ message: 'Too many' }, 429) }, { code: 'partner_lookup_limit' }],
			['a Nextcloud too old for partner sharing', { 'POST /api/v1/federation/recipient-certificate': () => json({ message: 'federation_unavailable' }, 409) }, { code: 'federation_unavailable' }],
			['a server that stops answering', { [SHARE_SAVE]: () => Promise.reject(new TypeError('Failed to fetch')) }, { code: 'offline' }],
		])('reports %s, writes nothing more and keeps the form\'s record for the next save', async (_, routes, error) => {
			expect(await saveFrom(routes)).toEqual({ ok: false, error })
			expect(await recordIds()).toEqual(['s1'])
		})

		it('reports unverified for partners when the form\'s record is gone', async () => {
			server({ ...shared({ copies: [BOB_COPY], shares: [BOB] }) }, [x])
			expect(await updateItem('s1', { key: 'new' }, x.updatedAt)).toEqual({ ok: false, error: { code: 'unverified' } })
			expect(sent()).not.toContain('POST /api/v1/federation/recipient-certificate')
		})

		it('saves without an unreachable partner the user named, suspending its share', async () => {
			const unreachable = { 'POST /api/v1/federation/recipient-certificate': () => json({ message: 'partner_unreachable' }, 502) }
			expect(await saveFrom(unreachable, [BOB.shareId])).toEqual({ ok: true, value: { id: 's1', shareSave: {paused: [BOB.recipientCloudId] } } })
			expect(lastBodyOf(SHARE_SAVE).suspend).toEqual([{ shareId: BOB.shareId, reason: 'partner_unreachable' }])
			expect(await recordIds()).toEqual([])
		})

		it('suspends a partner on the server\'s own 502 bad_chain, without going offline', async () => {
			const badChain = { 'POST /api/v1/federation/recipient-certificate': () => json({ message: 'bad_chain' }, 502) }
			expect(await saveFrom(badChain)).toEqual({ ok: true, value: { id: 's1', shareSave: {suspended: [BOB.recipientCloudId] } } })
			expect(lastBodyOf(SHARE_SAVE).suspend).toEqual([{ shareId: BOB.shareId, reason: 'bad_chain' }])
			expect(await syncStatus(accountId)).toMatchObject({ offline: false })
		})

		it('fails a lookup answer not in the server\'s shape without suspending or going offline', async () => {
			const unshaped = { 'POST /api/v1/federation/recipient-certificate': () => json({ cloudId: BOB.recipientCloudId, chain: [] }) }
			expect(await saveFrom(unshaped)).toEqual({ ok: false, error: { code: 'server_error' } })
			expect(bodiesOf(SHARE_SAVE)).toHaveLength(1)
			expect(await syncStatus(accountId)).toMatchObject({ offline: false })
		})
	})

	describe('with a partner share', () => {
		let x: ReturnType<typeof secretRow>

		beforeEach(async () => {
			x = secretRow({ key: await encrypt('x-password'), login: await encrypt('alice'), additionalFields: await encrypt('{"notes":"X notes"}') })
			await snapshot([x])
		})

		/** The edit form's fetch, which the cache holds; returns the record its save names. */
		async function openForm() {
			server({ ...shared({ copies: [BOB_COPY], shares: [BOB] }), 'GET /api/v1/secrets/s1': () => json(x) }, [x])
			const opened = await fetchItem('s1')
			expect(opened).toMatchObject({ ok: true, value: { additionalFields: { notes: 'X notes' } } })
			return recordOf(opened)
		}

		it('sends the partner the whole value as the edit form opened it, and uses up the form\'s record', async () => {
			const form = await openForm()
			partnerOpens()
			expect(await updateItem('s1', { key: 'new' }, x.updatedAt, undefined, form)).toEqual({ ok: true, value: { id: 's1' } })
			expect(await partnerValue()).toEqual({ key: 'new', login: 'alice', additionalFields: '{"notes":"X notes"}' })
			// A second save from the same form, whose record the first used up.
			expect(await updateItem('s1', { key: 'newer' }, x.updatedAt, undefined, form)).toEqual({ ok: false, error: { code: 'unverified' } })
		})

		it.each([
			['another record', 'made-up'],
			['no record', undefined],
		])('fails partners as unverified on a save naming %s, though a form of the item opened', async (_, named) => {
			await openForm()
			expect(await updateItem('s1', { key: 'new' }, x.updatedAt, undefined, named)).toEqual({ ok: false, error: { code: 'unverified' } })
			expect(sent()).not.toContain('POST /api/v1/federation/recipient-certificate')
		})

		it('sends each of two forms of the item, opened at two versions, what it showed', async () => {
			const first = await openForm()
			const x2 = { ...x, additionalFields: await encrypt('{"notes":"X2 notes"}'), updatedAt: '2026-03-02T11:00:00+00:00' }
			await snapshot([x2])
			server({ ...shared({ copies: [BOB_COPY], shares: [BOB] }), 'GET /api/v1/secrets/s1': () => json(x2) }, [x2])
			const second = recordOf(await fetchItem('s1'))
			partnerOpens()
			expect(await updateItem('s1', { key: 'new' }, x2.updatedAt, undefined, second)).toEqual({ ok: true, value: { id: 's1' } })
			expect((await partnerValue()).additionalFields).toBe('{"notes":"X2 notes"}')
			partnerOpens()
			expect(await updateItem('s1', { key: 'newer' }, x.updatedAt, undefined, first)).toEqual({ ok: true, value: { id: 's1' } })
			expect(await partnerValue()).toEqual({ key: 'newer', login: 'alice', additionalFields: '{"notes":"X notes"}' })
		})

		it('keeps what the form opened across a worker restart', async () => {
			const form = await openForm()
			vi.resetModules()
			const restarted = await import('./write')
			expect(await restarted.updateItem('s1', { key: 'new' }, x.updatedAt, undefined, form)).toEqual({ ok: true, value: { id: 's1' } })
		})

		it('sends the partner what the form opened, never another item\'s value a sync put in the cache since', async () => {
			const y = secretRow({ id: 's2', additionalFields: await encrypt('{"recovery":"Y-RECOVERY-CODES"}') })
			const swapped = { ...x, additionalFields: y.additionalFields }
			await snapshot([x, y])
			const form = await openForm()
			server({ ...shared({ copies: [BOB_COPY], shares: [BOB] }) }, [swapped, y])
			await sync(accountId)
			expect((await readSnapshot(accountId))?.secrets[0]).toMatchObject({ additionalFields: y.additionalFields })
			partnerOpens()
			expect(await updateItem('s1', { key: 'new' }, x.updatedAt, undefined, form)).toMatchObject({ ok: true })
			expect((await partnerValue()).additionalFields).toBe('{"notes":"X notes"}')
			expect(Object.keys((lastBodyOf(SHARE_SAVE).copies as object[])[0]!).sort()).toEqual(['key', 'secretId'])
		})

		describe('by what the edit form shows', () => {
			const card = '{"number":"4111","expiry":"12/29","cvv":"","pin":"","cardholder":"Alice"}'
			const identity = '{"firstName":"Alice","lastName":"Doe","address":"","phone":"","email":"","bsn":""}'
			type Plain = { key: string; login?: string; additionalFields?: string }

			/** Item `s1` of `type`, which a sync cached and the server serves alike. */
			async function serveItem(type: string, plain: Plain) {
				const row = secretRow({
					typeId: `t-${type}`, key: await encrypt(plain.key), login: plain.login ? await encrypt(plain.login) : null,
					additionalFields: plain.additionalFields ? await encrypt(plain.additionalFields) : null,
				})
				await snapshot([row])
				server({ ...shared({ copies: [BOB_COPY], shares: [BOB] }), 'GET /api/v1/secrets/s1': () => json(row) }, [row])
				return row
			}

			/** Opens the item as the popup does and saves what `edit` changes, through the form's own functions. */
			async function editInForm(type: string, edit: (values: FormValues) => Partial<FormValues>, baseUpdatedAt: string) {
				const opened = await fetchItem('s1')
				if (!opened.ok) throw new Error(opened.error.code)
				// The form renders by the type the fetch vouched under.
				expect(opened.value.typeName).toBe(type)
				const values = fromItem(opened.value, type)
				const after = { ...values, ...edit(values) }
				const patch = diffDraft(toDraft(values, type), toDraft(after, type))
				expect(Object.keys(patch).length).toBeGreaterThan(0)
				return { values: after, result: await updateItem('s1', patch, baseUpdatedAt, undefined, recordOf(opened)) }
			}

			const noteText = (values: FormValues) => ({ secret: `${values.secret} edited` })
			const notes = () => ({ notes: 'edited' })

			it.each([
				['a card member the form has no field for', 'card', { key: card.replace('}', ',"recovery":"Y-BANK-PASSWORD"}') }, notes],
				['a duplicate card member', 'card', { key: card.replace('"cvv":""', '"cvv":"Y-BANK-PASSWORD","cvv":""') }, notes],
				['a duplicate additional field', 'login', { key: 'x-password', additionalFields: '{"pin":"Y-BANK-PASSWORD","pin":"1234"}' }, () => ({ secret: 'new' })],
			] as const)('saves nothing for %s, before any partner lookup', async (_, type, plain, edit) => {
				const row = await serveItem(type, plain)
				expect((await editInForm(type, edit, row.updatedAt)).result).toEqual({ ok: false, error: { code: 'unverified' } })
				expect(sent()).not.toContain('POST /api/v1/federation/recipient-certificate')
				expect(bodiesOf(SHARE_SAVE)).toHaveLength(1)
			})

			it.each([
				['a note without a username', 'note', { key: 'Wifi: hunter2', additionalFields: '{}' }, noteText],
				['a note with its username and notes, which the form shows', 'note', { key: 'Wifi: hunter2', login: 'alice', additionalFields: '{"notes":"Old"}' }, noteText],
				['a TOTP item with its username', 'totp', { key: 'JBSWY3DPEHPK3PXP', login: 'alice' }, notes],
				['a card whose members the form shows', 'card', { key: card, login: 'alice', additionalFields: '{"notes":"Old"}' }, notes],
				['a card with members missing or a number, sent as it was', 'card', { key: '{"number":4111,"cardholder":"Alice"}' }, notes],
				['a card key that is not JSON, which the form shows as one secret', 'card', { key: 'legacy card text' }, notes],
				['an identity whose members the form shows', 'identity', { key: identity }, notes],
				['an identity key that is a JSON array, which the form shows as one secret', 'identity', { key: '["Alice"]' }, notes],
				['a login with its username', 'login', { key: 'x-password', login: 'alice', additionalFields: '{"pin":"1234"}' }, () => ({ secret: 'new' })],
			] as const)('sends the partner %s', async (_, type, plain, edit) => {
				const row = await serveItem(type, plain)
				partnerOpens()
				const { result } = await editInForm(type, edit, row.updatedAt)
				expect(result).toEqual({ ok: true, value: { id: 's1' } })
				// A key the save left alone goes to partners as the edit fetch recorded it, never re-serialized.
				if (edit === notes) {
					expect((await partnerValue()).key).toBe(plain.key)
					expect(Object.keys((lastBodyOf(SHARE_SAVE).copies as object[])[0]!).sort()).toEqual(['additionalFields', 'secretId'])
				}
			})

			it('counts a field shown in full only when the cached type shows it so too, not just the type the server sends', async () => {
				const cached = secretRow({ typeId: 't-card', key: await encrypt(card.replace('}', ',"recovery":"Y-BANK-PASSWORD"}')) })
				await snapshot([cached])
				server({ ...shared({ copies: [BOB_COPY], shares: [BOB] }), 'GET /api/v1/secrets/s1': () => json({ ...cached, typeId: 't-login' }) }, [cached])
				expect((await editInForm('login', notes, cached.updatedAt)).result).toEqual({ ok: false, error: { code: 'unverified' } })
				expect(sent()).not.toContain('POST /api/v1/federation/recipient-certificate')
			})

			it('sends a note\'s notes member because the form shows it', async () => {
				// Item Y's notes under note X, in the cache too, so the edit fetch opens them.
				const row = await serveItem('note', { key: 'Wifi: hunter2', additionalFields: '{"notes":"Y-RECOVERY-CODES"}' })
				const { values, result } = await editInForm('note', (opened) => ({ fields: [...opened.fields, customField('pin', '1')] }), row.updatedAt)
				expect(result).toMatchObject({ ok: true })
				const shown: Record<string, string> = Object.fromEntries(values.fields.map((field) => [field.name, field.value]))
				if (formSections('note', values).notes) shown[values.notesKey ?? 'notes'] = values.notes
				const copy = (lastBodyOf(SHARE_SAVE).copies as Array<Record<string, string>>)[0]!
				expect(JSON.parse(await decrypt(copy.additionalFields))).toEqual(shown)
				expect(shown).toMatchObject({ notes: 'Y-RECOVERY-CODES' })
			})
		})

		describe('when session storage refuses a write', () => {
			/** Fails each `storage.session.set` that writes `key`, as a full store does. */
			function refuse(key: string) {
				const set = browser.storage.session.set.bind(browser.storage.session)
				vi.spyOn(browser.storage.session, 'set').mockImplementation(async (values) => {
					if (key in values) throw new Error('QUOTA_BYTES quota exceeded')
					return set(values)
				})
			}

			it('still reports the save as one and syncs when it cannot drop what the form opened', async () => {
				const form = await openForm()
				refuse(editOpenedKey(accountId))
				expect(await updateItem('s1', { key: 'new' }, x.updatedAt, undefined, form)).toEqual({ ok: true, value: { id: 's1' } })
				expect(sent().lastIndexOf('GET /api/v1/offline/manifest')).toBeGreaterThan(sent().lastIndexOf(SHARE_SAVE))
			})

			it('opens the form without a record, so its save fails partners as unverified', async () => {
				server({ ...shared({ copies: [BOB_COPY], shares: [BOB] }), 'GET /api/v1/secrets/s1': () => json(x) }, [x])
				refuse(editOpenedKey(accountId))
				const opened = await fetchItem('s1')
				expect(opened).toMatchObject({ ok: true, value: { key: 'x-password' } })
				expect(await updateItem('s1', { key: 'new' }, x.updatedAt, undefined, recordOf(opened))).toEqual({ ok: false, error: { code: 'unverified' } })
			})
		})
	})

	it('refuses for another account or a locked vault before any request', async () => {
		server()
		expect(await updateItem('s1', { key: 'new' }, BASE, 'another')).toEqual({ ok: false, error: { code: 'account_changed' } })
		await lock(accountId)
		expect(await updateItem('s1', { key: 'new' }, BASE, accountId)).toEqual({ ok: false, error: { code: 'locked' } })
		expect(fetchMock).not.toHaveBeenCalled()
	})
})

describe('itemEditable', () => {
	const grade = (sourceSecretId: string, effectiveGrade: string | null) => server({ 'GET /api/v1/secrets/s1/write-context': () => json({ sourceSecretId, effectiveGrade, ownerCertificate: null, sourceUpdatedAt: SOURCE_AT }) })

	it.each([
		['a copy on a team-folder read grade', 'src', 'read', false],
		['a copy shared directly without a grade', 'src', null, false],
		['a copy on the write grade', 'src', 'write', true],
		['a copy on the manage grade', 'src', 'manage', true],
		['a delegate\'s copy on the owner grade', 'src', 'owner', true],
		['the source', 's1', 'owner', true],
	])('answers %s from write-context alone, without a sync', async (_, sourceSecretId, effectiveGrade, editable) => {
		grade(sourceSecretId, effectiveGrade)
		expect(await itemEditable('s1')).toEqual({ ok: true, value: { editable } })
		expect(sent()).toEqual(['GET /api/v1/secrets/s1/write-context'])
	})

	it('fails as a write does when write-context does not read', async () => {
		server({ 'GET /api/v1/secrets/s1/write-context': () => json({ message: 'Unavailable' }, 503) })
		expect(await itemEditable('s1')).toEqual({ ok: false, error: { code: 'server_error' } })
	})

	it('refuses for another account or a locked vault before any request', async () => {
		grade('src', 'read')
		expect(await itemEditable('s1', 'another')).toEqual({ ok: false, error: { code: 'account_changed' } })
		await lock(accountId)
		expect(await itemEditable('s1', accountId)).toEqual({ ok: false, error: { code: 'locked' } })
		expect(fetchMock).not.toHaveBeenCalled()
	})
})

describe('errors and the offline flag', () => {
	it.each([
		[423, 'write_locked'], [403, 'forbidden'], [428, 'forbidden'], [400, 'invalid'], [409, 'conflict'], [404, 'not_found'], [503, 'server_error'],
	])('maps %i to %s', async (status, code) => {
		server({ 'DELETE /api/v1/secrets/s1': () => json({ message: 'English text' }, status) })
		expect(await deleteItem('s1')).toEqual({ ok: false, error: { code } })
	})

	it.each([
		['a 403 with a code', 403, { message: 'Blocked by policy', code: 'org_ownership_required' }, 'policy'],
		['a 428 naming a policy', 428, { message: 'Blocked by policy', code: 'org_ownership_required', error: 'org_ownership_required' }, 'policy'],
		['a 428 naming none', 428, { message: 'Forbidden', error: 'forbidden' }, 'forbidden'],
	])('tells a policy refusal from any other refusal: %s', async (_, status, body, code) => {
		server({ 'PUT /api/v1/folders/f1': () => json(body, status) })
		expect(await renameFolder('f1', 'Office')).toEqual({ ok: false, error: { code } })
	})

	it('reads a 428 on saving an item as a refusal', async () => {
		server({
			'POST /api/v1/secrets': () => json({ message: 'Blocked by policy', code: 'org_ownership_required', error: 'org_ownership_required' }, 428),
			'PUT /api/v1/secrets/s1': () => json({ message: 'Read-only copy', error: 'forbidden' }, 428),
		})
		expect(await createItem(draft())).toEqual({ ok: false, error: { code: 'policy' } })
		expect(await updateItem('s1', { name: 'Renamed' })).toEqual({ ok: false, error: { code: 'forbidden' } })
	})

	it('refuses a write for an account that is no longer the active one', async () => {
		server()
		expect(await createItem(draft(), 'another')).toEqual({ ok: false, error: { code: 'account_changed' } })
		expect(fetchMock).not.toHaveBeenCalled()
	})

	it('goes offline when the server does not answer, and back when it does', async () => {
		fetchMock.mockRejectedValue(new TypeError('Failed to fetch'))
		expect(await deleteItem('s1')).toEqual({ ok: false, error: { code: 'offline' } })
		expect(await syncStatus(accountId)).toMatchObject({ offline: true })
		server({ 'DELETE /api/v1/secrets/s1': () => json({ status: 'deleted' }) }, [])
		expect(await deleteItem('s1')).toEqual({ ok: true, value: null })
		expect(await syncStatus(accountId)).toMatchObject({ offline: false })
		expect((await readSnapshot(accountId))?.secrets).toEqual([])
	})

	it('stays offline after a refusal that never reached the server', async () => {
		fetchMock.mockRejectedValue(new TypeError('Failed to fetch'))
		await deleteItem('s1')
		expect(await createFolder('Work/Clients', null)).toEqual({ ok: false, error: { code: 'invalid' } })
		expect(await syncStatus(accountId)).toMatchObject({ offline: true })
	})
})

describe('folders', () => {
	it('creates a trimmed folder under its parent', async () => {
		server({ 'POST /api/v1/folders': () => json(folderRow({ id: 'f2' }), 201) })
		expect(await createFolder(' Banking ', 'f1')).toEqual({ ok: true, value: { id: 'f2' } })
		expect(bodyOf('POST /api/v1/folders')).toEqual({ name: 'Banking', parentId: 'f1' })
	})

	it('refuses a blank name or a slash without a request', async () => {
		server()
		expect(await createFolder('Work/Clients', null)).toEqual({ ok: false, error: { code: 'invalid' } })
		expect(await renameFolder('f1', '  ')).toEqual({ ok: false, error: { code: 'invalid' } })
		expect(fetchMock).not.toHaveBeenCalled()
	})

	it('renames with only the name', async () => {
		server({ 'PUT /api/v1/folders/f1': () => json(folderRow({ name: 'Office' })) })
		await renameFolder('f1', 'Office')
		expect(bodyOf('PUT /api/v1/folders/f1')).toEqual({ name: 'Office' })
	})

	it('reads the children without syncing', async () => {
		const children = { directSecretCount: 2, subfolders: [{ id: 'c', name: 'Clients', secretCount: 5, subfolderCount: 1 }] }
		server({ 'GET /api/v1/folders/f1/children': () => json(children) })
		expect(await folderChildren('f1')).toEqual({ ok: true, value: children })
		expect(sent()).toEqual(['GET /api/v1/folders/f1/children'])
	})

	it('deletes in each of the three modes', async () => {
		const deleted = () => json({ status: 'deleted' })
		server({
			'DELETE /api/v1/folders/f1': deleted,
			'DELETE /api/v1/folders/f1?cascade=move': deleted,
			'DELETE /api/v1/folders/f1?cascade=delete': deleted,
		})
		await deleteFolder('f1', { mode: 'plain' })
		await deleteFolder('f1', { mode: 'cascade', cascade: 'move' })
		await deleteFolder('f1', { mode: 'cascade', cascade: 'delete' })
		await deleteFolder('f1', { mode: 'resolution', directSecrets: 'move', subfolders: { c: 'keep' } })
		expect(writes()).toEqual(['DELETE /api/v1/folders/f1', 'DELETE /api/v1/folders/f1?cascade=move', 'DELETE /api/v1/folders/f1?cascade=delete', 'DELETE /api/v1/folders/f1'])
		const plans = fetchMock.mock.calls.filter(([, init]) => init?.method === 'DELETE').map(([, init]) => init?.body)
		expect(plans).toEqual([undefined, undefined, undefined, JSON.stringify({ directSecrets: 'move', subfolders: { c: 'keep' } })])
	})
})
