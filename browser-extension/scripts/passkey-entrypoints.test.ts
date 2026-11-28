import { describe, expect, it } from 'vitest'
import main from '../entrypoints/passkey-page-main.content'
import injected from '../entrypoints/passkey-page'

interface Targets {
	include?: string[]
	exclude?: string[]
}

/** WXT's rule for which browsers build an entrypoint; it skips one that lists both. */
function builtFor(entry: Targets, browser: string): boolean {
	expect(entry.include?.length && entry.exclude?.length).toBeFalsy()
	if (entry.exclude?.length) return !entry.exclude.includes(browser)
	if (entry.include?.length) return entry.include.includes(browser)
	return true
}

describe('passkey page script per browser', () => {
	it.each([['chrome'], ['edge'], ['opera'], ['safari']])('%s runs it as a MAIN world content script at document_start', (browser) => {
		expect(builtFor(main, browser)).toBe(true)
		expect(builtFor(injected, browser)).toBe(false)
		expect(main).toMatchObject({ world: 'MAIN', runAt: 'document_start', allFrames: true, matches: ['*://*/*'] })
	})

	it('Firefox gets the script the relay injects instead', () => {
		expect(builtFor(main, 'firefox')).toBe(false)
		expect(builtFor(injected, 'firefox')).toBe(true)
	})
})
