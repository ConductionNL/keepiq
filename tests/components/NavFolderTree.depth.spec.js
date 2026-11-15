/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * NavFolderTree beyond its create action: the vault menu's other actions, the
 * remembered collapse state, the highlighted row, and the "…" stand-in for the
 * folders below the display cap.
 *
 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import NavFolderTree, { NAV_TREE_MAX_DEPTH } from '../../src/components/KeepiqAppNav/NavFolderTree.vue'

const NcAppNavigationItem = {
	name: 'NcAppNavigationItem',
	props: ['name', 'to', 'active', 'allowCollapse', 'open'],
	emits: ['update:open'],
	template: '<li :data-name="name" v-bind="$attrs"><slot name="icon" /><slot name="actions" /><slot /></li>',
}

const stubs = {
	NcAppNavigationItem,
	NcActionButton: { emits: ['click'], template: '<button v-bind="$attrs" @click="$emit(\'click\')"><slot /></button>' },
	NcActionSeparator: { template: '<hr />' },
}

/**
 * A chain of nested folders, `length` deep, under one vault.
 *
 * @param {number} length How many levels, the vault included.
 * @param {object[]} leaves The children of the deepest folder.
 * @return {object[]} The top level.
 */
function chain(length, leaves = []) {
	let node = { id: `f${length - 1}`, name: `Folder ${length - 1}`, children: leaves }
	for (let level = length - 2; level >= 0; level--) {
		node = { id: `f${level}`, name: `Folder ${level}`, children: [node] }
	}
	return [node]
}

/**
 * Mount the tree.
 *
 * @param {object} props The props.
 * @return {object} The wrapper.
 */
function mountTree(props) {
	return mount(NavFolderTree, { props, global: { stubs } })
}

/**
 * The navigation item rendered for a folder name.
 *
 * @param {object} wrapper The mounted tree.
 * @param {string} name The item name.
 * @return {object} The item component.
 */
function item(wrapper, name) {
	return wrapper.findAllComponents(NcAppNavigationItem).find((found) => found.props('name') === name)
}

describe('NavFolderTree depth and actions', () => {
	it.each(['edit', 'share', 'move', 'delete'])('emits %s with the vault the menu belongs to', async (action) => {
		const wrapper = mountTree({ folders: chain(2) })

		await wrapper.find(`[data-testid="nav-folder-${action}-f0"]`).trigger('click')

		expect(wrapper.emitted(action)).toHaveLength(1)
		expect(wrapper.emitted(action)[0][0].id).toBe('f0')
	})

	it('links every row to its folder and opens rows until the user collapses one', async () => {
		const wrapper = mountTree({ folders: chain(2) })
		const vault = item(wrapper, 'Folder 0')

		expect(vault.props('to')).toEqual({ name: 'SecretListFolder', params: { folderId: 'f0' } })
		expect(vault.props('open')).toBe(true)
		expect(vault.props('allowCollapse')).toBe(true)
		expect(item(wrapper, 'Folder 1').props('allowCollapse')).toBe(false)

		vault.vm.$emit('update:open', false)
		await wrapper.vm.$nextTick()

		expect(item(wrapper, 'Folder 0').props('open')).toBe(false)
		expect(item(wrapper, 'Folder 1').props('open')).toBe(true)
	})

	it('highlights only the row of the open folder, at any rendered depth', () => {
		const wrapper = mountTree({ folders: chain(3), highlightId: 'f2' })

		expect(item(wrapper, 'Folder 0').props('active')).toBe(false)
		expect(item(wrapper, 'Folder 1').props('active')).toBe(false)
		expect(item(wrapper, 'Folder 2').props('active')).toBe(true)
	})

	it('renders folders down to the cap and a "…" into the single hidden child below it', () => {
		const wrapper = mountTree({ folders: chain(NAV_TREE_MAX_DEPTH + 1) })
		const last = `f${NAV_TREE_MAX_DEPTH - 1}`

		expect(item(wrapper, `Folder ${NAV_TREE_MAX_DEPTH - 1}`)).toBeDefined()
		expect(item(wrapper, `Folder ${NAV_TREE_MAX_DEPTH}`)).toBeUndefined()

		const ellipsis = wrapper.find(`[data-testid="nav-folder-ellipsis-${last}"]`)
		expect(ellipsis.exists()).toBe(true)
		const stand = item(wrapper, '…')
		expect(stand.props('to')).toEqual({ name: 'SecretListFolder', params: { folderId: `f${NAV_TREE_MAX_DEPTH}` } })
		expect(stand.props('active')).toBe(false)
	})

	it('sends a "…" over several hidden children to the capped folder itself, and lights it when asked', () => {
		const hidden = [{ id: 'h1', name: 'H1', children: [] }, { id: 'h2', name: 'H2', children: [] }]
		const last = `f${NAV_TREE_MAX_DEPTH - 1}`
		const wrapper = mountTree({ folders: chain(NAV_TREE_MAX_DEPTH, hidden), ellipsisHighlightId: last })

		const stand = item(wrapper, '…')
		expect(stand.props('to')).toEqual({ name: 'SecretListFolder', params: { folderId: last } })
		expect(stand.props('active')).toBe(true)
		expect(item(wrapper, 'H1')).toBeUndefined()
		expect(item(wrapper, `Folder ${NAV_TREE_MAX_DEPTH - 1}`).props('active')).toBe(false)
	})

	it('renders nothing for an empty level and no "…" for a childless folder at the cap', () => {
		expect(mountTree({ folders: [] }).findAllComponents(NcAppNavigationItem)).toHaveLength(0)
		expect(mountTree({ folders: chain(NAV_TREE_MAX_DEPTH) }).find('[data-testid^="nav-folder-ellipsis-"]').exists()).toBe(false)
	})
})
