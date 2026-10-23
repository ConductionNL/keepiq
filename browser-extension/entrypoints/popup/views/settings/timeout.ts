import { i18n } from '#i18n'
import type { VaultTimeout } from '@/src/accounts/settings'
import type { SelectOption } from '../../components/Select'

/** `Select` takes strings, so each `VaultTimeout` has a key: minutes as digits, the rest by name, and `custom` for the form. */
export type TimeoutChoice = string

const PRESET_MINUTES = [1, 5, 15, 30, 60, 240]

/** "2 h 30 min", for every duration in a note or message. */
export function formatDuration(minutes: number): string {
	const hours = Math.floor(minutes / 60)
	const rest = minutes % 60
	return [hours && i18n.t('settings.durationHours', { count: String(hours) }), (rest || !hours) && i18n.t('settings.durationMinutes', { count: String(rest) })]
		.filter(Boolean).join(' ')
}

export const timeoutChoice = (timeout: VaultTimeout): TimeoutChoice => String(timeout)

/**
 * Under any maximum, nothing that could outlast it (ADR-002). `current`, the shown timeout, gets an option
 * of its own when it is no preset, so a custom or clamped value shows as chosen and Custom stays free to edit it.
 */
export function timeoutOptions(maxIdleMinutes: number | null, current: VaultTimeout): SelectOption<TimeoutChoice>[] {
	const presets = PRESET_MINUTES.filter((minutes) => maxIdleMinutes === null || minutes <= maxIdleMinutes)
	const durations = typeof current === 'number' && !presets.includes(current) ? [...presets, current].sort((a, b) => a - b) : presets
	const minutes = durations.map((m) => ({
		value: String(m),
		label: !PRESET_MINUTES.includes(m) ? formatDuration(m) : m < 60 ? i18n.t('settings.timeoutMinutes', m) : i18n.t('settings.timeoutHours', m / 60),
	}))
	return [
		{ value: 'immediately', label: i18n.t('settings.timeoutImmediately') },
		...minutes,
		{
			value: 'onSystemLock',
			label: maxIdleMinutes === null ? i18n.t('settings.timeoutOnSystemLock') : i18n.t('settings.timeoutOnSystemLockCapped', { duration: formatDuration(maxIdleMinutes) }),
		},
		...(maxIdleMinutes === null
			? [{ value: 'onRestart', label: i18n.t('settings.timeoutOnRestart') }, { value: 'never', label: i18n.t('settings.never') }]
			: []),
		{ value: 'custom', label: i18n.t('settings.timeoutCustom') },
	]
}

/** The stored form of a chosen key; `custom` has none of its own. */
export function timeoutOf(choice: TimeoutChoice): VaultTimeout {
	const minutes = Number(choice)
	return Number.isInteger(minutes) ? minutes : choice as VaultTimeout
}
