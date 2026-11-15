/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The master-password policy section: it loads the floors, clamps what it
 * submits to 12-20 characters and score 3-4, shows what the server stored
 * rather than what was typed, and reports a refused write.
 *
 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-8
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/policy/policy.js', () => ({ resetPolicyCache: vi.fn() }))

import PasswordPolicySection from '../../src/components/settings/PasswordPolicySection.vue'
import { resetPolicyCache } from '../../src/policy/policy.js'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const URL = '/apps/keepiq/api/settings'

async function mountSection() {
	const wrapper = mount(PasswordPolicySection, {
		global: { stubs: { CnSettingsSection: { template: '<section><slot /></section>' } } },
	})
	await flush()
	return wrapper
}

describe('PasswordPolicySection', () => {
	beforeEach(() => {
		vi.restoreAllMocks()
		resetPolicyCache.mockClear()
	})

	it('loads the stored floors', async () => {
		const get = vi.spyOn(axios, 'get').mockResolvedValue({
			data: { master_password_min_length: '16', master_password_min_score: '4' },
		})
		const wrapper = await mountSection()

		expect(get).toHaveBeenCalledWith(URL)
		expect(wrapper.vm.minLength).toBe(16)
		expect(wrapper.vm.minScore).toBe(4)
	})

	it('falls back to 12 and 3 when the stored values are not numbers', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { master_password_min_length: '' } })
		const wrapper = await mountSection()

		expect(wrapper.vm.minLength).toBe(12)
		expect(wrapper.vm.minScore).toBe(3)
	})

	it('shows an error when the policy cannot be loaded', async () => {
		vi.spyOn(axios, 'get').mockRejectedValue(new Error('500'))
		const wrapper = await mountSection()

		expect(wrapper.vm.error).toBe('Could not load the password policy.')
		expect(wrapper.find('[data-testid="password-policy-error"]').text()).toBe(
			'Could not load the password policy.',
		)
	})

	it('clamps the submission to the allowed bounds', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
		const wrapper = await mountSection()

		wrapper.vm.minLength = 40
		wrapper.vm.minScore = 1
		await wrapper.vm.save()

		expect(post).toHaveBeenCalledWith(URL, {
			master_password_min_length: '20',
			master_password_min_score: '3',
		})

		wrapper.vm.minLength = 4
		wrapper.vm.minScore = 9
		await wrapper.vm.save()

		expect(post).toHaveBeenLastCalledWith(URL, {
			master_password_min_length: '12',
			master_password_min_score: '4',
		})
	})

	it('shows the values the server stored and drops the cached policy', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
		vi.spyOn(axios, 'post').mockResolvedValue({
			data: { config: { master_password_min_length: '14', master_password_min_score: '4' } },
		})
		const wrapper = await mountSection()

		wrapper.vm.minLength = 18
		await wrapper.vm.save()

		expect(wrapper.vm.minLength).toBe(14)
		expect(wrapper.vm.minScore).toBe(4)
		expect(resetPolicyCache).toHaveBeenCalledTimes(1)
	})

	it('keeps the submitted values when the answer carries no config', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
		vi.spyOn(axios, 'post').mockResolvedValue({ data: { success: true } })
		const wrapper = await mountSection()

		wrapper.vm.minLength = 15
		await wrapper.vm.save()

		expect(wrapper.vm.minLength).toBe(15)
		expect(wrapper.vm.error).toBe('')
	})

	it('reports a refused write with the server message and keeps the cache', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
		vi.spyOn(axios, 'post').mockRejectedValue({ response: { data: { message: 'Too short' } } })
		const wrapper = await mountSection()

		await wrapper.vm.save()

		expect(wrapper.vm.error).toBe('Too short')
		expect(resetPolicyCache).not.toHaveBeenCalled()
	})

	it('reports a refused write without a message generically', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
		vi.spyOn(axios, 'post').mockRejectedValue(new Error('network'))
		const wrapper = await mountSection()

		await wrapper.vm.save()

		expect(wrapper.vm.error).toBe('Could not save the password policy.')
	})
})
