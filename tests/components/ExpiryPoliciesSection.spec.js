/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The expiry rule editor (#77): list, create and delete type and folder rules.
 *
 * @spec openspec/specs/rotation-expiry-policies/spec.md#requirement-expiry-policies-with-admin-default-and-user-override
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ExpiryPoliciesSection, { parseMaxAge, parseReminderDays } from '../../src/components/settings/ExpiryPoliciesSection.vue'
import { useFolderStore } from '../../src/store/modules/folder.js'
import { useSecretTypeStore } from '../../src/store/modules/secretType.js'

vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: () => ({ uid: 'ann' }),
	getRequestToken: () => 'token',
	onRequestTokenUpdate: () => {},
}))

const stubs = {
	NcSelect: { props: ['options', 'inputLabel'], template: '<div class="nc-select" :data-label="inputLabel" />' },
	NcTextField: { props: ['label'], template: '<input class="nc-text" :data-label="label" />' },
	NcButton: { template: '<button v-bind="$attrs" @click="$emit(\'click\')"><slot /></button>' },
}

const POLICIES = [
	{ id: 'p1', ownerId: 'ann', scope: 'type', scopeId: 'type-login', maxAgeDays: 90, reminderDays: [14, 3] },
	{ id: 'p2', ownerId: null, scope: 'folder', scopeId: 'f-ops', maxAgeDays: 30, reminderDays: null },
]

/**
 * Mount with types, folders and two rules loaded.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountSection() {
	vi.spyOn(axios, 'get').mockResolvedValue({ data: POLICIES })
	const wrapper = mount(ExpiryPoliciesSection, { global: { stubs } })
	await flushPromises()
	return wrapper
}

describe('ExpiryPoliciesSection', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		const types = useSecretTypeStore()
		types.types = [{ id: 'type-login', name: 'login', label: 'Login' }]
		types.fetchTypes = vi.fn().mockResolvedValue()
		const folders = useFolderStore()
		folders.folders = [{ id: 'f-ops', name: 'Ops' }]
		folders.fetchFolders = vi.fn().mockResolvedValue()
	})

	it('lists the rules, with delete only on the user\'s own', async () => {
		const wrapper = await mountSection()

		const rows = wrapper.findAll('[data-testid="expiry-policy-row"]')
		expect(rows).toHaveLength(2)
		expect(rows[0].text()).toContain('Login')
		expect(rows[0].find('[data-testid="expiry-policy-delete"]').exists()).toBe(true)
		expect(rows[1].text()).toContain('Ops')
		expect(rows[1].find('[data-testid="expiry-policy-delete"]').exists()).toBe(false)
	})

	it('saves a folder rule through the API', async () => {
		const wrapper = await mountSection()
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })

		wrapper.vm.draft.scope = 'folder'
		await flushPromises()
		wrapper.vm.draft.scopeId = 'f-ops'
		wrapper.vm.draft.maxAgeDays = '60'
		wrapper.vm.draft.reminderDays = '7, 30'
		await flushPromises()
		await wrapper.find('[data-testid="expiry-policy-save"]').trigger('click')
		await flushPromises()

		expect(post).toHaveBeenCalledWith('/apps/keepiq/api/v1/expiry-policies', {
			scope: 'folder',
			scopeId: 'f-ops',
			maxAgeDays: 60,
			reminderDays: [30, 7],
		})
	})

	it('deletes the user\'s own rule', async () => {
		const wrapper = await mountSection()
		const del = vi.spyOn(axios, 'delete').mockResolvedValue({ data: {} })

		await wrapper.find('[data-testid="expiry-policy-delete"]').trigger('click')
		await flushPromises()

		expect(del).toHaveBeenCalledWith('/apps/keepiq/api/v1/expiry-policies/p1')
	})

	it('refuses a rule that sets nothing or an invalid age', async () => {
		const wrapper = await mountSection()

		wrapper.vm.draft.scopeId = 'type-login'
		expect(wrapper.vm.canSave).toBe(false)
		wrapper.vm.draft.maxAgeDays = '0'
		expect(wrapper.vm.canSave).toBe(false)
		wrapper.vm.draft.maxAgeDays = ''
		wrapper.vm.draft.reminderDays = '5'
		expect(wrapper.vm.canSave).toBe(true)
	})

	it('parses the inputs', () => {
		expect(parseReminderDays('1, 30;7 7 x -2')).toEqual([30, 7, 1])
		expect(parseMaxAge('')).toBeNull()
		expect(parseMaxAge('45')).toBe(45)
		expect(parseMaxAge('2.5')).toBeNaN()
	})
})
