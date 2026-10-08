import { beforeEach, describe, expect, it } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import { putKey } from '@/src/vault/key-store'
import { suiteRow } from '@/src/testing/vectors'
import { readSettings, writeSettings } from './settings'
import {
	accountStatus, addAccount, AccountLimitReached, DuplicateAccount, getAccount, getActiveAccountId, listAccounts,
	markLoggedOut, removeAccount, removeAllAccounts, setActive, suiteKey, vaultCacheKey,
} from './store'

const suite = { ...suiteRow }

function fields(uid = 'alice', origin = 'https://cloud.example.org') {
	return { origin, uid, loginName: uid, displayName: uid, email: null, avatarDataUrl: null, appPassword: 'pw' }
}

async function local(key: string) {
	return (await browser.storage.local.get(key))[key]
}

beforeEach(() => {
	fakeBrowser.reset()
})

describe('adding', () => {
	it('stores the record, makes it active and writes default settings and the suite', async () => {
		const account = await addAccount(fields(), suite)
		expect(await getAccount(account.id)).toEqual(account)
		expect(await getActiveAccountId()).toBe(account.id)
		expect(await local(`settings.${account.id}`)).toEqual({ vaultTimeout: 15, vaultTimeoutAction: 'lock' })
		expect(await local(suiteKey(account.id))).toEqual(suite)
	})

	it('rejects the same uid on the same origin', async () => {
		await addAccount(fields(), suite)
		await expect(addAccount(fields(), suite)).rejects.toBeInstanceOf(DuplicateAccount)
		await expect(addAccount(fields('alice', 'https://other.example.org'), suite)).resolves.toBeDefined()
	})

	it('allows at most 5 accounts', async () => {
		for (let i = 0; i < 5; i++) await addAccount(fields(`user${i}`), suite)
		await expect(addAccount(fields('sixth'), suite)).rejects.toBeInstanceOf(AccountLimitReached)
		expect(await listAccounts()).toHaveLength(5)
	})
})

describe('status', () => {
	it('is derived from the app password and the key store', async () => {
		const account = await addAccount(fields(), suite)
		expect(await accountStatus(account)).toBe('locked')
		await putKey(account.id, 'AAAA')
		expect(await accountStatus(account)).toBe('unlocked')
		await markLoggedOut(account.id)
		expect(await accountStatus((await getAccount(account.id))!)).toBe('logged_out')
	})
})

describe('logging out', () => {
	it('on a 401 purges credentials and caches but keeps identity and settings', async () => {
		const account = await addAccount(fields(), suite)
		await putKey(account.id, 'AAAA')
		await browser.storage.local.set({ [vaultCacheKey(account.id)]: { rows: [] } })
		await markLoggedOut(account.id, true)

		const after = await getAccount(account.id)
		expect(after).toMatchObject({ uid: 'alice', appPassword: null, revoked: true })
		expect(await local(suiteKey(account.id))).toBeUndefined()
		expect(await local(vaultCacheKey(account.id))).toBeUndefined()
		expect(await browser.storage.session.get(null)).toEqual({})
		expect(await readSettings(account.id)).toEqual({ vaultTimeout: 15, vaultTimeoutAction: 'lock' })
	})

	it('manually removes everything of the account', async () => {
		const account = await addAccount(fields(), suite)
		await putKey(account.id, 'AAAA')
		await removeAccount(account.id)
		const left = await browser.storage.local.get(null)
		expect(Object.keys(left).filter((k) => k.includes(account.id))).toEqual([])
		expect(await listAccounts()).toEqual([])
		expect(await getActiveAccountId()).toBeNull()
	})

	it('makes the next account active when the active one is removed', async () => {
		const a = await addAccount(fields('a'), suite)
		const b = await addAccount(fields('b'), suite)
		await setActive(a.id)
		await removeAccount(a.id)
		expect(await getActiveAccountId()).toBe(b.id)
	})

	it('removes every account', async () => {
		await addAccount(fields('a'), suite)
		const b = await addAccount(fields('b'), suite)
		await putKey(b.id, 'AAAA')
		await removeAllAccounts()
		expect(await browser.storage.local.get(null)).toEqual({})
		expect(await browser.storage.session.get(null)).toEqual({})
	})
})

describe('settings', () => {
	it('reads invalid stored values as their default', async () => {
		await browser.storage.local.set({ 'settings.x': { vaultTimeout: 'tomorrow', vaultTimeoutAction: 'explode' } })
		expect(await readSettings('x')).toEqual({ vaultTimeout: 15, vaultTimeoutAction: 'lock' })
	})

	it('keeps valid values', async () => {
		await writeSettings('x', { vaultTimeout: 'never', vaultTimeoutAction: 'logout' })
		expect(await readSettings('x')).toEqual({ vaultTimeout: 'never', vaultTimeoutAction: 'logout' })
	})
})
