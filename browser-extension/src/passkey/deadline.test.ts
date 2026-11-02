import { describe, expect, it } from 'vitest'
import { ceremonyTimeout } from './deadline'

describe('ceremonyTimeout', () => {
	it('clamps to 30 s–10 min, 5 min by default, unless verification is discouraged', () => {
		expect(ceremonyTimeout(undefined, undefined)).toBe(300_000)
		expect(ceremonyTimeout(undefined, 'required')).toBe(300_000)
		expect(ceremonyTimeout(1_000, 'preferred')).toBe(30_000)
		expect(ceremonyTimeout(60_000, 'preferred')).toBe(60_000)
		expect(ceremonyTimeout(3_600_000, 'required')).toBe(600_000)
	})

	it('clamps to 30 s–3 min, 2 min by default, when discouraged', () => {
		expect(ceremonyTimeout(undefined, 'discouraged')).toBe(120_000)
		expect(ceremonyTimeout(0, 'discouraged')).toBe(30_000)
		expect(ceremonyTimeout(600_000, 'discouraged')).toBe(180_000)
	})

	it('takes the default for a timeout that is not a finite number', () => {
		expect(ceremonyTimeout(Number.NaN, undefined)).toBe(300_000)
		expect(ceremonyTimeout(Number.POSITIVE_INFINITY, 'discouraged')).toBe(120_000)
	})
})
