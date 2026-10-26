import { THEMES, type GlobalSettings } from '@/src/messages'

/** Account ids are UUIDs, so this never meets an account's `settings.<id>`. */
export const GLOBAL_SETTINGS_KEY = 'settings.global'

export const DEFAULT_GLOBAL_SETTINGS: GlobalSettings = { theme: 'system' }

const isTheme = (value: unknown): value is GlobalSettings['theme'] => THEMES.includes(value as never)

/** Invalid stored values read as their default. */
export async function readGlobalSettings(): Promise<GlobalSettings> {
	const stored = (await browser.storage.local.get(GLOBAL_SETTINGS_KEY))[GLOBAL_SETTINGS_KEY] as Partial<GlobalSettings> | undefined
	return { theme: isTheme(stored?.theme) ? stored.theme : DEFAULT_GLOBAL_SETTINGS.theme }
}

/** Patch values that fail validation are ignored; returns the record as stored. */
export async function updateGlobalSettings(patch: Partial<GlobalSettings>): Promise<GlobalSettings> {
	const settings = await readGlobalSettings()
	if (isTheme(patch.theme)) settings.theme = patch.theme
	await browser.storage.local.set({ [GLOBAL_SETTINGS_KEY]: settings })
	return settings
}
