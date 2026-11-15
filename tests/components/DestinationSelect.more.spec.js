/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/components/DestinationSelect.vue`, covering the
 * rendered option rows that DestinationSelect.spec.js leaves to NcSelect.
 *
 * The NcSelect stub here renders the component's `#option` slot once per
 * option and the `#selected-option` slot for the option matching the model
 * value, and re-emits `update:modelValue`. Covered: each option row carries
 * its label, the depth indent, the vault / folder fallback glyph or the
 * custom icon, the resolved fill colour (or `currentColor`) and the tinted
 * tint for a custom colour; the selected row is never indented; picking an
 * option is re-emitted as `update:modelValue` with the option's id; and the
 * `reduce` handed to the select maps an option to its id.
 *
 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import DestinationSelect from '../../src/components/DestinationSelect.vue'
import { useFolderStore } from '../../src/store/modules/folder.js'

const FOLDERS = [
	{ id: 'v1', name: 'Personal', parentId: null, customIcon: 'briefcase', customColor: 'blue' },
	{ id: 'f1', name: 'Servers', parentId: 'v1' },
	{ id: 'v2', name: 'Work', parentId: null, customIcon: null, customColor: null },
]

const NcSelect = {
	name: 'NcSelect',
	props: ['modelValue', 'options', 'reduce', 'inputLabel', 'disabled', 'clearable'],
	emits: ['update:modelValue'],
	template: `
		<div class="stub-select">
			<div class="stub-selected">
				<template v-for="opt in options" :key="'s-' + opt.value">
					<slot v-if="opt.value === modelValue" name="selected-option" v-bind="opt" />
				</template>
			</div>
			<ul>
				<li
					v-for="opt in options"
					:key="opt.value"
					:data-value="opt.value"
					@click="$emit('update:modelValue', reduce(opt))">
					<slot name="option" v-bind="opt" />
				</li>
			</ul>
		</div>`,
}

/**
 * Mount the picker with the slot-rendering select stub.
 *
 * @param {object} props Props to pass.
 * @return {object} The wrapper.
 */
function mountPicker(props = {}) {
	return mount(DestinationSelect, {
		props: { label: 'Destination', ...props },
		global: { stubs: { NcSelect } },
	})
}

describe('DestinationSelect option rows', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		const store = useFolderStore()
		store.folders = [...FOLDERS]
		store.fetchFolders = vi.fn().mockResolvedValue()
	})

	it('renders each option with label, indent and glyph styling', () => {
		const wrapper = mountPicker()
		const rows = wrapper.findAll('li')
		expect(rows.map((r) => r.attributes('data-value'))).toEqual(['v1', 'f1', 'v2'])

		const personal = rows[0].find('.destination-select__option')
		expect(personal.text()).toBe('Personal')
		expect(personal.attributes('style')).toContain('padding-inline-start: 0px')
		// Custom colour: the resolved light-theme hex fill. (The stub tint is
		// not valid CSS, so jsdom drops it; glyphStyle is asserted directly below.)
		const personalIcon = rows[0].findComponent({ name: 'StubFolderIcon' })
		expect(personalIcon.exists()).toBe(true)
		expect(personalIcon.props('fillColor')).toBe('#0064a3')
		expect(personalIcon.props('size')).toBe(16)

		const servers = rows[1].find('.destination-select__option')
		expect(servers.text()).toBe('Servers')
		expect(servers.attributes('style')).toContain('padding-inline-start: 16px')
		// No custom colour: neutral circle and a currentColor fill.
		expect(servers.find('.destination-select__glyph').attributes('style')).toBeUndefined()
		expect(rows[1].findComponent({ name: 'FolderOutlineIcon' }).props('fillColor')).toBe(
			'currentColor',
		)

		// A vault without a custom icon falls back to the safe glyph.
		expect(rows[2].findComponent({ name: 'SafeIcon' }).exists()).toBe(true)
	})

	it('renders the selected option without indent', () => {
		const wrapper = mountPicker({ modelValue: 'f1' })
		const selected = wrapper.find('.stub-selected .destination-select__option')

		expect(selected.text()).toBe('Servers')
		expect(selected.attributes('style')).toBeUndefined()
		expect(wrapper.findAll('.stub-selected .destination-select__option')).toHaveLength(1)
	})

	it('renders the selected custom icon in its resolved colour', () => {
		const wrapper = mountPicker({ modelValue: 'v1' })
		const icon = wrapper.find('.stub-selected').findComponent({ name: 'StubFolderIcon' })

		expect(wrapper.find('.stub-selected').text()).toBe('Personal')
		expect(icon.props('fillColor')).toBe('#0064a3')
	})

	it('re-emits a pick as update:modelValue with the option id', async () => {
		const wrapper = mountPicker({ modelValue: 'v1' })

		await wrapper.find('li[data-value="v2"]').trigger('click')

		expect(wrapper.emitted('update:modelValue')).toEqual([['v2']])
	})

	it('hands the select a reduce that maps an option to its id', () => {
		const reduce = mountPicker().findComponent({ name: 'NcSelect' }).props('reduce')

		expect(reduce({ value: 'f1', label: 'Servers' })).toBe('f1')
	})

	it('glyphStyle returns the tint for a custom colour and undefined otherwise', () => {
		const wrapper = mountPicker()

		expect(wrapper.vm.glyphStyle({ customColor: 'red' })).toEqual({
			backgroundColor: 'rgba(0, 0, 0, 0.15) /* #c92020 */',
		})
		expect(wrapper.vm.glyphStyle({ customColor: null })).toBeUndefined()
	})
})
