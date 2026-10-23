import { describe, expect, it } from 'vitest'
import {
	CUSTOM_HOURS, DEFAULT_EXPIRY, DEFAULT_MAX_VIEWS, EXPIRY_PRESETS, isValidCustomHours, isValidMaxViews, MAX_VIEWS_CAP, presetToTtlSeconds, TTL_CAP_SECONDS,
} from './expiry'

describe('presetToTtlSeconds', () => {
	it('maps every fixed preset to its seconds', () => {
		expect(presetToTtlSeconds('1h')).toBe(3600)
		expect(presetToTtlSeconds('1d')).toBe(86400)
		expect(presetToTtlSeconds('2d')).toBe(172800)
		expect(presetToTtlSeconds('3d')).toBe(259200)
		expect(presetToTtlSeconds('7d')).toBe(604800)
		expect(presetToTtlSeconds('30d')).toBe(2592000)
	})

	it('keeps every preset within the server cap', () => {
		for (const preset of EXPIRY_PRESETS) {
			const ttl = presetToTtlSeconds(preset, CUSTOM_HOURS.max)
			expect(ttl).toBeGreaterThan(0)
			expect(ttl).toBeLessThanOrEqual(TTL_CAP_SECONDS)
		}
	})

	it('takes custom hours from 1 to 720', () => {
		expect(presetToTtlSeconds('custom', 1)).toBe(3600)
		expect(presetToTtlSeconds('custom', 720)).toBe(TTL_CAP_SECONDS)
	})

	it('refuses custom without a valid hour count', () => {
		expect(presetToTtlSeconds('custom')).toBeNull()
		expect(presetToTtlSeconds('custom', 0)).toBeNull()
		expect(presetToTtlSeconds('custom', 721)).toBeNull()
		expect(presetToTtlSeconds('custom', 1.5)).toBeNull()
	})

	it('defaults to 7 days', () => {
		expect(DEFAULT_EXPIRY).toBe('7d')
	})
})

describe('isValidCustomHours', () => {
	it('accepts whole hours in range only', () => {
		expect(isValidCustomHours(1)).toBe(true)
		expect(isValidCustomHours(720)).toBe(true)
		expect(isValidCustomHours(0)).toBe(false)
		expect(isValidCustomHours(721)).toBe(false)
		expect(isValidCustomHours(Number.NaN)).toBe(false)
	})
})

describe('isValidMaxViews', () => {
	it('accepts 1 to the cap of 100', () => {
		expect(MAX_VIEWS_CAP).toBe(100)
		expect(isValidMaxViews(DEFAULT_MAX_VIEWS)).toBe(true)
		expect(isValidMaxViews(100)).toBe(true)
		expect(isValidMaxViews(0)).toBe(false)
		expect(isValidMaxViews(101)).toBe(false)
		expect(isValidMaxViews(2.5)).toBe(false)
	})

	it('defaults to one view', () => {
		expect(DEFAULT_MAX_VIEWS).toBe(1)
	})
})
