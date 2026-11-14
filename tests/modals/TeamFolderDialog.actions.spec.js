/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The membership actions of `src/modals/TeamFolderDialog.vue`: sharing the
 * folder, adding a member (then refreshing and running the fan-out),
 * changing a grade, approving a waiting member, running the fan-out and
 * unsharing — each with what it hands the team-folder store, what it shows
 * afterwards and what a refusal leaves behind. The team-folder, share and
 * group stores and the policy fetch are the mocked boundaries.
 *
 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-share-a-folder-as-a-team-folder
 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-inherited-access-on-add-revoked-on-removal
 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-approve-one-waiting-member
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import TeamFolderDialog from '../../src/modals/TeamFolderDialog.vue'
import { fetchPolicy } from '../../src/policy/policy.js'
import { useGroupStore } from '../../src/store/modules/group.js'
import { useShareStore } from '../../src/store/modules/share.js'
import { useTeamFolderStore } from '../../src/store/modules/teamFolder.js'

vi.mock('../../src/policy/policy.js', () => ({
	fetchPolicy: vi.fn(),
	resetPolicyCache: vi.fn(),
}))

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const FOLDER = { id: 'tf-1', folderId: 'folder-1', folderName: 'DevOps', members: [] }

describe('TeamFolderDialog actions', () => {
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
		store.shareFolder = vi.fn().mockResolvedValue()
		store.addMember = vi.fn().mockResolvedValue()
		store.setMemberGrade = vi.fn().mockResolvedValue()
		store.approveMember = vi.fn().mockResolvedValue({ refused: [] })
		store.runFanOut = vi.fn().mockResolvedValue({ skipped: [], refused: [] })
		store.unshareFolder = vi.fn().mockResolvedValue()
	})

	function mountDialog(folderId = 'folder-1') {
		return mount(TeamFolderDialog, {
			props: { open: false, folderId, folderName: 'DevOps' },
		})
	}

	it('refreshes on opening: pending members once each, confirmations and auto-confirm', async () => {
		store.reconcile.mockResolvedValue({
			missing: [
				{ userId: 'bob', secretId: 's1' },
				{ userId: 'bob', secretId: 's2' },
				{ userId: 'carol', secretId: 's1' },
			],
			confirmedBy: { bob: 'alice' },
		})
		fetchPolicy.mockResolvedValue({ team_folder_auto_confirm: true })
		const wrapper = mountDialog()
		wrapper.vm.error = 'stale'

		await wrapper.setProps({ open: true })
		await flush()

		expect(wrapper.vm.error).toBe(null)
		expect(store.fetchTeamFolders).toHaveBeenCalled()
		expect(store.reconcile).toHaveBeenCalledWith('tf-1')
		expect(wrapper.vm.pendingCount).toBe(3)
		expect(wrapper.vm.pendingMembers).toEqual(['bob', 'carol'])
		expect(wrapper.vm.confirmedBy).toEqual({ bob: 'alice' })
		expect(wrapper.vm.autoConfirm).toBe(true)
	})

	it('does not reconcile a folder that is not shared yet', async () => {
		const wrapper = mountDialog('folder-unshared')

		await wrapper.vm.refresh()

		expect(store.reconcile).not.toHaveBeenCalled()
		expect(wrapper.vm.error).toBe(null)
	})

	it('reports a failed refresh', async () => {
		store.fetchTeamFolders.mockRejectedValue(new Error('server down'))
		const wrapper = mountDialog()

		await wrapper.vm.refresh()
		expect(wrapper.vm.error).toBe('server down')

		store.fetchTeamFolders.mockRejectedValue({})
		await wrapper.vm.refresh()
		expect(wrapper.vm.error).toBe('Failed to load team folder')
	})

	it('shares the folder and reports a refusal', async () => {
		const wrapper = mountDialog()

		await wrapper.vm.onShareFolder()
		expect(store.shareFolder).toHaveBeenCalledWith('folder-1')
		expect(wrapper.vm.error).toBe(null)
		expect(wrapper.vm.busy).toBe(false)

		store.shareFolder.mockRejectedValue({ response: { data: { message: 'Already shared' } } })
		await wrapper.vm.onShareFolder()
		expect(wrapper.vm.error).toBe('Already shared')
		expect(wrapper.vm.busy).toBe(false)
	})

	it('adds a member with its restriction, resets the form and runs the fan-out', async () => {
		const wrapper = mountDialog()
		wrapper.vm.newMemberType = 'user'
		await flush()
		wrapper.vm.newMemberId = 'dave'
		wrapper.vm.newRestriction = { useOnly: true, endDate: '' }

		await wrapper.vm.onAddMember()

		expect(store.addMember).toHaveBeenCalledWith('tf-1', 'user', 'dave', {
			useOnly: true,
			expiresAt: null,
		})
		expect(wrapper.vm.newMemberId).toBe('')
		expect(wrapper.vm.newRestriction).toEqual({ useOnly: false, endDate: '' })
		expect(store.reconcile).toHaveBeenCalled()
		expect(store.runFanOut).toHaveBeenCalledWith('tf-1')
		expect(wrapper.vm.busy).toBe(false)
	})

	it('keeps the entered member when adding is refused', async () => {
		store.addMember.mockRejectedValue({ response: { data: { message: 'No such user' } } })
		const wrapper = mountDialog()
		wrapper.vm.newMemberId = 'ghost'

		await wrapper.vm.onAddMember()

		expect(wrapper.vm.error).toBe('No such user')
		expect(wrapper.vm.newMemberId).toBe('ghost')
		expect(store.runFanOut).not.toHaveBeenCalled()
		expect(wrapper.vm.busy).toBe(false)
	})

	it('clears the member id when the member type changes', async () => {
		const wrapper = mountDialog()
		wrapper.vm.newMemberId = 'bob'

		wrapper.vm.newMemberType = 'group'
		await flush()

		expect(wrapper.vm.newMemberId).toBe('')
	})

	it('changes a grade and reports a refusal', async () => {
		const wrapper = mountDialog()

		await wrapper.vm.onGradeChange({ id: 'm1' }, 'write')
		expect(store.setMemberGrade).toHaveBeenCalledWith('tf-1', 'm1', 'write')
		expect(store.reconcile).toHaveBeenCalled()

		store.setMemberGrade.mockRejectedValue(new Error('Owner only'))
		await wrapper.vm.onGradeChange({ id: 'm1' }, 'manage')
		expect(wrapper.vm.error).toBe('Owner only')
		expect(wrapper.vm.busy).toBe(false)
	})

	it('approves a waiting member and lists the secrets the server refused', async () => {
		store.approveMember.mockResolvedValue({ refused: ['Prod DB'] })
		const wrapper = mountDialog()

		await wrapper.vm.onApproveMember('bob')

		expect(store.approveMember).toHaveBeenCalledWith('tf-1', 'bob')
		expect(wrapper.vm.refusedSecrets).toEqual(['Prod DB'])

		store.approveMember.mockRejectedValue(new Error('Not waiting'))
		await wrapper.vm.onApproveMember('bob')
		expect(wrapper.vm.refusedSecrets).toEqual([])
		expect(wrapper.vm.error).toBe('Not waiting')
		expect(wrapper.vm.busy).toBe(false)
	})

	it('records skipped and refused secrets from the fan-out', async () => {
		store.runFanOut.mockResolvedValue({ skipped: ['Wifi'], refused: ['Root'] })
		const wrapper = mountDialog()

		await wrapper.vm.onRunFanOut()

		expect(wrapper.vm.skippedSecrets).toEqual(['Wifi'])
		expect(wrapper.vm.refusedSecrets).toEqual(['Root'])

		store.runFanOut.mockResolvedValue(undefined)
		await wrapper.vm.onRunFanOut()
		expect(wrapper.vm.skippedSecrets).toEqual([])
		expect(wrapper.vm.refusedSecrets).toEqual([])

		store.runFanOut.mockRejectedValue({ response: { data: { message: 'Locked' } } })
		await wrapper.vm.onRunFanOut()
		expect(wrapper.vm.error).toBe('Locked')
	})

	it('unshares the folder and closes, or keeps it open with the refusal', async () => {
		const wrapper = mountDialog()

		await wrapper.vm.onUnshare()
		expect(store.unshareFolder).toHaveBeenCalledWith('tf-1')
		expect(wrapper.emitted('update:open')).toEqual([[false]])

		store.unshareFolder.mockRejectedValue(new Error('Busy'))
		await wrapper.vm.onUnshare()
		expect(wrapper.vm.error).toBe('Busy')
		expect(wrapper.emitted('update:open')).toHaveLength(1)
		expect(wrapper.vm.busy).toBe(false)
	})

	it('re-emits its own close and shows an unparsable end date as given', () => {
		const wrapper = mountDialog()

		wrapper.vm.onUpdateOpen(false)

		expect(wrapper.emitted('update:open')).toEqual([[false]])
		expect(wrapper.vm.formatEndDate('not a date')).toBe('not a date')
		expect(wrapper.vm.formatEndDate('2026-12-31T00:00:00Z')).toBe(
			new Date('2026-12-31T00:00:00Z').toLocaleDateString(),
		)
	})
})
