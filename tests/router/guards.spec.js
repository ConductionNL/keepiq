/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Unit tests for the vault navigation guard.
 *
 * The regression these lock down: the lock screen used to be reached via an
 * App.vue `created()` redirect that ran after an awaited settings request, so
 * the target page mounted and fetched before the redirect landed. The guard
 * must reject the navigation itself, for every application route.
 *
 * @spec openspec/specs/encryption-suites/spec.md#requirement-session-mechanism
 */

import { describe, it, expect, vi } from 'vitest'
import {
	LOCK_ROUTE_NAME,
	PUBLIC_ROUTE_NAMES,
	createVaultGuard,
	isPublicRoute,
} from '../../src/router/guards.js'

/**
 * Build a guard plus a spy `next`, over a session store of the given state.
 *
 * @param {boolean} isLocked Whether the vault is locked.
 * @return {{guard: Function, next: Function}} Guard under test and its next spy.
 */
function harness(isLocked) {
	const next = vi.fn()
	const guard = createVaultGuard(() => ({ isLocked }))
	return { guard, next }
}

/**
 * Minimal vue-router route stub.
 *
 * @param {string} name Route name.
 * @param {string} fullPath Resolved path.
 * @param {object} meta Route meta.
 * @return {object} Route-like object.
 */
function route(name, fullPath = `/${name.toLowerCase()}`, meta = {}) {
	return { name, fullPath, meta }
}

// Every non-public application screen in src/manifest.json. Listed
// explicitly rather than imported so that adding a page to the manifest
// without considering the guard shows up as a deliberate edit here.
const PROTECTED_ROUTES = [
	'Dashboard',
	'FeaturesRoadmap',
	'PersonalActivity',
	'PasswordHealth',
	'Certificates',
	'EmergencyAccess',
	'SecretList',
	'SecretListFolder',
	'SecretDetail',
	'ApplicationRegister',
	'ApplicationDetail',
]

describe('createVaultGuard', () => {
	describe('when the vault is locked', () => {
		it.each(PROTECTED_ROUTES)('redirects %s to the lock screen', (name) => {
			const { guard, next } = harness(true)

			guard(route(name), route('Dashboard', '/'), next)

			expect(next).toHaveBeenCalledTimes(1)
			expect(next).toHaveBeenCalledWith({
				name: LOCK_ROUTE_NAME,
				query: { returnUrl: `/${name.toLowerCase()}` },
			})
		})

		it('preserves the attempted path as returnUrl so unlock resumes it', () => {
			const { guard, next } = harness(true)

			guard(
				route('SecretDetail', '/secrets/42'),
				route('Dashboard', '/'),
				next,
			)

			expect(next).toHaveBeenCalledWith({
				name: LOCK_ROUTE_NAME,
				query: { returnUrl: '/secrets/42' },
			})
		})

		it('lets the lock screen itself resolve, so the redirect cannot recurse', () => {
			const { guard, next } = harness(true)

			guard(route(LOCK_ROUTE_NAME, '/lock'), route('Dashboard', '/'), next)

			expect(next).toHaveBeenCalledWith()
		})

		it.each(PUBLIC_ROUTE_NAMES)(
			'lets the recipient-facing route %s through',
			(name) => {
				const { guard, next } = harness(true)

				guard(
					route(name, `/share/${name}/tok`),
					route('Dashboard', '/'),
					next,
				)

				expect(next).toHaveBeenCalledWith()
			},
		)

		it('lets a route flagged meta.public through', () => {
			const { guard, next } = harness(true)

			guard(
				route('SomeFuturePublicPage', '/pub', { public: true }),
				route('Dashboard', '/'),
				next,
			)

			expect(next).toHaveBeenCalledWith()
		})

		it('does not consult the session store before the route is known to be protected', () => {
			const getSessionStore = vi.fn(() => ({ isLocked: true }))
			const guard = createVaultGuard(getSessionStore)

			guard(route(LOCK_ROUTE_NAME, '/lock'), route('Dashboard', '/'), vi.fn())

			expect(getSessionStore).not.toHaveBeenCalled()
		})
	})

	describe('when the vault is unlocked', () => {
		it.each(PROTECTED_ROUTES)('allows %s', (name) => {
			const { guard, next } = harness(false)

			guard(route(name), route('Dashboard', '/'), next)

			expect(next).toHaveBeenCalledTimes(1)
			expect(next).toHaveBeenCalledWith()
		})
	})

	it('is synchronous — it must not defer the decision behind a promise', () => {
		const { guard, next } = harness(true)

		const returned = guard(
			route('SecretList', '/secrets'),
			route('Dashboard', '/'),
			next,
		)

		expect(returned).toBeUndefined()
		expect(next).toHaveBeenCalledTimes(1)
	})
})

describe('isPublicRoute', () => {
	it('returns false for a null route', () => {
		expect(isPublicRoute(null)).toBe(false)
	})

	it('returns false for a protected route', () => {
		expect(isPublicRoute(route('SecretList'))).toBe(false)
	})

	it('honours the allow-list', () => {
		expect(isPublicRoute(route('LinkShareAccess'))).toBe(true)
	})

	it('honours meta.public', () => {
		expect(isPublicRoute(route('Anything', '/x', { public: true }))).toBe(true)
	})

	it('does not treat a falsy meta.public as public', () => {
		expect(isPublicRoute(route('Anything', '/x', { public: false }))).toBe(false)
	})
})
