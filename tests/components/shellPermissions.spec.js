/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin-only Integrations page is refused to a non-admin (#878).
 *
 * The library's page guard (CnPageRenderer `forbiddenPagePermission`) allows
 * every page when the shell's permission list is empty, and the shell used
 * to pass `OC.currentUser.permissions`, which Nextcloud never sets. These
 * tests feed App.vue's real `permissions` value into the library's real
 * guard, so they fail if either side drifts back to an empty list.
 *
 * @spec openspec/specs/admin-integrations/spec.md#requirement-req-keepiq-conn-004-an-admin-reads-the-connections-on-an-integrations-page
 */

import * as fs from 'fs'
import * as path from 'path'
import { afterEach, describe, expect, it, vi } from 'vitest'

const auth = vi.hoisted(() => ({ user: null }))
vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: () => auth.user,
	getRequestToken: () => 'token',
	onRequestTokenUpdate: () => {},
}))

const { default: App } = await import('../../src/App.vue')
const ROOT = path.resolve(__dirname, '../..')
const fragment = JSON.parse(
	fs.readFileSync(
		path.join(ROOT, 'src', 'manifest.d', '80-connection-registry.json'),
		'utf8',
	),
)
const integrationsPage = fragment.pages.find((p) => p.permission === 'admin')

// The library's page guard, taken from its installed source rather than
// copied, so a change in the library's rule shows up here. Importing the
// component itself drags in CSS and charting bundles jsdom cannot load.
const rendererSource = fs.readFileSync(
	path.join(
		ROOT,
		'node_modules',
		'@conduction',
		'nextcloud-vue',
		'src',
		'components',
		'CnPageRenderer',
		'CnPageRenderer.vue',
	),
	'utf8',
)
const guardBody = rendererSource.match(
	/forbiddenPagePermission\(\) \{\n([\s\S]*?)\n\t\t\},/,
)
const forbiddenPagePermission = guardBody ? new Function(guardBody[1]) : null

/**
 * Run the library's guard for the Integrations page as the given user.
 *
 * @param {object|null} user What getCurrentUser() returns.
 * @return {string|null} The refused permission, or null when the page opens.
 */
function guardFor(user) {
	auth.user = user
	const cnPermissions = App.computed.permissions.call({})
	return forbiddenPagePermission.call({
		currentPage: integrationsPage,
		cnPermissions,
	})
}

describe('App.vue page permissions (#878)', () => {
	afterEach(() => {
		auth.user = null
	})

	it('declares an admin-only page to guard, and finds the library guard', () => {
		expect(integrationsPage).toBeTruthy()
		expect(forbiddenPagePermission).toBeTypeOf('function')
	})

	it('the library guard serves every page on an empty list', () => {
		// The hole this fix closes, measured on the library itself.
		expect(
			forbiddenPagePermission.call({
				currentPage: integrationsPage,
				cnPermissions: [],
			}),
		).toBeNull()
	})

	it('refuses the Integrations page to a non-admin', () => {
		expect(guardFor({ uid: 'alice', isAdmin: false })).toBe('admin')
	})

	it('opens the Integrations page for an admin', () => {
		expect(guardFor({ uid: 'admin', isAdmin: true })).toBeNull()
	})

	it('no longer reads the permission list Nextcloud does not provide', () => {
		const source = fs.readFileSync(path.join(ROOT, 'src', 'App.vue'), 'utf8')
		expect(source).not.toContain('currentUser?.permissions')
	})
})
