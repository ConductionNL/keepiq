import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import { setUnauthorizedHandler } from '@/src/api/client'
import { addAccount, getAccount, markLoggedOut } from '@/src/accounts/store'
import { pemToPkcs8, toBase64 } from '@/src/crypto'
import { clearPolicy, readPolicy, writePolicy } from '@/src/generator/store'
import { blockedRow, folderRow, secretRow, typeRows } from '@/src/testing/vault'
import { envelope, suiteRow } from '@/src/testing/vectors'
import { hasKey, putKey } from './key-store'
import { checkedAt, markChecked, readSnapshot, writeSnapshot } from './store'
import { isStale, refreshPolicy, sync, syncStatus } from './sync'
import { unlock } from './unlock'
import type { VaultSnapshot } from './types'

const fetchMock = vi.fn<typeof fetch>()
const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status })
/** What Keepiq answers when the admin disabled offline caching (ADR-003). */
const manifestDisabled = () => json({ message: 'Offline caching is disabled by the administrator', error: 'forbidden' }, 428)
const MINUTE = 60_000

/** Routes by path; each handler gets the full URL. Unrouted paths 404 like Nextcloud does. */
function server(routes: Record<string, (url: URL) => Response | Promise<Response>>) {
	fetchMock.mockImplementation(async (input) => {
		const url = new URL(String(input))
		const path = url.pathname.replace('/index.php/apps/keepiq', '')
		const route = routes[path]
		return route ? route(url) : json({ message: 'Not found' }, 404)
	})
}
const paths = () => fetchMock.mock.calls.map(([input]) => {
	const url = new URL(String(input))
	return url.pathname.replace('/index.php/apps/keepiq', '') + url.search
})

const rows = [secretRow({ id: 's1', updatedAt: '2026-03-02T10:00:00+00:00' }), secretRow({ id: 's2', name: 'Bank', updatedAt: '2026-04-01T10:00:00+00:00' })]
const manifest = { suite: suiteRow, secrets: rows, folders: [folderRow()], types: typeRows, syncedAt: '2026-05-01T00:00:00+00:00' }

let accountId: string
/** Fixed per test, so two `cached()` snapshots compare equal. */
let recent: string

beforeEach(async () => {
	fakeBrowser.reset()
	vi.stubGlobal('fetch', fetchMock)
	fetchMock.mockReset()
	recent = new Date(Date.now() - 5 * MINUTE).toISOString()
	vi.spyOn(console, 'error').mockImplementation(() => {})
	setUnauthorizedHandler((id) => markLoggedOut(id, true))
	accountId = (await addAccount({
		serverUrl: 'https://cloud.example.org', uid: 'alice', loginName: 'alice', displayName: 'Alice', email: null, avatarDataUrl: null, appPassword: 'pw', keepiqVersion: '0.3.12',
	}, { ...suiteRow })).id
	await putKey(accountId, toBase64(pemToPkcs8(envelope.privateKeyPem)))
})

function cached(overrides: Partial<VaultSnapshot> = {}): VaultSnapshot {
	const at = recent
	return {
		suite: { id: suiteRow.id, unlockKeyEpoch: suiteRow.unlockKeyEpoch }, secrets: rows, folders: [], types: typeRows,
		syncedAt: at, listsSyncedAt: at, newestUpdatedAt: '2026-04-01T10:00:00+00:00', total: 2, ...overrides,
	}
}

describe('full sync', () => {
	it('takes the whole vault from the manifest, beside the user settings and the policy', async () => {
		server({ '/api/v1/offline/manifest': () => json(manifest), '/api/settings/user': () => json({ default_secret_type: 'note' }) })
		const status = await sync(accountId)
		expect(paths()).toEqual(['/api/settings/policy', '/api/settings/user', '/api/v1/offline/manifest', '/api/health'])
		const snapshot = await readSnapshot(accountId)
		expect(snapshot).toMatchObject({ secrets: rows, folders: [folderRow()], total: 2, newestUpdatedAt: '2026-04-01T10:00:00+00:00', defaultSecretType: 'note' })
		expect(status).toEqual({ syncing: false, syncedAt: snapshot!.syncedAt, offline: false, lastError: null })
		expect(await isStale(accountId)).toBe(false)
	})

	it('keeps the Keepiq version the health check names, and the stored one when it names none', async () => {
		server({ '/api/v1/offline/manifest': () => json(manifest), '/api/health': () => json({ status: 'ok', version: '0.3.13' }) })
		await sync(accountId)
		expect((await getAccount(accountId))?.keepiqVersion).toBe('0.3.13')
		server({ '/api/v1/offline/manifest': () => json(manifest) })
		await sync(accountId)
		expect((await getAccount(accountId))?.keepiqVersion).toBe('0.3.13')
	})

	it('keeps no default type when the user settings fail', async () => {
		server({ '/api/v1/offline/manifest': () => json(manifest), '/api/settings/user': () => json({ message: 'Down' }, 503) })
		expect(await sync(accountId)).toMatchObject({ offline: false, lastError: null })
		expect(await readSnapshot(accountId)).toMatchObject({ defaultSecretType: null })
	})

	it.each([
		['428, as Keepiq refuses on its OCS routes', manifestDisabled],
		['a bare 403', () => json({ message: 'Offline caching is disabled' }, 403)],
	])('pages through the secrets when the admin disabled the manifest, refused with %s', async (_, refusal) => {
		const many = Array.from({ length: 150 }, (_, i) => secretRow({ id: `s${i}` }))
		server({
			'/api/v1/offline/manifest': refusal,
			'/api/v1/secrets': (url) => {
				const page = Number(url.searchParams.get('page'))
				return json({ items: many.slice((page - 1) * 100, page * 100), total: 150, page, limit: 100 })
			},
			'/api/v1/folders': () => json([folderRow()]),
			'/api/v1/secret-types': () => json(typeRows),
			'/api/v1/suites': () => json([{ ...suiteRow, status: 'revoked', id: 'old' }, suiteRow]),
		})
		await sync(accountId)
		expect(paths()).toEqual(expect.arrayContaining(['/api/v1/secrets?sort=created_at&direction=asc&limit=100&page=1', '/api/v1/secrets?sort=created_at&direction=asc&limit=100&page=2']))
		expect(paths()).not.toContain('/api/v1/secrets?sort=created_at&direction=asc&limit=100&page=3')
		expect(await readSnapshot(accountId)).toMatchObject({ secrets: many, folders: [folderRow()], types: typeRows, total: 150 })
	})

	it('keeps blocked rows from the fallback, with a reason code instead of the server text', async () => {
		const failed = blockedRow({ id: 'b2', migrationError: 'Key migration failed' })
		server({
			'/api/v1/offline/manifest': () => json({ message: 'No active suite' }, 404),
			'/api/v1/secrets': () => json({ items: [{ ...blockedRow(), blockedReason: 'Encryption suite is revoked' }, { ...failed, blockedReason: 'Could not be decrypted' }], total: 2, page: 1, limit: 100 }),
			'/api/v1/folders': () => json([]),
			'/api/v1/secret-types': () => json(typeRows),
			'/api/v1/suites': () => json([suiteRow, { ...suiteRow, id: 'suite-0', status: 'revoked' }]),
		})
		await sync(accountId)
		expect((await readSnapshot(accountId))?.secrets).toEqual([blockedRow(), { ...failed, blockedReason: 'migration_failed' }])
	})

	it('leaves the previous snapshot untouched when a fallback page fails', async () => {
		const before = cached()
		await writeSnapshot(accountId, before)
		server({
			'/api/v1/offline/manifest': manifestDisabled,
			'/api/v1/secrets': (url) => url.searchParams.get('page') === '3'
				? json({ message: 'Boom' }, 500)
				: json({ items: Array.from({ length: 100 }, (_, i) => secretRow({ id: `n${i}` })), total: 500, page: 1, limit: 100 }),
			'/api/v1/folders': () => json([]),
			'/api/v1/secret-types': () => json([]),
			'/api/v1/suites': () => json([suiteRow]),
		})
		const status = await sync(accountId)
		expect(await readSnapshot(accountId)).toEqual(before)
		expect(status).toMatchObject({ offline: true, lastError: 'server' })
	})
})

describe('change probe', () => {
	const probe = (total: number, updatedAt: string) => () => json({ items: [secretRow({ updatedAt })], total, page: 1, limit: 1 })
	const probeRequests = ['/api/settings/policy', '/api/v1/secrets?sort=updated_at&direction=desc&limit=1', '/api/v1/suites']
	const suites = () => json([suiteRow])

	it('costs the probe and the suite list when nothing changed', async () => {
		await writeSnapshot(accountId, cached())
		const before = await checkedAt(accountId)
		server({ '/api/v1/secrets': probe(2, '2026-04-01T10:00:00+00:00'), '/api/v1/suites': suites })
		await sync(accountId, 'probe')
		expect(paths().sort()).toEqual(probeRequests)
		expect(await readSnapshot(accountId)).toEqual(cached())
		expect(Date.parse((await checkedAt(accountId))!)).toBeGreaterThan(Date.parse(before!))
	})

	it('fetches everything when a secret was deleted elsewhere', async () => {
		await writeSnapshot(accountId, cached({ total: 3 }))
		server({ '/api/v1/secrets': probe(2, '2026-04-01T10:00:00+00:00'), '/api/v1/suites': suites, '/api/v1/offline/manifest': () => json(manifest) })
		await sync(accountId, 'probe')
		expect(paths()).toContain('/api/v1/offline/manifest')
		expect((await readSnapshot(accountId))?.total).toBe(2)
	})

	it('downloads nothing when the snapshot is older than the interval but the lists are not', async () => {
		const at = new Date(Date.now() - 20 * MINUTE).toISOString()
		await writeSnapshot(accountId, cached({ syncedAt: at, listsSyncedAt: at }))
		server({ '/api/v1/secrets': probe(2, '2026-04-01T10:00:00+00:00'), '/api/v1/suites': suites })
		await sync(accountId, 'probe')
		expect(paths().sort()).toEqual(probeRequests)
	})

	it('fetches everything when the folder and type lists are stale', async () => {
		const old = new Date(Date.now() - 61 * MINUTE).toISOString()
		await writeSnapshot(accountId, cached({ listsSyncedAt: old }))
		server({ '/api/v1/secrets': probe(2, '2026-04-01T10:00:00+00:00'), '/api/v1/suites': suites, '/api/v1/offline/manifest': () => json(manifest) })
		await sync(accountId, 'probe')
		expect(paths()).toContain('/api/v1/offline/manifest')
	})

	it('fetches everything when the suite changed though the vault did not', async () => {
		await writeSnapshot(accountId, cached())
		const rotated = { ...suiteRow, unlockKeyEpoch: suiteRow.unlockKeyEpoch + 1 }
		server({ '/api/v1/secrets': probe(2, '2026-04-01T10:00:00+00:00'), '/api/v1/suites': () => json([rotated]), '/api/v1/offline/manifest': () => json({ ...manifest, suite: rotated }) })
		await sync(accountId, 'probe')
		expect(paths()).toContain('/api/v1/offline/manifest')
		expect(await hasKey(accountId)).toBe(false)
		expect((await getAccount(accountId))?.keyChanged).toBe(true)
	})

	it('sees a two-factor block though the vault did not change', async () => {
		await writeSnapshot(accountId, cached())
		const withheld: Partial<typeof suiteRow> = { ...suiteRow }
		delete withheld.privateKey
		server({ '/api/v1/secrets': probe(2, '2026-04-01T10:00:00+00:00'), '/api/v1/suites': () => json([{ ...withheld, unlockBlocked: 'two_factor_required' }]) })
		await sync(accountId, 'probe')
		expect(await hasKey(accountId)).toBe(false)
		expect(await readSnapshot(accountId)).toBeUndefined()
		expect(paths()).not.toContain('/api/v1/offline/manifest')
	})

	it('is skipped by a full sync', async () => {
		await writeSnapshot(accountId, cached())
		server({ '/api/v1/offline/manifest': () => json(manifest) })
		await sync(accountId, 'full')
		expect(paths()).toEqual(['/api/settings/policy', '/api/settings/user', '/api/v1/offline/manifest', '/api/health'])
	})
})

describe('staleness', () => {
	it('is stale without a snapshot and after the interval', async () => {
		expect(await isStale(accountId)).toBe(true)
		await markChecked(accountId, new Date(Date.now() - 14 * MINUTE).toISOString())
		expect(await isStale(accountId)).toBe(false)
		await markChecked(accountId, new Date(Date.now() - 15 * MINUTE).toISOString())
		expect(await isStale(accountId)).toBe(true)
	})
})

describe('suite change', () => {
	it('purges the snapshot and the key when the epoch moved', async () => {
		await writeSnapshot(accountId, cached())
		server({ '/api/v1/offline/manifest': () => json({ ...manifest, suite: { ...suiteRow, unlockKeyEpoch: 4 } }) })
		await sync(accountId)
		expect(await readSnapshot(accountId)).toBeUndefined()
		expect(await hasKey(accountId)).toBe(false)
		expect((await getAccount(accountId))?.keyChanged).toBe(true)
	})

	it('keeps the key when the suite is the same', async () => {
		server({ '/api/v1/offline/manifest': () => json(manifest) })
		await sync(accountId)
		expect(await hasKey(accountId)).toBe(true)
		expect((await getAccount(accountId))?.keyChanged).toBeFalsy()
	})

})

describe('two-factor policy', () => {
	const withheld: Partial<typeof suiteRow> = { ...suiteRow }
	delete withheld.privateKey

	it('drops the vault, the key and the cached suite when the manifest says unlock is blocked', async () => {
		await writeSnapshot(accountId, cached())
		server({ '/api/v1/offline/manifest': () => json({ ...manifest, suite: null, unlockBlocked: 'two_factor_required' }) })
		await sync(accountId)
		expect(await hasKey(accountId)).toBe(false)
		expect(await readSnapshot(accountId)).toBeUndefined()
		expect(await browser.storage.local.get(`suite.${accountId}`)).toEqual({})
	})

	it('does the same when the fallback suite comes without its key', async () => {
		await writeSnapshot(accountId, cached())
		server({
			'/api/v1/offline/manifest': manifestDisabled,
			'/api/v1/secrets': () => json({ items: rows, total: 2, page: 1, limit: 100 }),
			'/api/v1/folders': () => json([]),
			'/api/v1/secret-types': () => json(typeRows),
			'/api/v1/suites': () => json([{ ...withheld, unlockBlocked: 'two_factor_required' }]),
		})
		await sync(accountId)
		expect(await hasKey(accountId)).toBe(false)
		expect(await readSnapshot(accountId)).toBeUndefined()
	})

	it('then refuses to unlock from what is left', async () => {
		server({ '/api/v1/offline/manifest': () => json({ ...manifest, suite: null, unlockBlocked: 'two_factor_required' }), '/api/v1/suites': () => json([withheld]) })
		await sync(accountId)
		await expect(unlock(accountId, { type: 'masterPassword', masterPassword: envelope.password })).rejects.toMatchObject({ code: 'unlock_blocked' })
	})
})

describe('server quirks', () => {
	it('pages instead when the manifest hit its row limit', async () => {
		const many = Array.from({ length: 1001 }, (_, i) => secretRow({ id: `s${i}` }))
		server({
			'/api/v1/offline/manifest': () => json({ ...manifest, secrets: many.slice(0, 1000) }),
			'/api/v1/secrets': (url) => {
				const page = Number(url.searchParams.get('page'))
				return json({ items: many.slice((page - 1) * 100, page * 100), total: 1001, page, limit: 100 })
			},
		})
		await sync(accountId)
		expect((await readSnapshot(accountId))?.secrets).toHaveLength(1001)
	})

	it('skips the manifest when the vault already reached its row limit', async () => {
		await writeSnapshot(accountId, cached({ total: 1000 }))
		server({
			'/api/v1/secrets': () => json({ items: rows, total: 2, page: 1, limit: 100 }),
			'/api/v1/folders': () => json([]),
			'/api/v1/secret-types': () => json(typeRows),
			'/api/v1/suites': () => json([suiteRow]),
		})
		await sync(accountId)
		expect(paths()).not.toContain('/api/v1/offline/manifest')
		expect((await readSnapshot(accountId))?.total).toBe(2)
	})

	it('pages again in another order when rows went missing, and keeps the server count', async () => {
		const all = Array.from({ length: 101 }, (_, i) => secretRow({ id: `s${i}` }))
		server({
			'/api/v1/offline/manifest': manifestDisabled,
			'/api/v1/secrets': (url) => {
				const page = Number(url.searchParams.get('page'))
				// The first order loses s100 to a tie; the second returns everything.
				const rows = url.searchParams.get('sort') === 'created_at' ? [...all.slice(0, 100), all[99]] : all
				return json({ items: rows.slice((page - 1) * 100, page * 100), total: 101, page, limit: 100 })
			},
			'/api/v1/folders': () => json([]),
			'/api/v1/secret-types': () => json([]),
			'/api/v1/suites': () => json([suiteRow]),
		})
		await sync(accountId)
		expect(paths()).toContain('/api/v1/secrets?sort=updated_at&direction=desc&limit=100&page=1')
		expect((await readSnapshot(accountId))?.secrets).toHaveLength(101)
	})

	it('drops a row deleted between the two passes', async () => {
		const all = Array.from({ length: 101 }, (_, i) => secretRow({ id: `s${i}` }))
		server({
			'/api/v1/offline/manifest': manifestDisabled,
			'/api/v1/secrets': (url) => {
				const page = Number(url.searchParams.get('page'))
				// The first pass sees s0 but loses s100; s0 is deleted before the second.
				const rows = url.searchParams.get('sort') === 'created_at' ? [...all.slice(0, 100), all[99]] : all.slice(1)
				const total = url.searchParams.get('sort') === 'created_at' ? 101 : 100
				return json({ items: rows.slice((page - 1) * 100, page * 100), total, page, limit: 100 })
			},
			'/api/v1/folders': () => json([]),
			'/api/v1/secret-types': () => json([]),
			'/api/v1/suites': () => json([suiteRow]),
		})
		await sync(accountId)
		const ids = (await readSnapshot(accountId))!.secrets.map((row) => row.id)
		expect(ids).toHaveLength(100)
		expect(ids).not.toContain('s0')
		expect(ids).toContain('s100')
	})

	it('stores the server count when a row stays missing, so the probe does not download again', async () => {
		server({
			'/api/v1/offline/manifest': manifestDisabled,
			'/api/v1/secrets': () => json({ items: rows, total: 3, page: 1, limit: 100 }),
			'/api/v1/folders': () => json([]),
			'/api/v1/secret-types': () => json([]),
			'/api/v1/suites': () => json([suiteRow]),
		})
		await sync(accountId)
		expect(await readSnapshot(accountId)).toMatchObject({ total: 3, secrets: rows })
	})

	it('keeps a row once when it shows up on two pages', async () => {
		const page1 = Array.from({ length: 100 }, (_, i) => secretRow({ id: `s${i}` }))
		server({
			'/api/v1/offline/manifest': manifestDisabled,
			'/api/v1/secrets': (url) => json(url.searchParams.get('page') === '1'
				? { items: page1, total: 101, page: 1, limit: 100 }
				: { items: [page1[99], secretRow({ id: 'last' })], total: 101, page: 2, limit: 100 }),
			'/api/v1/folders': () => json([]),
			'/api/v1/secret-types': () => json([]),
			'/api/v1/suites': () => json([suiteRow]),
		})
		await sync(accountId)
		const ids = (await readSnapshot(accountId))!.secrets.map((row) => row.id)
		expect(new Set(ids).size).toBe(ids.length)
		expect(ids).toContain('last')
	})

	it('marks manifest rows on a revoked or missing suite as blocked, like the server does', async () => {
		const onRevoked = secretRow({ id: 'old', encryptionSuiteId: 'suite-0', login: 'ciphertext' })
		const orphan = secretRow({ id: 'gone', encryptionSuiteId: 'suite-x' })
		server({
			'/api/v1/offline/manifest': () => json({ ...manifest, secrets: [rows[0], onRevoked, orphan] }),
			'/api/v1/suites': () => json([suiteRow, { ...suiteRow, id: 'suite-0', status: 'revoked' }]),
		})
		await sync(accountId)
		const stored = (await readSnapshot(accountId))!.secrets
		expect(stored[0]).toMatchObject({ id: 's1', blocked: false })
		expect(stored[1]).toMatchObject({ id: 'old', blocked: true, blockedReason: 'suite_revoked' })
		expect(stored[1]).not.toHaveProperty('login')
		expect(stored[1]).not.toHaveProperty('key')
		expect(stored[2]).toMatchObject({ id: 'gone', blocked: true, blockedReason: 'suite_missing' })
	})

	it('gives a row on a compromised suite the compromised reason', async () => {
		server({
			'/api/v1/offline/manifest': () => json({ ...manifest, secrets: [secretRow({ id: 'old', encryptionSuiteId: 'suite-0' })] }),
			'/api/v1/suites': () => json([suiteRow, { ...suiteRow, id: 'suite-0', status: 'compromised' }]),
		})
		await sync(accountId)
		expect((await readSnapshot(accountId))!.secrets[0]).toMatchObject({ blocked: true, blockedReason: 'suite_compromised' })
	})

	it('asks for the suites only when a row is on another suite', async () => {
		server({ '/api/v1/offline/manifest': () => json(manifest) })
		await sync(accountId)
		expect(paths()).not.toContain('/api/v1/suites')
	})
})

describe('errors', () => {
	it('logs the account out on 401', async () => {
		await writeSnapshot(accountId, cached())
		server({ '/api/v1/offline/manifest': () => json({ message: 'Unauthorized' }, 401) })
		await sync(accountId)
		expect(await readSnapshot(accountId)).toBeUndefined()
		expect((await getAccount(accountId))?.appPassword).toBeNull()
	})

	it('keeps the snapshot and reports busy on 423', async () => {
		await writeSnapshot(accountId, cached())
		server({ '/api/v1/offline/manifest': () => json({ message: 'Locked' }, 423) })
		expect(await sync(accountId)).toMatchObject({ offline: false, lastError: 'busy' })
		expect(await readSnapshot(accountId)).toEqual(cached())
	})

	it('reports offline without a network and recovers on the next success', async () => {
		await writeSnapshot(accountId, cached())
		fetchMock.mockRejectedValue(new TypeError('Failed to fetch'))
		expect(await sync(accountId)).toMatchObject({ offline: true, lastError: 'network' })
		server({ '/api/v1/offline/manifest': () => json(manifest) })
		expect(await sync(accountId)).toMatchObject({ offline: false, lastError: null })
	})

	it('writes neither suite nor snapshot when the account logs out during the fetch', async () => {
		let release!: () => void
		const gate = new Promise<void>((resolve) => (release = resolve))
		server({ '/api/v1/offline/manifest': async () => { await gate; return json({ ...manifest, suite: { ...suiteRow, id: 'suite-2' } }) } })
		const running = sync(accountId)
		await vi.waitFor(() => expect(fetchMock).toHaveBeenCalled())
		await markLoggedOut(accountId)
		release()
		await running
		expect(await browser.storage.local.get(`suite.${accountId}`)).toEqual({})
		expect(await readSnapshot(accountId)).toBeUndefined()
	})

	it.each([['suite', 'suite.'], ['vault', 'vaultCache.']])('leaves nothing behind when the logout lands as sync writes the %s', async (_, prefix) => {
		server({ '/api/v1/offline/manifest': () => json(manifest) })
		// The logout runs to completion right before sync's write reaches storage.
		const set = browser.storage.local.set.bind(browser.storage.local)
		let loggedOut = false
		vi.spyOn(browser.storage.local, 'set').mockImplementation(async (values) => {
			if (!loggedOut && Object.keys(values).some((key) => key.startsWith(prefix))) {
				loggedOut = true
				await markLoggedOut(accountId)
			}
			return set(values)
		})
		await sync(accountId)
		expect(loggedOut).toBe(true)
		expect(await browser.storage.local.get([`suite.${accountId}`, `vaultCache.${accountId}`])).toEqual({})
	})

	it('leaves no check time behind when the logout lands as the probe records it', async () => {
		await writeSnapshot(accountId, cached())
		server({ '/api/v1/secrets': () => json({ items: [secretRow({ updatedAt: '2026-04-01T10:00:00+00:00' })], total: 2, page: 1, limit: 1 }), '/api/v1/suites': () => json([suiteRow]) })
		const set = browser.storage.local.set.bind(browser.storage.local)
		let loggedOut = false
		vi.spyOn(browser.storage.local, 'set').mockImplementation(async (values) => {
			if (!loggedOut && Object.keys(values).some((key) => key.startsWith('vaultCheckedAt.'))) {
				loggedOut = true
				await markLoggedOut(accountId)
			}
			return set(values)
		})
		await sync(accountId, 'probe')
		expect(loggedOut).toBe(true)
		expect(await checkedAt(accountId)).toBeNull()
	})

	it('does not sync a logged out account', async () => {
		await markLoggedOut(accountId)
		await sync(accountId)
		expect(fetchMock).not.toHaveBeenCalled()
	})
})

describe('generator policy', () => {
	const enabled = { policy_enabled: true, generator_min_length: 20, generator_require_symbol: true, generator_allow_passphrase: false }
	const mapped = { minLength: 20, requireUpper: false, requireLower: false, requireDigit: false, requireSymbol: true, allowPassphrase: false }
	const old = { ...mapped, minLength: 10 }
	const probeRoutes = {
		'/api/v1/secrets': () => json({ items: [secretRow({ updatedAt: '2026-04-01T10:00:00+00:00' })], total: 2, page: 1, limit: 1 }),
		'/api/v1/suites': () => json([suiteRow]),
	}

	it('is cached by a full sync and by a probe that finds nothing changed', async () => {
		server({ '/api/v1/offline/manifest': () => json(manifest), '/api/settings/policy': () => json(enabled) })
		await sync(accountId)
		expect(await readPolicy(accountId)).toEqual(mapped)
		await clearPolicy(accountId)
		server({ ...probeRoutes, '/api/settings/policy': () => json(enabled) })
		await sync(accountId, 'probe')
		expect(await readPolicy(accountId)).toEqual(mapped)
	})

	it('is dropped when the server says no policy applies', async () => {
		await writePolicy(accountId, old)
		server({ '/api/v1/offline/manifest': () => json(manifest), '/api/settings/policy': () => json({ ...enabled, policy_enabled: false }) })
		await sync(accountId)
		expect(await readPolicy(accountId)).toBeNull()
	})

	it.each([
		['a 500', () => json({ message: 'Down' }, 500)],
		['a refusal', () => json({ message: 'No', error: 'forbidden' }, 428)],
		['an HTML page', () => new Response('<html></html>', { status: 200 })],
		['an empty object', () => json({})],
		['an array', () => json([])],
		['a network error', () => Promise.reject(new TypeError('Failed to fetch'))],
	])('keeps the cached one on %s', async (_, answer) => {
		await writePolicy(accountId, old)
		server({ '/api/v1/offline/manifest': () => json(manifest), '/api/settings/policy': answer })
		await sync(accountId)
		expect(await readSnapshot(accountId)).toBeDefined()
		expect(await readPolicy(accountId)).toEqual(old)
	})

	it('is cached even when the vault sync fails', async () => {
		await writePolicy(accountId, old)
		server({ '/api/v1/offline/manifest': () => json({ message: 'Down' }, 503), '/api/settings/policy': () => json(enabled) })
		expect(await sync(accountId)).toMatchObject({ offline: true, lastError: 'server' })
		expect(await readPolicy(accountId)).toEqual(mapped)
	})

	it('is cached by refreshPolicy, and only for a logged in account', async () => {
		server({ '/api/settings/policy': () => json(enabled) })
		await refreshPolicy(accountId)
		expect(await readPolicy(accountId)).toEqual(mapped)
		await markLoggedOut(accountId)
		await refreshPolicy(accountId)
		expect(await readPolicy(accountId)).toBeNull()
	})

	it('logs the account out on a 401 like any other request', async () => {
		await writePolicy(accountId, old)
		server({ '/api/v1/offline/manifest': () => json(manifest), '/api/settings/policy': () => json({ message: 'Unauthorized' }, 401) })
		await sync(accountId)
		expect((await getAccount(accountId))?.appPassword).toBeNull()
		expect(await readPolicy(accountId)).toBeNull()
	})

	it('is not brought back by a logout during the fetch', async () => {
		let release!: () => void
		const gate = new Promise<void>((resolve) => (release = resolve))
		server({ '/api/v1/offline/manifest': () => json(manifest), '/api/settings/policy': async () => { await gate; return json(enabled) } })
		const running = sync(accountId)
		await vi.waitFor(() => expect(paths()).toContain('/api/v1/offline/manifest'))
		await markLoggedOut(accountId)
		release()
		await running
		expect(await readPolicy(accountId)).toBeNull()
	})

	it('is not left behind when the logout lands as sync writes it', async () => {
		server({ '/api/v1/offline/manifest': () => json(manifest), '/api/settings/policy': () => json(enabled) })
		const set = browser.storage.local.set.bind(browser.storage.local)
		let loggedOut = false
		vi.spyOn(browser.storage.local, 'set').mockImplementation(async (values) => {
			if (!loggedOut && Object.keys(values).some((key) => key.startsWith('generatorPolicy.'))) {
				loggedOut = true
				await markLoggedOut(accountId)
			}
			return set(values)
		})
		await sync(accountId)
		expect(loggedOut).toBe(true)
		expect(await readPolicy(accountId)).toBeNull()
	})
})

describe('one sync at a time', () => {
	it('joins a running sync instead of starting another', async () => {
		let release!: () => void
		const gate = new Promise<void>((resolve) => (release = resolve))
		server({ '/api/v1/offline/manifest': async () => { await gate; return json(manifest) } })
		const first = sync(accountId, 'probe')
		const second = sync(accountId, 'full')
		expect(second).toBe(first)
		await vi.waitFor(async () => expect((await syncStatus(accountId)).syncing).toBe(true))
		release()
		await first
		expect(paths().filter((path) => path === '/api/v1/offline/manifest')).toHaveLength(1)
		expect((await syncStatus(accountId)).syncing).toBe(false)
	})
})
