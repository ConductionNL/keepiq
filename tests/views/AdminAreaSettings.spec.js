/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin bundle renders one area per mount (admin-scoped-roles D3):
 * each area mounts only where the server provided its initial-state flag,
 * renders only its own sections, and only General carries the shell.
 *
 * @spec openspec/changes/admin-scoped-roles/tasks.md#3.1
 */

import { shallowMount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Settings from '../../src/views/settings/Settings.vue'
import {
	ADMIN_AREAS,
	areaSettingsPath,
	mountAdminAreas,
	sectionsOf,
} from '../../src/views/settings/adminAreas.js'

describe('admin areas', () => {
	it('mounts only the areas the server provided, each on its own element', () => {
		const provided = { 'area-policies': true, 'area-audit': true }
		const mount = vi.fn()
		const mounted = mountAdminAreas({
			loadState: (app, key, fallback) => provided[key] ?? fallback,
			mount,
		})

		expect(mounted).toEqual(['policies', 'audit'])
		expect(mount).toHaveBeenCalledWith('policies', '#keepiq-settings-policies')
		expect(mount).toHaveBeenCalledWith('audit', '#keepiq-settings-audit')
		expect(mount).toHaveBeenCalledTimes(2)
	})

	it('mounts nothing when no area flag is present', () => {
		const mount = vi.fn()
		mountAdminAreas({ loadState: (app, key, fallback) => fallback, mount })

		expect(mount).not.toHaveBeenCalled()
	})

	it('gives every section to exactly one area', () => {
		const all = ADMIN_AREAS.flatMap((area) => area.sections)

		expect(new Set(all).size).toBe(all.length)
		expect(ADMIN_AREAS.map((area) => area.key)).toEqual([
			'general',
			'policies',
			'applications',
			'people',
			'audit',
		])
	})

	it('puts retention in Policies and the CA in General (decision of 2 Oct)', () => {
		expect(sectionsOf('policies')).toContain('RetentionPolicySection')
		expect(sectionsOf('general')).toContain('CaHealthSection')
		expect(sectionsOf('general')).not.toContain('RetentionPolicySection')
		expect(sectionsOf('nope')).toEqual([])
	})

	it('names one settings route per area', () => {
		expect(areaSettingsPath('audit')).toBe(
			'/apps/keepiq/api/settings/admin/audit',
		)
	})

	it.each(ADMIN_AREAS.map((area) => [area.key, area.sections]))(
		'renders only the %s sections',
		(key, sections) => {
			const wrapper = shallowMount(Settings, { props: { area: key } })
			const rendered = wrapper
				.findAll('.keepiq-settings > *')
				.map((node) => node.element.tagName.toLowerCase())

			expect(rendered).toHaveLength(sections.length)
			for (const other of ADMIN_AREAS.filter((a) => a.key !== key)) {
				for (const section of other.sections) {
					expect(wrapper.findComponent({ name: section }).exists()).toBe(
						false,
					)
				}
			}
			for (const section of sections) {
				expect(wrapper.findComponent({ name: section }).exists()).toBe(true)
			}
		},
	)
})
