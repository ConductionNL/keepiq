import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import { addAccount, getAccount, markLoggedOut, setActive, suiteKey, updateAccount } from '@/src/accounts/store'
import { writeSettings } from '@/src/accounts/settings'
import { decodeEnvelope, encodeEnvelope } from '@/src/crypto/envelope'
import { envelope, suiteRow } from '@/src/testing/vectors'
import { getKey, hasKey, LAST_TAB_KEY, sessionValues } from './key-store'
import { blockUnlock, checkSuite, lock, lockAll, logoutForTimeout, unlock } from './unlock'

const fetchMock = vi.fn<typeof fetch>()
const password = { type: 'masterPassword' as const, masterPassword: envelope.password }

async function newAccount(uid = 'alice') {
	return addAccount({
		serverUrl: 'https://cloud.example.org', uid, loginName: uid, displayName: uid, email: null, avatarDataUrl: null, appPassword: 'pw',
	}, { ...suiteRow })
}

/** The suite as cached before the master password changed: the current password no longer opens it. */
function staleSuite() {
	const decoded = decodeEnvelope(envelope.envelope)
	decoded.ciphertextWithTag[decoded.ciphertextWithTag.length - 1]! ^= 1
	return { ...suiteRow, privateKey: encodeEnvelope(decoded), unlockKeyEpoch: 0 }
}

beforeEach(() => {
	fakeBrowser.reset()
	vi.stubGlobal('fetch', fetchMock)
	fetchMock.mockReset()
})

describe('unlock', () => {
	it('opens the cached suite without a request', async () => {
		const account = await newAccount()
		await unlock(account.id, password)
		expect(fetchMock).not.toHaveBeenCalled()
		expect(await hasKey(account.id)).toBe(true)
		expect(await getKey(account.id)).not.toBeNull()
	})

	it('rejects a wrong master password and stays locked', async () => {
		const account = await newAccount()
		await expect(unlock(account.id, { type: 'masterPassword', masterPassword: envelope.wrongPassword }))
			.rejects.toMatchObject({ code: 'invalid_master_password' })
		expect(await hasKey(account.id)).toBe(false)
	})

	describe('after the master password changed in the web app', () => {
		it('fetches the new suite once and unlocks with the new password', async () => {
			const account = await newAccount()
			await browser.storage.local.set({ [suiteKey(account.id)]: staleSuite() })
			fetchMock.mockResolvedValue(new Response(JSON.stringify([suiteRow])))
			await unlock(account.id, password)
			expect(fetchMock).toHaveBeenCalledOnce()
			expect(await hasKey(account.id)).toBe(true)
			expect((await browser.storage.local.get(suiteKey(account.id)))[suiteKey(account.id)]).toEqual(suiteRow)
		})

		it('stays invalid when the server has the same suite', async () => {
			const account = await newAccount()
			fetchMock.mockResolvedValue(new Response(JSON.stringify([suiteRow])))
			await expect(unlock(account.id, { type: 'masterPassword', masterPassword: envelope.wrongPassword }))
				.rejects.toMatchObject({ code: 'invalid_master_password' })
			expect(fetchMock).toHaveBeenCalledOnce()
		})

		it.each([
			['unlock_blocked', [{ ...suiteRow, privateKey: undefined, unlockBlocked: 'two_factor_required' }]],
			['no_active_suite', []],
		])('reports %s from the check instead of a wrong password', async (code, rows) => {
			const account = await newAccount()
			fetchMock.mockResolvedValue(new Response(JSON.stringify(rows)))
			await expect(unlock(account.id, { type: 'masterPassword', masterPassword: envelope.wrongPassword }))
				.rejects.toMatchObject({ code })
		})

		it('reports a wrong password, not offline, when the check cannot reach the server', async () => {
			const account = await newAccount()
			fetchMock.mockRejectedValue(new TypeError('offline'))
			await expect(unlock(account.id, { type: 'masterPassword', masterPassword: envelope.wrongPassword }))
				.rejects.toMatchObject({ code: 'invalid_master_password' })
		})
	})

	it('fetches and caches the suite when nothing is cached', async () => {
		const account = await newAccount()
		await browser.storage.local.remove(suiteKey(account.id))
		fetchMock.mockResolvedValue(new Response(JSON.stringify([suiteRow])))
		await unlock(account.id, password)
		expect(fetchMock).toHaveBeenCalledOnce()
		expect((await browser.storage.local.get(suiteKey(account.id)))[suiteKey(account.id)]).toEqual(suiteRow)
	})

	it('reports an uncached suite while offline', async () => {
		const account = await newAccount()
		await browser.storage.local.remove(suiteKey(account.id))
		fetchMock.mockRejectedValue(new TypeError('offline'))
		await expect(unlock(account.id, password)).rejects.toMatchObject({ code: 'offline_no_cache' })
	})

	it('refuses a logged out account', async () => {
		const account = await newAccount()
		await markLoggedOut(account.id)
		await expect(unlock(account.id, password)).rejects.toMatchObject({ code: 'session_revoked' })
		expect(fetchMock).not.toHaveBeenCalled()
	})
})

describe('lock and log out', () => {
	it('lock keeps the app password and the suite', async () => {
		const account = await newAccount()
		await unlock(account.id, password)
		await lock(account.id)
		expect(await hasKey(account.id)).toBe(false)
		expect((await getAccount(account.id))?.appPassword).toBe('pw')
		expect(await browser.storage.local.get(suiteKey(account.id))).toHaveProperty(suiteKey(account.id))
	})

	it('lock all locks every account', async () => {
		const a = await newAccount('a')
		const b = await newAccount('b')
		await unlock(a.id, password)
		await unlock(b.id, password)
		await lockAll()
		expect([await hasKey(a.id), await hasKey(b.id)]).toEqual([false, false])
	})

	it('the timeout action Log out keeps the identity', async () => {
		const account = await newAccount()
		await unlock(account.id, password)
		await logoutForTimeout(account.id)
		expect(await getAccount(account.id)).toMatchObject({ uid: 'alice', appPassword: null })
		expect(await hasKey(account.id)).toBe(false)
	})
})

describe('checkSuite', () => {
	it('locks when the epoch changed', async () => {
		const account = await newAccount()
		await unlock(account.id, password)
		expect(await checkSuite(account.id, { ...suiteRow, unlockKeyEpoch: 2 })).toBe(true)
		expect(await hasKey(account.id)).toBe(false)
		expect((await browser.storage.local.get(suiteKey(account.id)))[suiteKey(account.id)]).toMatchObject({ unlockKeyEpoch: 2 })
	})

	it('leaves an unchanged suite unlocked', async () => {
		const account = await newAccount()
		await unlock(account.id, password)
		expect(await checkSuite(account.id, { ...suiteRow })).toBe(false)
		expect(await hasKey(account.id)).toBe(true)
	})
})

describe('announcing a lock', () => {
	/** Records storage writes and broadcasts in the order they happen. */
	function trace() {
		const events: string[] = []
		vi.spyOn(browser.storage.local, 'set').mockImplementation(async () => void events.push('write'))
		vi.spyOn(browser.runtime, 'sendMessage').mockImplementation(async (message) => void events.push((message as unknown as { kind: string }).kind))
		return events
	}

	it('tells the popup only after a logout finished writing', async () => {
		const account = await newAccount()
		const events = trace()
		await markLoggedOut(account.id, true)
		expect(events.at(-1)).toBe('vault.locked')
		expect(events).toContain('write')
	})

	it('resets the remembered tab only when the active account locks', async () => {
		const alice = await newAccount('alice')
		const bob = await newAccount('bob')
		await setActive(alice.id)
		await sessionValues.set({ [LAST_TAB_KEY]: 'send' })
		await lock(bob.id)
		expect(await sessionValues.get(LAST_TAB_KEY)).toBe('send')
		await lock(alice.id)
		expect(await sessionValues.get(LAST_TAB_KEY)).toBeUndefined()
	})
})

describe('a logout racing an unlock', () => {
	it('takes the key back off session and disk', async () => {
		const account = await newAccount()
		await writeSettings(account.id, { vaultTimeout: 'never', vaultTimeoutAction: 'lock' })
		// The logout runs to completion right as unlock stores the key.
		const set = browser.storage.session.set.bind(browser.storage.session)
		let loggedOut = false
		vi.spyOn(browser.storage.session, 'set').mockImplementation(async (values) => {
			if (!loggedOut && Object.keys(values).some((key) => key.startsWith('privateKeyPkcs8.'))) {
				loggedOut = true
				await markLoggedOut(account.id)
			}
			return set(values)
		})
		await expect(unlock(account.id, password)).rejects.toMatchObject({ code: 'session_revoked' })
		expect(await hasKey(account.id)).toBe(false)
		expect(await browser.storage.local.get([`neverLockKey.${account.id}`, `suite.${account.id}`])).toEqual({})
	})
})

describe('a suite change racing an unlock', () => {
	/** `sync` runs right as the unlock stores its key. */
	function syncDuringUnlock(sync: () => Promise<unknown>) {
		const set = browser.storage.session.set.bind(browser.storage.session)
		let synced = false
		vi.spyOn(browser.storage.session, 'set').mockImplementation(async (values) => {
			if (!synced && Object.keys(values).some((key) => key.startsWith('privateKeyPkcs8.'))) {
				synced = true
				await set(values)
				await sync()
				return
			}
			return set(values)
		})
	}

	it('unlocks again with the new suite when the password opens it', async () => {
		const account = await newAccount()
		syncDuringUnlock(() => checkSuite(account.id, { ...suiteRow, unlockKeyEpoch: suiteRow.unlockKeyEpoch + 1 }))
		await unlock(account.id, password)
		expect(await hasKey(account.id)).toBe(true)
		expect((await getAccount(account.id))?.keyChanged).toBe(false)
	})

	it('keeps no key from the old suite when the password does not open the new one', async () => {
		const account = await newAccount()
		syncDuringUnlock(() => checkSuite(account.id, { ...staleSuite(), unlockKeyEpoch: suiteRow.unlockKeyEpoch + 1 }))
		await expect(unlock(account.id, password)).rejects.toMatchObject({ code: 'invalid_master_password' })
		expect(await hasKey(account.id)).toBe(false)
		expect((await getAccount(account.id))?.keyChanged).toBe(true)
	})

	it('does not get past a two-factor block that lands during the unlock', async () => {
		const account = await newAccount()
		const withheld: Partial<typeof suiteRow> = { ...suiteRow }
		delete withheld.privateKey
		fetchMock.mockResolvedValue(new Response(JSON.stringify([{ ...withheld, unlockBlocked: 'two_factor_required' }])))
		syncDuringUnlock(() => blockUnlock(account.id))
		await expect(unlock(account.id, password)).rejects.toMatchObject({ code: 'unlock_blocked' })
		expect(await hasKey(account.id)).toBe(false)
	})

	it('keeps the key changed notice a sync sets after the unlock cleared it', async () => {
		const account = await newAccount()
		await updateAccount(account.id, { keyChanged: true })
		// Any write after the key is stored must not clear the notice again.
		syncDuringUnlock(() => updateAccount(account.id, { keyChanged: true }))
		await unlock(account.id, password)
		expect((await getAccount(account.id))?.keyChanged).toBe(true)
	})
})
