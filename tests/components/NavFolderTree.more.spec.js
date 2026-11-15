/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The rest of the vault "⋮" menu in the left rail: every action button
 * renders its icon and emits its event with the vault node it was opened
 * on (edit, share, move, delete), the menu trigger is the vertical-dots
 * glyph, nested rows have neither, and a tree mounted without folders
 * renders an empty list. A row's collapse toggle is remembered per node.
 *
 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import NavFolderTree from '../../src/components/KeepiqAppNav/NavFolderTree.vue'

const stubs = {
	NcAppNavigationItem: {
		props: ['name', 'to', 'active', 'allowCollapse', 'open'],
		emits: ['update:open'],
		template:
			'<li :data-name="name" :data-open="String(open)"><span class="menu-icon"><slot name="menu-icon" /></span><slot name="icon" /><slot name="actions" /><slot /></li>',
	},
	NcActionButton: {
		emits: ['click'],
		template:
			'<button v-bind="$attrs" @click="$emit(\'click\')"><slot name="icon" /><slot /></button>',
	},
	NcActionSeparator: { template: '<hr />' },
}

const VAULT = {
	id: 'v-1',
	name: 'Suppliers',
	parentId: null,
	children: [{ id: 'f-1', name: 'Invoices', parentId: 'v-1', children: [] }],
}

/**
 * Mount the tree with one vault holding one folder.
 *
 * @return {object} The wrapper.
 */
function mountTree() {
	return mount(NavFolderTree, {
		props: { folders: [VAULT] },
		global: { stubs },
	})
}

describe('NavFolderTree vault menu', () => {
	it.each([
		['edit', 'nav-folder-edit-v-1', 'Edit vault'],
		['share', 'nav-folder-share-v-1', 'Share vault'],
		['move', 'nav-folder-move-v-1', 'Move vault contents'],
		['delete', 'nav-folder-delete-v-1', 'Delete vault'],
		['newFolder', 'nav-folder-new-v-1', 'New folder'],
	])('emits %s with the vault node from its labelled button', async (event, testId, label) => {
		const wrapper = mountTree()
		const button = wrapper.find(`[data-testid="${testId}"]`)

		expect(button.text()).toBe(label)
		expect(button.find('svg').exists()).toBe(true)

		await button.trigger('click')

		expect(wrapper.emitted(event)).toHaveLength(1)
		expect(wrapper.emitted(event)[0][0]).toEqual(VAULT)
		const others = ['edit', 'share', 'move', 'delete', 'newFolder'].filter((e) => e !== event)
		for (const other of others) {
			expect(wrapper.emitted(other)).toBeUndefined()
		}
	})

	it('uses the vertical dots as the vault menu trigger, and none on nested rows', () => {
		const wrapper = mountTree()
		const rows = wrapper.findAll('li')

		expect(rows[0].attributes('data-name')).toBe('Suppliers')
		expect(rows[0].find('.menu-icon svg').exists()).toBe(true)
		expect(rows[1].attributes('data-name')).toBe('Invoices')
		expect(rows[1].find('.menu-icon svg').exists()).toBe(false)
		expect(rows[1].find('[data-testid="nav-folder-edit-f-1"]').exists()).toBe(false)
	})

	it('remembers a collapse toggle for the node', async () => {
		const wrapper = mountTree()
		const vaultRow = wrapper.findComponent(stubs.NcAppNavigationItem)
		expect(vaultRow.props('open')).toBe(true)

		await vaultRow.vm.$emit('update:open', false)

		expect(wrapper.vm.openState).toEqual({ 'v-1': false })
		expect(vaultRow.props('open')).toBe(false)
	})

	it('renders an empty root list when mounted without folders', () => {
		const wrapper = mount(NavFolderTree, { global: { stubs } })

		expect(wrapper.find('[data-testid="nav-folder-tree"]').exists()).toBe(true)
		expect(wrapper.findAll('li')).toHaveLength(0)
		expect(wrapper.vm.folders).toEqual([])
	})
})
