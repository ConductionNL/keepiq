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

export async function writeSettings(accountId: string, settings: AccountSettings): Promise<void> {
	await browser.storage.local.set({ [settingsKey(accountId)]: settings })
}
