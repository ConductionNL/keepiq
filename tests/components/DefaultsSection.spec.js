/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The personal settings section that edits the default item type and the
 * default list view (vault-20).
 *
 * @spec openspec/changes/vault-defaults-and-recently-used-widget/specs/vault-defaults/spec.md#requirement-default-item-type-and-view
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import DefaultsSection from '../../src/components/settings/DefaultsSection.vue'
import { useSecretTypeStore } from '../../src/store/modules/secretType.js'
import { useUserPreferencesStore } from '../../src/store/modules/userPreferences.js'

const stubs = {
	NcSelect: {
		props: ['options', 'reduce', 'inputLabel', 'clearable', 'modelValue', 'label'],
		emits: ['update:modelValue'],
		template: '<div class="nc-select" :data-label="inputLabel" />',
	},
}

/**
 * Mount the section and let its mounted hook settle.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountSection() {
	const wrapper = mount(DefaultsSection, { global: { stubs } })
	await wrapper.vm.$nextTick()
	await Promise.resolve()
	return wrapper
}

describe('DefaultsSection', () => {
	let prefs

	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		const typeStore = useSecretTypeStore()
		typeStore.types = [
			{ id: 'type-login', name: 'login', label: 'Login' },
			{ id: 'type-ssh', name: 'ssh_key', label: 'SSH Key' },
		]
		typeStore.fetchTypes = vi.fn().mockResolvedValue()
		prefs = useUserPreferencesStore()
		prefs.ensureLoaded = vi.fn().mockResolvedValue()
		prefs.save = vi.fn().mockResolvedValue()
	})

	it('offers every item type by its name and the three list views', async () => {
		const wrapper = await mountSection()

		expect(prefs.ensureLoaded).toHaveBeenCalled()
		expect(wrapper.vm.typeOptions.map((o) => o.value)).toEqual(['login', 'ssh_key'])
		expect(wrapper.vm.viewOptions.map((o) => o.value)).toEqual([
			'list',
			'cards',
			'table',
		])
		const labels = wrapper.findAll('.nc-select').map((n) => n.attributes('data-label'))
		expect(labels).toEqual(['Default item type', 'Default view'])
	})

	it('saves the picked default type', async () => {
		const wrapper = await mountSection()

		await wrapper.vm.onTypeChange('ssh_key')

		expect(prefs.save).toHaveBeenCalledWith({ defaultSecretType: 'ssh_key' })
	})

	it('saves the picked default view', async () => {
		const wrapper = await mountSection()

		await wrapper.vm.onViewChange('cards')

		expect(prefs.save).toHaveBeenCalledWith({ defaultView: 'cards' })
	})
})
