import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import { clearKey, generatorHistoryKey, sessionEpoch, sessionValues } from '@/src/vault/key-store'
import { addHistory, clearHistory, listHistory } from './history'
import { HISTORY_LIMIT } from './options'

beforeEach(() => {
	fakeBrowser.reset()
})

describe('generator history', () => {
	it('lists newest first with the time it was added', async () => {
		await addHistory('a', { value: 'one', kind: 'password' }, undefined, new Date('2026-10-01T10:00:00Z'))
		await addHistory('a', { value: 'two', kind: 'passphrase' }, undefined, new Date('2026-10-01T11:00:00Z'))
		expect(await listHistory('a')).toEqual([
			{ value: 'two', kind: 'passphrase', createdAt: '2026-10-01T11:00:00.000Z' },
			{ value: 'one', kind: 'password', createdAt: '2026-10-01T10:00:00.000Z' },
		])
		expect(await listHistory('b')).toEqual([])
	})

	it(`keeps the newest ${HISTORY_LIMIT}`, async () => {
		for (let i = 0; i < HISTORY_LIMIT + 5; i++) await addHistory('a', { value: `v${i}`, kind: 'password' })
		const entries = await listHistory('a')
		expect(entries).toHaveLength(HISTORY_LIMIT)
		expect(entries[0]!.value).toBe(`v${HISTORY_LIMIT + 4}`)
		expect(entries.at(-1)!.value).toBe('v5')
	})

	it('records the same value twice in a row once', async () => {
		await addHistory('a', { value: 'one', kind: 'password' })
		await addHistory('a', { value: 'one', kind: 'password' })
		await addHistory('a', { value: 'two', kind: 'password' })
		await addHistory('a', { value: 'one', kind: 'password' })
		expect((await listHistory('a')).map((e) => e.value)).toEqual(['one', 'two', 'one'])
	})

	it('drops no add when several arrive at once', async () => {
		await Promise.all(['a', 'b', 'c', 'd'].map((value) => addHistory('x', { value, kind: 'username' })))
		expect((await listHistory('x')).map((e) => e.value).sort()).toEqual(['a', 'b', 'c', 'd'])
	})

	it('lives in session storage only', async () => {
		const set = vi.spyOn(browser.storage.local, 'set')
		await addHistory('a', { value: 'secret', kind: 'password' })
		expect(set).not.toHaveBeenCalled()
		expect(await browser.storage.local.get(null)).toEqual({})
		expect(await browser.storage.session.get(generatorHistoryKey('a'))).toHaveProperty(generatorHistoryKey('a'))
	})

	it('clears, and goes with the key on lock and logout', async () => {
		await addHistory('a', { value: 'one', kind: 'password' })
		await clearHistory('a')
		expect(await listHistory('a')).toEqual([])
		await addHistory('a', { value: 'two', kind: 'password' })
		await clearKey('a')
		expect(await listHistory('a')).toEqual([])
	})

	it('does not write the history back when a lock clears it during an add', async () => {
		await addHistory('a', { value: 'one', kind: 'password' })
		const get = browser.storage.session.get.bind(browser.storage.session)
		let cleared = false
		vi.spyOn(browser.storage.session, 'get').mockImplementation(async (keys) => {
			const values = await get(keys)
			if (!cleared) {
				cleared = true
				await clearKey('a')
			}
			return values
		})
		expect(await addHistory('a', { value: 'two', kind: 'password' })).toEqual([])
		expect(cleared).toBe(true)
		expect(await browser.storage.session.get(null)).toEqual({})
		// The next add after the lock records again.
		expect(await addHistory('a', { value: 'three', kind: 'password' })).toHaveLength(1)
	})

	it('does not write an add whose request arrived before a lock', async () => {
		const epoch = sessionEpoch('a')
		await clearKey('a')
		expect(await addHistory('a', { value: 'one', kind: 'password' }, epoch)).toEqual([])
		expect(await browser.storage.session.get(null)).toEqual({})
	})

	it('reads damaged storage as empty and skips invalid entries', async () => {
		await sessionValues.set({ [generatorHistoryKey('a')]: '{not json' })
		expect(await listHistory('a')).toEqual([])
		await sessionValues.set({ [generatorHistoryKey('a')]: JSON.stringify([{ value: 'ok', kind: 'password', createdAt: 'x' }, { value: 1 }, { value: 'v', kind: 'pin', createdAt: 'x' }]) })
		expect((await listHistory('a')).map((e) => e.value)).toEqual(['ok'])
	})
})

describe('generator history without storage.session (Firefox < 115)', () => {
	it('keeps the values in memory', async () => {
		vi.spyOn(browser.storage, 'session', 'get').mockReturnValue(undefined as never)
		await addHistory('a', { value: 'one', kind: 'password' })
		expect(await listHistory('a')).toHaveLength(1)
		expect(await browser.storage.local.get(null)).toEqual({})
		await clearKey('a')
		expect(await listHistory('a')).toEqual([])
	})
})
