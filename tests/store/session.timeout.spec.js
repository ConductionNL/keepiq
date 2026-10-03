/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The inactivity lock follows activity, and the timeout the user picks is
 * saved and applied (crypto-06, crypto-07).
 *
 * @spec openspec/specs/vault-session-lock/spec.md#requirement-inactivity-lock
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useSessionStore } from '../../src/store/modules/session.js'

const MINUTE = 60 * 1000

/**
 * An unlocked store at the fake clock's current time.
 *
 * @return {object} The session store.
 */
function unlockedStore() {
	const store = useSessionStore()
	store.cryptoKey = {}
	store.lastActivity = Date.now()
	return store
}

describe('session store inactivity lock', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		vi.useFakeTimers()
		vi.setSystemTime(new Date('2026-09-29T10:00:00Z'))
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('keeps an active user unlocked past the timeout', () => {
		const store = unlockedStore()
		store.applyTimeoutChoice('10min')

		for (let minute = 1; minute <= 25; minute++) {
			vi.advanceTimersByTime(MINUTE)
			store.noteActivity()
			store.checkTimeout()
		}

		expect(store.isLocked).toBe(false)
	})

	it('locks an idle user after the timeout and clears the key', () => {
		const store = unlockedStore()
		store.applyTimeoutChoice('10min')

		vi.advanceTimersByTime(10 * MINUTE + 1000)
		store.checkTimeout()

		expect(store.isLocked).toBe(true)
		expect(store.cryptoKey).toBeNull()
	})

	it('throttles activity to one write per 15 seconds', () => {
		const store = unlockedStore()
		const start = store.lastActivity

		vi.advanceTimersByTime(5000)
		store.noteActivity()
		expect(store.lastActivity).toBe(start)

		vi.advanceTimersByTime(10000)
		store.noteActivity()
		expect(store.lastActivity).toBe(start + 15000)
	})

	it('ignores activity while locked', () => {
		const store = useSessionStore()
		const before = store.lastActivity
		vi.advanceTimersByTime(MINUTE)
		store.noteActivity()
		expect(store.lastActivity).toBe(before)
	})

	it('never locks on an idle timer for the Nextcloud session choice', () => {
		const store = unlockedStore()
		store.applyTimeoutChoice('session')

		expect(store.timeout).toBeNull()
		vi.advanceTimersByTime(60 * MINUTE)
		store.checkTimeout()

		expect(store.isLocked).toBe(false)
	})

	it('maps the choices to milliseconds and an unknown value to ten minutes', () => {
		const store = useSessionStore()
		store.applyTimeoutChoice('30min')
		expect(store.timeout).toBe(30 * MINUTE)
		expect(store.timeoutChoice).toBe('30min')
		store.applyTimeoutChoice('bogus')
		expect(store.timeout).toBe(10 * MINUTE)
		expect(store.timeoutChoice).toBe('10min')
	})
})

describe('session store saved timeout', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('loads the saved choice from the user settings', async () => {
		const get = vi
			.spyOn(axios, 'get')
			.mockResolvedValue({ data: { session_timeout: '30min' } })
		const store = useSessionStore()

		await store.loadTimeoutPreference()

		expect(get).toHaveBeenCalledWith('/apps/keepiq/api/settings/user')
		expect(store.timeout).toBe(30 * MINUTE)
		expect(store.timeoutChoice).toBe('30min')
	})

	it('keeps ten minutes when the settings cannot be read', async () => {
		vi.spyOn(axios, 'get').mockRejectedValue(new Error('offline'))
		const store = useSessionStore()

		await store.loadTimeoutPreference()

		expect(store.timeout).toBe(10 * MINUTE)
	})

	it('saves a choice through PUT and applies it at once', async () => {
		const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
		const store = useSessionStore()

		await store.saveTimeoutPreference('session')

		expect(put).toHaveBeenCalledWith('/apps/keepiq/api/settings/user', {
			session_timeout: 'session',
		})
		expect(store.timeout).toBeNull()
		expect(store.timeoutChoice).toBe('session')
	})
})
