/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The two-factor vault policy on the lock screen and in the offline store
 * (admin-vault-policies §3.3): a withheld key reads as a policy notice with
 * a link, never as a wrong password, and the offline snapshot is dropped.
 *
 * @spec openspec/changes/admin-vault-policies/tasks.md#3.3
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import LockScreen from '../../src/views/LockScreen.vue'
import { useEncryptionSuiteStore } from '../../src/store/modules/encryptionSuite.js'
import { useOfflineStore } from '../../src/store/modules/offline.js'
import { usePasskeyStore } from '../../src/store/modules/passkey.js'
import { useSessionStore } from '../../src/store/modules/session.js'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))
const NOTICE = '[data-testid="lock-two-factor-required"]'

/**
 * Mount the lock screen with an active suite in the given shape.
 *
 * @param {object} suite The current suite the server returned.
 * @return {Promise<object>} The wrapper.
 */
async function mountWith(suite) {
	const suiteStore = useEncryptionSuiteStore()
	suiteStore.currentSuite = suite
	vi.spyOn(suiteStore, 'fetchSuite').mockResolvedValue(undefined)
	vi.spyOn(suiteStore, 'fetchMigrationStatus').mockResolvedValue(undefined)
	vi.spyOn(usePasskeyStore(), 'isUnlockOffered').mockResolvedValue(false)
	useOfflineStore().online = true
	window.matchMedia = vi.fn(() => ({
		matches: true,
		addEventListener() {},
		removeEventListener() {},
	}))
	Object.defineProperty(window, 'isSecureContext', {
		value: true,
		configurable: true,
	})

	const wrapper = mount(LockScreen, {
		global: {
			mocks: { $router: { push: vi.fn() }, $route: { query: {}, hash: '' } },
		},
	})
	await flush()
	return wrapper
}

describe('LockScreen under the two-factor policy', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('shows the notice with a link when the suite came without its key', async () => {
		const wrapper = await mountWith({
			id: 7,
			status: 'active',
			unlockBlocked: 'two_factor_required',
		})

		const notice = wrapper.find(NOTICE)
		expect(notice.exists()).toBe(true)
		expect(notice.find('a').attributes('href')).toContain(
			'/settings/user/security',
		)
	})

	it('shows nothing for an ordinary suite', async () => {
		const wrapper = await mountWith({ id: 7, status: 'active' })

		expect(wrapper.find(NOTICE).exists()).toBe(false)
	})

	it('a refused unlock is the policy, not a wrong password, and drops the snapshot', async () => {
		const wrapper = await mountWith({ id: 7, status: 'active' })
		vi.spyOn(useSessionStore(), 'unlock').mockRejectedValue(
			Object.assign(new Error('two_factor_required'), {
				code: 'two_factor_required',
			}),
		)
		const evict = vi
			.spyOn(useOfflineStore(), 'evict')
			.mockResolvedValue(undefined)

		wrapper.vm.masterPassword = 'correct horse'
		await wrapper.vm.handleUnlock()
		await flush()

		expect(wrapper.find(NOTICE).exists()).toBe(true)
		expect(wrapper.vm.error).toBe(null)
		expect(evict).toHaveBeenCalled()
	})
})

describe('session.unlock and offline sync under the two-factor policy', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('unlock throws the policy code instead of trying to decrypt nothing', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: [
				{
					id: 's',
					status: 'active',
					certificate: 'C',
					unlockBlocked: 'two_factor_required',
				},
			],
		})
		const session = useSessionStore()
		const fromBlob = vi.spyOn(session, 'unlockFromBlob')

		await expect(session.unlock('pw')).rejects.toMatchObject({
			code: 'two_factor_required',
		})
		expect(fromBlob).not.toHaveBeenCalled()
	})

	it('a blocked manifest is not written to the offline cache', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: { suite: null, unlockBlocked: 'two_factor_required' },
		})
		const offline = useOfflineStore()
		offline.available = true
		useSessionStore().aesKey = {}

		await expect(offline.syncNow()).resolves.toBe(false)
	})
})
