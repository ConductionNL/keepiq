/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * App.vue is wired to the inactivity lock and the saved timeout
 * (crypto-06, crypto-07). The handlers are called off the options object
 * with a stubbed `this`, like appLockWiring.spec.js, so the test covers the
 * wiring and not the whole shell.
 *
 * @spec openspec/specs/vault-session-lock/spec.md#requirement-inactivity-lock
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { describe, expect, it, vi } from 'vitest'
import App from '../../src/App.vue'

describe('App.vue session wiring', () => {
	it('records activity through the session store', () => {
		const noteActivity = vi.fn()

		App.methods.handleActivity.call({ sessionStore: { noteActivity } })

		expect(noteActivity).toHaveBeenCalledTimes(1)
	})

	it('saves a timeout choice through the session store', () => {
		const saveTimeoutPreference = vi.fn().mockResolvedValue(undefined)

		App.methods.onTimeoutChange.call(
			{ sessionStore: { saveTimeoutPreference } },
			'30min',
		)

		expect(saveTimeoutPreference).toHaveBeenCalledWith('30min')
	})

	it('no longer maps the choice in memory with a ten-minute fallback', () => {
		// The old saveTimeout turned "Nextcloud session" (0) into 10 minutes.
		expect(App.methods.saveTimeout).toBeUndefined()
	})

	it('listens for activity on mount and stops on unmount', async () => {
		// created() first initialises the settings store over axios.
		setActivePinia(createPinia())
		vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
		const add = vi.spyOn(document, 'addEventListener')
		const remove = vi.spyOn(document, 'removeEventListener')
		const context = {
			sessionStore: { loadTimeoutPreference: vi.fn(), checkTimeout: vi.fn(), isLocked: true },
			offlineStore: { bindConnectivity: vi.fn(), ensureLockHook: vi.fn(), online: false },
			registerServiceWorker: vi.fn(),
			handleActivity: () => {},
			handleVisibilityChange: () => {},
			handleBeforeUnload: () => {},
		}

		await App.created.call(context)
		const added = add.mock.calls.filter(([, fn]) => fn === context.handleActivity).map(([type]) => type)
		App.beforeUnmount.call({ ...context, timeoutInterval: context.timeoutInterval })
		const removed = remove.mock.calls.filter(([, fn]) => fn === context.handleActivity).map(([type]) => type)

		expect(context.sessionStore.loadTimeoutPreference).toHaveBeenCalled()
		expect(added).toEqual(expect.arrayContaining(['pointerdown', 'keydown', 'scroll']))
		expect(removed).toEqual(added)
		clearInterval(context.timeoutInterval)
	})
})
