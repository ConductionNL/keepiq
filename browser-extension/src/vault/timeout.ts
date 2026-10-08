import { effectiveSettings, type VaultTimeoutAction } from '@/src/accounts/settings'
import { accountStatus, listAccounts } from '@/src/accounts/store'
import { sessionValues, unlockedAtKey } from './key-store'
import { lock, logoutForTimeout } from './unlock'

export const TIMEOUT_ALARM = 'vault-timeout'
const LAST_INTERACTION = 'lastInteractionAt'

export async function touch(now = Date.now()): Promise<void> {
	await sessionValues.set({ [LAST_INTERACTION]: String(now) })
}

async function unlockedAccounts() {
	const unlocked = []
	for (const account of await listAccounts()) {
		if (await accountStatus(account) === 'unlocked') unlocked.push({ account, settings: await effectiveSettings(account.id) })
	}
	return unlocked
}

async function expire(accountId: string, action: VaultTimeoutAction): Promise<void> {
	if (action === 'logout') await logoutForTimeout(accountId)
	else await lock(accountId)
}

/** Locks or logs out every account past its timed option. Runs on the alarm and before every popup reply. */
export async function enforce(now = Date.now()): Promise<void> {
	const last = Number(await sessionValues.get(LAST_INTERACTION))
	for (const { account, settings } of await unlockedAccounts()) {
		if (typeof settings.vaultTimeout !== 'number') continue
		// Unlocking is an interaction too, and it is all a restored "Never" key had.
		const since = Math.max(Number.isFinite(last) ? last : 0, Number(await sessionValues.get(unlockedAtKey(account.id))) || 0)
		if (now - since >= settings.vaultTimeout * 60_000) await expire(account.id, settings.vaultTimeoutAction)
	}
	await syncAlarm()
}

/** "Immediately": the popup port disconnected. */
export async function onPopupClosed(): Promise<void> {
	for (const { account, settings } of await unlockedAccounts()) {
		if (settings.vaultTimeout === 'immediately') await expire(account.id, settings.vaultTimeoutAction)
	}
	await syncAlarm()
}

/** "On system lock". */
export async function onIdleStateChanged(state: string): Promise<void> {
	if (state !== 'locked') return
	for (const { account, settings } of await unlockedAccounts()) {
		if (settings.vaultTimeout === 'onSystemLock') await expire(account.id, settings.vaultTimeoutAction)
	}
	await syncAlarm()
}

/** The alarm runs only while something is unlocked. */
export async function syncAlarm(): Promise<void> {
	const anyUnlocked = (await unlockedAccounts()).length > 0
	const existing = await browser.alarms.get(TIMEOUT_ALARM)
	if (anyUnlocked && !existing) await browser.alarms.create(TIMEOUT_ALARM, { periodInMinutes: 1 })
	if (!anyUnlocked && existing) await browser.alarms.clear(TIMEOUT_ALARM)
}
