// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The Integrations page over integriq's connection registry
 * (adopt-connection-registry, hydra connection-registry D8 and D9).
 *
 * The page is declared in JSON and resolves two formatters, one handler and
 * one icon by NAME. A misspelled name renders a raw enum, no glyph, or an Add
 * integration that does nothing, and none of them logs a thing. So this spec
 * reads the real fragment and checks every name against what has to answer it.
 * The two formatters are @conduction/nextcloud-vue built-ins since 3.2.0, so
 * their names are checked against the installed library, not a local copy.
 *
 * The library's map is IMPORTED and called, not read as text. A regex over the
 * module source answers about the file on disk, which is one step beside the
 * question: whether the formatter the page resolves actually returns the label.
 * The import reaches @nextcloud/auth through formatMetric, which wants a
 * `window`, so this file runs on jsdom rather than the suite's default node.
 *
 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-keepiq-conn-004-an-admin-reads-the-connections-on-an-integrations-page
 */

import { BUILT_IN_FORMATTERS } from '@conduction/nextcloud-vue/src/utils/builtInFormatters.js'
import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it } from 'vitest'
import {
	createConnectionHandlers,
	INTEGRIQ_CONNECTIONS_PATH,
} from '../../src/services/connectionRegistry.js'

const ROOT = path.resolve(__dirname, '../..')
const read = (...parts) => fs.readFileSync(path.join(ROOT, ...parts), 'utf8')
const fragment = JSON.parse(read('src', 'manifest.d', '80-connection-registry.json'))
const page = fragment.pages.find((p) => p.id === 'Integrations')
const menu = fragment.menu.find((m) => m.id === 'IntegrationsMenu')

/**
 * The formatter registry CnAppRoot provides, built the way CnAppRoot builds it:
 * the library's built-ins under whatever the app passes in its `formatters`
 * prop. A same-named local formatter wins, which is why keepiq passes none.
 *
 * @param {object} appFormatters What the app hands CnAppRoot. Empty by default.
 * @return {object} The merged registry, keyed by formatter name.
 */
function shellFormatterRegistry(appFormatters = {}) {
	return { ...BUILT_IN_FORMATTERS, ...appFormatters }
}

describe('connection strings', () => {
	it('ships an English and a Dutch catalogue entry for every label the page declares', () => {
		const en = JSON.parse(read('l10n', 'en.json')).translations
		const nl = JSON.parse(read('l10n', 'nl.json')).translations
		const labels = [
			page.title,
			menu.label,
			page.config.folderSidebar.allLabel,
			...page.config.headerActions.map((a) => a.label),
			...page.config.columns.map((c) => c.label),
		]
		for (const label of labels) {
			expect(en[label], `en: ${label}`).toBe(label)
			expect(nl[label], `nl: ${label}`).toBeTruthy()
		}
	})
})

describe('Add integration handler', () => {
	it('opens integriq on the link dialog, preset to keepiq', () => {
		const opened = []
		const handlers = createConnectionHandlers({
			generateUrl: (p) => `/index.php${p}`,
			assign: (url) => opened.push(url),
		})

		handlers.openIntegriqConnections()

		expect(INTEGRIQ_CONNECTIONS_PATH).toBe(
			'/apps/integriq/connections?app=keepiq&link=1',
		)
		expect(opened).toEqual([
			'/index.php/apps/integriq/connections?app=keepiq&link=1',
		])
	})
})

describe('the Integrations page declaration', () => {
	it('lists integriq app_connection rows, admin only, and requires integriq', () => {
		expect(page.type).toBe('index')
		expect(page.route).toBe('/settings/integrations')
		expect(page.permission).toBe('admin')
		expect(page.requiresApp).toEqual({ id: 'integriq', name: 'Integriq' })
		expect(page.config.register).toBe('integriq')
		expect(page.config.schema).toBe('app_connection')
		expect(page.config.defaultSort).toEqual({ field: 'order', direction: 'asc' })
	})

	// A row nothing declared has nothing to check (connection-registry D9).
	it('offers no generic Add button', () => {
		expect(page.config.showAdd).toBe(false)
	})

	// THE PRESET. integriq's schema holds every app's rows. Without the query
	// the page lists them all as though they were this app's.
	it('scopes the rows to keepiq through the menu preset, in the gear', () => {
		expect(menu.route).toBe(page.id)
		expect(menu.query).toEqual({ app: 'keepiq' })
		expect(menu.section).toBe('settings')
		expect(menu.permission).toBe('admin')
		expect(menu.visibleIf).toEqual({ appInstalled: 'integriq' })
	})

	it('names only formatters the library ships and handlers that exist, and wires the handler into the shell', () => {
		const registry = shellFormatterRegistry()
		const handlers = createConnectionHandlers({
			generateUrl: (p) => p,
			assign: () => {},
		})

		expect(typeof registry.date, 'the built-in formatter map was read').toBe('function')
		const named = page.config.columns.filter((c) => c.formatter).map((c) => c.formatter)
		expect(named.sort()).toEqual(['connectionSettingsLabel', 'connectionStatus'])
		for (const formatter of named) {
			expect(typeof registry[formatter], `@conduction/nextcloud-vue ships ${formatter}`).toBe('function')
		}
		for (const action of page.config.headerActions) {
			expect(typeof handlers[action.handler], action.handler).toBe('function')
		}

		const app = read('src', 'App.vue')
		expect(app).toContain(':customComponents="shellCustomComponents"')
		expect(app).toMatch(
			/shellCustomComponents\(\) \{\s+return \{\s+\.\.\.this\.customComponents,\s+\.\.\.createConnectionHandlers\(\{/,
		)
	})

	// The library labels all seven statuses. A copy of the formatter passed to
	// CnAppRoot would win over the built-in and could predate `disabled`, which
	// is the status the hibp switch introduces.
	it('lets the library label the statuses, disabled included', () => {
		const registry = shellFormatterRegistry()

		expect(registry.connectionStatus('disabled')).toBe('Switched off')
		expect(registry.connectionStatus('unconfigured')).toBe('Not configured')
		expect(registry.connectionStatus('configured')).toBe('Configured')
	})

	// THE SHADOW. CnAppRoot merges `{ ...BUILT_IN_FORMATTERS, ...formatters }`,
	// so a local formatter under either name silently replaces the built-in and
	// nothing logs. keepiq passes no formatters at all, and this states what
	// that buys: the built-in is what the Status column resolves.
	it('passes CnAppRoot no formatters, so nothing shadows the built-ins', () => {
		const shadow = shellFormatterRegistry({
			connectionStatus: () => 'a local copy answered',
		})

		expect(shadow.connectionStatus('disabled')).toBe('a local copy answered')
		expect(read('src', 'App.vue')).not.toContain(':formatters=')
	})

	it('names an icon src/icons.js registers', () => {
		const icons = read('src', 'icons.js')
		for (const icon of [
			menu.icon,
			...page.config.headerActions.map((a) => a.icon),
		]) {
			expect(icons).toContain(`\n\t${icon},`)
		}
	})

	it('keeps its id and route apart from every page the base manifest declares', () => {
		const base = JSON.parse(read('src', 'manifest.json'))
		expect(base.pages.map((p) => p.id)).not.toContain(page.id)
		expect(base.pages.map((p) => p.route)).not.toContain(page.route)
		expect(base.menu.map((m) => m.id)).not.toContain(menu.id)
	})
})
