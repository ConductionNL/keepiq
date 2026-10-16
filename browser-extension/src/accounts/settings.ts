/** Minutes, or one of the untimed options. Custom is any other number of minutes. */
export type VaultTimeout = number | 'immediately' | 'onSystemLock' | 'onRestart' | 'never'
export type VaultTimeoutAction = 'lock' | 'logout'

export interface AccountSettings {
	vaultTimeout: VaultTimeout
	vaultTimeoutAction: VaultTimeoutAction
}

export const DEFAULT_SETTINGS: AccountSettings = { vaultTimeout: 15, vaultTimeoutAction: 'lock' }

export const settingsKey = (accountId: string) => `settings.${accountId}`

function isTimeout(value: unknown): value is VaultTimeout {
	return (typeof value === 'number' && Number.isInteger(value) && value > 0)
		|| value === 'immediately' || value === 'onSystemLock' || value === 'onRestart' || value === 'never'
}

/** Invalid stored values read as their default. */
export async function readSettings(accountId: string): Promise<AccountSettings> {
	const key = settingsKey(accountId)
	const stored = (await browser.storage.local.get(key))[key] as Partial<AccountSettings> | undefined
	return {
		vaultTimeout: isTimeout(stored?.vaultTimeout) ? stored.vaultTimeout : DEFAULT_SETTINGS.vaultTimeout,
		vaultTimeoutAction: stored?.vaultTimeoutAction === 'logout' ? 'logout' : 'lock',
	}
}

/** Raw write; once an account may be unlocked, go through `updateSettings` in the store so the "Never" key follows. */
export async function writeSettings(accountId: string, settings: AccountSettings): Promise<void> {
	await browser.storage.local.set({ [settingsKey(accountId)]: settings })
}

/** The one place an admin policy will clamp the timeout (ADR-002). */
export const TIMEOUT_POLICY: { maxMinutes: number | null; forcedAction: VaultTimeoutAction | null } = {
	maxMinutes: null,
	forcedAction: null,
}

/** The settings after the policy; everything that acts on a timeout reads these. */
export async function effectiveSettings(accountId: string): Promise<AccountSettings> {
	const settings = await readSettings(accountId)
	const { maxMinutes, forcedAction } = TIMEOUT_POLICY
	let { vaultTimeout } = settings
	if (maxMinutes !== null && vaultTimeout !== 'immediately' && (typeof vaultTimeout !== 'number' || vaultTimeout > maxMinutes)) {
		vaultTimeout = maxMinutes
	}
	return { vaultTimeout, vaultTimeoutAction: forcedAction ?? settings.vaultTimeoutAction }
}
