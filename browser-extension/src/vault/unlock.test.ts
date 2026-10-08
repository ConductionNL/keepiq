import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import { addAccount, getAccount, markLoggedOut, suiteKey } from '@/src/accounts/store'
import { decodeEnvelope, encodeEnvelope } from '@/src/crypto/envelope'
import { envelope, suiteRow } from '@/src/testing/vectors'
import { getKey, hasKey } from './key-store'
import { checkSuite, lock, lockAll, logoutForTimeout, unlock } from './unlock'

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
			.rejects.toMatchObject({ code: 'invalid_master_password', message: 'Invalid master password' })
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
		await expect(unlock(account.id, password)).rejects.toMatchObject({
			code: 'offline_no_cache',
			message: 'You are offline and this vault has not been synced yet',
		})
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
