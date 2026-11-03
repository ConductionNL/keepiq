import { describe, expect, it } from 'vitest'
import { setLocale } from '@/src/testing/i18n'
import { formatDuration, timeoutChoice, timeoutOf, timeoutOptions } from './timeout'

describe('formatDuration', () => {
	it.each([[150, '2 h 30 min'], [240, '4 h'], [30, '30 min'], [1, '1 min']])('%i minutes → %s', (minutes, text) => {
		expect(formatDuration(minutes)).toBe(text)
	})

	it('reads in the catalog\'s language', () => {
		setLocale('nl')
		expect(formatDuration(150)).toBe('2 u 30 min')
	})
})

describe('timeoutOptions', () => {
	const labels = (max: number | null, current: Parameters<typeof timeoutOptions>[1] = 15) => timeoutOptions(max, current).map((option) => option.label)

	it('offers everything before a maximum was read', () => {
		expect(labels(null)).toEqual([
			'Immediately', '1 minute', '5 minutes', '15 minutes', '30 minutes', '1 hour', '4 hours', 'On system lock', 'On browser restart', 'Never', 'Custom',
		])
	})

	it('leaves out what outlasts a maximum, and words On system lock with it', () => {
		expect(labels(60)).toEqual([
			'Immediately', '1 minute', '5 minutes', '15 minutes', '30 minutes', '1 hour', 'On system lock, or after 1 h idle (set by your organisation)', 'Custom',
		])
	})

	it('gives a custom value its own option among the durations, keeping Custom to edit it', () => {
		const options = timeoutOptions(null, 150)
		expect(options.map((option) => option.label)).toEqual([
			'Immediately', '1 minute', '5 minutes', '15 minutes', '30 minutes', '1 hour', '2 h 30 min', '4 hours', 'On system lock', 'On browser restart', 'Never', 'Custom',
		])
		expect(options.find((option) => option.label === '2 h 30 min')!.value).toBe('150')
		expect(timeoutChoice(150)).toBe('150')
		expect(timeoutChoice('onSystemLock')).toBe('onSystemLock')
	})

	it('reads a key back as the stored form', () => {
		expect(timeoutOf('150')).toBe(150)
		expect(timeoutOf('onSystemLock')).toBe('onSystemLock')
	})

	it('does the same for a maximum that is no preset', () => {
		expect(labels(120, 120)).toEqual([
			'Immediately', '1 minute', '5 minutes', '15 minutes', '30 minutes', '1 hour', '2 h', 'On system lock, or after 2 h idle (set by your organisation)', 'Custom',
		])
	})
})
