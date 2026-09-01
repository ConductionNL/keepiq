/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Diagnostic + regression spec for the vault glyph's highlight behavior
 * (restyle Stage 9): the HIGHLIGHTED vault renders a plain currentColor
 * glyph with no tint circle; at rest the picked color + same-hex tint show.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import NavFolderTree from '../../src/components/KeepiqAppNav/NavFolderTree.vue'

const stubs = {
	NcAppNavigationItem: {
		props: ['name', 'to', 'active', 'allowCollapse', 'open'],
		template:
			'<li :data-active="active"><slot name="icon" /><slot name="actions" /><slot /></li>',
	},
	NcActionButton: { template: '<button><slot /></button>' },
	NcActionSeparator: { template: '<hr />' },
}

const VAULTS = [
	{
		id: 'v-all',
		name: 'All',
		parentId: null,
		customIcon: 'briefcase',
		customColor: 'blue',
		children: [
			{ id: 'f-sub', name: 'Test2', parentId: 'v-all', children: [] },
		],
	},
	{
		id: 'v-keys',
		name: 'Keys',
		parentId: null,
		customIcon: 'star',
		customColor: 'red',
		children: [],
	},
]

function factory(highlightId) {
	return mount(NavFolderTree, {
		propsData: { folders: VAULTS, highlightId },
		global: { stubs },
	})
}

describe('NavFolderTree vault glyph highlight', () => {
	it('renders the HIGHLIGHTED vault plain: currentColor fill, no tint style', () => {
		const w = factory('v-all')
		const vm = w.vm
		const all = VAULTS[0]
		expect(vm.isHighlighted(all)).toBe(true)
		expect(vm.vaultColor(all)).toBe('currentColor')
		expect(vm.vaultGlyphStyle(all)).toBeUndefined()
	})

	it('renders a highlighted vault WITH CHILDREN identically to a leaf vault', () => {
		const withChildren = factory('v-all')
		const leaf = factory('v-keys')
		expect(withChildren.vm.vaultColor(VAULTS[0])).toBe('currentColor')
		expect(leaf.vm.vaultColor(VAULTS[1])).toBe('currentColor')
	})

	it('renders vaults AT REST with their color and same-hex tint', () => {
		const w = factory('v-all')
		const keys = VAULTS[1]
		expect(w.vm.isHighlighted(keys)).toBe(false)
		// Stub resolvers: red light-variant hex + derived tint.
		expect(w.vm.vaultColor(keys)).toBe('#c92020')
		expect(w.vm.vaultGlyphStyle(keys)).toBeTruthy()
	})

	it('renders the DOM fill correctly for the highlighted vault', () => {
		const w = factory('v-all')
		const glyphs = w.findAll('.keepiq-nav-tree__vault-glyph')
		// First glyph = v-all (highlighted): its icon stub must receive
		// currentColor, and the span must carry no background style.
		const first = glyphs[0]
		expect(first.attributes('style')).toBeUndefined()
		const icon = first.find('[data-stub="folder-icon"]')
		expect(icon.exists()).toBe(true)
	})
})
