import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import { pemToPkcs8, toBase64 } from '@/src/crypto'
import { patchAccountPolicy } from '@/src/accounts/policy'
import { writeSettings } from '@/src/accounts/settings'
import { envelope } from '@/src/testing/vectors'
import { clearKey, editOpenedKey, generatorHistoryKey, getKey, hasKey, neverLockKey, putKey, sessionValues, syncNeverLockKey } from './key-store'

const pkcs8 = toBase64(pemToPkcs8(envelope.privateKeyPem))

beforeEach(() => {
	fakeBrowser.reset()
})

async function local(key: string) {
	return (await browser.storage.local.get(key))[key]
}

describe('key store on storage.session', () => {
	it('stores the bytes in session only and imports a non-extractable key', async () => {
		await putKey('a', pkcs8)
		expect((await browser.storage.session.get('privateKeyPkcs8.a'))['privateKeyPkcs8.a']).toBe(pkcs8)
		expect(await browser.storage.session.get('unlockedAt.a')).toHaveProperty('unlockedAt.a')
		expect(await browser.storage.local.get(null)).toEqual({})
		const key = await getKey('a')
		expect(key?.extractable).toBe(false)
		expect(key?.usages).toEqual(['decrypt'])
	})

	it('clears the key, what edit forms opened with it and the generator history', async () => {
		await putKey('a', pkcs8)
		await browser.storage.session.set({ [editOpenedKey('a')]: '[{"id":"s1"}]', [generatorHistoryKey('a')]: '[]' })
		await clearKey('a')
		expect(await hasKey('a')).toBe(false)
		expect(await getKey('a')).toBeNull()
		expect(await browser.storage.session.get(null)).toEqual({})
	})

	it('reports a missing key as locked', async () => {
		expect(await hasKey('nobody')).toBe(false)
	})
})

describe('the Never timeout', () => {
	it('also writes the bytes to storage.local, and survives a restart', async () => {
		await writeSettings('a', { vaultTimeout: 'never', vaultTimeoutAction: 'lock' })
		await putKey('a', pkcs8)
		expect(await local(neverLockKey('a'))).toBe(pkcs8)

		await browser.storage.session.clear()
		expect(await hasKey('a')).toBe(true)
		expect(await getKey('a')).not.toBeNull()
	})

	it('deletes the copy on disk when leaving Never', async () => {
		await writeSettings('a', { vaultTimeout: 'never', vaultTimeoutAction: 'lock' })
		await putKey('a', pkcs8)
		await writeSettings('a', { vaultTimeout: 15, vaultTimeoutAction: 'lock' })
		await syncNeverLockKey('a')
		expect(await local(neverLockKey('a'))).toBeUndefined()
		expect(await hasKey('a')).toBe(true)
	})

	it('deletes the copy on disk on lock', async () => {
		await writeSettings('a', { vaultTimeout: 'never', vaultTimeoutAction: 'lock' })
		await putKey('a', pkcs8)
		await clearKey('a')
		expect(await local(neverLockKey('a'))).toBeUndefined()
	})

	it('writes no copy to disk for a key a lock cleared while the copy was being written', async () => {
		await writeSettings('a', { vaultTimeout: 'never', vaultTimeoutAction: 'lock' })
		await putKey('a', pkcs8)
		await browser.storage.local.remove(neverLockKey('a'))
		const get = browser.storage.session.get.bind(browser.storage.session)
		let locked = false
		vi.spyOn(browser.storage.session, 'get').mockImplementation(async (keys) => {
			const values = await get(keys)
			if (!locked) {
				locked = true
				await clearKey('a')
			}
			return values
		})
		await syncNeverLockKey('a')
		expect(await local(neverLockKey('a'))).toBeUndefined()
	})

	it('ends with the copy matching the last of two timeout changes made at once', async () => {
		await putKey('a', pkcs8)
		await Promise.all([
			writeSettings('a', { vaultTimeout: 'never' }).then(() => syncNeverLockKey('a')),
			writeSettings('a', { vaultTimeout: 15 }).then(() => syncNeverLockKey('a')),
		])
		expect(await local(neverLockKey('a'))).toBeUndefined()
	})

	it('ignores a leftover copy on disk under any other timeout', async () => {
		await browser.storage.local.set({ [neverLockKey('a')]: pkcs8 })
		expect(await hasKey('a')).toBe(false)
	})

	describe('under an organisation maximum', () => {
		it('neither writes nor restores the copy on disk', async () => {
			await writeSettings('a', { vaultTimeout: 'never', vaultTimeoutAction: 'lock' })
			await patchAccountPolicy('a', { maxIdleMinutes: 60 })
			await putKey('a', pkcs8)
			expect(await local(neverLockKey('a'))).toBeUndefined()

			await browser.storage.local.set({ [neverLockKey('a')]: pkcs8 })
			await browser.storage.session.clear()
			expect(await hasKey('a')).toBe(false)
		})
	})
})

describe('key store without storage.session (Firefox < 115)', () => {
	it('keeps the bytes in memory', async () => {
		vi.spyOn(browser.storage, 'session', 'get').mockReturnValue(undefined as never)
		await putKey('m', pkcs8)
		expect(await hasKey('m')).toBe(true)
		expect(await browser.storage.local.get(null)).toEqual({})
		await sessionValues.set({ [editOpenedKey('m')]: '[]' })
		await clearKey('m')
		expect(await hasKey('m')).toBe(false)
		expect(await sessionValues.get(editOpenedKey('m'))).toBeUndefined()
	})
})
