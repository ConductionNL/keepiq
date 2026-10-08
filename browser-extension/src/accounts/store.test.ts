import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import { neverLockKey, putKey } from '@/src/vault/key-store'
import { vaultCacheKey } from '@/src/vault/store'
import { suiteRow } from '@/src/testing/vectors'
import { readSettings, writeSettings } from './settings'
import {
	accountStatus, addAccount, AccountLimitReached, DuplicateAccount, getAccount, getActiveAccountId, listAccounts,
	markLoggedOut, removeAccount, removeAllAccounts, setActive, suiteKey, updateAccount, updateSettings,
} from './store'

const suite = { ...suiteRow }

function fields(uid = 'alice', serverUrl = 'https://cloud.example.org') {
	return { serverUrl, uid, loginName: uid, displayName: uid, email: null, avatarDataUrl: null, appPassword: 'pw' }
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

	it('rejects the same uid on the same server', async () => {
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
		expect(await getActiveAccountId()).toBeNull()
		expect(await browser.storage.local.get(null)).toEqual({})
		expect(await browser.storage.session.get(null)).toEqual({})
	})
})

describe('concurrent writes', () => {
	it('loses neither a logout nor the save racing it', async () => {
		const account = await addAccount(fields(), suite)
		await Promise.all([updateAccount(account.id, { keyChanged: true }), markLoggedOut(account.id)])
		expect(await getAccount(account.id)).toMatchObject({ appPassword: null, keyChanged: true })
	})

	it('does not bring a removed account back', async () => {
		const account = await addAccount(fields(), suite)
		await Promise.all([updateAccount(account.id, { displayName: 'Late' }), removeAccount(account.id)])
		expect(await getAccount(account.id)).toBeUndefined()
	})

	it('keeps an account added during log out all whole', async () => {
		await addAccount(fields('a'), suite)
		const [, late] = await Promise.all([removeAllAccounts(), addAccount(fields('late'), suite)])
		expect((await listAccounts()).map((a) => a.id)).toEqual([late.id])
		expect(await getActiveAccountId()).toBe(late.id)
	})

	it('leaves nothing of an account added just before log out all', async () => {
		const set = browser.storage.local.set.bind(browser.storage.local)
		let removed: Promise<void> | undefined
		vi.spyOn(browser.storage.local, 'set').mockImplementation(async (values) => {
			// Log out all runs before the add writes its suite; queued behind the add, it waits instead.
			if (!removed && Object.keys(values).some((key) => key.startsWith('suite.'))) {
				removed = removeAllAccounts()
				await Promise.race([removed, new Promise((resolve) => setTimeout(resolve, 20))])
			}
			return set(values)
		})
		await addAccount(fields('early'), suite)
		await removed
		expect(await browser.storage.local.get(null)).toEqual({})
	})

	it('makes the new account active though the pointer is read while it is being added', async () => {
		await addAccount(fields('first'), suite)
		const set = browser.storage.local.set.bind(browser.storage.local)
		vi.spyOn(browser.storage.local, 'set').mockImplementation(async (values) => {
			await set(values)
			// Another popup or a lock reads the active account in the middle of the add.
			if (Object.keys(values).some((key) => key.startsWith('suite.'))) await getActiveAccountId()
		})
		const added = await addAccount(fields('second'), suite)
		expect(await getActiveAccountId()).toBe(added.id)
	})

	it('adds two accounts at once without dropping either', async () => {
		await Promise.all([addAccount(fields('alice'), suite), addAccount(fields('bob'), suite)])
		expect((await listAccounts()).map((a) => a.uid).sort()).toEqual(['alice', 'bob'])
	})
})

describe('settings', () => {
	it('reads invalid stored values as their default', async () => {
		await browser.storage.local.set({ 'settings.x': { vaultTimeout: 'tomorrow', vaultTimeoutAction: 'explode' } })
		expect(await readSettings('x')).toEqual({ vaultTimeout: 15, vaultTimeoutAction: 'lock' })
	})

	it('moves the key off disk when leaving Never', async () => {
		const account = await addAccount(fields(), suite)
		await updateSettings(account.id, { vaultTimeout: 'never', vaultTimeoutAction: 'lock' })
		await putKey(account.id, 'AAAA')
		expect(await local(neverLockKey(account.id))).toBe('AAAA')
		await updateSettings(account.id, { vaultTimeout: 5, vaultTimeoutAction: 'lock' })
		expect(await local(neverLockKey(account.id))).toBeUndefined()
		expect(await accountStatus(account)).toBe('unlocked')
	})

	it('keeps valid values', async () => {
		await writeSettings('x', { vaultTimeout: 'never', vaultTimeoutAction: 'logout' })
		expect(await readSettings('x')).toEqual({ vaultTimeout: 'never', vaultTimeoutAction: 'logout' })
	})
})
