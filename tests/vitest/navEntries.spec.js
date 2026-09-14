/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * How Keepiq's own navigation rail reads a menu entry.
 *
 * KeepiqAppNav fills CnAppRoot's `#menu` slot, so CnAppNav's handling of
 * `query`, `permission` and `visibleIf` never runs. Each of the three fails
 * silently: no preset, an admin entry for everyone, an entry to a missing app.
 *
 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-keepiq-conn-004-an-admin-reads-the-connections-on-an-integrations-page
 */

import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it } from 'vitest'
import { isMenuEntryVisible, menuEntryTo } from '../../src/utils/navEntries.js'

const ROOT = path.resolve(__dirname, '../..')
const read = (...parts) => fs.readFileSync(path.join(ROOT, ...parts), 'utf8')
const fragment = JSON.parse(read('src', 'manifest.d', '80-connection-registry.json'))
const integrations = fragment.menu.find((m) => m.id === 'IntegrationsMenu')
const base = JSON.parse(read('src', 'manifest.json'))

const WITH_INTEGRIQ = {
	integriq: '/custom_apps/integriq',
	keepiq: '/custom_apps/keepiq',
}
const WITHOUT_INTEGRIQ = { keepiq: '/custom_apps/keepiq' }

describe('menuEntryTo', () => {
	it('carries the Integrations preset into the route', () => {
		expect(menuEntryTo(integrations)).toEqual({
			name: 'Integrations',
			query: { app: 'keepiq' },
		})
	})

	it('copies the query, so a router cannot write back into the manifest', () => {
		const target = menuEntryTo(integrations)
		target.query.app = 'someone-else'
		expect(integrations.query).toEqual({ app: 'keepiq' })
	})

	it('keeps every existing entry exactly as it rendered before', () => {
		for (const item of base.menu) {
			const before = item.route && !item.action ? { name: item.route } : null
			expect(menuEntryTo(item), item.id).toEqual(before)
		}
	})

	it('answers null for an action or an entry without a route', () => {
		expect(
			menuEntryTo({ id: 'x', action: 'user-settings', route: 'Lock' }),
		).toBeNull()
		expect(
			menuEntryTo({ id: 'y', href: 'https://keepiq.conduction.nl' }),
		).toBeNull()
		expect(menuEntryTo({ id: 'z', route: 'Lock', query: {} })).toEqual({
			name: 'Lock',
		})
	})
})

describe('isMenuEntryVisible', () => {
	it('shows the Integrations entry to an admin with integriq enabled', () => {
		expect(
			isMenuEntryVisible(integrations, {
				isAdmin: true,
				appsWebRoots: WITH_INTEGRIQ,
			}),
		).toBe(true)
	})

	it('hides it from a user who is not an instance admin', () => {
		expect(
			isMenuEntryVisible(integrations, {
				isAdmin: false,
				appsWebRoots: WITH_INTEGRIQ,
			}),
		).toBe(false)
		expect(
			isMenuEntryVisible(integrations, {
				isAdmin: undefined,
				appsWebRoots: WITH_INTEGRIQ,
			}),
		).toBe(false)
	})

	it('hides it when integriq is not enabled, or when nobody can say', () => {
		expect(
			isMenuEntryVisible(integrations, {
				isAdmin: true,
				appsWebRoots: WITHOUT_INTEGRIQ,
			}),
		).toBe(false)
		expect(
			isMenuEntryVisible(integrations, { isAdmin: true, appsWebRoots: null }),
		).toBe(false)
		// An inherited property is not an enabled app.
		expect(
			isMenuEntryVisible(integrations, {
				isAdmin: true,
				appsWebRoots: Object.create({ integriq: '/x' }),
			}),
		).toBe(false)
	})

	it('leaves every existing entry visible for every user', () => {
		for (const item of base.menu) {
			expect(
				isMenuEntryVisible(item, { isAdmin: false, appsWebRoots: null }),
				item.id,
			).toBe(true)
		}
	})

	it('is what KeepiqAppNav filters and routes with', () => {
		const nav = read('src', 'components', 'KeepiqAppNav', 'KeepiqAppNav.vue')
		expect(nav).toContain('.filter((item) => isMenuEntryVisible(item, context))')
		expect(nav).toMatch(/itemTo\(item\) \{\s+return menuEntryTo\(item\)\s+\}/)
		expect(nav).toMatch(
			/appsWebRoots:\s+\(typeof window !== 'undefined' && window\.OC\?\.appswebroots\)\s+\|\| null,/,
		)
	})
})
