/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The vault policy admin section (admin-vault-policies §1.3): loads the
 * policies, labels every picker, saves the keys, and warns how many users
 * in the two-factor scope have no second factor.
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
 * Mount with the given admin payload and gap count.
 *
 * @param {object} admin The admin settings payload.
 * @param {object} gaps The gap count.
 * @return {Promise<object>} The wrapper.
 */
async function mountWith(admin, gaps = { inScope: 0, withoutTwoFactor: 0 }) {
	vi.spyOn(useGroupStore(), 'fetchGroups').mockResolvedValue()
	vi.spyOn(useSecretTypeStore(), 'fetchTypes').mockResolvedValue()
	vi.spyOn(axios, 'get').mockImplementation(async (url) => ({
		data: url.includes('two-factor-gaps') ? gaps : admin,
	}))
	const wrapper = mount(VaultPolicySection, { global: { stubs } })
	await flushPromises()
	return wrapper
}

describe('VaultPolicySection', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('labels every picker', async () => {
		const wrapper = await mountWith({})

		const labels = wrapper
			.findAllComponents({ name: 'NcSelect' })
			.map((select) => select.props('inputLabel'))
		expect(labels).toHaveLength(4)
		expect(
			labels.every((label) => typeof label === 'string' && label.length > 0),
		).toBe(true)
	})

	it('loads the stored values and saves every policy key with a group scope', async () => {
		const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
		const wrapper = await mountWith({
			vault_export_disabled: false,
			vault_export_disabled_groups: [],
		})

		wrapper.vm.values.vault_export_disabled_groups = ['staff']
		await wrapper
			.find('[data-testid="vault-policy-vault_export_disabled"]')
			.setValue(true)
		await flushPromises()

		expect(put).toHaveBeenCalledWith(
			'/apps/keepiq/api/settings/admin/policies',
			expect.objectContaining({
				vault_export_disabled: true,
				vault_export_disabled_groups: ['staff'],
			}),
		)
	})

	it('warns how many users in scope have no second factor', async () => {
		const wrapper = await mountWith({}, { inScope: 5, withoutTwoFactor: 2 })

		expect(
			wrapper.find('[data-testid="vault-policy-two-factor-gaps"]').exists(),
		).toBe(true)
	})

	it('says nothing when everyone in scope has a second factor', async () => {
		const wrapper = await mountWith({}, { inScope: 5, withoutTwoFactor: 0 })

		expect(
			wrapper.find('[data-testid="vault-policy-two-factor-gaps"]').exists(),
		).toBe(false)
	})
})
