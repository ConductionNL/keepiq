import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import { importPrivateKey, pemToPkcs8, rsaDecrypt } from '@/src/crypto'
import { encrypt, secretRow, typeRows } from '@/src/testing/vault'
import { envelope, suiteRow } from '@/src/testing/vectors'
import { clearSnapshot, writeSnapshot } from '@/src/vault/store'
import { forgetOfferedPasskeys, offeredPasskeys } from './offered'

vi.mock('@/src/crypto', async (importOriginal) => {
	const actual = await importOriginal<typeof import('@/src/crypto')>()
	return { ...actual, rsaDecrypt: vi.fn(actual.rsaDecrypt) }
})

let key: CryptoKey

const passkey = (credentialId: string, rpId = 'example.test') => ({
	credentialId, rpId, rpName: 'Example', userName: 'alice', userDisplayName: 'Alice', userHandle: 'dXNlcg',
	privateKey: 'cHJpdmF0ZQ', algorithm: -7, counter: 0, transports: ['internal'], createdAt: '2026-01-01T00:00:00.000Z',
})

async function vault(accountId: string, name: string, credentials = [passkey('QQ'), passkey('Qg', 'other.test')]) {
	const syncedAt = new Date().toISOString()
	const secrets = [secretRow({ id: 'work', name, typeId: 't-login', passkeys: await encrypt(JSON.stringify(credentials)) })]
	await writeSnapshot(accountId, {
		suite: { id: suiteRow.id, unlockKeyEpoch: 1 }, secrets, folders: [], types: typeRows, syncedAt, listsSyncedAt: syncedAt, newestUpdatedAt: null, total: 1,
	})
}

const query = (rpId = 'example.test') => rpId

beforeAll(async () => {
	key = await importPrivateKey(pemToPkcs8(envelope.privateKeyPem))
})

beforeEach(async () => {
	fakeBrowser.reset()
	await clearSnapshot('a1')
	await clearSnapshot('a2')
	forgetOfferedPasskeys()
	await vault('a1', 'Work')
	vi.mocked(rsaDecrypt).mockClear()
})

describe('offeredPasskeys', () => {
	it('decrypts once for any number of requests and RP IDs on an unchanged vault', async () => {
		expect(await offeredPasskeys('a1', key, query())).toEqual([{ itemId: 'work', credentialId: 'QQ', name: 'Work', userName: 'alice' }])
		for (let i = 0; i < 5; i++) await offeredPasskeys('a1', key, query())
		expect(await offeredPasskeys('a1', key, query('other.test'))).toEqual([{ itemId: 'work', credentialId: 'Qg', name: 'Work', userName: 'alice' }])
		expect(rsaDecrypt).toHaveBeenCalledTimes(1)
	})

	it('shares one decryption between requests that arrive together', async () => {
		await Promise.all([offeredPasskeys('a1', key, query()), offeredPasskeys('a1', key, query())])
		expect(rsaDecrypt).toHaveBeenCalledTimes(1)
	})

	it('decrypts again once forgotten, and shows the vault as it is then', async () => {
		await offeredPasskeys('a1', key, query())
		await vault('a1', 'Renamed')
		expect((await offeredPasskeys('a1', key, query()))[0]?.name).toBe('Work')
		forgetOfferedPasskeys()
		expect((await offeredPasskeys('a1', key, query()))[0]?.name).toBe('Renamed')
		expect(rsaDecrypt).toHaveBeenCalledTimes(2)
	})

	it('decrypts again for another key or account, never answering from the other', async () => {
		await offeredPasskeys('a1', key, query())
		const unlockedAgain = await importPrivateKey(pemToPkcs8(envelope.privateKeyPem))
		await offeredPasskeys('a1', unlockedAgain, query())
		await vault('a2', 'Other account', [passkey('Qw')])
		expect(await offeredPasskeys('a2', unlockedAgain, query())).toEqual([{ itemId: 'work', credentialId: 'Qw', name: 'Other account', userName: 'alice' }])
		expect(rsaDecrypt).toHaveBeenCalledTimes(3)
	})

	it('does not keep a listing that failed, so the next request tries again', async () => {
		vi.mocked(rsaDecrypt).mockClear()
		const read = vi.spyOn(browser.storage.local, 'get').mockRejectedValueOnce(new Error('storage gone'))
		await clearSnapshot('a1')
		await expect(offeredPasskeys('a1', key, query())).rejects.toThrow('storage gone')
		read.mockRestore()
		await vault('a1', 'Work')
		expect(await offeredPasskeys('a1', key, query())).toHaveLength(1)
	})
})
