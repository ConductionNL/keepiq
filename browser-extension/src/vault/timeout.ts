import { effectiveSettings, type VaultTimeoutAction } from '@/src/accounts/settings'
import { accountStatus, listAccounts } from '@/src/accounts/store'
import { sessionValues, unlockedAtKey } from './key-store'
import { SYNC_ALARM, SYNC_INTERVAL_MINUTES } from './sync'
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
	let expired = false
	for (const { account, settings } of await unlockedAccounts()) {
		if (typeof settings.vaultTimeout !== 'number') continue
		// Unlocking is an interaction too, and it is all a restored "Never" key had.
		const since = Math.max(Number.isFinite(last) ? last : 0, Number(await sessionValues.get(unlockedAtKey(account.id))) || 0)
		if (now - since >= settings.vaultTimeout * 60_000) {
			await expire(account.id, settings.vaultTimeoutAction)
			expired = true
		}
	}
	// Runs before every popup request, so the alarms are only touched when something changed.
	if (expired) await syncAlarm()
}

const POPOUT_GRACE_MS = 5_000
let openPopups = 0
let popoutExpectedAt = 0

/** Pop out closes the toolbar popup before its window connects. */
export function expectPopout(now = Date.now()): void {
	popoutExpectedAt = now
}

/** The window did not open, so the popup that asked stays and a close is a close again. */
export function cancelPopout(): void {
	popoutExpectedAt = 0
}

export function onPopupOpened(): void {
	openPopups++
}

/** "Immediately": the last popup port disconnected, and no popout window took over within the grace. */
export async function onPopupClosed(): Promise<void> {
	openPopups = Math.max(0, openPopups - 1)
	if (openPopups > 0) {
		// The popout already connected and took over; a later close gets no grace.
		popoutExpectedAt = 0
		return
	}
	if (Date.now() - popoutExpectedAt < POPOUT_GRACE_MS) {
		popoutExpectedAt = 0
		await new Promise((resolve) => setTimeout(resolve, POPOUT_GRACE_MS))
		if (openPopups > 0) return
	}
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

/** The timeout and vault sync alarms run only while something is unlocked. */
export async function syncAlarm(): Promise<void> {
	const anyUnlocked = (await unlockedAccounts()).length > 0
	for (const [name, periodInMinutes] of [[TIMEOUT_ALARM, 1], [SYNC_ALARM, SYNC_INTERVAL_MINUTES]] as const) {
		const existing = await browser.alarms.get(name)
		if (anyUnlocked && !existing) await browser.alarms.create(name, { periodInMinutes })
		if (!anyUnlocked && existing) await browser.alarms.clear(name)
	}
}
