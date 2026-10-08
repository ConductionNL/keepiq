import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import { effectiveSettings, TIMEOUT_POLICY, writeSettings, type AccountSettings } from '@/src/accounts/settings'
import { addAccount, getAccount } from '@/src/accounts/store'
import { pemToPkcs8, toBase64 } from '@/src/crypto'
import { envelope, suiteRow } from '@/src/testing/vectors'
import { hasKey, putKey } from './key-store'
import { enforce, onIdleStateChanged, onPopupClosed, syncAlarm, TIMEOUT_ALARM, touch } from './timeout'

const pkcs8 = toBase64(pemToPkcs8(envelope.privateKeyPem))
const MINUTE = 60_000

async function unlocked(settings: AccountSettings, uid = 'alice') {
	const account = await addAccount({
		serverUrl: 'https://cloud.example.org', uid, loginName: uid, displayName: uid, email: null, avatarDataUrl: null, appPassword: 'pw',
	}, { ...suiteRow })
	await writeSettings(account.id, settings)
	await putKey(account.id, pkcs8)
	return account.id
}

beforeEach(() => {
	fakeBrowser.reset()
})
afterEach(() => {
	TIMEOUT_POLICY.maxMinutes = null
	TIMEOUT_POLICY.forcedAction = null
})

describe('timed options', () => {
	it('locks once the timeout has passed since the last interaction', async () => {
		const id = await unlocked({ vaultTimeout: 5, vaultTimeoutAction: 'lock' })
		const now = Date.now()
		await touch(now)
		await enforce(now + 4 * MINUTE)
		expect(await hasKey(id)).toBe(true)
		await enforce(now + 6 * MINUTE)
		expect(await hasKey(id)).toBe(false)
	})

	it('restarts the clock on interaction', async () => {
		const id = await unlocked({ vaultTimeout: 5, vaultTimeoutAction: 'lock' })
		const now = Date.now()
		await touch(now + 4 * MINUTE)
		await enforce(now + 8 * MINUTE)
		expect(await hasKey(id)).toBe(true)
	})

	it('logs out when that is the action', async () => {
		const id = await unlocked({ vaultTimeout: 1, vaultTimeoutAction: 'logout' })
		await enforce(Date.now() + 2 * MINUTE)
		expect((await getAccount(id))?.appPassword).toBeNull()
	})

	it('leaves untimed options alone', async () => {
		const id = await unlocked({ vaultTimeout: 'never', vaultTimeoutAction: 'lock' })
		await enforce(Date.now() + 10_000 * MINUTE)
		expect(await hasKey(id)).toBe(true)
	})
})

describe('event options', () => {
	it('Immediately locks when the popup closes', async () => {
		const immediate = await unlocked({ vaultTimeout: 'immediately', vaultTimeoutAction: 'lock' }, 'a')
		const timed = await unlocked({ vaultTimeout: 15, vaultTimeoutAction: 'lock' }, 'b')
		await onPopupClosed()
		expect(await hasKey(immediate)).toBe(false)
		expect(await hasKey(timed)).toBe(true)
	})

	it('On system lock locks on the idle state locked only', async () => {
		const id = await unlocked({ vaultTimeout: 'onSystemLock', vaultTimeoutAction: 'lock' })
		await onIdleStateChanged('idle')
		expect(await hasKey(id)).toBe(true)
		await onIdleStateChanged('locked')
		expect(await hasKey(id)).toBe(false)
	})
})

describe('alarm', () => {
	it('runs only while an account is unlocked', async () => {
		await syncAlarm()
		expect(await browser.alarms.get(TIMEOUT_ALARM)).toBeUndefined()
		const id = await unlocked({ vaultTimeout: 1, vaultTimeoutAction: 'lock' })
		await syncAlarm()
		expect(await browser.alarms.get(TIMEOUT_ALARM)).toMatchObject({ periodInMinutes: 1 })
		await enforce(Date.now() + 2 * MINUTE)
		expect(await hasKey(id)).toBe(false)
		expect(await browser.alarms.get(TIMEOUT_ALARM)).toBeUndefined()
	})
})

describe('policy', () => {
	it('clamps the timeout and forces the action', async () => {
		await writeSettings('x', { vaultTimeout: 'never', vaultTimeoutAction: 'lock' })
		TIMEOUT_POLICY.maxMinutes = 60
		TIMEOUT_POLICY.forcedAction = 'logout'
		expect(await effectiveSettings('x')).toEqual({ vaultTimeout: 60, vaultTimeoutAction: 'logout' })
	})
})
