import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import { addAccount, suiteKey } from '@/src/accounts/store'
import { importPrivateKey, pemToPkcs8, rsaDecrypt, toBase64 } from '@/src/crypto'
import type { ItemDraft } from '@/src/messages'
import { blockedRow, encrypt, folderRow, secretRow, typeRows } from '@/src/testing/vault'
import { envelope, suiteRow } from '@/src/testing/vectors'
import { hasKey, putKey } from './key-store'
import { readSnapshot, writeSnapshot } from './store'
import { sync, syncStatus } from './sync'
import type { VaultSnapshot } from './types'
import { lock } from './unlock'
import { createFolder, createItem, deleteFolder, deleteItem, fetchItem, folderChildren, renameFolder, updateItem } from './write'

const fetchMock = vi.fn<typeof fetch>()
const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status })

interface Sent { method: string; path: string; body: Record<string, unknown> | undefined }

/** Routes by `METHOD /path`; the suites and manifest answer with the rows given, so post-write syncs succeed. */
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
		return json({ message: 'Not found' }, 404)
	})
}

const sent = () => fetchMock.mock.calls.map(([input, init]) => `${init?.method ?? 'GET'} ${new URL(String(input)).pathname.replace('/index.php/apps/keepiq', '')}${new URL(String(input)).search}`)
const writes = () => sent().filter((line) => !['GET /api/v1/offline/manifest', 'GET /api/settings/user', 'GET /api/v1/suites'].includes(line))
const bodyOf = (line: string) => {
	const index = sent().indexOf(line)
	return JSON.parse(String(fetchMock.mock.calls[index]![1]!.body)) as Record<string, unknown>
}

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

const draft = (overrides: Partial<ItemDraft> = {}): ItemDraft => ({
	typeId: 't-login', name: 'GitHub', url: 'https://github.com', folderId: 'f1', key: 'hunter2', login: 'alice', additionalFields: {}, ...overrides,
})

beforeEach(async () => {
	fakeBrowser.reset()
	vi.stubGlobal('fetch', fetchMock)
	fetchMock.mockReset()
	vi.spyOn(console, 'error').mockImplementation(() => {})
	accountId = (await addAccount({
		serverUrl: 'https://cloud.example.org', uid: 'alice', loginName: 'alice', displayName: 'Alice', email: null, avatarDataUrl: null, appPassword: 'pw',
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

	it('refuses a certificate that does not match the unlocked key, without a request', async () => {
		const { publicKey } = await crypto.subtle.generateKey({ name: 'RSA-OAEP', modulusLength: 2048, publicExponent: new Uint8Array([1, 0, 1]), hash: 'SHA-256' }, true, ['encrypt', 'decrypt'])
		const spki = toBase64(new Uint8Array(await crypto.subtle.exportKey('spki', publicKey)))
		// What a sync stores when the server swapped the certificate but kept the suite id and epoch.
		await browser.storage.local.set({ [suiteKey(accountId)]: { ...suiteRow, certificate: `-----BEGIN PUBLIC KEY-----\n${spki}\n-----END PUBLIC KEY-----` } })
		server({ 'POST /api/v1/secrets': () => json(secretRow({ id: 'new' }), 201) })
		vi.spyOn(console, 'error').mockImplementation(() => {})
		expect(await createItem(draft())).toEqual({ ok: false, error: { code: 'key_mismatch' } })
		expect(await updateItem('s1', { key: 'new' })).toEqual({ ok: false, error: { code: 'key_mismatch' } })
		expect(writes()).toEqual([])
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
	it('decrypts the server row, not the cache', async () => {
		const fresh = secretRow({ key: await encrypt('new password'), login: await encrypt('bob'), additionalFields: await encrypt('{"notes":"n","pin":1234}') })
		server({ 'GET /api/v1/secrets/s1': () => json(fresh) })
		expect(await fetchItem('s1')).toEqual({
			ok: true,
			value: {
				id: 's1', typeId: 't-login', name: 'GitHub', url: 'https://github.com', folderId: null,
				key: 'new password', login: 'bob', additionalFields: { notes: 'n', pin: '1234' }, updatedAt: fresh.updatedAt,
			},
		})
	})

	it('withholds a passkey credential', async () => {
		server({ 'GET /api/v1/secrets/s1': async () => json(secretRow({ typeId: 't-passkey', key: await encrypt('{"privateKey":"secret"}') })) })
		const reply = await fetchItem('s1')
		expect(reply).toMatchObject({ ok: true, value: { key: '', keyWithheld: true } })
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
		server({ 'GET /api/v1/secrets/s1': async () => json(secretRow({ key: await encrypt('k'), additionalFields: await encrypt('[1]') })) })
		expect(await fetchItem('s1')).toEqual({ ok: false, error: { code: 'unreadable' } })
	})

	it.each([
		['a use-only copy', { useOnly: true }, 'restricted', 'restricted'],
		['a read-only copy from another organisation', { readOnly: true }, 'restricted', 'restricted'],
		['an expiring copy', { accessExpiresAt: '2026-12-01T00:00:00+00:00' }, 'ok', 'restricted'],
	])('opens %s for editing and cloning only as its limits allow', async (_, limits, edit, clone) => {
		server({ 'GET /api/v1/secrets/s1': async () => json(secretRow({ key: await encrypt('pw'), ...limits })) })
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
	it('sends only the changed plaintext with the base version', async () => {
		server({ 'PUT /api/v1/secrets/s1': () => json(secretRow()) })
		expect(await updateItem('s1', { name: 'GitHub work' }, '2026-03-02T10:00:00+00:00')).toEqual({ ok: true, value: { id: 's1' } })
		expect(bodyOf('PUT /api/v1/secrets/s1')).toEqual({ name: 'GitHub work', baseUpdatedAt: '2026-03-02T10:00:00+00:00' })
	})

	it('clears with null and encrypts what changed', async () => {
		server({ 'PUT /api/v1/secrets/s1': () => json(secretRow()) })
		await updateItem('s1', { login: null, url: null, folderId: null, key: 'new', additionalFields: { pin: '1' } })
		const body = bodyOf('PUT /api/v1/secrets/s1')
		expect(body).toMatchObject({ login: null, url: null, folderId: null })
		expect(await decrypt(body.key)).toBe('new')
		expect(await decrypt(body.additionalFields)).toBe('{"pin":"1"}')
	})

	it('never writes a passkey credential', async () => {
		await snapshot([secretRow({ typeId: 't-passkey' })])
		server({ 'PUT /api/v1/secrets/s1': () => json(secretRow()) })
		await updateItem('s1', { name: 'Renamed', key: '{}' })
		expect(bodyOf('PUT /api/v1/secrets/s1')).toEqual({ name: 'Renamed' })
	})

	it('refuses an item the cache does not hold, since its type and limits are unknown', async () => {
		server({ 'PUT /api/v1/secrets/elsewhere': () => json(secretRow()) })
		expect(await updateItem('elsewhere', { key: '{}' })).toEqual({ ok: false, error: { code: 'not_found' } })
		expect(writes()).toEqual([])
	})

	it('checks the suite once for all it encrypts', async () => {
		server({ 'PUT /api/v1/secrets/s1': () => json(secretRow()) })
		await updateItem('s1', { key: 'new', login: 'bob', additionalFields: { pin: '1' } })
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
		expect(await updateItem('s1', { key: 'new' })).toEqual({ ok: false, error: { code: 'restricted' } })
		expect(writes()).toEqual([])
	})

	it('refuses a blank name', async () => {
		server()
		expect(await updateItem('s1', { name: ' ' })).toEqual({ ok: false, error: { code: 'invalid' } })
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
