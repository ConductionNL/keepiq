import { afterEach, describe, expect, it, vi } from 'vitest'
import { broadcast } from '@/src/background/broadcast'
import { clearIntent, INTENT_MS, registerAddIntent, setIntent, takeIntent } from './add-intent'

afterEach(() => {
	vi.useRealTimers()
	clearIntent()
})

describe('the Add intent', () => {
	it('is read once', () => {
		setIntent('identity')
		expect(takeIntent()).toEqual({ add: 'identity' })
		expect(takeIntent()).toBeNull()
	})

	it('expires after ten seconds', () => {
		vi.useFakeTimers({ toFake: ['Date'] })
		setIntent('card')
		vi.advanceTimersByTime(INTENT_MS)
		expect(takeIntent()).toEqual({ add: 'card' })
		setIntent('card')
		vi.advanceTimersByTime(INTENT_MS + 1)
		expect(takeIntent()).toBeNull()
		expect(INTENT_MS).toBe(10_000)
	})

	it('is cleared by a lock and by an account switch, and a later Add replaces an earlier one', () => {
		registerAddIntent()
		setIntent('login')
		broadcast({ kind: 'vault.locked' })
		expect(takeIntent()).toBeNull()
		setIntent('login')
		clearIntent()
		expect(takeIntent()).toBeNull()
		setIntent('login')
		setIntent('card')
		expect(takeIntent()).toEqual({ add: 'card' })
	})
})
