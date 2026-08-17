/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Guarantees the `#skip-actions` Teleport target exists before the app mounts.
 *
 * `NcContent` (which CnAppRoot renders) teleports its accessibility skip-link
 * into `#skip-actions`. Nextcloud's AUTHENTICATED layout (`layout.user.php`)
 * provides that element; the base layout used for anonymous pages does not.
 *
 * The consequence was not a cosmetic warning. Vue treats a null Teleport target
 * as a hard error during the component update:
 *
 *   [Vue warn]: Failed to locate Teleport target with selector "#skip-actions"
 *   [Vue warn]: Invalid Teleport target on mount: null
 *   Uncaught TypeError: Cannot set properties of null (setting '__vnode')
 *
 * That error aborted the update before `CnAppRoot.mounted()` finished, so the
 * component never reached the `finally` block that clears `capabilitiesLoading`.
 * The shell stayed on its loading spinner forever and the `<router-view>` was
 * never rendered — meaning EVERY route on the anonymous `/public` shell rendered
 * a blank page: secret-request fills, link shares and ephemeral sends alike.
 * Recipients without a Nextcloud account could not complete any public flow.
 *
 * The fix lives here rather than in the template because the target is a
 * client-side requirement of the component tree, and doing it once at bootstrap
 * covers every controller that renders the shell with a non-user `renderAs`
 * instead of relying on each one to remember.
 */

/**
 * Create the `#skip-actions` Teleport target if the layout did not supply one.
 *
 * Idempotent: when Nextcloud's authenticated layout already rendered the
 * element, nothing is added and no duplicate id is introduced.
 *
 * The element is prepended to `<body>` because skip links must be the first
 * focusable content on the page — the same position core gives them — so this
 * does not merely satisfy the Teleport, it keeps the link useful.
 *
 * @param {Document} doc The document to operate on (injectable for tests).
 * @return {boolean} True when a target was created, false when one already existed.
 */
export function ensureSkipActionsTarget(doc = document) {
	if (doc.getElementById('skip-actions') !== null) {
		return false
	}

	const target = doc.createElement('div')
	target.id = 'skip-actions'
	doc.body.prepend(target)

	return true
}
