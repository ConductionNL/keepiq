/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The vault policy section's edges: group and type options from the stores,
 * failed group and type loads that must not break the section, load and save
 * errors, the ownership-type picker, and re-counting the two-factor gaps when
 * (and only when) the two-factor scope changes.
 *
 * @spec openspec/specs/vault-policies/spec.md#requirement-administrator-configures-vault-policies-per-group
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import VaultPolicySection from '../../src/components/settings/VaultPolicySection.vue'
import { useGroupStore } from '../../src/store/modules/group.js'
import { useSecretTypeStore } from '../../src/store/modules/secretType.js'

const POLICIES = '/apps/keepiq/api/settings/admin/policies'
const GAPS = '/apps/keepiq/api/settings/admin/two-factor-gaps'

const stubs = {
	CnSettingsSection: {
		props: ['name', 'description'],
		template: '<section><slot /></section>',
	},
	NcNoteCard: { props: ['type'], template: '<div class="note"><slot /></div>' },
	NcSelect: {
		name: 'NcSelect',
		props: ['modelValue', 'options', 'inputLabel', 'multiple'],
		emits: ['update:modelValue'],
		template: '<div class="select" />',
	},
}

/**
 * Mount the section once `get` has been mocked.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountSection() {
	const wrapper = mount(VaultPolicySection, { global: { stubs } })
	await flushPromises()
	return wrapper
}

/**
 * The picker with the given test id.
 *
 * @param {object} wrapper The section wrapper.
 * @param {string} testId The data-testid.
 * @return {object} The NcSelect wrapper.
 */
function picker(wrapper, testId) {
	return wrapper
		.findAllComponents({ name: 'NcSelect' })
		.find((c) => c.attributes('data-testid') === testId)
}

describe('VaultPolicySection edges', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('offers group ids and type names from the stores', async () => {
		const groups = useGroupStore()
		const types = useSecretTypeStore()
		vi.spyOn(groups, 'fetchGroups').mockResolvedValue()
		types.types = [{ name: 'login' }, { name: 'ssh' }]
		const fetchTypes = vi.spyOn(types, 'fetchTypes')
		groups.groups = ['admin', { id: 'finance', displayName: 'Finance' }]
		vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })

		const wrapper = await mountSection()

		expect(fetchTypes).not.toHaveBeenCalled()
		expect(picker(wrapper, 'vault-policy-vault_export_disabled-groups').props('options'))
			.toEqual(['admin', 'finance'])
		expect(picker(wrapper, 'vault-policy-ownership-types').props('options'))
			.toEqual(['login', 'ssh'])
		expect(picker(wrapper, 'vault-policy-ownership-types').props('modelValue'))
			.toEqual(['login', 'api_key', 'database'])
	})

	it('still loads the policies when the group and type lists fail', async () => {
		vi.spyOn(useGroupStore(), 'fetchGroups').mockRejectedValue(new Error('groups down'))
		vi.spyOn(useSecretTypeStore(), 'fetchTypes').mockRejectedValue(new Error('types down'))
		vi.spyOn(axios, 'get').mockImplementation(async (url) => ({
			data: url === GAPS ? { inScope: 1, withoutTwoFactor: 0 } : { vault_export_disabled: true },
		}))

		const wrapper = await mountSection()

		expect(wrapper.find('[data-testid="vault-policy-vault_export_disabled"]').element.checked).toBe(true)
		expect(wrapper.find('[data-testid="vault-policy-error"]').exists()).toBe(false)
	})

	it('shows the load error and still counts the gaps', async () => {
		vi.spyOn(useGroupStore(), 'fetchGroups').mockResolvedValue()
		vi.spyOn(useSecretTypeStore(), 'fetchTypes').mockResolvedValue()
		vi.spyOn(axios, 'get').mockImplementation(async (url) => {
			if (url === GAPS) {
				return { data: { inScope: 3, withoutTwoFactor: 2 } }
			}
			throw { response: { data: { message: 'Forbidden' } } }
		})

		const wrapper = await mountSection()

		expect(wrapper.find('[data-testid="vault-policy-error"]').text()).toBe('Forbidden')
		expect(wrapper.vm.gaps).toEqual({ inScope: 3, withoutTwoFactor: 2 })
	})

	it('hides the gap warning when the gap count cannot be loaded', async () => {
		vi.spyOn(useGroupStore(), 'fetchGroups').mockResolvedValue()
		vi.spyOn(useSecretTypeStore(), 'fetchTypes').mockResolvedValue()
		vi.spyOn(axios, 'get').mockImplementation(async (url) => {
			if (url === GAPS) {
				throw new Error('gaps down')
			}
			return { data: { vault_require_two_factor: true } }
		})

		const wrapper = await mountSection()

		expect(wrapper.vm.gaps).toBeNull()
		expect(wrapper.find('[data-testid="vault-policy-two-factor-gaps"]').exists()).toBe(false)
	})

	it('re-counts the gaps for the new two-factor scope after saving it', async () => {
		vi.spyOn(useGroupStore(), 'fetchGroups').mockResolvedValue()
		vi.spyOn(useSecretTypeStore(), 'fetchTypes').mockResolvedValue()
		const get = vi.spyOn(axios, 'get').mockImplementation(async (url) => ({
			data: url === GAPS ? { inScope: 0, withoutTwoFactor: 0 } : {},
		}))
		const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
		const wrapper = await mountSection()
		get.mockClear()

		const scope = picker(wrapper, 'vault-policy-vault_require_two_factor-groups')
		scope.vm.$emit('update:modelValue', ['finance'])
		await flushPromises()

		expect(put).toHaveBeenCalledWith(POLICIES, expect.objectContaining({
			vault_require_two_factor_groups: ['finance'],
		}))
		expect(get).toHaveBeenCalledWith(GAPS, { params: { groups: ['finance'] } })
	})

	it('does not re-count the gaps when another policy scope changes', async () => {
		vi.spyOn(useGroupStore(), 'fetchGroups').mockResolvedValue()
		vi.spyOn(useSecretTypeStore(), 'fetchTypes').mockResolvedValue()
		const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
		const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
		const wrapper = await mountSection()
		get.mockClear()

		picker(wrapper, 'vault-policy-vault_export_disabled-groups').vm.$emit('update:modelValue', ['hr'])
		await flushPromises()

		expect(put).toHaveBeenCalledTimes(1)
		expect(get).not.toHaveBeenCalled()
	})

	it('saves the ownership types with every other key', async () => {
		vi.spyOn(useGroupStore(), 'fetchGroups').mockResolvedValue()
		vi.spyOn(useSecretTypeStore(), 'fetchTypes').mockResolvedValue()
		vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
		const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
		const wrapper = await mountSection()

		picker(wrapper, 'vault-policy-ownership-types').vm.$emit('update:modelValue', ['ssh'])
		await flushPromises()

		expect(put).toHaveBeenCalledWith(POLICIES, {
			vault_export_disabled: false,
			vault_export_disabled_groups: [],
			vault_require_two_factor: false,
			vault_require_two_factor_groups: [],
			vault_org_ownership: false,
			vault_org_ownership_groups: [],
			vault_org_ownership_types: ['ssh'],
		})
	})

	it('shows a save error and clears it on the next successful save', async () => {
		vi.spyOn(useGroupStore(), 'fetchGroups').mockResolvedValue()
		vi.spyOn(useSecretTypeStore(), 'fetchTypes').mockResolvedValue()
		vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
		vi.spyOn(axios, 'put')
			.mockRejectedValueOnce(new Error('Network Error'))
			.mockResolvedValueOnce({ data: {} })
		const wrapper = await mountSection()

		await wrapper.find('[data-testid="vault-policy-vault_org_ownership"]').setValue(true)
		await flushPromises()
		expect(wrapper.find('[data-testid="vault-policy-error"]').text()).toBe('Network Error')

		await wrapper.find('[data-testid="vault-policy-vault_org_ownership"]').setValue(false)
		await flushPromises()
		expect(wrapper.find('[data-testid="vault-policy-error"]').exists()).toBe(false)
	})
})
