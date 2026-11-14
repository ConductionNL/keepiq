/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The template wiring of `src/modals/TeamFolderDialog.vue`: what each control
 * in a shared folder's member list hands its handler — the grade select, the
 * remove button, the member-type and member pickers (their `reduce` and model
 * updates), the restriction fields and the fan-out cancel button — plus the
 * pending candidate search being dropped on unmount and a failing search
 * being swallowed. The team-folder, share and group stores and the policy
 * fetch are the mocked boundaries; the pickers are stubbed.
 *
 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-inherited-access-on-add-revoked-on-removal
 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-membership-propagation-with-group-membership
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import TeamFolderDialog from '../../src/modals/TeamFolderDialog.vue'
import { fetchPolicy } from '../../src/policy/policy.js'
import { useGroupStore } from '../../src/store/modules/group.js'
import { useShareStore } from '../../src/store/modules/share.js'
import { useTeamFolderStore } from '../../src/store/modules/teamFolder.js'

vi.mock('../../src/policy/policy.js', () => ({
	fetchPolicy: vi.fn(),
	resetPolicyCache: vi.fn(),
}))

const FOLDER = {
	id: 'tf-1',
	folderId: 'folder-1',
	folderName: 'DevOps',
	members: [
		{ id: 'm1', memberType: 'user', memberId: 'bob', grade: 'read' },
		{ id: 'm2', memberType: 'group', memberId: 'devops', grade: 'write' },
	],
}

describe('TeamFolderDialog wiring', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		fetchPolicy.mockReset().mockResolvedValue({ team_folder_auto_confirm: false })
		useShareStore().searchShareableRecipients = vi.fn().mockResolvedValue([])
		useGroupStore().fetchGroups = vi.fn().mockResolvedValue([])
		store = useTeamFolderStore()
		store.owned = [FOLDER]
		store.fetchTeamFolders = vi.fn().mockResolvedValue()
		store.reconcile = vi.fn().mockResolvedValue({ missing: [], confirmedBy: {} })
		store.setMemberGrade = vi.fn().mockResolvedValue()
		store.removeMember = vi.fn().mockResolvedValue()
		store.cancelFanOut = vi.fn()
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	function mountDialog() {
		return mount(TeamFolderDialog, {
			props: { open: true, folderId: 'folder-1', folderName: 'DevOps' },
			global: {
				stubs: { NcSelect: true, ShareRestrictionFields: true },
			},
		})
	}

	function selects(wrapper) {
		return wrapper.findAllComponents({ name: 'NcSelect' })
	}

	it('hands the grade select\'s chosen value and the row to the grade change', async () => {
		const wrapper = mountDialog()
		const spy = vi.spyOn(wrapper.vm, 'onGradeChange').mockResolvedValue()
		await wrapper.vm.$forceUpdate()

		const grade = wrapper.find('[data-testid="team-folder-grade-bob"]')
		await grade.setValue('write')

		expect(spy).toHaveBeenCalledTimes(1)
		expect(spy.mock.calls[0][0]).toMatchObject({ id: 'm1', memberId: 'bob' })
		expect(spy.mock.calls[0][1]).toBe('write')
	})

	it('removes the member whose remove button is clicked', async () => {
		const wrapper = mountDialog()

		await wrapper.find('[data-testid="team-folder-remove-devops"]').trigger('click')
		await new Promise((resolve) => setTimeout(resolve, 0))

		expect(store.removeMember).toHaveBeenCalledWith('tf-1', 'm2')
		expect(store.fetchTeamFolders).toHaveBeenCalled()
	})

	it('reduces the member-type option to its value and writes the pick back', async () => {
		const wrapper = mountDialog()
		const typeSelect = selects(wrapper)[0]

		expect(typeSelect.props('reduce')({ label: 'Group', value: 'group' })).toBe('group')

		wrapper.vm.newMemberId = 'bob'
		typeSelect.vm.$emit('update:modelValue', 'group')
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.newMemberType).toBe('group')
		expect(wrapper.vm.newMemberId).toBe('')
	})

	it('reduces a candidate to its id and treats a cleared pick as no member', async () => {
		const wrapper = mountDialog()
		const memberSelect = selects(wrapper)[1]

		expect(memberSelect.props('reduce')({ id: 'carol', label: 'Carol' })).toBe('carol')

		memberSelect.vm.$emit('update:modelValue', 'carol')
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.newMemberId).toBe('carol')
		expect(selects(wrapper)[1].props('modelValue')).toBe('carol')

		memberSelect.vm.$emit('update:modelValue', null)
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.newMemberId).toBe('')
		expect(selects(wrapper)[1].props('modelValue')).toBe(null)
	})

	it('writes the restriction fields\' update into the new restriction', async () => {
		const wrapper = mountDialog()
		const fields = wrapper.findComponent({ name: 'ShareRestrictionFields' })

		fields.vm.$emit('update:modelValue', { useOnly: true, endDate: '2027-01-01' })
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.newRestriction).toEqual({ useOnly: true, endDate: '2027-01-01' })
	})

	it('cancels a running fan-out from its cancel button', async () => {
		const wrapper = mountDialog()
		store.fanOut = { total: 4, done: 1, running: true }
		await wrapper.vm.$nextTick()

		expect(wrapper.find('[data-testid="team-folder-run-fanout"]').exists()).toBe(false)
		await wrapper.find('[data-testid="team-folder-cancel-fanout"]').trigger('click')

		expect(store.cancelFanOut).toHaveBeenCalledTimes(1)
	})

	it('drops the pending candidate search when unmounted', () => {
		vi.useFakeTimers()
		const wrapper = mountDialog()
		const search = useShareStore().searchShareableRecipients
		search.mockClear()

		wrapper.vm.onCandidateSearch('car')
		wrapper.unmount()
		vi.advanceTimersByTime(1000)

		expect(search).not.toHaveBeenCalled()
	})

	it('swallows a failing candidate search', async () => {
		vi.useFakeTimers()
		const wrapper = mountDialog()
		const failure = vi.fn().mockRejectedValue(new Error('directory down'))
		useGroupStore().fetchGroups = failure
		wrapper.vm.newMemberType = 'group'
		await wrapper.vm.$nextTick()

		wrapper.vm.onCandidateSearch('dev')
		vi.advanceTimersByTime(1000)
		await vi.runAllTimersAsync()

		expect(failure).toHaveBeenCalledWith('dev')
		expect(wrapper.vm.error).toBe(null)
	})
})
