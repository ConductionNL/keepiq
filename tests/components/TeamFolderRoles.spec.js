/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Viewer, Editor and Manager in the team-folder dialog, and a manager's
 * fan-out from its own copies (sharing-team-folder-manager-role 3.1, 3.2).
 *
 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-managers-keep-the-membership-current
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import TeamFolderDialog from '../../src/modals/TeamFolderDialog.vue'
import { useSecretStore } from '../../src/store/modules/secret.js'
import { useShareStore } from '../../src/store/modules/share.js'
import { useTeamFolderStore } from '../../src/store/modules/teamFolder.js'

let currentUid = 'alice'
vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: () => ({ uid: currentUid }),
	getRequestToken: () => 'token',
	onRequestTokenUpdate: () => {},
}))

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const members = [
	{
		id: 'm-olga',
		memberType: 'user',
		memberId: 'olga',
		grade: 'manage',
		addedBy: 'alice',
	},
	{
		id: 'm-vic',
		memberType: 'user',
		memberId: 'vic',
		grade: 'read',
		addedBy: 'olga',
	},
]

function mockList(entry) {
	vi.spyOn(axios, 'get').mockImplementation((url) => {
		if (url.includes('/reconcile')) {
			return Promise.resolve({
				data: { secrets: [], recipients: [], missing: [] },
			})
		}
		return Promise.resolve({ data: entry })
	})
	vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
}

async function mountDialog() {
	const wrapper = mount(TeamFolderDialog, {
		props: { open: true, folderId: 'folder-1', folderName: 'DevOps' },
	})
	wrapper.vm.refresh()
	await flush()
	await flush()
	return wrapper
}

describe('team-folder roles', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('the owner sees Viewer, Editor and Manager and the danger zone', async () => {
		currentUid = 'alice'
		mockList({
			owned: [
				{
					id: 'tf-1',
					folderId: 'folder-1',
					folderName: 'DevOps',
					ownerId: 'alice',
					members,
				},
			],
			memberOf: [],
		})
		const wrapper = await mountDialog()
		const options = wrapper
			.findAll('[data-testid="team-folder-grade-vic"] option')
			.map((o) => o.text())
		expect(options).toEqual(['Viewer', 'Editor', 'Manager'])
		expect(
			wrapper
				.find('[data-testid="team-folder-grade-olga"]')
				.attributes('disabled'),
		).toBeUndefined()
		expect(wrapper.find('[data-testid="team-folder-unshare"]').exists()).toBe(
			true,
		)
		expect(
			wrapper.find('[data-testid="team-folder-added-by-vic"]').exists(),
		).toBe(true)
	})

	it('a manager sees Viewer and Editor, a manager row read-only, and no danger zone', async () => {
		currentUid = 'olga'
		mockList({
			owned: [],
			memberOf: [
				{
					id: 'tf-1',
					folderId: 'folder-1',
					folderName: 'DevOps',
					ownerId: 'alice',
					grade: 'manage',
					members,
				},
			],
		})
		const wrapper = await mountDialog()
		const options = wrapper
			.findAll('[data-testid="team-folder-grade-vic"] option')
			.map((o) => o.text())
		expect(options).toEqual(['Viewer', 'Editor'])
		expect(
			wrapper
				.find('[data-testid="team-folder-grade-olga"]')
				.attributes('disabled'),
		).toBeDefined()
		expect(wrapper.find('[data-testid="team-folder-unshare"]').exists()).toBe(
			false,
		)
		// She may leave: her own remove button stays.
		expect(
			wrapper.find('[data-testid="team-folder-remove-olga"]').exists(),
		).toBe(true)
	})

	it('a viewer gets no dialog content at all', async () => {
		currentUid = 'vic'
		mockList({
			owned: [],
			memberOf: [
				{
					id: 'tf-1',
					folderId: 'folder-1',
					folderName: 'DevOps',
					ownerId: 'alice',
					grade: 'read',
				},
			],
		})
		const wrapper = await mountDialog()
		expect(wrapper.find('[data-testid="team-folder-members"]').exists()).toBe(
			false,
		)
		expect(wrapper.find('[data-testid="team-folder-grade-vic"]').exists()).toBe(
			false,
		)
	})

	it('a manager fans out from its own copies and reports the ones it lacks', async () => {
		const store = useTeamFolderStore()
		store.reconcile = vi.fn().mockResolvedValue({
			secrets: [
				{ id: 'sec-1', name: 'Wiki', copyId: 'olga-copy-1' },
				{ id: 'sec-2', name: 'Mail', copyId: null },
			],
			recipients: [{ userId: 'bob', certificate: 'PEM' }],
			missing: [
				{ secretId: 'sec-1', userId: 'bob' },
				{ secretId: 'sec-2', userId: 'bob' },
			],
		})
		store.regrantAttachments = vi.fn().mockResolvedValue()
		const fetchSecret = vi
			.fn()
			.mockResolvedValue({ key: 'k', login: 'l', additionalFields: null })
		useSecretStore().fetchSecret = fetchSecret
		useShareStore().encryptForRecipient = vi
			.fn()
			.mockResolvedValue({ key: 'CIPHER' })
		const post = vi
			.spyOn(axios, 'post')
			.mockResolvedValue({ data: { created: 1, rows: [] } })

		const result = await store.runFanOut('tf-1')

		expect(fetchSecret).toHaveBeenCalledTimes(1)
		expect(fetchSecret).toHaveBeenCalledWith('olga-copy-1')
		expect(post.mock.calls[0][1].shares).toEqual([
			expect.objectContaining({
				sourceSecretId: 'sec-1',
				targetUserId: 'bob',
				encryptedKey: 'CIPHER',
			}),
		])
		expect(result.skipped).toEqual(['Mail'])
	})
})
