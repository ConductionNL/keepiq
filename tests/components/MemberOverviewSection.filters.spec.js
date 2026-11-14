/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The member overview's search field, error note, emergency contact column
 * and paging back: each search restarts at the first page, and Previous
 * never goes below offset 0.
 *
 * @spec openspec/specs/admin-member-overview/spec.md#requirement-administrator-lists-vault-status-per-user
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import MemberOverviewSection from '../../src/components/settings/MemberOverviewSection.vue'

const URL = '/apps/keepiq/api/v1/admin/members'

const ROWS = [
	{ userId: 'alice', displayName: 'Alice', vaultStatus: 'active', activeSuiteId: 's1', hasEmergencyContact: true },
	{ userId: 'bob', displayName: 'Bob', vaultStatus: 'archived', activeSuiteId: null, hasEmergencyContact: false },
]

const stubs = {
	CnSettingsSection: { template: '<section><slot /></section>' },
	CnDataTable: {
		props: ['rows', 'columns', 'loading', 'rowKey', 'emptyText'],
		template:
			'<table><tr v-for="row in rows" :key="row.userId" :class="\'row-\' + row.userId"><td class="status"><slot name="column-vaultStatus" :row="row" /></td><td class="contact"><slot name="column-hasEmergencyContact" :row="row" /></td></tr></table>',
	},
	NcButton: {
		props: ['variant', 'disabled'],
		emits: ['click'],
		template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcNoteCard: { props: ['type'], template: '<div class="note"><slot /></div>' },
	NcSelect: {
		name: 'NcSelect',
		props: ['modelValue', 'options', 'inputLabel', 'label', 'clearable'],
		emits: ['update:modelValue'],
		template: '<div class="select" />',
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		template: '<input class="search" />',
	},
}

/**
 * @return {Promise<object>} The loaded section.
 */
async function mountSection() {
	const wrapper = mount(MemberOverviewSection, { global: { stubs } })
	await flushPromises()
	return wrapper
}

describe('MemberOverviewSection filters and paging', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('answers Yes or No for the emergency contact and passes an unknown status through', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { results: ROWS, hasMore: false } })
		const wrapper = await mountSection()

		expect(wrapper.find('.row-alice .contact').text()).toBe('Yes')
		expect(wrapper.find('.row-bob .contact').text()).toBe('No')
		expect(wrapper.find('[data-testid="member-status-alice"]').text()).toBe('Active')
		expect(wrapper.find('[data-testid="member-status-bob"]').text()).toBe('archived')
	})

	it('offers every status with All statuses selected first', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { results: [], hasMore: false } })
		const wrapper = await mountSection()
		const select = wrapper.findComponent({ name: 'NcSelect' })

		expect(select.props('options').map((o) => o.id)).toEqual(['', 'none', 'active', 'revoked', 'compromised'])
		expect(select.props('modelValue')).toEqual({ id: '', label: 'All statuses' })
		expect(select.props('inputLabel')).toBe('Vault status')
	})

	it('searches from the first page with the current status', async () => {
		const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: { results: ROWS, hasMore: true } })
		const wrapper = await mountSection()

		await wrapper.find('[data-testid="member-overview-next"]').trigger('click')
		await flushPromises()
		expect(get).toHaveBeenLastCalledWith(URL, { params: { status: '', search: '', limit: 50, offset: 50 } })

		await wrapper.findComponent({ name: 'NcTextField' }).vm.$emit('update:modelValue', 'ali')
		await flushPromises()

		expect(get).toHaveBeenLastCalledWith(URL, { params: { status: '', search: 'ali', limit: 50, offset: 0 } })
		expect(wrapper.find('[data-testid="member-overview-previous"]').attributes('disabled')).toBeDefined()
	})

	it('pages back to the previous page', async () => {
		const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: { results: ROWS, hasMore: true } })
		const wrapper = await mountSection()

		await wrapper.find('[data-testid="member-overview-next"]').trigger('click')
		await flushPromises()
		await wrapper.find('[data-testid="member-overview-next"]').trigger('click')
		await flushPromises()
		await wrapper.find('[data-testid="member-overview-previous"]').trigger('click')
		await flushPromises()

		expect(get).toHaveBeenLastCalledWith(URL, { params: { status: '', search: '', limit: 50, offset: 50 } })
		expect(wrapper.vm.offset).toBe(50)
	})

	it('shows an error note when the members cannot be loaded', async () => {
		vi.spyOn(axios, 'get').mockRejectedValue(new Error('offline'))
		const wrapper = await mountSection()

		expect(wrapper.find('[data-testid="member-overview-error"]').text()).toBe('Could not load the members.')
		expect(wrapper.find('[data-testid="member-overview-next"]').attributes('disabled')).toBeDefined()
	})
})
