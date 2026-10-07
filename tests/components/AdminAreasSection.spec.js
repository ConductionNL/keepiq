/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The "Admin areas" note lists the five areas, links to Nextcloud's
 * administration privileges page. The vault_admin notice is gone with its
 * alias (#1043).
 *
 * @spec openspec/changes/archive/2026-10-04-admin-scoped-roles/tasks.md#3.3
 */

import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

const state = {}
vi.mock('@nextcloud/initial-state', () => ({
	loadState: (app, key, fallback) => state[key] ?? fallback,
}))

const { default: AdminAreasSection } =
	await import('../../src/components/settings/AdminAreasSection.vue')

const stubs = {
	CnSettingsSection: {
		props: ['name', 'description'],
		template: '<section><slot /></section>',
	},
	NcNoteCard: { props: ['type'], template: '<div class="note"><slot /></div>' },
}

describe('AdminAreasSection', () => {
	afterEach(() => {
		delete state['vault-admin-members']
	})

	it('lists the five areas and links to the privileges page', () => {
		const wrapper = mount(AdminAreasSection, { global: { stubs } })

		for (const key of [
			'general',
			'policies',
			'applications',
			'people',
			'audit',
		]) {
			expect(wrapper.find(`[data-testid="admin-area-${key}"]`).exists()).toBe(
				true,
			)
		}
		expect(
			wrapper
				.find('[data-testid="admin-areas-privileges-link"]')
				.attributes('href'),
		).toBe('/settings/admin/admindelegation')
	})

	it('no longer warns about vault_admin, even when the group has members', () => {
		state['vault-admin-members'] = 3
		const wrapper = mount(AdminAreasSection, { global: { stubs } })

		expect(
			wrapper.find('[data-testid="admin-areas-legacy-warning"]').exists(),
		).toBe(false)
		expect(wrapper.text()).not.toContain('vault_admin')
	})
})
