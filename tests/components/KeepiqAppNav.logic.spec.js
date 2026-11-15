/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The left rail's folder logic: the active folder trail and which row
 * carries the highlight below the display cap, the delete flow, the
 * create entry points and the folder refresh on unlock.
 *
 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-managers-keep-the-membership-current
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const user = vi.hoisted(() => ({ current: null }))
const teamFolderStore = { managed: [], fetchTeamFolders: vi.fn() }

vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: () => user.current,
}))
vi.mock('../../src/store/modules/teamFolder.js', () => ({
	useTeamFolderStore: () => teamFolderStore,
}))

const { default: KeepiqAppNav } = await import(
	'../../src/components/KeepiqAppNav/KeepiqAppNav.vue'
)
const { NAV_TREE_MAX_DEPTH } = await import(
	'../../src/components/KeepiqAppNav/NavFolderTree.vue'
)
const { computed, methods } = KeepiqAppNav

/**
 * A chain of folders f1 > f2 > ... > f<depth>.
 *
 * @param {number} depth The chain length.
 * @return {object[]} The folders.
 */
function chain(depth) {
	return Array.from({ length: depth }, (_, i) => ({
		id: `f${i + 1}`,
		parentId: i === 0 ? null : `f${i}`,
	}))
}

/**
 * A fake instance with the trail-related computeds resolved.
 *
 * @param {object[]} folders The store folders.
 * @param {string|null} activeFolderId The route folder.
 * @return {object} The instance.
 */
function trailVm(folders, activeFolderId) {
	const vm = { folderStore: { folders }, activeFolderId }
	vm.activeFolderTrail = computed.activeFolderTrail.call(vm)
	return vm
}

/**
 * A fake instance with the methods bound to it.
 *
 * @param {object} overrides Fields to set.
 * @return {object} The instance.
 */
function makeVm(overrides = {}) {
	const vm = {
		...KeepiqAppNav.data(),
		folderStore: {
			fetchFolders: vi.fn().mockResolvedValue(),
			fetchChildren: vi.fn(),
		},
		sessionStore: { isLocked: false },
		$router: { push: vi.fn() },
		$route: { params: {} },
		...overrides,
	}
	for (const [name, fn] of Object.entries(methods)) {
		vm[name] = fn.bind(vm)
	}
	return vm
}

describe('KeepiqAppNav logic', () => {
	beforeEach(() => {
		user.current = null
		teamFolderStore.fetchTeamFolders = vi.fn().mockResolvedValue()
	})

	describe('active folder trail', () => {
		it('is empty without an active folder', () => {
			expect(trailVm(chain(3), null).activeFolderTrail).toEqual([])
		})

		it('runs from the root down to the active folder', () => {
			expect(trailVm(chain(3), 'f3').activeFolderTrail).toEqual(['f1', 'f2', 'f3'])
		})

		it('stops on a parent cycle instead of looping', () => {
			const folders = [
				{ id: 'a', parentId: 'b' },
				{ id: 'b', parentId: 'a' },
			]
			expect(trailVm(folders, 'a').activeFolderTrail).toEqual(['b', 'a'])
		})

		it('is empty for a folder the store does not know', () => {
			expect(trailVm(chain(2), 'zz').activeFolderTrail).toEqual([])
		})

		it('reads the active folder from the route', () => {
			expect(computed.activeFolderId.call({ $route: { params: { folderId: 'f9' } } })).toBe('f9')
			expect(computed.activeFolderId.call({ $route: { params: {} } })).toBeNull()
			expect(computed.activeFolderId.call({})).toBeNull()
		})
	})

	describe('highlight', () => {
		it('highlights the active folder while it is within the display cap', () => {
			const vm = trailVm(chain(NAV_TREE_MAX_DEPTH), `f${NAV_TREE_MAX_DEPTH}`)

			expect(computed.highlightFolderId.call(vm)).toBe(`f${NAV_TREE_MAX_DEPTH}`)
			expect(computed.ellipsisHighlightId.call(vm)).toBeNull()
		})

		it('moves the highlight to the ellipsis under the deepest visible ancestor below the cap', () => {
			const depth = NAV_TREE_MAX_DEPTH + 2
			const vm = trailVm(chain(depth), `f${depth}`)

			expect(computed.highlightFolderId.call(vm)).toBeNull()
			expect(computed.ellipsisHighlightId.call(vm)).toBe(`f${NAV_TREE_MAX_DEPTH}`)
		})

		it('highlights nothing without a trail', () => {
			expect(computed.highlightFolderId.call(trailVm([], null))).toBeNull()
		})
	})

	describe('delete emptiness', () => {
		const isEmpty = (deleteChildren) => computed.deleteIsEmpty.call({ deleteChildren })

		it('is not empty while the children are unknown', () => {
			expect(isEmpty(null)).toBe(false)
		})

		it('is empty with no secrets and no subfolders', () => {
			expect(isEmpty({ directSecretCount: 0, subfolders: [] })).toBe(true)
			expect(isEmpty({})).toBe(true)
		})

		it('is not empty with a secret or a subfolder', () => {
			expect(isEmpty({ directSecretCount: 1, subfolders: [] })).toBe(false)
			expect(isEmpty({ directSecretCount: 0, subfolders: [{ id: 'x' }] })).toBe(false)
		})
	})

	describe('menu sections', () => {
		it('orders visible entries by order, unordered last, and splits them by section', () => {
			const manifest = {
				menu: [
					{ id: 'c', section: 'footer', order: 1 },
					{ id: 'z' },
					{ id: 'a', order: 2 },
					{ id: 's', section: 'settings', order: 3 },
					{ id: 'b', section: 'main', order: 1 },
				],
			}
			const vm = { manifest, isAdmin: false }
			vm.sortedMenu = computed.sortedMenu.call(vm)

			expect(vm.sortedMenu.map((i) => i.id)).toEqual(['c', 'b', 'a', 's', 'z'])
			expect(computed.mainItems.call(vm).map((i) => i.id)).toEqual(['b', 'a', 'z'])
			expect(computed.footerItems.call(vm).map((i) => i.id)).toEqual(['c'])
			expect(computed.settingsItems.call(vm).map((i) => i.id)).toEqual(['s'])
		})

		it('is empty without a manifest menu', () => {
			expect(computed.sortedMenu.call({ manifest: {}, isAdmin: false })).toEqual([])
		})

		it('treats only an explicit isAdmin true as admin', () => {
			expect(computed.isAdmin.call({})).toBe(false)
			user.current = { isAdmin: 'yes' }
			expect(computed.isAdmin.call({})).toBe(false)
			user.current = { isAdmin: true }
			expect(computed.isAdmin.call({})).toBe(true)
		})

		it('points the admin settings link at the keepiq admin page', () => {
			expect(computed.adminSettingsHref.call({})).toBe(
				window.location.origin + '/settings/admin/keepiq',
			)
		})

		it('lists the managed team folders', () => {
			teamFolderStore.managed = [{ id: 't1' }]
			expect(computed.managedTeamFolders.call({})).toEqual([{ id: 't1' }])
		})
	})

	describe('folder loading', () => {
		it('loads folders and team folders on mount when unlocked', () => {
			const vm = makeVm()

			KeepiqAppNav.mounted.call(vm)

			expect(vm.folderStore.fetchFolders).toHaveBeenCalledTimes(1)
			expect(teamFolderStore.fetchTeamFolders).toHaveBeenCalledTimes(1)
		})

		it('does not load on mount while locked, and loads on unlock', () => {
			const vm = makeVm({ sessionStore: { isLocked: true } })

			KeepiqAppNav.mounted.call(vm)
			expect(vm.folderStore.fetchFolders).not.toHaveBeenCalled()

			KeepiqAppNav.watch['sessionStore.isLocked'].call(vm, true)
			expect(vm.folderStore.fetchFolders).not.toHaveBeenCalled()

			KeepiqAppNav.watch['sessionStore.isLocked'].call(vm, false)
			expect(vm.folderStore.fetchFolders).toHaveBeenCalledTimes(1)
		})

		it('swallows a failed folder load', async () => {
			const vm = makeVm()
			vm.folderStore.fetchFolders = vi.fn().mockRejectedValue(new Error('500'))
			teamFolderStore.fetchTeamFolders = vi.fn().mockRejectedValue(new Error('500'))

			expect(() => vm.fetchFoldersSafe()).not.toThrow()
			await Promise.resolve()
		})
	})

	describe('create', () => {
		it('opens a root vault create and a subfolder create under the node', () => {
			const vm = makeVm({ createParentId: 'x' })

			vm.onNewVault()
			expect(vm.createParentId).toBeNull()
			expect(vm.createOpen).toBe(true)

			vm.createOpen = false
			vm.onNewFolder({ id: 'f2' })
			expect(vm.createParentId).toBe('f2')
			expect(vm.createOpen).toBe(true)
		})
	})

	describe('delete', () => {
		it('loads the children of the folder to delete', async () => {
			const children = { directSecretCount: 2, subfolders: [] }
			const vm = makeVm()
			vm.folderStore.fetchChildren.mockResolvedValue(children)

			await vm.onDeleteRequested({ id: 'f1' })

			expect(vm.folderStore.fetchChildren).toHaveBeenCalledWith('f1')
			expect(vm.deleteFolder).toEqual({ id: 'f1' })
			expect(vm.deleteChildren).toBe(children)
		})

		it('offers no delete when the children cannot be loaded', async () => {
			const vm = makeVm()
			vm.folderStore.fetchChildren.mockRejectedValue(new Error('403'))

			await vm.onDeleteRequested({ id: 'f1' })

			expect(vm.deleteFolder).toBeNull()
			expect(vm.deleteChildren).toBeNull()
		})

		it('leaves the route of a deleted active folder', () => {
			const vm = makeVm({
				deleteFolder: { id: 'f1' },
				deleteChildren: {},
				$route: { params: { folderId: 'f1' } },
			})

			vm.onDeleted('f1')

			expect(vm.deleteFolder).toBeNull()
			expect(vm.deleteChildren).toBeNull()
			expect(vm.$router.push).toHaveBeenCalledWith({ name: 'SecretList' })
		})

		it('stays on the route when another folder was deleted', () => {
			const vm = makeVm({ $route: { params: { folderId: 'f2' } } })

			vm.onDeleted('f1')

			expect(vm.$router.push).not.toHaveBeenCalled()
		})
	})

	it('closes the move dialog and reloads folders after a move', () => {
		const vm = makeVm({ moveFolder: { id: 'f1' } })

		vm.onMoved()

		expect(vm.moveFolder).toBeNull()
		expect(vm.folderStore.fetchFolders).toHaveBeenCalledTimes(1)
	})
})
