/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Bootstrap test for `src/main.js`, the user-facing entry point.
 *
 * The entry runs everything at import time, so each case re-imports it with
 * every collaborator mocked and asserts the wiring it performs: the routes it
 * derives from the merged manifest, the catch-all, the vault guard and the
 * dashboard cache drop on the router, the props handed to the shell, and the
 * order of plugin installation, skip-link target and mount.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const calls = []
const lib = {
	buildManifest: vi.fn(),
	invalidateEndpointSourceCache: vi.fn(),
	registerBuiltinDashboardWidgets: vi.fn(),
	registerIcons: vi.fn(),
	registerTranslations: vi.fn(),
}
const loadTranslations = vi.fn()
const generateUrl = vi.fn((path) => '/index.php' + path)
const createWebHistory = vi.fn((base) => ({ base }))
const routers = []
const apps = []
const matchRouterBase = vi.fn()
const applyHashRouteHandoff = vi.fn(() => calls.push('handoff'))
const ensureSkipActionsTarget = vi.fn(() => calls.push('skip-actions'))
const vaultGuard = vi.fn()
const createVaultGuard = vi.fn(() => vaultGuard)
const manifestForLockState = vi.fn((manifest) => ({ ...manifest, locked: false }))
const sessionStore = { isLocked: false }
const useSessionStore = vi.fn(() => sessionStore)
const fragmentCtx = vi.fn((key) => ({ fragment: key }))
const requireContext = vi.fn(() => fragmentCtx)

const PageRenderer = { name: 'CnPageRenderer' }
const PageComponent = { name: 'LockScreen' }
const DialogComponent = { name: 'MoveDialog' }

vi.mock('@conduction/nextcloud-vue', () => ({
	buildManifest: (...args) => lib.buildManifest(...args),
	CnPageRenderer: PageRenderer,
	defaultPageTypes: Object.freeze({ index: 'IndexType', detail: 'DetailType' }),
	invalidateEndpointSourceCache: (...args) =>
		lib.invalidateEndpointSourceCache(...args),
	registerBuiltinDashboardWidgets: (...args) =>
		lib.registerBuiltinDashboardWidgets(...args),
	registerIcons: (...args) => lib.registerIcons(...args),
	registerTranslations: (...args) => lib.registerTranslations(...args),
}))

vi.mock('@nextcloud/l10n', () => ({
	loadTranslations: (...args) => loadTranslations(...args),
	translate: (_app, key) => `t:${key}`,
	translatePlural: (_app, one, many, count) => (count === 1 ? one : many),
}))

vi.mock('@nextcloud/router', () => ({
	generateUrl: (...args) => generateUrl(...args),
}))

vi.mock('vue-router', () => ({
	createWebHistory: (...args) => createWebHistory(...args),
	createRouter: (options) => {
		const router = {
			options,
			beforeEach: vi.fn(),
			afterEach: vi.fn(),
		}
		routers.push(router)
		return router
	},
}))

vi.mock('vue', async (importOriginal) => {
	const actual = await importOriginal()
	return {
		...actual,
		createApp: (root) => {
			const app = {
				root,
				mixin: vi.fn(() => calls.push('mixin')),
				use: vi.fn((plugin) =>
					calls.push(
						plugin && plugin.options ? 'use:router' : 'use:pinia',
					),
				),
				mount: vi.fn(() => calls.push('mount')),
			}
			apps.push(app)
			return app
		},
	}
})

vi.mock('../../src/App.vue', () => ({
	default: { name: 'App', render: () => null },
}))

vi.mock('../../src/bootstrap/hash-route-handoff.js', () => ({
	applyHashRouteHandoff: (...args) => applyHashRouteHandoff(...args),
	matchRouterBase: (...args) => matchRouterBase(...args),
}))

vi.mock('../../src/bootstrap/skip-actions.js', () => ({
	ensureSkipActionsTarget: (...args) => ensureSkipActionsTarget(...args),
}))

vi.mock('../../src/manifest.json', () => ({
	default: { version: '1.0.0', pages: [] },
}))

vi.mock('../../src/menu-layout.json', () => ({
	default: { groups: [] },
}))

vi.mock('../../src/registry.js', () => ({
	default: {
		LockScreen: { kind: 'page', component: PageComponent },
		MoveDialog: { kind: 'modal', component: DialogComponent },
		Broken: null,
	},
}))

vi.mock('../../src/router/guards.js', () => ({
	createVaultGuard: (...args) => createVaultGuard(...args),
	manifestForLockState: (...args) => manifestForLockState(...args),
}))

vi.mock('../../src/store/modules/session.js', () => ({
	useSessionStore: (...args) => useSessionStore(...args),
}))

const MERGED = {
	pages: [
		{ id: 'Dashboard', route: '/' },
		{ id: 'SecretDetail', route: '/secrets/:id' },
		{ id: 'Lock', route: '/lock' },
	],
}

/**
 * Import the entry point afresh so its top-level code runs again.
 *
 * @return {Promise<void>}
 */
async function boot() {
	vi.resetModules()
	await import('../../src/main.js')
}

describe('main.js bootstrap', () => {
	beforeEach(() => {
		calls.length = 0
		routers.length = 0
		apps.length = 0
		Object.values(lib).forEach((fn) => fn.mockReset())
		lib.buildManifest.mockReturnValue(MERGED)
		loadTranslations.mockReset()
		matchRouterBase.mockReset()
		matchRouterBase.mockReturnValue('/apps/keepiq')
		generateUrl.mockClear()
		createWebHistory.mockClear()
		createVaultGuard.mockClear()
		manifestForLockState.mockClear()
		useSessionStore.mockClear()
		applyHashRouteHandoff.mockClear()
		ensureSkipActionsTarget.mockClear()
		fragmentCtx.mockClear()
		fragmentCtx.keys = vi.fn(() => ['./b.json', './a.json'])
		// `require.context` is a webpack compile-time API. Under vitest the
		// module-scoped `require` is Node's, a plain function, so the lookup
		// falls through to Function.prototype; define it there for the test.
		requireContext.mockClear()
		Object.defineProperty(Function.prototype, 'context', {
			value: requireContext,
			configurable: true,
			writable: true,
		})
	})

	afterEach(() => {
		delete Function.prototype.context
		vi.restoreAllMocks()
	})

	it('registers the library icons, dashboard widgets and translations', async () => {
		const icons = (await import('../../src/icons.js')).default

		await boot()

		expect(lib.registerIcons).toHaveBeenCalledTimes(1)
		expect(Object.keys(lib.registerIcons.mock.calls[0][0])).toEqual(
			Object.keys(icons),
		)
		expect(Object.keys(icons).length).toBeGreaterThan(0)
		expect(lib.registerBuiltinDashboardWidgets).toHaveBeenCalledTimes(1)
		expect(lib.registerTranslations).toHaveBeenCalledTimes(1)
		expect(loadTranslations).toHaveBeenCalledWith('keepiq', expect.any(Function))
	})

	it('warns and keeps booting when the library translations fail to register', async () => {
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
		const error = new Error('no bundle')
		lib.registerTranslations.mockImplementation(() => {
			throw error
		})

		await boot()

		expect(warn).toHaveBeenCalledWith(
			'[keepiq] registerTranslations failed; falling back to English',
			error,
		)
		expect(apps).toHaveLength(1)
		expect(apps[0].mount).toHaveBeenCalledWith('#keepiq-app')
	})

	it('still mounts when loading the app translations throws or rejects', async () => {
		loadTranslations.mockImplementation(() => {
			throw new Error('404')
		})
		await boot()
		expect(apps).toHaveLength(1)

		loadTranslations.mockReset()
		loadTranslations.mockImplementation(() => Promise.reject(new Error('404')))
		await boot()
		await new Promise((resolve) => setTimeout(resolve, 0))
		expect(apps).toHaveLength(2)
		expect(apps[1].mount).toHaveBeenCalledWith('#keepiq-app')
	})

	it('merges the manifest fragments in sorted key order', async () => {
		await boot()

		expect(requireContext).toHaveBeenCalledWith(
			'./manifest.d/',
			false,
			/\.json$/,
		)
		const [base, fragments, layout] = lib.buildManifest.mock.calls[0]
		expect(base).toEqual({ version: '1.0.0', pages: [] })
		expect(fragments).toEqual([
			{ fragment: './a.json' },
			{ fragment: './b.json' },
		])
		expect(layout).toEqual({ groups: [] })
	})

	it('builds one route per manifest page plus a catch-all redirect', async () => {
		await boot()

		const { routes } = routers[0].options
		expect(routes).toHaveLength(4)
		expect(routes.slice(0, 3).map((r) => [r.name, r.path, r.props])).toEqual([
			['Dashboard', '/', false],
			['SecretDetail', '/secrets/:id', true],
			['Lock', '/lock', false],
		])
		// The renderer is a shallow, extensible copy, not the library's export.
		expect(routes[0].component).not.toBe(PageRenderer)
		expect(routes[0].component).toEqual(PageRenderer)
		expect(routes[1].component).toBe(routes[0].component)
		expect(routes[3]).toEqual({ path: '/:pathMatch(.*)*', redirect: '/' })
	})

	it('uses the matched router base and rewrites hash links before creating the router', async () => {
		matchRouterBase.mockReturnValue('/index.php/apps/keepiq/public')

		await boot()

		expect(matchRouterBase).toHaveBeenCalledWith(window.location.pathname)
		expect(generateUrl).not.toHaveBeenCalled()
		expect(createWebHistory).toHaveBeenCalledWith(
			'/index.php/apps/keepiq/public',
		)
		expect(routers[0].options.history).toEqual({
			base: '/index.php/apps/keepiq/public',
		})
		expect(applyHashRouteHandoff).toHaveBeenCalledTimes(1)
		expect(applyHashRouteHandoff.mock.invocationCallOrder[0]).toBeLessThan(
			createWebHistory.mock.invocationCallOrder[0],
		)
	})

	it('falls back to the generated app URL when the path is not a keepiq URL', async () => {
		matchRouterBase.mockReturnValue(null)

		await boot()

		expect(generateUrl).toHaveBeenCalledWith('/apps/keepiq')
		expect(createWebHistory).toHaveBeenCalledWith('/index.php/apps/keepiq')
	})

	it('installs the vault guard with a lazy session-store factory', async () => {
		await boot()

		const router = routers[0]
		expect(router.beforeEach).toHaveBeenCalledWith(vaultGuard)
		const factory = createVaultGuard.mock.calls[0][0]
		expect(useSessionStore).not.toHaveBeenCalled()
		const pinia = (await import('../../src/pinia.js')).default
		expect(factory()).toBe(sessionStore)
		expect(useSessionStore).toHaveBeenCalledWith(pinia)
	})

	it('drops the endpoint cache only when a navigation lands on the dashboard', async () => {
		await boot()

		const afterEach = routers[0].afterEach.mock.calls[0][0]
		afterEach({ name: 'SecretDetail' })
		expect(lib.invalidateEndpointSourceCache).not.toHaveBeenCalled()
		afterEach({ name: 'Dashboard' })
		expect(lib.invalidateEndpointSourceCache).toHaveBeenCalledTimes(1)
	})

	it('renders App with the lock-aware manifest and extensible registry copies', async () => {
		await boot()

		const vnode = apps[0].root.render()
		expect(vnode.type.name).toBe('App')
		expect(manifestForLockState).toHaveBeenCalledWith(MERGED, sessionStore)
		expect(vnode.props.manifest).toEqual({ ...MERGED, locked: false })
		expect(vnode.props.customComponents).toEqual({ LockScreen: PageComponent })
		expect(vnode.props.pageTypes).toEqual({
			index: 'IndexType',
			detail: 'DetailType',
		})
		expect(Object.isFrozen(vnode.props.pageTypes)).toBe(false)
		expect(Object.keys(vnode.props.registry)).toEqual([
			'LockScreen',
			'MoveDialog',
			'Broken',
		])
		expect(vnode.props.registry.MoveDialog.component).toBe(DialogComponent)
	})

	it('provides t and n, installs pinia and the router, and mounts after the skip-link target', async () => {
		await boot()

		const app = apps[0]
		const { methods } = app.mixin.mock.calls[0][0]
		expect(methods.t('keepiq', 'Secrets')).toBe('t:Secrets')
		expect(methods.n('keepiq', 'one', 'many', 3)).toBe('many')
		const pinia = (await import('../../src/pinia.js')).default
		expect(app.use).toHaveBeenNthCalledWith(1, pinia)
		expect(app.use).toHaveBeenNthCalledWith(2, routers[0])
		expect(app.mount).toHaveBeenCalledWith('#keepiq-app')
		expect(calls.slice(-5)).toEqual([
			'mixin',
			'use:pinia',
			'use:router',
			'skip-actions',
			'mount',
		])
	})
})
