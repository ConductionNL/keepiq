/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * GroupShareForm through its child controls: picking a group in the
 * select and toggling use-only in the restriction fields both flow back
 * through v-model, enable the submit, and land in the exact share
 * request; typing in the select runs the group search.
 *
 * @spec openspec/specs/sharing-group/spec.md#requirement-share-with-a-group
 */

import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import GroupShareForm from '../../src/components/share/GroupShareForm.vue'
import { useGroupShareStore } from '../../src/store/modules/groupShare.js'

const FINANCE = { id: 'finance', label: 'Finance' }

const NcSelect = {
	name: 'NcSelect',
	props: ['modelValue', 'options', 'loading'],
	emits: ['update:modelValue', 'search'],
	template: '<div data-testid="select" />',
}

const ShareRestrictionFields = {
	name: 'ShareRestrictionFields',
	props: ['modelValue'],
	emits: ['update:modelValue'],
	template: '<div data-testid="restriction" />',
}

describe('GroupShareForm controls', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		store = useGroupShareStore()
	})

	/**
	 * Mount the form for secret s-1 with the child controls stubbed.
	 *
	 * @return {object} The wrapper.
	 */
	function mountForm() {
		return mount(GroupShareForm, {
			props: { secretId: 's-1' },
			global: { stubs: { NcSelect, ShareRestrictionFields } },
		})
	}

	it('shares with the group picked in the select and the restriction set in the fields', async () => {
		const share = vi.spyOn(store, 'shareWithGroup').mockResolvedValue({ received: 4, skipped: 0 })
		const wrapper = mountForm()
		const submit = wrapper.find('[data-testid="group-share-form-submit"]')
		expect(submit.attributes('disabled')).toBeDefined()

		await wrapper.findComponent(NcSelect).vm.$emit('update:modelValue', FINANCE)
		await wrapper
			.findComponent(ShareRestrictionFields)
			.vm.$emit('update:modelValue', { useOnly: true, endDate: '' })

		expect(submit.attributes('disabled')).toBeUndefined()
		expect(wrapper.findComponent(NcSelect).props('modelValue')).toEqual(FINANCE)
		expect(wrapper.findComponent(ShareRestrictionFields).props('modelValue')).toEqual({
			useOnly: true,
			endDate: '',
		})

		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(share).toHaveBeenCalledWith('s-1', 'finance', { useOnly: true, expiresAt: null })
		expect(wrapper.emitted('shared')).toEqual([[{ group: FINANCE, received: 4, skipped: 0 }]])
	})

	it('runs the group search from the select and offers its results', async () => {
		const search = vi.spyOn(store, 'searchGroups').mockResolvedValue([FINANCE])
		const wrapper = mountForm()

		await wrapper.findComponent(NcSelect).vm.$emit('search', 'fin')
		await flushPromises()

		expect(search).toHaveBeenCalledWith('fin')
		expect(wrapper.findComponent(NcSelect).props('options')).toEqual([FINANCE])
		expect(wrapper.findComponent(NcSelect).props('loading')).toBe(false)
	})
})
