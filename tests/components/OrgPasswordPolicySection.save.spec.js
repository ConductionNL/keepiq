/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The org password policy section beyond the passphrase switch: loading the
 * stored policy and exempt types, saving every control with the full policy,
 * and surfacing load and save errors.
 *
 * @spec openspec/specs/org-password-policies/spec.md#requirement-configurable-org-password-policy
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import OrgPasswordPolicySection from '../../src/components/settings/OrgPasswordPolicySection.vue'
import { useSecretTypeStore } from '../../src/store/modules/secretType.js'

const URL = '/apps/keepiq/api/settings/admin/policies'

const stubs = {
	CnSettingsSection: {
		props: ['name', 'description'],
		template: '<section><slot /></section>',
	},
	NcNoteCard: { props: ['type'], template: '<div class="note"><slot /></div>' },
	NcSelect: {
		name: 'NcSelect',
		props: ['modelValue', 'options'],
		emits: ['update:modelValue'],
		template: '<div class="select" />',
	},
}

/**
 * Answer the policy GET with `settings` and the type list with `types`.
 *
 * @param {object} settings The stored policy.
 * @param {Array<object>} types The secret types.
 * @return {object} The axios.get spy.
 */
function mockGet(settings, types = [{ name: 'login' }, { name: 'card' }]) {
	return vi.spyOn(axios, 'get').mockImplementation(async (url) => {
		if (url.includes('/api/settings/admin')) {
			return { data: settings }
		}
		return { data: types }
	})
}

/**
 * Mount the section and wait for its load.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountSection() {
	const wrapper = mount(OrgPasswordPolicySection, { global: { stubs } })
	await flushPromises()
	return wrapper
}

describe('OrgPasswordPolicySection: load and save', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('loads known policy keys and the exempt types, ignoring unknown keys', async () => {
		mockGet({
			policy_enabled: true,
			generator_min_length: 20,
			generator_require_digit: true,
			min_zxcvbn_score: 3,
			block_on_hibp_hit: true,
			policy_exempt_types: ['card'],
			unrelated: 'x',
		})
		const wrapper = await mountSection()

		expect(wrapper.find('[data-testid="policy-enabled"]').element.checked).toBe(true)
		expect(wrapper.find('[data-testid="generator-min-length"]').element.value).toBe('20')
		expect(wrapper.find('[data-testid="generator_require_digit"]').element.checked).toBe(true)
		expect(wrapper.find('[data-testid="generator_require_upper"]').element.checked).toBe(false)
		expect(wrapper.find('[data-testid="min-zxcvbn-score"]').element.value).toBe('3')
		expect(wrapper.find('[data-testid="block-on-hibp"]').element.checked).toBe(true)

		const select = wrapper.findComponent({ name: 'NcSelect' })
		expect(select.props('modelValue')).toEqual(['card'])
		expect(select.props('options')).toEqual(['login', 'card'])
		expect(wrapper.vm.policy).not.toHaveProperty('unrelated')
	})

	it('treats a non-array exempt list as empty', async () => {
		mockGet({ policy_exempt_types: 'card' })
		const wrapper = await mountSection()

		expect(wrapper.findComponent({ name: 'NcSelect' }).props('modelValue')).toEqual([])
	})

	it('does not refetch the types when the store already has them', async () => {
		const store = useSecretTypeStore()
		store.types = [{ name: 'ssh' }]
		const get = mockGet({})
		const wrapper = await mountSection()

		expect(get).toHaveBeenCalledTimes(1)
		expect(get.mock.calls[0][0]).toBe(URL)
		expect(wrapper.findComponent({ name: 'NcSelect' }).props('options')).toEqual(['ssh'])
	})

	it('saves the whole policy with the exempt types when a control changes', async () => {
		mockGet({ policy_exempt_types: ['card'] })
		const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
		const wrapper = await mountSection()

		await wrapper.find('[data-testid="policy-enabled"]').setValue(true)
		await wrapper.find('[data-testid="generator-min-length"]').setValue('16')
		await wrapper.find('[data-testid="generator_require_symbol"]').setValue(true)
		await wrapper.find('[data-testid="min-zxcvbn-score"]').setValue('2')
		await wrapper.find('[data-testid="block-on-hibp"]').setValue(true)
		await flushPromises()

		expect(put).toHaveBeenCalledTimes(5)
		expect(put).toHaveBeenLastCalledWith(URL, {
			policy_enabled: true,
			generator_min_length: 16,
			generator_require_upper: false,
			generator_require_lower: false,
			generator_require_digit: false,
			generator_require_symbol: true,
			generator_allow_passphrase: true,
			min_zxcvbn_score: 2,
			block_on_hibp_hit: true,
			policy_exempt_types: ['card'],
		})
	})

	it('saves a new exempt selection from the picker', async () => {
		mockGet({})
		const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
		const wrapper = await mountSection()

		wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', ['login'])
		await flushPromises()

		expect(put).toHaveBeenCalledWith(URL, expect.objectContaining({ policy_exempt_types: ['login'] }))
	})

	it('shows the server message when loading fails', async () => {
		vi.spyOn(axios, 'get').mockRejectedValue({ response: { data: { message: 'Not allowed' } } })
		const wrapper = await mountSection()

		expect(wrapper.find('.note').text()).toBe('Not allowed')
	})

	it('shows the server message when saving fails, and clears it on the next save', async () => {
		mockGet({})
		const put = vi.spyOn(axios, 'put')
			.mockRejectedValueOnce({ response: { data: { message: 'Length below 8' } } })
			.mockResolvedValueOnce({ data: {} })
		const wrapper = await mountSection()

		await wrapper.find('[data-testid="generator-min-length"]').setValue('4')
		await flushPromises()
		expect(wrapper.find('.note').text()).toBe('Length below 8')

		await wrapper.find('[data-testid="generator-min-length"]').setValue('12')
		await flushPromises()
		expect(put).toHaveBeenCalledTimes(2)
		expect(wrapper.find('.note').exists()).toBe(false)
	})

	it('falls back to the error message without a server message', async () => {
		mockGet({})
		vi.spyOn(axios, 'put').mockRejectedValue(new Error('Network Error'))
		const wrapper = await mountSection()

		await wrapper.find('[data-testid="block-on-hibp"]').setValue(true)
		await flushPromises()

		expect(wrapper.find('.note').text()).toBe('Network Error')
	})
})
