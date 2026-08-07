/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Vault navigation guard.
 *
 * The encryption-suites spec requires that when the in-memory CryptoKey is
 * absent, "any Doriath route" access redirects to the lock screen, and that
 * the lock screen is a full page rather than an overlay. Enforcing that from
 * a component lifecycle hook is too late: the router has already resolved
 * the route, so `CnPageRenderer` mounts the target page and its `mounted()`
 * fires before any redirect can land. `SecretList` in particular issues
 * `fetchSecrets()` unconditionally on mount, and secret `name` / `url` /
 * folder placement are stored server-side in plaintext by design (see
 * lib/Db/Secret.php) so the vault inventory paints before the lock screen
 * replaces it.
 *
 * This guard runs in `beforeEach`, so a locked vault never resolves a
 * protected route at all — no page component is instantiated and no fetch
 * is issued. It is deliberately synchronous: gating navigation behind an
 * awaited settings request (as the previous App.vue boot redirect did)
 * reopens exactly the window it is meant to close.
 *
 * Metadata itself is NOT protected by this guard, and that is by design —
 * secret names and URLs are plaintext and searchable for their owner. What
 * the guard protects is the application surface: no Doriath screen renders
 * until the master password has been entered.
 *
 * vue-router 4 note: the `(to, from, next)` signature is still fully
 * supported. It is kept over the newer "return a route location" idiom so
 * the redirect and the pass-through read identically.
 *
 * @spec openspec/specs/encryption-suites/spec.md#requirement-session-mechanism
 */

/**
 * Name of the lock-screen route (manifest page id `Lock`, path `/lock`).
 *
 * @type {string}
 */
export const LOCK_ROUTE_NAME = 'Lock'

/**
 * Route names reachable without an unlocked vault.
 *
 * These are the recipient-facing token URLs. A link-share, secret-request,
 * or ephemeral-send recipient is frequently not the vault owner and may
 * have no Doriath suite at all, so gating them on a master password would
 * make the sharing features unusable rather than more secure. They carry
 * their own token-scoped authorisation server-side.
 *
 * @type {string[]}
 * @spec openspec/changes/implement-secret-requests/tasks.md#task-9.2
 */
export const PUBLIC_ROUTE_NAMES = ['SecretRequestFill', 'LinkShareAccess', 'EphemeralSendAccess']

/**
 * Whether a vue-router route lives outside the locked-vault guard.
 *
 * @param {object|null} route The route object to test.
 * @return {boolean} True when the route needs no unlocked vault.
 * @spec openspec/specs/encryption-suites/spec.md#requirement-session-mechanism
 */
export function isPublicRoute(route) {
	if (route == null) {
		return false
	}
	// Allow either explicit per-route `meta.public:true` (when the shared
	// manifest schema ships that field) or membership in the
	// PUBLIC_ROUTE_NAMES allow-list maintained alongside this module.
	if (route.meta && route.meta.public === true) {
		return true
	}
	return PUBLIC_ROUTE_NAMES.includes(route.name)
}

/**
 * Build the `beforeEach` guard that keeps every application screen behind
 * the master password.
 *
 * The session store is injected as a factory rather than imported directly
 * so the guard can be constructed before the Pinia instance is installed on
 * the app, and so unit tests can drive it without Pinia.
 *
 * @param {Function} getSessionStore Returns the session store (with `isLocked`).
 * @return {Function} A vue-router `beforeEach(to, from, next)` guard.
 * @spec openspec/specs/encryption-suites/spec.md#requirement-session-mechanism
 */
export function createVaultGuard(getSessionStore) {
	return function vaultGuard(to, from, next) {
		// The lock screen itself must always resolve, otherwise the
		// redirect below would recurse.
		if (to.name === LOCK_ROUTE_NAME || isPublicRoute(to)) {
			next()
			return
		}

		if (getSessionStore().isLocked) {
			next({
				name: LOCK_ROUTE_NAME,
				query: { returnUrl: to.fullPath },
			})
			return
		}

		next()
	}
}