/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Shell behaviour of `src/App.vue` that the wiring specs leave open: the
 * route watcher that locks on a bare Lock route, the unload guard, the
 * visibility check, service-worker registration, the detail-sidebar close
 * target and the computed labels.
 *
 * Like appLockWiring.spec.js, the options are invoked directly with a stubbed
 * `this`: mounting the shell would drag in CnAppRoot and every store and test
 * the framework rather than these decisions.
 *
 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
 * @spec openspec/specs/offline-readonly-cache/spec.md#requirement-the-app-shell-loads-offline-via-a-service-worker
 * @spec openspec/specs/secrets/spec.md#requirement-read-secret
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import App from '../../src/App.vue'
import { handleLockTransition } from '../../src/router/guards.js'

vi.mock('../../src/router/guards.js', async (importOriginal) => {
	const actual = await importOriginal()

	return { ...actual, handleLockTransition: vi.fn(() => true) }
})

/**
 * A stubbed component `this` for the route watcher.
 *
 * @param {object} overrides Fields to replace.
 * @return {object} The context.
 */
function routeContext(overrides = {}) {
	return {
		sessionStore: { isLocked: false, lock: vi.fn() },
		suiteStore: { currentSuite: { id: 'suite-1' } },
		...overrides,
	}
}

describe('App.vue shell options', () => {
	beforeEach(() => {
		handleLockTransition.mockClear()
	})

	afterEach(() => {
		vi.useRealTimers()
		vi.restoreAllMocks()
	})

	describe('$route watcher', () => {
		it('locks an unlocked session that lands on a bare Lock route', () => {
			const context = routeContext()

			App.watch.$route.call(context, { name: 'Lock', query: {} })

			expect(context.sessionStore.lock).toHaveBeenCalledTimes(1)
		})

		it('does not lock when the Lock route carries a returnUrl', () => {
			const context = routeContext()

			App.watch.$route.call(context, {
				name: 'Lock',
				query: { returnUrl: '/secrets' },
			})

			expect(context.sessionStore.lock).not.toHaveBeenCalled()
		})

		it('does not lock again when already locked', () => {
			const context = routeContext()
			context.sessionStore.isLocked = true

			App.watch.$route.call(context, { name: 'Lock' })

			expect(context.sessionStore.lock).not.toHaveBeenCalled()
		})

		it('does not lock without an encryption suite', () => {
			const context = routeContext({ suiteStore: { currentSuite: null } })

			App.watch.$route.call(context, { name: 'Lock' })

			expect(context.sessionStore.lock).not.toHaveBeenCalled()
		})

		it('ignores any other route', () => {
			const context = routeContext()

			App.watch.$route.call(context, { name: 'SecretList' })
			App.watch.$route.call(context, undefined)

			expect(context.sessionStore.lock).not.toHaveBeenCalled()
		})
	})

	describe('isLocked watcher', () => {
		it('stays out of the way while the page is unloading', () => {
			const syncNow = vi.fn()
			const context = {
				unloading: true,
				offlineStore: { online: true, servedFromCache: false, syncNow },
			}

			App.watch.isLocked.call(context, false)

			expect(handleLockTransition).not.toHaveBeenCalled()
			expect(syncNow).not.toHaveBeenCalled()
		})

		it('warms the snapshot after an online unlock that was not served from cache', () => {
			const syncNow = vi.fn().mockRejectedValue(new Error('offline'))
			const context = {
				$route: {},
				$router: {},
				offlineStore: { online: true, servedFromCache: false, syncNow },
			}

			App.watch.isLocked.call(context, false)

			expect(syncNow).toHaveBeenCalledTimes(1)
		})

		it('does not sync after a lock or from cache', () => {
			const syncNow = vi.fn()
			App.watch.isLocked.call(
				{ offlineStore: { online: true, servedFromCache: false, syncNow } },
				true,
			)
			App.watch.isLocked.call(
				{ offlineStore: { online: true, servedFromCache: true, syncNow } },
				false,
			)

			expect(syncNow).not.toHaveBeenCalled()
		})
	})

	describe('handleBeforeUnload', () => {
		it('locks at once and re-checks the lock transition after the grace period', () => {
			vi.useFakeTimers()
			const context = {
				$route: { name: 'SecretList' },
				$router: {},
				unloading: false,
				offlineStore: { pendingCount: 0 },
				sessionStore: { isLocked: true, lock: vi.fn() },
			}
			const event = { preventDefault: vi.fn(), returnValue: undefined }

			App.methods.handleBeforeUnload.call(context, event)

			expect(context.sessionStore.lock).toHaveBeenCalledTimes(1)
			expect(context.unloading).toBe(true)
			expect(event.preventDefault).not.toHaveBeenCalled()
			expect(handleLockTransition).not.toHaveBeenCalled()

			vi.advanceTimersByTime(2000)

			expect(context.unloading).toBe(false)
			expect(handleLockTransition).toHaveBeenCalledWith(
				true,
				context.$route,
				context.$router,
			)
		})

		it('asks the browser to confirm leaving with unsynced offline edits', () => {
			vi.useFakeTimers()
			const context = {
				offlineStore: { pendingCount: 2 },
				sessionStore: { isLocked: true, lock: vi.fn() },
			}
			const event = { preventDefault: vi.fn(), returnValue: undefined }

			App.methods.handleBeforeUnload.call(context, event)

			expect(event.preventDefault).toHaveBeenCalledTimes(1)
			expect(event.returnValue).toBe('')
		})
	})

	describe('handleVisibilityChange', () => {
		it('checks the timeout only when the tab becomes visible', () => {
			const checkTimeout = vi.fn()
			const context = { sessionStore: { checkTimeout } }
			const state = vi.spyOn(document, 'visibilityState', 'get')

			state.mockReturnValue('hidden')
			App.methods.handleVisibilityChange.call(context)
			expect(checkTimeout).not.toHaveBeenCalled()

			state.mockReturnValue('visible')
			App.methods.handleVisibilityChange.call(context)
			expect(checkTimeout).toHaveBeenCalledTimes(1)
		})
	})

	describe('registerServiceWorker', () => {
		afterEach(() => {
			delete navigator.serviceWorker
		})

		it('registers the worker served at the app root', () => {
			const register = vi.fn().mockRejectedValue(new Error('blocked'))
			Object.defineProperty(navigator, 'serviceWorker', {
				value: { register },
				configurable: true,
			})

			App.methods.registerServiceWorker.call({})

			expect(register).toHaveBeenCalledWith('/apps/keepiq/serviceworker.js')
		})

		it('does nothing where service workers are unsupported', () => {
			expect('serviceWorker' in navigator).toBe(false)

			expect(() => App.methods.registerServiceWorker.call({})).not.toThrow()
		})
	})

	describe('detail sidebar', () => {
		it('closes back to the folder the secret was opened from', () => {
			const push = vi.fn()

			App.methods.closeDetailSidebar.call({
				$route: {
					name: 'SecretListFolder',
					params: { folderId: 'f1', id: 's1' },
				},
				$router: { push },
			})

			expect(push).toHaveBeenCalledWith({
				name: 'SecretListFolder',
				params: { folderId: 'f1' },
			})
		})

		it('opens only for a list route with an id', () => {
			const id = App.computed.detailSidebarSecretId
			expect(id.call({ $route: { name: 'SecretList', params: { id: 's1' } } })).toBe(
				's1',
			)
			expect(
				id.call({ $route: { name: 'ApplicationDetail', params: { id: 'a1' } } }),
			).toBeNull()
		})
	})

	describe('computed', () => {
		it('labels the last sync time, or unknown without one', () => {
			const at = '2026-02-03T04:05:06Z'

			expect(
				App.computed.syncedLabel.call({ offlineStore: { syncedAt: at } }),
			).toBe(new Date(at).toLocaleString())
			expect(
				App.computed.syncedLabel.call({ offlineStore: { syncedAt: null } }),
			).toBe('unknown')
		})

		it('recognises the lock screen by route name', () => {
			expect(App.computed.isLockScreen.call({ $route: { name: 'Lock' } })).toBe(
				true,
			)
			expect(
				App.computed.isLockScreen.call({ $route: { name: 'SecretList' } }),
			).toBe(false)
			expect(App.computed.isLockScreen.call({ $route: undefined })).toBe(false)
		})

		it('mirrors the session lock state', () => {
			expect(
				App.computed.isLocked.call({ sessionStore: { isLocked: true } }),
			).toBe(true)
		})

		it('points to the Nextcloud security settings', () => {
			expect(App.computed.securitySettingsUrl.call({})).toBe(
				'/settings/user/security',
			)
		})

		it('keeps the host components and adds the connection handlers', () => {
			const host = { Marker: { name: 'Marker' } }

			const merged = App.computed.shellCustomComponents.call({
				customComponents: host,
			})

			expect(merged.Marker).toBe(host.Marker)
			expect(typeof merged.openIntegriqConnections).toBe('function')
		})

		it('translates through the keepiq app id', () => {
			expect(App.methods.translateForApp.call({}, 'Secrets')).toBe('Secrets')
		})
	})
})
