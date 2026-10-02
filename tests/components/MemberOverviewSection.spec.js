/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin Members section lists vault status per user, filters and pages,
 * and hands a row to the offboarding and encryption suite sections, so an
 * administrator never types a user or suite id
 * (admin-member-overview-and-offboarding §1.5, §3.1, §3.2, §3.3).
 *
 * @spec openspec/changes/admin-member-overview-and-offboarding/tasks.md#3.1
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import AdminSuiteSection from '../../src/components/settings/AdminSuiteSection.vue'
import MemberOverviewSection from '../../src/components/settings/MemberOverviewSection.vue'
import OffboardingSection from '../../src/components/settings/OffboardingSection.vue'
import { useTeamFolderStore } from '../../src/store/modules/teamFolder.js'

const ROWS = [
	{ userId: 'alice', displayName: 'Alice', enabled: true, vaultStatus: 'active', activeSuiteId: 'suite-alice', secretCount: 3, teamFolderMemberships: 1, hasEmergencyContact: true },
	{ userId: 'bob', displayName: 'Bob', enabled: true, vaultStatus: 'none', activeSuiteId: null, secretCount: 0, teamFolderMemberships: 0, hasEmergencyContact: false },
]

const stubs = {
	CnSettingsSection: { props: ['name', 'description'], template: '<section><slot /></section>' },
	CnDataTable: {
		props: ['rows', 'columns', 'loading', 'rowKey', 'emptyText'],
		template: '<table><tr v-for="row in rows" :key="row.userId" class="row"><td><slot name="column-vaultStatus" :row="row" /></td><td><slot name="row-actions" :row="row" /></td></tr></table>',
	},
	NcButton: {
		props: ['variant', 'disabled'],
		emits: ['click'],
		template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcNoteCard: { props: ['type'], template: '<div class="note"><slot /></div>' },
	NcSelect: {
		name: 'NcSelect',
		props: ['modelValue', 'options', 'inputLabel', 'label', 'filterable', 'loading', 'clearable'],
		emits: ['update:modelValue', 'search'],
		template: '<div class="select" :data-input-label="inputLabel" />',
	},
	NcTextField: { props: ['modelValue', 'label'], emits: ['update:modelValue'], template: '<input />' },
	NcCheckboxRadioSwitch: { props: ['modelValue'], template: '<span />' },
	OffboardingConfirmDialog: { props: ['open', 'leavingUserId', 'successorUserId'], template: '<div />' },
}

/**
 * Mount a component with the shared stubs.
 *
 * @param {object} component The SFC.
 * @return {object} The wrapper.
 */
function mountWith(component) {
	return mount(component, { global: { stubs } })
}

describe('MemberOverviewSection', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { results: ROWS, hasMore: true } })
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('loads the first page from the admin member endpoint and shows each status', async () => {
		const wrapper = mountWith(MemberOverviewSection)
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith(
			'/apps/keepiq/api/v1/admin/members',
			{ params: { status: '', search: '', limit: 50, offset: 0 } },
		)
		expect(wrapper.findAll('.row')).toHaveLength(2)
		expect(wrapper.find('[data-testid="member-status-bob"]').text()).toBe('Not set up')
	})

	it('filters on a status and pages forward', async () => {
		const wrapper = mountWith(MemberOverviewSection)
		await flushPromises()

		const select = wrapper.findComponent({ name: 'NcSelect' })
		expect(select.props('inputLabel')).toBe('Vault status')
		await select.vm.$emit('update:modelValue', { id: 'none', label: 'Not set up' })
		await flushPromises()
		expect(axios.get).toHaveBeenLastCalledWith(
			'/apps/keepiq/api/v1/admin/members',
			{ params: { status: 'none', search: '', limit: 50, offset: 0 } },
		)

		await wrapper.find('[data-testid="member-overview-next"]').trigger('click')
		await flushPromises()
		expect(axios.get).toHaveBeenLastCalledWith(
			'/apps/keepiq/api/v1/admin/members',
			{ params: { status: 'none', search: '', limit: 50, offset: 50 } },
		)
	})

	it('offers Revoke suite only for a row with an active suite', async () => {
		const wrapper = mountWith(MemberOverviewSection)
		await flushPromises()

		expect(wrapper.find('[data-testid="member-revoke-alice"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="member-revoke-bob"]').exists()).toBe(false)
	})

	it('hands the row to the offboarding and encryption suite sections', async () => {
		const list = mountWith(MemberOverviewSection)
		const offboarding = mountWith(OffboardingSection)
		const suites = mountWith(AdminSuiteSection)
		await flushPromises()

		await list.find('[data-testid="member-offboard-bob"]').trigger('click')
		await list.find('[data-testid="member-revoke-alice"]').trigger('click')
		await flushPromises()

		expect(offboarding.vm.leavingUserId).toBe('bob')
		expect(offboarding.vm.leavingUser.displayName).toBe('Bob')
		expect(suites.vm.suiteId).toBe('suite-alice')
	})
})

describe('OffboardingSection', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('picks both users from the member endpoint, with labelled pickers', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { results: ROWS, hasMore: false } })
		const wrapper = mountWith(OffboardingSection)

		const pickers = wrapper.findAllComponents({ name: 'NcSelect' })
		expect(pickers.map((picker) => picker.props('inputLabel'))).toEqual(['Leaving user', 'Successor'])

		await pickers[0].vm.$emit('search', 'bo')
		await flushPromises()
		expect(axios.get).toHaveBeenCalledWith(
			'/apps/keepiq/api/v1/admin/members',
			{ params: { search: 'bo', limit: 20, offset: 0 } },
		)
		expect(wrapper.vm.userOptions).toEqual([
			{ userId: 'alice', displayName: 'Alice' },
			{ userId: 'bob', displayName: 'Bob' },
		])
	})

	it('reports the removed memberships and warns about covering groups', async () => {
		const wrapper = mountWith(OffboardingSection)
		vi.spyOn(useTeamFolderStore(), 'offboard').mockResolvedValue({
			revoked: 1,
			transferred: 0,
			skipped: [],
			membershipsRemoved: 2,
			stillCoveredByGroups: [{ teamFolderId: 'tf-finance', groupId: 'finance-team' }],
		})
		wrapper.vm.leavingUser = { userId: 'carol', displayName: 'Carol' }
		wrapper.vm.successorUser = { userId: 'dave', displayName: 'Dave' }

		await wrapper.vm.run()
		await flushPromises()

		expect(wrapper.find('[data-testid="offboarding-summary"]').text()).toContain('Removed the user from {count} team folders.')
		expect(wrapper.find('[data-testid="offboarding-covering-groups"]').exists()).toBe(true)
		expect(wrapper.vm.coveringGroups).toEqual([{ teamFolderId: 'tf-finance', groupId: 'finance-team' }])
	})

	it('shows no group warning when no group covers the leaver', async () => {
		const wrapper = mountWith(OffboardingSection)
		vi.spyOn(useTeamFolderStore(), 'offboard').mockResolvedValue({
			revoked: 0, transferred: 0, skipped: [], membershipsRemoved: 0, stillCoveredByGroups: [],
		})
		wrapper.vm.leavingUser = { userId: 'carol', displayName: 'Carol' }
		wrapper.vm.successorUser = { userId: 'dave', displayName: 'Dave' }

		await wrapper.vm.run()
		await flushPromises()

		expect(wrapper.find('[data-testid="offboarding-covering-groups"]').exists()).toBe(false)
	})
})
