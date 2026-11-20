/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The team folder membership actions of `useTeamFolderStore`: loading the
 * folders, sharing and unsharing a folder, adding and removing members,
 * changing a grade, cancelling a fan-out and the best-effort attachment
 * re-grant.
 *
 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-share-a-folder-as-a-team-folder
 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-inherited-access-on-add-revoked-on-removal
 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-team-folder-membership-carries-a-read-write-or-manage-grade
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useAttachmentStore } from '../../src/store/modules/attachment.js'
import { useTeamFolderStore } from '../../src/store/modules/teamFolder.js'

vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: vi.fn(),
	showError: vi.fn(),
	showWarning: vi.fn(),
}))

const LIST = { data: { owned: [{ id: 'tf-1' }], memberOf: [{ id: 'tf-2' }] } }

describe('teamFolder membership actions', () => {
	let get

	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		get = vi.spyOn(axios, 'get').mockResolvedValue(LIST)
	})

	describe('fetchTeamFolders', () => {
		it('stores the owned and member-of folders and clears loading', async () => {
			const store = useTeamFolderStore()
			await store.fetchTeamFolders()

			expect(get.mock.calls[0][0]).toContain(
				'/apps/keepiq/api/v1/team-folders',
			)
			expect(store.owned).toEqual([{ id: 'tf-1' }])
			expect(store.memberOf).toEqual([{ id: 'tf-2' }])
			expect(store.loading).toBe(false)
			expect(store.error).toBeNull()
		})

		it('falls back to empty lists when the answer has none', async () => {
			get.mockResolvedValue({ data: {} })
			const store = useTeamFolderStore()
			await store.fetchTeamFolders()

			expect(store.owned).toEqual([])
			expect(store.memberOf).toEqual([])
		})

		it('keeps the server message as the error and rethrows', async () => {
			const failure = Object.assign(new Error('network'), {
				response: { data: { message: 'Not allowed' } },
			})
			get.mockRejectedValue(failure)
			const store = useTeamFolderStore()

			await expect(store.fetchTeamFolders()).rejects.toBe(failure)
			expect(store.error).toBe('Not allowed')
			expect(store.loading).toBe(false)
		})

		it('uses the error message when the server sent none', async () => {
			get.mockRejectedValue(new Error('offline'))
			const store = useTeamFolderStore()

			await expect(store.fetchTeamFolders()).rejects.toThrow('offline')
			expect(store.error).toBe('offline')
		})

		it('uses a generic text when the failure carries no message at all', async () => {
			get.mockRejectedValue({})
			const store = useTeamFolderStore()

			await expect(store.fetchTeamFolders()).rejects.toEqual({})
			expect(store.error).toBe('Failed to load team folders')
		})
	})

	it('shareFolder posts the folder id, reloads and returns the row', async () => {
		const post = vi
			.spyOn(axios, 'post')
			.mockResolvedValue({ data: { id: 'tf-9' } })
		const store = useTeamFolderStore()

		const row = await store.shareFolder('folder-7')

		expect(post.mock.calls[0][0]).toContain('/apps/keepiq/api/v1/team-folders')
		expect(post.mock.calls[0][1]).toEqual({ folderId: 'folder-7' })
		expect(row).toEqual({ id: 'tf-9' })
		expect(get).toHaveBeenCalledTimes(1)
		expect(store.owned).toEqual([{ id: 'tf-1' }])
	})

	it('addMember posts the member and its restriction, then reloads', async () => {
		const post = vi
			.spyOn(axios, 'post')
			.mockResolvedValue({ data: { recipients: 2 } })
		const store = useTeamFolderStore()

		const result = await store.addMember('tf-1', 'group', 'ops', {
			useOnly: true,
			expiresAt: '2027-01-01',
		})

		expect(post.mock.calls[0][0]).toContain(
			'/apps/keepiq/api/v1/team-folders/tf-1/members',
		)
		expect(post.mock.calls[0][1]).toEqual({
			memberType: 'group',
			memberId: 'ops',
			useOnly: true,
			expiresAt: '2027-01-01',
		})
		expect(result).toEqual({ recipients: 2 })
		expect(get).toHaveBeenCalledTimes(1)
	})

	it('addMember without a restriction sends only the member', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
		const store = useTeamFolderStore()

		await store.addMember('tf-1', 'user', 'kim')

		expect(post.mock.calls[0][1]).toEqual({
			memberType: 'user',
			memberId: 'kim',
		})
	})

	it('removeMember deletes the membership row and reloads', async () => {
		const del = vi
			.spyOn(axios, 'delete')
			.mockResolvedValue({ data: { revoked: 3 } })
		const store = useTeamFolderStore()

		const result = await store.removeMember('tf-1', 'm-4')

		expect(del.mock.calls[0][0]).toContain(
			'/apps/keepiq/api/v1/team-folders/tf-1/members/m-4',
		)
		expect(result).toEqual({ revoked: 3 })
		expect(get).toHaveBeenCalledTimes(1)
	})

	it('setMemberGrade patches the grade and reloads', async () => {
		const patch = vi
			.spyOn(axios, 'patch')
			.mockResolvedValue({ data: { grade: 'write' } })
		const store = useTeamFolderStore()

		const result = await store.setMemberGrade('tf-1', 'm-4', 'write')

		expect(patch.mock.calls[0][0]).toContain(
			'/apps/keepiq/api/v1/team-folders/tf-1/members/m-4',
		)
		expect(patch.mock.calls[0][1]).toEqual({ grade: 'write' })
		expect(result).toEqual({ grade: 'write' })
		expect(get).toHaveBeenCalledTimes(1)
	})

	it('unshareFolder deletes the team folder and reloads', async () => {
		const del = vi
			.spyOn(axios, 'delete')
			.mockResolvedValue({ data: { revoked: 5 } })
		const store = useTeamFolderStore()

		const result = await store.unshareFolder('tf-1')

		expect(del.mock.calls[0][0]).toMatch(
			/\/apps\/keepiq\/api\/v1\/team-folders\/tf-1$/,
		)
		expect(result).toEqual({ revoked: 5 })
		expect(get).toHaveBeenCalledTimes(1)
	})

	it('a failed reload after a write surfaces the reload failure', async () => {
		vi.spyOn(axios, 'post').mockResolvedValue({ data: { id: 'tf-9' } })
		get.mockRejectedValue(new Error('reload failed'))
		const store = useTeamFolderStore()

		await expect(store.shareFolder('folder-7')).rejects.toThrow('reload failed')
		expect(store.error).toBe('reload failed')
	})

	it('cancelFanOut flags the running fan-out as cancelled', () => {
		const store = useTeamFolderStore()
		store.fanOutCancelled = false

		store.cancelFanOut()

		expect(store.fanOutCancelled).toBe(true)
	})

	describe('regrantAttachments', () => {
		it('re-grants each row whose recipient has a certificate, in order', async () => {
			const attachments = useAttachmentStore()
			const regrant = vi
				.spyOn(attachments, 'regrantForRecipient')
				.mockResolvedValue(undefined)
			const store = useTeamFolderStore()

			await store.regrantAttachments(
				[
					{
						sourceSecretId: 's-1',
						recipientSecretId: 'c-1',
						targetUserId: 'kim',
					},
					{
						sourceSecretId: 's-2',
						recipientSecretId: 'c-2',
						targetUserId: 'nocert',
					},
					{
						sourceSecretId: 's-3',
						recipientSecretId: 'c-3',
						targetUserId: 'lee',
					},
				],
				{ kim: 'CERT-KIM', lee: 'CERT-LEE' },
			)

			expect(regrant).toHaveBeenCalledTimes(2)
			expect(regrant.mock.calls[0][0]).toEqual({
				sourceSecretId: 's-1',
				copySecretId: 'c-1',
				recipientId: 'kim',
				recipientCertificate: 'CERT-KIM',
			})
			expect(regrant.mock.calls[1][0]).toEqual({
				sourceSecretId: 's-3',
				copySecretId: 'c-3',
				recipientId: 'lee',
				recipientCertificate: 'CERT-LEE',
			})
		})

		it('keeps going after a failed re-grant', async () => {
			const attachments = useAttachmentStore()
			const regrant = vi
				.spyOn(attachments, 'regrantForRecipient')
				.mockRejectedValueOnce(new Error('no key'))
				.mockResolvedValueOnce(undefined)
			const store = useTeamFolderStore()

			await expect(
				store.regrantAttachments(
					[
						{
							sourceSecretId: 's-1',
							recipientSecretId: 'c-1',
							targetUserId: 'kim',
						},
						{
							sourceSecretId: 's-2',
							recipientSecretId: 'c-2',
							targetUserId: 'lee',
						},
					],
					{ kim: 'CERT-KIM', lee: 'CERT-LEE' },
				),
			).resolves.toBeUndefined()

			expect(regrant).toHaveBeenCalledTimes(2)
			expect(regrant.mock.calls[1][0].recipientId).toBe('lee')
		})
	})
})
