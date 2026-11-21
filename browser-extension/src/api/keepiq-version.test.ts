import { describe, expect, it } from 'vitest'
import { KEEPIQ_FEATURES, meetsVersion, MIN_KEEPIQ_VERSION, parseVersion, supportedKeepiq } from './keepiq-version'

describe('parseVersion', () => {
	it('reads a release, a beta and an unstable build', () => {
		expect(parseVersion('0.3.12')).toEqual({ core: [0, 3, 12], unstable: false })
		expect(parseVersion('0.3.12-beta.20261008125823')).toEqual({ core: [0, 3, 12], unstable: false })
		expect(parseVersion('0.3.9-unstable.20261019140000')).toEqual({ core: [0, 3, 9], unstable: true })
	})

	it('refuses anything else', () => {
		for (const version of ['', '0.3', 'v0.3.12', '0.3.12.1', 'latest']) expect(parseVersion(version)).toBeNull()
	})
})

describe('meetsVersion', () => {
	it('compares each part as a number', () => {
		expect(meetsVersion('0.3.12', '0.3.12')).toBe(true)
		expect(meetsVersion('0.3.13', '0.3.12')).toBe(true)
		expect(meetsVersion('0.10.0', '0.3.12')).toBe(true)
		expect(meetsVersion('1.0.0', '0.3.12')).toBe(true)
		expect(meetsVersion('0.3.11', '0.3.12')).toBe(false)
		expect(meetsVersion('0.2.99', '0.3.12')).toBe(false)
	})

	it('counts a beta as its release', () => {
		expect(meetsVersion('0.3.12-beta.20261101000000', '0.3.12')).toBe(true)
		expect(meetsVersion('0.3.11-beta.20261008125823', '0.3.12')).toBe(false)
	})

	it('lets an unstable build through, as a development checkout reports an old version', () => {
		expect(meetsVersion('0.3.9-unstable.20261019140000', '0.3.12')).toBe(true)
	})

	it('refuses a missing or unreadable version', () => {
		for (const version of [null, undefined, '', 'latest']) expect(meetsVersion(version, '0.3.12')).toBe(false)
	})
})

describe('supportedKeepiq', () => {
	it('needs the minimum', () => {
		expect(supportedKeepiq(MIN_KEEPIQ_VERSION)).toBe(true)
		expect(supportedKeepiq('0.3.10')).toBe(false)
	})
})

describe('KEEPIQ_FEATURES', () => {
	it('names only versions newer than the minimum', () => {
		for (const since of Object.values(KEEPIQ_FEATURES) as string[]) {
			expect(parseVersion(since)).not.toBeNull()
			expect(meetsVersion(MIN_KEEPIQ_VERSION, since)).toBe(false)
		}
	})
})
