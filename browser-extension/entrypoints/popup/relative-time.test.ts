import { describe, expect, it } from 'vitest'
import { relativeTime } from './relative-time'

describe('relativeTime', () => {
	const now = Date.parse('2026-05-01T12:00:00Z')
	it.each([
		['2026-05-01T11:59:30Z', 'just now'],
		['2026-05-01T11:55:00Z', '5 minutes ago'],
		['2026-05-01T10:00:00Z', '2 hours ago'],
		['2026-04-30T12:00:00Z', 'yesterday'],
		['2026-04-28T12:00:00Z', '3 days ago'],
	])('%s → %s', (iso, text) => {
		expect(relativeTime(iso, now)).toBe(text)
	})
})
