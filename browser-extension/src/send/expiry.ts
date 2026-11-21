/** Send expiry and view limits. Bitwarden's presets; the caps are Keepiq's (`EphemeralSendService`). */

export const EXPIRY_PRESETS = ['1h', '1d', '2d', '3d', '7d', '30d', 'custom'] as const
export type ExpiryPreset = typeof EXPIRY_PRESETS[number]

export const DEFAULT_EXPIRY: ExpiryPreset = '7d'

const HOUR = 3600

const PRESET_HOURS: Record<Exclude<ExpiryPreset, 'custom'>, number> = {
	'1h': 1,
	'1d': 24,
	'2d': 48,
	'3d': 72,
	'7d': 168,
	'30d': 720,
}

export const CUSTOM_HOURS = { min: 1, max: 720 } as const

export const TTL_CAP_SECONDS = 2592000
/** The shortest preset; the extension never makes a send that does not expire. */
export const MIN_TTL_SECONDS = HOUR

export const MAX_VIEWS_CAP = 100
export const DEFAULT_MAX_VIEWS = 1

export function isValidCustomHours(hours: number): boolean {
	return Number.isInteger(hours) && hours >= CUSTOM_HOURS.min && hours <= CUSTOM_HOURS.max
}

export function isValidMaxViews(views: number): boolean {
	return Number.isInteger(views) && views >= 1 && views <= MAX_VIEWS_CAP
}

/** `null` when `custom` has no valid hour count. */
export function presetToTtlSeconds(preset: ExpiryPreset, customHours?: number): number | null {
	if (preset !== 'custom') return PRESET_HOURS[preset] * HOUR
	return customHours !== undefined && isValidCustomHours(customHours) ? customHours * HOUR : null
}
