/**
 * SPDX-FileCopyrightText: 2026 Conduction / Doriath Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/components/FolderTree.vue`.
 *
 * FolderTree is the recursive sidebar tree that drives the folder filter
 * on SecretList. It MUST:
 *  - render one `<button>` per folder, with the folder name visible
 *  - recurse: a folder with `children` renders a nested FolderTree
 *  - emit `select` with the clicked folder's id on click
 *  - bubble nested `select` emissions up through every parent (so the
 *    top-level listener on SecretList catches selections from leaves
 *    arbitrarily deep)
 *  - mark the active folder with the `--active` class when its id ===
 *    selectedId
 *
 * @spec openspec/changes/implement-secrets/tasks.md#7.4
 * @spec openspec/changes/implement-secrets/tasks.md#13.5
 */

import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import FolderTree from '../../src/components/FolderTree.vue'

const FLAT_TREE = [
	{ id: 'f-work', name: 'Work' },
	{ id: 'f-home', name: 'Personal' },
]

const NESTED_TREE = [
	{
		id: 'f-work',
		name: 'Work',
		children: [
			{ id: 'f-work-prod', name: 'Production' },
			{ id: 'f-work-dev', name: 'Dev' },
		],
	},
	{ id: 'f-home', name: 'Personal' },
]

describe('FolderTree', () => {
	it('renders one button per folder at the top level', () => {
		const wrapper = mount(FolderTree, {
			propsData: { folders: FLAT_TREE },
		})

		const buttons = wrapper.findAll('button.folder-tree__item')
		expect(buttons).toHaveLength(2)
		expect(buttons.at(0).text()).toContain('Work')
		expect(buttons.at(1).text()).toContain('Personal')
	})

	it('emits `select` with the folder id on click', async () => {
		const wrapper = mount(FolderTree, {
			propsData: { folders: FLAT_TREE },
		})

		await wrapper.findAll('button.folder-tree__item').at(0).trigger('click')

		expect(wrapper.emitted('select')).toBeTruthy()
		expect(wrapper.emitted('select')[0]).toEqual(['f-work'])
	})

	it('recurses into nested children and renders them', () => {
		const wrapper = mount(FolderTree, {
			propsData: { folders: NESTED_TREE },
		})

		// 2 roots + 2 nested under Work = 4 total buttons.
		const buttons = wrapper.findAll('button.folder-tree__item')
		expect(buttons).toHaveLength(4)

		const names = buttons.wrappers.map(b => b.text().trim())
		expect(names).toEqual(expect.arrayContaining(['Work', 'Personal', 'Production', 'Dev']))
	})

	it('bubbles a nested `select` emission up through every parent', async () => {
		const wrapper = mount(FolderTree, {
			propsData: { folders: NESTED_TREE },
		})

		// Click the deeply-nested "Production" button (one of the children).
		const buttons = wrapper.findAll('button.folder-tree__item')
		const prod = buttons.wrappers.find(b => b.text().includes('Production'))
		await prod.trigger('click')

		// The TOP-LEVEL FolderTree must re-emit the leaf's select event so
		// the parent view (SecretList) only needs a single listener.
		expect(wrapper.emitted('select')).toBeTruthy()
		expect(wrapper.emitted('select')[0]).toEqual(['f-work-prod'])
	})

	it('marks the selected folder with the --active class', () => {
		const wrapper = mount(FolderTree, {
			propsData: { folders: FLAT_TREE, selectedId: 'f-home' },
		})

		const buttons = wrapper.findAll('button.folder-tree__item')
		// Work is NOT active.
		expect(buttons.at(0).classes()).not.toContain('folder-tree__item--active')
		// Personal IS active.
		expect(buttons.at(1).classes()).toContain('folder-tree__item--active')
	})

	it('renders an empty tree without errors when `folders` is []', () => {
		const wrapper = mount(FolderTree, {
			propsData: { folders: [] },
		})

		expect(wrapper.findAll('button.folder-tree__item')).toHaveLength(0)
		expect(wrapper.find('ul.folder-tree').exists()).toBe(true)
	})
})
