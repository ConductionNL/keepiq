import { afterEach, describe, expect, it, vi } from 'vitest'
import { hasIdle, isChromium, isSafari } from './platform'

describe('isChromium', () => {
	afterEach(() => {
		vi.unstubAllEnvs()
	})

	it.each([['chrome', true], ['edge', true], ['opera', true], ['firefox', false], ['safari', false]])('%s → %s', (target, chromium) => {
		expect(isChromium(target)).toBe(chromium)
		vi.stubEnv('BROWSER', target)
		expect(isChromium()).toBe(chromium)
	})
})

describe('isSafari', () => {
	afterEach(() => {
		vi.unstubAllEnvs()
	})

	it.each([['safari', true], ['chrome', false], ['edge', false], ['firefox', false]])('%s → %s', (target, safari) => {
		expect(isSafari(target)).toBe(safari)
		vi.stubEnv('BROWSER', target)
		expect(isSafari()).toBe(safari)
	})
})

describe('hasIdle', () => {
	it('is true where the browser has the idle API', () => {
		Object.assign(browser, { idle: { onStateChanged: {} } })
		expect(hasIdle()).toBe(true)
	})

	it('is false on Safari, which has none', () => {
		Object.assign(browser, { idle: undefined })
		expect(hasIdle()).toBe(false)
	})
})
