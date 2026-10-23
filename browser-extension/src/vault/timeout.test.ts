import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import { patchAccountPolicy } from '@/src/accounts/policy'
import { writeSettings, type AccountSettings } from '@/src/accounts/settings'
import { addAccount, getAccount } from '@/src/accounts/store'
import { pemToPkcs8, toBase64 } from '@/src/crypto'
import { envelope, suiteRow } from '@/src/testing/vectors'
import { hasKey, putKey } from './key-store'
import { enforce, expectPopout, onIdleStateChanged, onPopupClosed, onPopupOpened, syncAlarm, TIMEOUT_ALARM, touch } from './timeout'

const pkcs8 = toBase64(pemToPkcs8(envelope.privateKeyPem))
const MINUTE = 60_000

async function unlocked(settings: Partial<AccountSettings>, uid = 'alice') {
	const account = await addAccount({
		serverUrl: 'https://cloud.example.org', uid, loginName: uid, displayName: uid, email: null, avatarDataUrl: null, appPassword: 'pw', keepiqVersion: '0.3.12',
	}, { ...suiteRow })
	await writeSettings(account.id, settings)
	await putKey(account.id, pkcs8)
	return account.id
}

beforeEach(() => {
	fakeBrowser.reset()
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

describe('organisation maximum', () => {
	it('locks a stored 4 hours after 30 idle minutes under a maximum of 30', async () => {
		const id = await unlocked({ vaultTimeout: 240, vaultTimeoutAction: 'lock' })
		await patchAccountPolicy(id, { maxIdleMinutes: 30 })
		const now = Date.now()
		await touch(now)
		await enforce(now + 29 * MINUTE)
		expect(await hasKey(id)).toBe(true)
		await enforce(now + 31 * MINUTE)
		expect(await hasKey(id)).toBe(false)
	})

	it('locks On system lock after the maximum idle time, though the system stays unlocked', async () => {
		const id = await unlocked({ vaultTimeout: 'onSystemLock', vaultTimeoutAction: 'lock' })
		await patchAccountPolicy(id, { maxIdleMinutes: 60 })
		const now = Date.now()
		await touch(now)
		await enforce(now + 59 * MINUTE)
		expect(await hasKey(id)).toBe(true)
		await enforce(now + 61 * MINUTE)
		expect(await hasKey(id)).toBe(false)
	})

	it('locks Immediately after the maximum idle time, for a popup window kept open', async () => {
		const id = await unlocked({ vaultTimeout: 'immediately', vaultTimeoutAction: 'lock' })
		await patchAccountPolicy(id, { maxIdleMinutes: 60 })
		const now = Date.now()
		await touch(now)
		await enforce(now + 59 * MINUTE)
		expect(await hasKey(id)).toBe(true)
		await enforce(now + 61 * MINUTE)
		expect(await hasKey(id)).toBe(false)
	})

	it('leaves Immediately to the popup closing before the first read', async () => {
		const id = await unlocked({ vaultTimeout: 'immediately', vaultTimeoutAction: 'lock' })
		await enforce(Date.now() + 10_000 * MINUTE)
		expect(await hasKey(id)).toBe(true)
	})

	it('leaves On system lock to the system lock alone before the first read', async () => {
		const id = await unlocked({ vaultTimeout: 'onSystemLock', vaultTimeoutAction: 'lock' })
		await enforce(Date.now() + 10_000 * MINUTE)
		expect(await hasKey(id)).toBe(true)
	})

	it('locks Never after the maximum', async () => {
		const id = await unlocked({ vaultTimeout: 'never', vaultTimeoutAction: 'lock' })
		await patchAccountPolicy(id, { maxIdleMinutes: 240 })
		await enforce(Date.now() + 241 * MINUTE)
		expect(await hasKey(id)).toBe(false)
	})
})

describe('Immediately with more than one popup', () => {
	afterEach(() => {
		vi.useRealTimers()
	})

	it('waits for the last popup to close', async () => {
		const id = await unlocked({ vaultTimeout: 'immediately', vaultTimeoutAction: 'lock' })
		onPopupOpened()
		onPopupOpened()
		await onPopupClosed()
		expect(await hasKey(id)).toBe(true)
		await onPopupClosed()
		expect(await hasKey(id)).toBe(false)
	})

	it('lets a popped-out window take over from the toolbar popup', async () => {
		vi.useFakeTimers({ toFake: ['setTimeout'] })
		const id = await unlocked({ vaultTimeout: 'immediately', vaultTimeoutAction: 'lock' })
		onPopupOpened()
		expectPopout()
		const closed = onPopupClosed()
		onPopupOpened()
		await vi.runAllTimersAsync()
		await closed
		expect(await hasKey(id)).toBe(true)
		await onPopupClosed()
		expect(await hasKey(id)).toBe(false)
	})

	it('still locks when the popped-out window never connects', async () => {
		vi.useFakeTimers({ toFake: ['setTimeout'] })
		const id = await unlocked({ vaultTimeout: 'immediately', vaultTimeoutAction: 'lock' })
		onPopupOpened()
		expectPopout()
		const closed = onPopupClosed()
		await vi.runAllTimersAsync()
		await closed
		expect(await hasKey(id)).toBe(false)
	})

	it('gives no grace to a close after the popout already took over', async () => {
		vi.useFakeTimers({ toFake: ['setTimeout'] })
		const id = await unlocked({ vaultTimeout: 'immediately', vaultTimeoutAction: 'lock' })
		onPopupOpened()
		expectPopout()
		onPopupOpened()
		await onPopupClosed()
		await onPopupClosed()
		expect(await hasKey(id)).toBe(false)
	})
})
