/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * One manifest entry of the left rail, mounted on its own: the route,
 * href and active state it renders, and that a "user-settings" entry
 * mounted WITHOUT a host (no cnOpenUserSettings provided) still prevents
 * the link and falls back to the injected no-op instead of throwing.
 *
 * @spec openspec/specs/menu-architecture/spec.md#app-navigation-renders
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import NavMenuEntry from '../../src/components/KeepiqAppNav/NavMenuEntry.vue'

const item = {
	props: ['name', 'to', 'href', 'active'],
	emits: ['click'],
	template: '<li :data-name="name" @click="$emit(\'click\', $event)"><slot name="icon" /></li>',
}

const stubs = {
	NcAppNavigationItem: item,
	CnIcon: { props: ['name', 'size'], template: '<i :data-icon="name" />' },
}

/**
 * Mount one entry on the given route.
 *
 * @param {object} entry The manifest entry.
 * @param {object} [provide] Injections.
 * @return {object} The wrapper.
 */
function mountEntry(entry, provide = {}) {
	return mount(NavMenuEntry, {
		props: { item: entry },
		global: {
			stubs,
			provide,
			mocks: { $route: { name: 'health', params: {}, query: {} } },
		},
	})
}

describe('NavMenuEntry standalone', () => {
	it('prevents the link of a user-settings entry and calls the default opener without a host', () => {
		const wrapper = mountEntry({
			id: 'Personal',
			label: 'Personal settings',
			action: 'user-settings',
			href: '#settings',
		})
		const entry = wrapper.findComponent(item)
		const event = { preventDefault: vi.fn() }

		expect(entry.props('href')).toBeNull()
		expect(() => entry.vm.$emit('click', event)).not.toThrow()
		expect(event.preventDefault).toHaveBeenCalledTimes(1)
	})

	it('opens the provided host settings even when the click carries no event', () => {
		const open = vi.fn()
		const wrapper = mountEntry(
			{ id: 'Personal', label: 'Personal settings', action: 'user-settings' },
			{ cnOpenUserSettings: open },
		)

		wrapper.findComponent(item).vm.$emit('click', undefined)

		expect(open).toHaveBeenCalledTimes(1)
	})

	it('renders a route entry active on its own route, with its icon', () => {
		const wrapper = mountEntry({ id: 'Health', label: 'Password health', route: 'health', icon: 'Shield' })
		const entry = wrapper.findComponent(item)

		expect(entry.props('active')).toBe(true)
		expect(entry.props('href')).toBeNull()
		expect(entry.attributes('data-testid')).toBe('cn-nav-entry-Health')
		expect(wrapper.find('[data-icon="Shield"]').exists()).toBe(true)
	})

	it('renders an external entry as a plain href, inactive and without icon', () => {
		const wrapper = mountEntry({ id: 'Docs', label: 'Documentation', href: 'https://docs.example.org' })
		const entry = wrapper.findComponent(item)

		expect(entry.props('href')).toBe('https://docs.example.org')
		expect(entry.props('active')).toBe(false)
		expect(wrapper.find('i').exists()).toBe(false)
	})
})
