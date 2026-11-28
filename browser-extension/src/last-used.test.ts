import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import { secretRow } from '@/src/testing/vault'
import { clearKey, putKey } from '@/src/vault/key-store'
import { clearSnapshot, writeSnapshot } from '@/src/vault/store'
import type { VaultSnapshot } from '@/src/vault/types'
import { forgetLastUsed, lastUsedKey, readLastUsed, recordLastUsed } from './last-used'

beforeEach(async () => {
	fakeBrowser.reset()
	// The snapshot store keeps a copy in memory across tests.
	await clearSnapshot('acc')
})

describe('last used', () => {
	it('is empty before any fill', async () => {
		expect(await readLastUsed('acc')).toEqual({})
	})

	it('records a fill per item, in storage.local, scoped to the account', async () => {
		await putKey('acc', 'AAAA')
		await recordLastUsed('acc', 'i1', 1_000)
		await recordLastUsed('acc', 'i2', 2_000)
		await recordLastUsed('acc', 'i1', 3_000)
		expect(await readLastUsed('acc')).toEqual({ i1: 3_000, i2: 2_000 })
		expect(await readLastUsed('other')).toEqual({})
		expect((await browser.storage.local.get(lastUsedKey('acc')))[lastUsedKey('acc')]).toEqual({ i1: 3_000, i2: 2_000 })
	})

	it('loses neither of two fills recorded at once', async () => {
		await putKey('acc', 'AAAA')
		await Promise.all([recordLastUsed('acc', 'i1', 1), recordLastUsed('acc', 'i2', 2)])
		expect(await readLastUsed('acc')).toEqual({ i1: 1, i2: 2 })
	})

	it('records nothing for an account that is locked, logged out or gone', async () => {
		await recordLastUsed('acc', 'i1', 1_000)
		expect(await browser.storage.local.get(null)).toEqual({})
	})

	it('drops items the cached vault no longer holds', async () => {
		await putKey('acc', 'AAAA')
		await recordLastUsed('acc', 'gone', 1)
		await recordLastUsed('acc', 'kept', 2)
		await writeSnapshot('acc', { secrets: [secretRow({ id: 'kept' }), secretRow({ id: 'new' })] } as VaultSnapshot)
		await recordLastUsed('acc', 'new', 3)
		expect(await readLastUsed('acc')).toEqual({ kept: 2, new: 3 })
	})

	it('forgets an account', async () => {
		await putKey('acc', 'AAAA')
		await putKey('other', 'AAAA')
		await recordLastUsed('acc', 'i1', 1)
		await recordLastUsed('other', 'i1', 1)
		await forgetLastUsed('acc')
		expect(await readLastUsed('acc')).toEqual({})
		expect(await readLastUsed('other')).toEqual({ i1: 1 })
	})

	it('forgets a fill recorded while the account logs out', async () => {
		await putKey('acc', 'AAAA')
		const set = browser.storage.local.set.bind(browser.storage.local)
		let release = () => {}
		const held = new Promise<void>((resolve) => (release = resolve))
		vi.spyOn(browser.storage.local, 'set').mockImplementation(async (values) => {
			// The fill passed its key check; the logout runs before its write lands.
			if (lastUsedKey('acc') in values) await held
			return set(values)
		})
		const recorded = recordLastUsed('acc', 'i1', 1)
		await vi.waitFor(() => expect(browser.storage.local.set).toHaveBeenCalled())
		const forgotten = (async () => {
			await clearKey('acc')
			await forgetLastUsed('acc')
		})()
		await new Promise((resolve) => setTimeout(resolve, 20))
		release()
		await Promise.all([recorded, forgotten])
		expect(await readLastUsed('acc')).toEqual({})
	})

	it('reads invalid stored entries as never used', async () => {
		await browser.storage.local.set({ [lastUsedKey('acc')]: { i1: 5, i2: 'x', i3: Number.NaN, i4: -1 } })
		expect(await readLastUsed('acc')).toEqual({ i1: 5 })
		await browser.storage.local.set({ [lastUsedKey('acc')]: ['i1'] })
		expect(await readLastUsed('acc')).toEqual({})
	})
})
