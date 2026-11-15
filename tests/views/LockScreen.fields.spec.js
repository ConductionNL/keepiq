/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The lock screen's password fields as wired in the template: what is typed
 * into the unlock field is the password the session unlocks with, and the two
 * setup fields fill the master and confirmation passwords the setup gate
 * compares. Only the stores' network actions, the router and matchMedia /
 * isSecureContext are faked.
 *
 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import LockScreen from '../../src/views/LockScreen.vue'
import { useAccountRecoveryStore } from '../../src/store/modules/accountRecovery.js'
import { useEncryptionSuiteStore } from '../../src/store/modules/encryptionSuite.js'
import { useOfflineStore } from '../../src/store/modules/offline.js'
import { usePasskeyStore } from '../../src/store/modules/passkey.js'
import { useSessionStore } from '../../src/store/modules/session.js'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

/**
 * Mount the lock screen.
 *
 * @param {object|null} suite The current suite (null = first setup).
 * @return {Promise<{wrapper: object, unlock: Function}>} The wrapper and the unlock spy.
 */
async function arrange(suite) {
	const suiteStore = useEncryptionSuiteStore()
	suiteStore.currentSuite = suite
	vi.spyOn(suiteStore, 'fetchSuite').mockResolvedValue(undefined)
	vi.spyOn(suiteStore, 'fetchMigrationStatus').mockResolvedValue(undefined)
	vi.spyOn(suiteStore, 'createSuite').mockResolvedValue(undefined)
	vi.spyOn(usePasskeyStore(), 'isUnlockOffered').mockResolvedValue(false)
	const unlock = vi.spyOn(useSessionStore(), 'unlock').mockResolvedValue(undefined)
	vi.spyOn(useAccountRecoveryStore(), 'enrolAtUnlock').mockResolvedValue('skipped')
	useOfflineStore().online = true
	const wrapper = mount(LockScreen, {
		global: {
			mocks: { $router: { push: vi.fn() }, $route: { query: {}, hash: '' } },
			stubs: {
				DeviceApprovalRequest: { template: '<div />' },
				ForgotPasswordRecovery: { template: '<div />' },
				PasswordStrengthMeter: { template: '<div />' },
				// A field whose v-model and key events behave like the real one.
				NcPasswordField: {
					props: ['modelValue'],
					emits: ['update:modelValue'],
					template:
						'<input type="password" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
				},
			},
		},
	})
	await flush()
	return { wrapper, unlock }
}

describe('LockScreen password fields', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		window.isSecureContext = true
		window.matchMedia = vi.fn(() => ({ matches: true, addEventListener() {}, removeEventListener() {} }))
	})

	afterEach(() => {
		vi.useRealTimers()
		vi.restoreAllMocks()
	})

	it('unlocks with the password typed into the unlock field on Enter', async () => {
		const { wrapper, unlock } = await arrange({ id: 7, status: 'active' })
		const inputs = wrapper.findAll('input[type="password"]')
		expect(inputs).toHaveLength(1)

		await inputs[0].setValue('typed-pw')
		expect(wrapper.vm.masterPassword).toBe('typed-pw')

		vi.useFakeTimers()
		await inputs[0].trigger('keyup.enter')
		await vi.runAllTimersAsync()
		expect(unlock).toHaveBeenCalledWith('typed-pw')
	})

	it('fills the master and confirmation passwords from the two setup fields', async () => {
		const { wrapper } = await arrange(null)
		const inputs = wrapper.findAll('input[type="password"]')
		expect(inputs).toHaveLength(2)

		await inputs[0].setValue('first-pw')
		await inputs[1].setValue('second-pw')

		expect(wrapper.vm.masterPassword).toBe('first-pw')
		expect(wrapper.vm.confirmPassword).toBe('second-pw')
	})
})
