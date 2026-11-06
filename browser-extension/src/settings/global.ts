import { THEMES, type GlobalSettings } from '@/src/messages'
import { pickValid, type Validators } from './pick-valid'

/** Account ids are UUIDs, so this never meets an account's `settings.<id>`. */
export const GLOBAL_SETTINGS_KEY = 'settings.global'

export const DEFAULT_GLOBAL_SETTINGS: GlobalSettings = { theme: 'system', compactMode: false, showAnimations: true, quickCopy: true, showBadgeCounter: true }

const isBoolean = (value: unknown): value is boolean => typeof value === 'boolean'

const VALIDATORS: Validators<GlobalSettings> = {
	theme: (value): value is GlobalSettings['theme'] => THEMES.includes(value as never),
	compactMode: isBoolean,
	showAnimations: isBoolean,
	quickCopy: isBoolean,
	showBadgeCounter: isBoolean,
}

const merge = (base: GlobalSettings, source: unknown): GlobalSettings => ({ ...base, ...pickValid(VALIDATORS, source) })

/** Invalid stored values read as their default. */
export async function readGlobalSettings(): Promise<GlobalSettings> {
	return merge(DEFAULT_GLOBAL_SETTINGS, (await browser.storage.local.get(GLOBAL_SETTINGS_KEY))[GLOBAL_SETTINGS_KEY])
}

/** Patch values that fail validation are ignored; returns the record as stored. */
export async function updateGlobalSettings(patch: Partial<GlobalSettings>): Promise<GlobalSettings> {
	const settings = merge(await readGlobalSettings(), patch)
	await browser.storage.local.set({ [GLOBAL_SETTINGS_KEY]: settings })
	return settings
}
