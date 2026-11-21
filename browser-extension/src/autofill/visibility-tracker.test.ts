// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { GUARD_MS } from './visibility'
import { trackVisibility } from './visibility-tracker'

afterEach(() => {
	vi.unstubAllGlobals()
	vi.useRealTimers()
})

it('allows every press without IntersectionObserver v2 (Firefox)', () => {
	vi.stubGlobal('IntersectionObserverEntry', class {})
	expect(trackVisibility(document.body).allows(Date.now())).toBe(true)
})

describe('with IntersectionObserver v2 (Chrome)', () => {
	let report: (visible: boolean) => void
	const disconnect = vi.fn()

	beforeEach(() => {
		vi.useFakeTimers({ now: 10_000 })
		vi.stubGlobal('IntersectionObserverEntry', class { get isVisible() { return false } })
		vi.stubGlobal('IntersectionObserver', class {
			constructor(callback: IntersectionObserverCallback, init: IntersectionObserverInit & { trackVisibility?: boolean; delay?: number }) {
				expect(init).toMatchObject({ trackVisibility: true, delay: 100 })
				report = (visible) => callback([{ isIntersecting: true, isVisible: visible } as never], this as never)
			}

			observe() {}
			disconnect = disconnect
		})
	})

	it('refuses until reported visible', () => {
		const tracker = trackVisibility(document.body)
		expect(tracker.allows(Date.now() + GUARD_MS * 4)).toBe(false)
	})

	it.each([[GUARD_MS - 1, false], [GUARD_MS, true]])('a press %i ms after it shows is allowed: %s', (after, allowed) => {
		const tracker = trackVisibility(document.body)
		vi.advanceTimersByTime(GUARD_MS * 2)
		report(true)
		expect(tracker.allows(Date.now() + after)).toBe(allowed)
	})

	it('refuses while reported not visible', () => {
		const tracker = trackVisibility(document.body)
		report(true)
		vi.advanceTimersByTime(GUARD_MS)
		report(false)
		expect(tracker.allows(Date.now() + GUARD_MS * 4)).toBe(false)
	})

	it('stop disconnects the observer', () => {
		trackVisibility(document.body).stop()
		expect(disconnect).toHaveBeenCalled()
	})
})
