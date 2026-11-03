import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import { addHistory, listHistory } from '@/src/generator/history'
import type { GeneratorPolicy } from '@/src/generator/options'
import { readOptions, readPolicy, saveOptions, writePolicy } from '@/src/generator/store'
import { pemToPkcs8 } from '@/src/crypto'
import { neverLockKey, putKey } from '@/src/vault/key-store'
import { pinGeneration, pinKey, pinSealKey, sealPin, storePinCopies, wrapPin } from '@/src/vault/pin'
import { vaultCacheKey } from '@/src/vault/store'
import { envelope, suiteRow } from '@/src/testing/vectors'
import { readGlobalSettings, updateGlobalSettings } from '@/src/settings/global'
import { readLastUsed, recordLastUsed } from '@/src/last-used'
import { patchAccountPolicy, readAccountPolicy } from './policy'
import { DEFAULT_SETTINGS, readSettings, writeSettings } from './settings'
import {
	accountStatus, addAccount, AccountLimitReached, DuplicateAccount, getAccount, getActiveAccountId, listAccounts,
	markLoggedOut, removeAccount, removeAllAccounts, setActive, suiteKey, updateAccount, updateSettings,
} from './store'

const suite = { ...suiteRow }
const policy: GeneratorPolicy = { minLength: 16, requireUpper: true, requireLower: false, requireDigit: false, requireSymbol: false, allowPassphrase: true }

function fields(uid = 'alice', serverUrl = 'https://cloud.example.org') {
	return { serverUrl, uid, loginName: uid, displayName: uid, email: null, avatarDataUrl: null, appPassword: 'pw', keepiqVersion: '0.3.12' }
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
		expect(await local(`settings.${account.id}`)).toEqual(DEFAULT_SETTINGS)
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
		expect(await readSettings(account.id)).toEqual(DEFAULT_SETTINGS)
	})

	it('drops the generator policy and history but keeps the options', async () => {
		const account = await addAccount(fields(), suite)
		await putKey(account.id, 'AAAA')
		const options = await saveOptions(account.id, { tab: 'passphrase' })
		await writePolicy(account.id, policy)
		await addHistory(account.id, { value: 'one', kind: 'password' })
		await markLoggedOut(account.id)
		expect(await readOptions(account.id)).toEqual(options)
		expect(await readPolicy(account.id)).toBeNull()
		expect(await listHistory(account.id)).toEqual([])
	})

	it('keeps the organisation policy through a 401, so a new login is never unclamped', async () => {
		const account = await addAccount(fields(), suite)
		await patchAccountPolicy(account.id, { maxIdleMinutes: 30, exportDisabled: true })
		await markLoggedOut(account.id, true)
		expect(await readAccountPolicy(account.id)).toEqual({ maxIdleMinutes: 30, exportDisabled: true })
	})

	it('manually removes everything of the account', async () => {
		const account = await addAccount(fields(), suite)
		await putKey(account.id, 'AAAA')
		await saveOptions(account.id, { tab: 'username' })
		await writePolicy(account.id, policy)
		await patchAccountPolicy(account.id, { maxIdleMinutes: 30 })
		await addHistory(account.id, { value: 'one', kind: 'password' })
		const appearance = await updateGlobalSettings({ theme: 'dark', compactMode: true })
		await removeAccount(account.id)
		expect(await readGlobalSettings()).toEqual(appearance)
		expect(await browser.storage.session.get(null)).toEqual({})
		const left = await browser.storage.local.get(null)
		expect(Object.keys(left).filter((k) => k.includes(account.id))).toEqual([])
		expect(await listAccounts()).toEqual([])
		expect(await getActiveAccountId()).toBeNull()
	})

	it.each<[string, (id: string) => Promise<unknown>, boolean]>([
		['a 401', (id) => markLoggedOut(id, true), true],
		['the timeout action Log out', (id) => markLoggedOut(id), true],
		['Log out', (id) => removeAccount(id), true],
		['Log out all', () => removeAllAccounts(), false],
	])('forgets which items were filled last on %s', async (_, event, otherKept) => {
		const account = await addAccount(fields(), suite)
		const other = await addAccount(fields('bob'), suite)
		for (const id of [account.id, other.id]) {
			await putKey(id, 'AAAA')
			await recordLastUsed(id, 'i1')
		}
		await event(account.id)
		expect(await readLastUsed(account.id)).toEqual({})
		expect(await readLastUsed(other.id)).toEqual(otherKept ? { i1: expect.any(Number) } : {})
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

describe('the PIN', () => {
	it.each<[string, (id: string) => Promise<unknown>]>([
		['a 401', (id) => markLoggedOut(id, true)],
		['the timeout action Log out', (id) => markLoggedOut(id)],
		['Log out', (id) => removeAccount(id)],
		['Log out all', () => removeAllAccounts()],
	])('loses both copies on %s', async (_, event) => {
		const account = await addAccount(fields(), suite)
		const bytes = pemToPkcs8(envelope.privateKeyPem)
		await storePinCopies(account.id, await wrapPin('246813', bytes, suite), await sealPin(account.id, '246813', bytes, suite), pinGeneration(account.id))
		await event(account.id)
		expect((await browser.storage.session.get(pinKey(account.id)))[pinKey(account.id)]).toBeUndefined()
		expect(await local(pinSealKey(account.id))).toBeUndefined()
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
		expect(await readSettings('x')).toEqual(DEFAULT_SETTINGS)
	})

	it('moves the key off disk when leaving Never', async () => {
		const account = await addAccount(fields(), suite)
		await updateSettings(account.id, { vaultTimeout: 'never' })
		await putKey(account.id, 'AAAA')
		expect(await local(neverLockKey(account.id))).toBe('AAAA')
		await updateSettings(account.id, { vaultTimeout: 5 })
		expect(await local(neverLockKey(account.id))).toBeUndefined()
		expect(await accountStatus(account)).toBe('unlocked')
	})

	it('patches, keeping every key the change does not name', async () => {
		const account = await addAccount(fields(), suite)
		await updateSettings(account.id, { clearClipboardMs: 20_000 })
		await updateSettings(account.id, { vaultTimeout: 60 })
		expect(await readSettings(account.id)).toMatchObject({ vaultTimeout: 60, clearClipboardMs: 20_000 })
	})

	it('keeps valid values', async () => {
		await writeSettings('x', { vaultTimeout: 'never', vaultTimeoutAction: 'logout' })
		expect(await readSettings('x')).toMatchObject({ vaultTimeout: 'never', vaultTimeoutAction: 'logout' })
	})
})
