/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden for the manifest entries of the left rail: the main, footer and
 * settings sections render each entry with the same target, href, active
 * state, test ids and icon, and a "user-settings" entry opens the host's
 * settings dialog while every other entry navigates natively.
 *
 * @spec openspec/specs/menu-architecture/spec.md#app-navigation-renders
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import KeepiqAppNav from '../../src/components/KeepiqAppNav/KeepiqAppNav.vue'
import { useFolderStore } from '../../src/store/modules/folder.js'
import { useTeamFolderStore } from '../../src/store/modules/teamFolder.js'

vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: () => ({ uid: 'ada', isAdmin: false }),
}))

const item = {
	props: ['name', 'to', 'href', 'active'],
	emits: ['click'],
	template:
		'<li :data-name="name" :data-to="to ? JSON.stringify(to) : undefined" :data-href="href || undefined" :data-active="String(active)" @click="$emit(\'click\', $event)"><slot name="icon" /><slot name="counter" /></li>',
}

const stubs = {
	NcAppNavigation: {
		template:
			'<nav><ul class="list"><slot name="list" /></ul><div class="footer"><slot name="footer" /></div></nav>',
	},
	NcAppNavigationCaption: {
		props: ['name'],
		template: '<li class="caption" :data-name="name" />',
	},
	NcAppNavigationItem: item,
	NcAppNavigationSettings: {
		props: ['name'],
		template: '<div class="settings"><slot /></div>',
	},
	CnIcon: { props: ['name', 'size'], template: '<i :data-icon="name" />' },
	Plus: { template: '<i data-icon="Plus" />' },
	NavFolderTree: { template: '<div class="tree" />' },
	FolderCreateDialog: true,
	FolderDeleteConfirmDialog: true,
	FolderEditDialog: true,
	MoveDialog: true,
	SubfolderResolutionDialog: true,
	TeamFolderDialog: true,
}

const MANIFEST = {
	menu: [
		{ id: 'Secrets', label: 'All secrets', route: 'secrets', icon: 'Key', order: 1 },
		{ id: 'Tools', type: 'caption', label: 'Tools', order: 2 },
		{ id: 'Health', label: 'Password health', route: 'health', order: 3 },
		{
			id: 'Docs',
			label: 'Documentation',
			href: 'https://docs.example.org',
			icon: 'BookOpen',
			section: 'footer',
			order: 4,
		},
		{
			id: 'Personal',
			label: 'Personal settings',
			action: 'user-settings',
			href: '#settings',
			icon: 'Cog',
			section: 'settings',
			order: 5,
		},
		{
			id: 'Integrations',
			label: 'Integrations',
			route: 'integrations',
			query: { app: 'keepiq' },
			section: 'settings',
			order: 6,
		},
	],
}

/**
 * @param {Function} openSettings The host's settings opener.
 * @return {object} The mounted rail on the Health route.
 */
function mountNav(openSettings) {
	return mount(KeepiqAppNav, {
		props: { manifest: MANIFEST },
		global: {
			stubs,
			mocks: { $route: { name: 'health', params: {}, query: {} } },
			provide: { cnOpenUserSettings: openSettings },
		},
	})
}

beforeEach(() => {
	setActivePinia(createPinia())
	const folderStore = useFolderStore()
	folderStore.fetchFolders = vi.fn().mockResolvedValue()
	useTeamFolderStore().fetchTeamFolders = vi.fn().mockResolvedValue()
})

describe('KeepiqAppNav manifest entries (golden)', () => {
	it('renders the main, footer and settings entries', () => {
		const wrapper = mountNav(vi.fn())
		const html = wrapper
			.html()
			.replace(/ data-v-[0-9a-f]+=""/g, '')
			.replace(/<!--[\s\S]*?-->/g, '')
			.replace(/>\s+</g, '><')
		expect(html).toMatchSnapshot()
	})

	it('opens the host settings for a user-settings entry and prevents the link', async () => {
		const openSettings = vi.fn()
		const wrapper = mountNav(openSettings)
		const event = { preventDefault: vi.fn() }
		wrapper.findAllComponents(item).forEach((entry) => {
			if (entry.attributes('data-testid') === 'cn-nav-entry-Personal') {
				entry.vm.$emit('click', event)
			}
		})
		expect(openSettings).toHaveBeenCalledTimes(1)
		expect(event.preventDefault).toHaveBeenCalledTimes(1)
	})

	it('leaves every other entry to native navigation', () => {
		const openSettings = vi.fn()
		const wrapper = mountNav(openSettings)
		const event = { preventDefault: vi.fn() }
		wrapper.findAllComponents(item).forEach((entry) => {
			const id = entry.attributes('data-testid') || ''
			if (id.startsWith('cn-nav-entry-') && id !== 'cn-nav-entry-Personal') {
				entry.vm.$emit('click', event)
			}
		})
		expect(openSettings).not.toHaveBeenCalled()
		expect(event.preventDefault).not.toHaveBeenCalled()
	})
})
