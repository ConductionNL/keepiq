/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Bootstrap test for `src/settings.js`, the admin-settings entry point.
 *
 * What it pins is the warning in that file's header: the panel must mount
 * whether or not translations load. A translation loader that throws, or
 * whose promise rejects, must not stop the per-area mounts, and each area
 * the initial state grants gets exactly one app mounted on its own selector.
 *
 * @spec openspec/changes/archive/2026-10-04-admin-scoped-roles/tasks.md#3.1
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const loadTranslations = vi.fn()
const loadState = vi.fn()
const mountAdminAreas = vi.fn()
const apps = []

vi.mock('@nextcloud/l10n', () => ({
	loadTranslations: (...args) => loadTranslations(...args),
	translate: (_app, key) => key,
	translatePlural: (_app, one, many, count) => (count === 1 ? one : many),
}))

vi.mock('@nextcloud/initial-state', () => ({
	loadState: (...args) => loadState(...args),
}))

vi.mock('../../src/views/settings/adminAreas.js', () => ({
	mountAdminAreas: (...args) => mountAdminAreas(...args),
}))

vi.mock('../../src/views/settings/AdminRoot.vue', () => ({
	default: { name: 'AdminRoot', props: ['area'], render: () => null },
}))

vi.mock('vue', async (importOriginal) => {
	const actual = await importOriginal()
	return {
		...actual,
		createApp: (root) => {
			const app = {
				root,
				mixin: vi.fn(),
				use: vi.fn(),
				mount: vi.fn(),
			}
			apps.push(app)
			return app
		},
	}
})

/**
 * Import the entry point afresh so its top-level code runs again.
 *
 * @return {Promise<void>}
 */
async function boot() {
	vi.resetModules()
	await import('../../src/settings.js')
}

describe('settings.js bootstrap', () => {
	beforeEach(() => {
		apps.length = 0
		loadTranslations.mockReset()
		mountAdminAreas.mockReset()
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('loads the keepiq translations and hands the initial state to the area mounter', async () => {
		await boot()

		expect(loadTranslations).toHaveBeenCalledWith('keepiq', expect.any(Function))
		expect(mountAdminAreas).toHaveBeenCalledTimes(1)
		expect(mountAdminAreas.mock.calls[0][0].loadState).toBeTypeOf('function')
		mountAdminAreas.mock.calls[0][0].loadState('keepiq', 'area-general', false)
		expect(loadState).toHaveBeenCalledWith('keepiq', 'area-general', false)
	})

	it('still mounts when loading translations throws', async () => {
		loadTranslations.mockImplementation(() => {
			throw new Error('404')
		})

		await boot()

		expect(mountAdminAreas).toHaveBeenCalledTimes(1)
	})

	it('still mounts when the translation promise rejects', async () => {
		loadTranslations.mockReturnValue(Promise.reject(new Error('404')))

		await boot()
		await new Promise((resolve) => setTimeout(resolve, 0))

		expect(mountAdminAreas).toHaveBeenCalledTimes(1)
	})

	it('mounts one app per area, with t/n, the shared pinia and the area prop', async () => {
		await boot()
		const pinia = (await import('../../src/pinia.js')).default
		const { mount } = mountAdminAreas.mock.calls[0][0]

		mount('policies', '#keepiq-settings-policies')

		expect(apps).toHaveLength(1)
		const [app] = apps
		expect(app.mount).toHaveBeenCalledWith('#keepiq-settings-policies')
		expect(app.use).toHaveBeenCalledWith(pinia)
		const mixin = app.mixin.mock.calls[0][0]
		expect(mixin.methods.t('keepiq', 'Save')).toBe('Save')
		expect(mixin.methods.n('keepiq', 'one', 'many', 2)).toBe('many')
		const vnode = app.root.render()
		expect(vnode.type.name).toBe('AdminRoot')
		expect(vnode.props).toEqual({ area: 'policies' })
	})
})
