import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import { blockedRow, secretRow } from '@/src/testing/vault'
import { checkedAt, clearSnapshot, markChecked, readSnapshot, toItemMeta, writeSnapshot } from './store'
import type { VaultSnapshot } from './types'

beforeEach(() => {
	fakeBrowser.reset()
})

const snapshot: VaultSnapshot = {
	suite: { id: 's', unlockKeyEpoch: 1 }, secrets: [secretRow()], folders: [], types: [],
	syncedAt: '2026-05-01T00:00:00.000Z', listsSyncedAt: '2026-05-01T00:00:00.000Z', newestUpdatedAt: null, total: 1,
}

describe('snapshot storage', () => {
	it('writes, reads and clears one account without touching another', async () => {
		await writeSnapshot('a', snapshot)
		await writeSnapshot('b', snapshot)
		expect(await readSnapshot('a')).toEqual(snapshot)
		expect(await checkedAt('a')).toBe(snapshot.syncedAt)
		await markChecked('a', '2026-05-02T00:00:00.000Z')
		expect(await checkedAt('a')).toBe('2026-05-02T00:00:00.000Z')
		await clearSnapshot('a')
		expect(await readSnapshot('a')).toBeUndefined()
		expect(await checkedAt('a')).toBeNull()
		expect(await readSnapshot('b')).toEqual(snapshot)
	})
})

describe('snapshot cache', () => {
	it('reads storage once, then serves memory until the next write', async () => {
		await writeSnapshot('c', snapshot)
		const get = vi.spyOn(browser.storage.local, 'get')
		await readSnapshot('c')
		await readSnapshot('c')
		expect(get).not.toHaveBeenCalled()
		await writeSnapshot('c', { ...snapshot, total: 2 })
		expect((await readSnapshot('c'))?.total).toBe(2)
		await clearSnapshot('c')
		expect(await readSnapshot('c')).toBeUndefined()
		expect(get).toHaveBeenCalledTimes(1)
	})
})

describe('a clear during a write', () => {
	it('does not let the write refill the cache', async () => {
		const set = browser.storage.local.set.bind(browser.storage.local)
		let cleared = false
		vi.spyOn(browser.storage.local, 'set').mockImplementation(async (values) => {
			await set(values)
			// The clear lands after the write reached storage but before writeSnapshot finished.
			if (!cleared) {
				cleared = true
				await clearSnapshot('d')
			}
		})
		await writeSnapshot('d', snapshot)
		expect(await readSnapshot('d')).toBeUndefined()
	})
})

describe('toItemMeta', () => {
	it('keeps metadata and only whether a login exists', () => {
		const meta = toItemMeta(secretRow({ login: 'ciphertext' }))
		expect(meta).toEqual({
			id: 's1', name: 'GitHub', url: 'https://github.com', typeId: 't-login', folderId: null, hasLogin: true, blocked: false,
			createdAt: '2026-01-05T10:00:00+00:00', updatedAt: '2026-03-02T10:00:00+00:00', expiresAt: null,
		})
	})

	it('carries the reason of a blocked row', () => {
		expect(toItemMeta({ ...blockedRow(), migrationError: 'failed' })).toMatchObject({ blocked: true, hasLogin: false, blockedReason: 'suite_revoked', migrationError: 'failed' })
	})

	it('drops a reason that is not a code, as a snapshot from an older version holds', () => {
		const meta = toItemMeta({ ...blockedRow(), blockedReason: 'Encryption suite is revoked' as never })
		expect(meta.blocked).toBe(true)
		expect(meta).not.toHaveProperty('blockedReason')
	})
})
