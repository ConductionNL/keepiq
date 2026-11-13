/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The left rail's mounted dialog wiring, driven through the rendered
 * template rather than the bare methods:
 *
 *   - the tree's edit / share / move / delete / newFolder events open the
 *     matching dialog with the node they carry;
 *   - each dialog's close / saved / deleted / update:open event closes it
 *     again (and a move refetches the folders, a deleted active folder
 *     leaves its route);
 *   - an EMPTY delete target gets the plain confirm, a non-empty one the
 *     subfolder resolution protocol;
 *   - "Team folders you manage" lists the managed team folders and opens
 *     the team share dialog for the folder a row stands for;
 *   - the "New vault" row opens the create dialog at the root;
 *   - an instance admin gets the "Admin settings" link-out with its icon
 *     and the open-in-new marker, a non-admin does not.
 *
 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-managers-keep-the-membership-current
 */

import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import KeepiqAppNav from '../../src/components/KeepiqAppNav/KeepiqAppNav.vue'
import { useFolderStore } from '../../src/store/modules/folder.js'
import { useTeamFolderStore } from '../../src/store/modules/teamFolder.js'

const user = vi.hoisted(() => ({ current: { uid: 'ada', isAdmin: false } }))

vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: () => user.current,
}))

const item = {
	props: ['name', 'to', 'href', 'active'],
	emits: ['click'],
	template:
		'<li :data-name="name" :data-href="href || undefined" @click="$emit(\'click\', $event)"><slot name="icon" /><slot name="counter" /></li>',
}

/**
 * A dialog stub that renders its props as data and re-emits on demand.
 *
 * @param {string} testId The stub's data-testid.
 * @param {string[]} props The props it accepts.
 * @param {string[]} emits The events it may emit.
 * @return {object} The stub component.
 */
function dialogStub(testId, props, emits) {
	return {
		props,
		emits,
		template: `<div data-testid="${testId}" />`,
	}
}

const stubs = {
	NcAppNavigation: {
		template:
			'<nav><ul><slot name="list" /></ul><div class="footer"><slot name="footer" /></div></nav>',
	},
	NcAppNavigationCaption: {
		props: ['name'],
		template: '<li class="caption" :data-name="name" />',
	},
	NcAppNavigationItem: item,
	NcAppNavigationSettings: { template: '<div><slot /></div>' },
	NavFolderTree: {
		props: ['folders', 'highlightId', 'ellipsisHighlightId'],
		emits: ['edit', 'share', 'move', 'delete', 'newFolder'],
		template: '<div data-testid="tree" />',
	},
	FolderCreateDialog: dialogStub('create-dialog', ['parentId'], ['saved', 'close']),
	FolderEditDialog: dialogStub('edit-dialog', ['folder'], ['close']),
	MoveDialog: dialogStub('move-dialog', ['folder', 'subject'], ['saved', 'close']),
	TeamFolderDialog: dialogStub(
		'team-dialog',
		['open', 'folderId', 'folderName'],
		['update:open'],
	),
	SubfolderResolutionDialog: dialogStub(
		'resolution-dialog',
		['open', 'folderId', 'children'],
		['deleted', 'update:open'],
	),
	FolderDeleteConfirmDialog: dialogStub(
		'confirm-dialog',
		['folder'],
		['deleted', 'close'],
	),
}

const VAULT = { id: 'v1', name: 'Suppliers', parentId: null }

let folderStore
let push

/**
 * Mount the rail with one vault in the store.
 *
 * @param {object} [route] The current route.
 * @return {object} The wrapper.
 */
function mountNav(route = { name: 'SecretList', params: {}, query: {} }) {
	return mount(KeepiqAppNav, {
		props: { manifest: { menu: [] } },
		global: {
			stubs,
			mocks: { $route: route, $router: { push } },
		},
	})
}

/**
 * The stubbed tree component.
 *
 * @param {object} wrapper The mounted rail.
 * @return {object} The tree wrapper.
 */
function tree(wrapper) {
	return wrapper.findComponent(stubs.NavFolderTree)
}

/**
 * Whether the element with this test id is rendered.
 *
 * @param {object} wrapper The mounted rail.
 * @param {string} testId The data-testid.
 * @return {boolean}
 */
function has(wrapper, testId) {
	return wrapper.find(`[data-testid="${testId}"]`).exists()
}

beforeEach(() => {
	setActivePinia(createPinia())
	user.current = { uid: 'ada', isAdmin: false }
	push = vi.fn()
	folderStore = useFolderStore()
	folderStore.folders = [VAULT]
	folderStore.fetchFolders = vi.fn().mockResolvedValue()
	folderStore.fetchChildren = vi.fn()
	useTeamFolderStore().fetchTeamFolders = vi.fn().mockResolvedValue()
})

describe('KeepiqAppNav dialogs from the tree', () => {
	it('hands the store tree and the highlight to the folder tree', () => {
		const wrapper = mountNav({ name: 'SecretListFolder', params: { folderId: 'v1' }, query: {} })

		expect(tree(wrapper).props('folders')).toEqual([{ ...VAULT, children: [] }])
		expect(tree(wrapper).props('highlightId')).toBe('v1')
		expect(tree(wrapper).props('ellipsisHighlightId')).toBeNull()
	})

	it('renders no tree without folders', () => {
		folderStore.folders = []
		const wrapper = mountNav()

		expect(has(wrapper, 'tree')).toBe(false)
		expect(has(wrapper, 'nav-vault-new')).toBe(true)
	})

	it('opens the edit dialog for the node and closes it again', async () => {
		const wrapper = mountNav()

		await tree(wrapper).vm.$emit('edit', VAULT)
		const dialog = wrapper.findComponent(stubs.FolderEditDialog)
		expect(dialog.props('folder')).toEqual(VAULT)

		await dialog.vm.$emit('close')
		expect(has(wrapper, 'edit-dialog')).toBe(false)
		expect(wrapper.vm.editFolder).toBeNull()
	})

	it('opens the team share dialog for the node and closes it on update:open false only', async () => {
		const wrapper = mountNav()

		await tree(wrapper).vm.$emit('share', VAULT)
		const dialog = wrapper.findComponent(stubs.TeamFolderDialog)
		expect(dialog.props()).toEqual({ open: true, folderId: 'v1', folderName: 'Suppliers' })

		await dialog.vm.$emit('update:open', true)
		expect(has(wrapper, 'team-dialog')).toBe(true)

		await dialog.vm.$emit('update:open', false)
		expect(has(wrapper, 'team-dialog')).toBe(false)
		expect(wrapper.vm.shareFolder).toBeNull()
	})

	it('opens the move dialog for the node, closes it, and refetches after a move', async () => {
		const wrapper = mountNav()

		await tree(wrapper).vm.$emit('move', VAULT)
		let dialog = wrapper.findComponent(stubs.MoveDialog)
		expect(dialog.props()).toEqual({ folder: VAULT, subject: 'vault' })

		await dialog.vm.$emit('close')
		expect(has(wrapper, 'move-dialog')).toBe(false)

		await tree(wrapper).vm.$emit('move', VAULT)
		dialog = wrapper.findComponent(stubs.MoveDialog)
		folderStore.fetchFolders.mockClear()
		await dialog.vm.$emit('saved')
		expect(has(wrapper, 'move-dialog')).toBe(false)
		expect(folderStore.fetchFolders).toHaveBeenCalledTimes(1)
	})

	it('opens a folder create under the vault and closes it on saved and on close', async () => {
		const wrapper = mountNav()

		await tree(wrapper).vm.$emit('newFolder', VAULT)
		let dialog = wrapper.findComponent(stubs.FolderCreateDialog)
		expect(dialog.props('parentId')).toBe('v1')

		await dialog.vm.$emit('saved')
		expect(has(wrapper, 'create-dialog')).toBe(false)

		await wrapper.find('[data-testid="nav-vault-new"]').trigger('click')
		dialog = wrapper.findComponent(stubs.FolderCreateDialog)
		expect(dialog.props('parentId')).toBeNull()

		await dialog.vm.$emit('close')
		expect(has(wrapper, 'create-dialog')).toBe(false)
	})
})

describe('KeepiqAppNav delete surfaces', () => {
	it('confirms an empty vault and leaves its route once deleted', async () => {
		folderStore.fetchChildren.mockResolvedValue({ directSecretCount: 0, subfolders: [] })
		const wrapper = mountNav({ name: 'SecretListFolder', params: { folderId: 'v1' }, query: {} })

		await tree(wrapper).vm.$emit('delete', VAULT)
		await flushPromises()

		expect(folderStore.fetchChildren).toHaveBeenCalledWith('v1')
		expect(has(wrapper, 'resolution-dialog')).toBe(false)
		const confirm = wrapper.findComponent(stubs.FolderDeleteConfirmDialog)
		expect(confirm.props('folder')).toEqual(VAULT)

		await confirm.vm.$emit('deleted', 'v1')
		expect(has(wrapper, 'confirm-dialog')).toBe(false)
		expect(push).toHaveBeenCalledWith({ name: 'SecretList' })
	})

	it('closes the confirm without navigating when cancelled', async () => {
		folderStore.fetchChildren.mockResolvedValue({})
		const wrapper = mountNav()

		await tree(wrapper).vm.$emit('delete', VAULT)
		await flushPromises()
		await wrapper.findComponent(stubs.FolderDeleteConfirmDialog).vm.$emit('close')

		expect(has(wrapper, 'confirm-dialog')).toBe(false)
		expect(wrapper.vm.deleteFolder).toBeNull()
		expect(wrapper.vm.deleteChildren).toBeNull()
		expect(push).not.toHaveBeenCalled()
	})

	it('runs a vault with content through the resolution protocol', async () => {
		const children = { directSecretCount: 3, subfolders: [{ id: 'f1' }] }
		folderStore.fetchChildren.mockResolvedValue(children)
		const wrapper = mountNav()

		await tree(wrapper).vm.$emit('delete', VAULT)
		await flushPromises()

		expect(has(wrapper, 'confirm-dialog')).toBe(false)
		const resolution = wrapper.findComponent(stubs.SubfolderResolutionDialog)
		expect(resolution.props()).toEqual({ open: true, folderId: 'v1', children })

		await resolution.vm.$emit('update:open', true)
		expect(has(wrapper, 'resolution-dialog')).toBe(true)

		await resolution.vm.$emit('update:open', false)
		expect(has(wrapper, 'resolution-dialog')).toBe(false)
		expect(wrapper.vm.deleteFolder).toBeNull()
	})

	it('closes the resolution protocol once the vault is deleted', async () => {
		folderStore.fetchChildren.mockResolvedValue({ directSecretCount: 1 })
		const wrapper = mountNav({ name: 'SecretListFolder', params: { folderId: 'other' }, query: {} })

		await tree(wrapper).vm.$emit('delete', VAULT)
		await flushPromises()
		await wrapper.findComponent(stubs.SubfolderResolutionDialog).vm.$emit('deleted', 'v1')

		expect(has(wrapper, 'resolution-dialog')).toBe(false)
		expect(push).not.toHaveBeenCalled()
	})
})

describe('KeepiqAppNav managed team folders', () => {
	it('lists them under their own caption and opens the team dialog for the folder', async () => {
		useTeamFolderStore().memberOf = [
			{ id: 'tf-1', folderId: 'v9', folderName: 'Finance', grade: 'manage' },
			{ id: 'tf-2', folderId: 'v8', folderName: 'Read only', grade: 'read' },
		]
		const wrapper = mountNav()

		expect(has(wrapper, 'nav-managed-caption')).toBe(true)
		expect(has(wrapper, 'nav-managed-tf-2')).toBe(false)
		const row = wrapper.find('[data-testid="nav-managed-tf-1"]')
		expect(row.attributes('data-name')).toBe('Finance')

		await row.trigger('click')

		expect(wrapper.findComponent(stubs.TeamFolderDialog).props()).toEqual({
			open: true,
			folderId: 'v9',
			folderName: 'Finance',
		})
	})

	it('shows no managed section without managed folders', () => {
		const wrapper = mountNav()

		expect(has(wrapper, 'nav-managed-caption')).toBe(false)
	})
})

describe('KeepiqAppNav admin settings link', () => {
	it('links an instance admin to the keepiq admin page with its icon and new-tab marker', () => {
		user.current = { uid: 'root', isAdmin: true }
		const wrapper = mountNav()

		const link = wrapper.find('[data-testid="cn-nav-admin-settings"]')
		expect(link.attributes('data-href')).toBe(
			window.location.origin + '/settings/admin/keepiq',
		)
		expect(link.findAll('svg').length).toBe(2)
	})

	it('offers no admin link to a non-admin', () => {
		const wrapper = mountNav()

		expect(has(wrapper, 'cn-nav-admin-settings')).toBe(false)
	})
})
