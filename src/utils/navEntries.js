// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * How Keepiq's own navigation rail reads one manifest menu entry.
 *
 * KeepiqAppNav replaces CnAppNav in CnAppRoot's `#menu` slot, so it gets none
 * of CnAppNav's entry handling for free. It used to read only `route`, `href`
 * and `action`. The Integrations entry (adopt-connection-registry) is the
 * first to declare three more fields, and each one is silent when dropped:
 *
 *   - `query`: without it the Integrations page opens with no `app=keepiq`
 *     preset and lists every app's connection rows as though they were
 *     Keepiq's.
 *   - `permission: "admin"`: without it every user sees an admin-only entry.
 *   - `visibleIf.appInstalled`: without it the entry shows on an instance
 *     without integriq and leads to a missing-dependency screen.
 *
 * The rules follow CnAppNav (`itemTo`, `passesVisibleIf` and
 * `isAppInstalled`). They differ in one place: CnAppNav checks `permission`
 * against a permission list Nextcloud does not provide, so it renders every
 * entry. Here `admin` means the instance admin flag. No other entry in the
 * manifest declares `permission` or `visibleIf`.
 *
 * Pure: the admin flag and the enabled apps are passed in.
 *
 * @spec openspec/specs/admin-integrations/spec.md#requirement-req-keepiq-conn-004-an-admin-reads-the-connections-on-an-integrations-page
 */

/**
 * The router target for a route entry, with its `query` preset when it has one.
 *
 * @param {object} item The menu entry.
 * @return {object|null} A vue-router location, or null for a non-route entry.
 * @spec openspec/specs/admin-integrations/spec.md#requirement-req-keepiq-conn-004-an-admin-reads-the-connections-on-an-integrations-page
 */
export function menuEntryTo(item) {
	if (!item?.route || item.action) {
		return null
	}

	const query = item.query
	if (query && typeof query === 'object' && Object.keys(query).length > 0) {
		return { name: item.route, query: { ...query } }
	}

	return { name: item.route }
}

/**
 * `OC.appswebroots`: one key per app enabled for the logged-in user, or null
 * outside a Nextcloud page.
 *
 * @return {object|null} The map, or null.
 * @spec openspec/specs/app-shell/spec.md#requirement-optional-integrations-appear-only-when-their-app-is-present
 */
export function currentAppsWebRoots() {
	return (typeof window !== 'undefined' && window.OC?.appswebroots) || null
}

/**
 * Whether an app is enabled for the logged-in user. The one place Keepiq
 * decides app presence, for the menu gates and the AI companion alike.
 *
 * @param {string} appId The app id, e.g. `openregister`.
 * @param {object|null|undefined} appsWebRoots `OC.appswebroots`.
 * @return {boolean} False on uncertainty: a missing map is not an enabled app.
 * @spec openspec/specs/app-shell/spec.md#requirement-optional-integrations-appear-only-when-their-app-is-present
 */
export function isAppEnabled(appId, appsWebRoots) {
	return Boolean(appsWebRoots) && Object.hasOwn(appsWebRoots, appId)
}

/**
 * Whether a menu entry may render for this user on this instance.
 *
 * @param {object} item The menu entry.
 * @param {{isAdmin: boolean, appsWebRoots: object|null|undefined}} context The instance admin flag, and `OC.appswebroots`: one key per app enabled for this user.
 * @return {boolean} False when the entry is admin only and the user is not an admin, or names an app that is not enabled.
 * @spec openspec/specs/admin-integrations/spec.md#requirement-req-keepiq-conn-004-an-admin-reads-the-connections-on-an-integrations-page
 */
export function isMenuEntryVisible(item, { isAdmin, appsWebRoots }) {
	if (item?.permission === 'admin' && isAdmin !== true) {
		return false
	}

	const required = item?.visibleIf?.appInstalled
	if (typeof required === 'string' && required.length > 0) {
		// Hide on uncertainty, like CnAppNav: a missing map is not an installed app.
		return isAppEnabled(required, appsWebRoots)
	}

	return true
}

/**
 * The permission list the app shell hands to CnAppRoot.
 *
 * CnPageRenderer refuses a page whose `permission` the list does not hold,
 * but it treats an EMPTY list as "the app did not say" and serves every
 * page. Nextcloud provides no permission list (`OC.currentUser` is the uid
 * string), so the shell used to pass an empty one and the admin-only
 * Integrations page opened for anyone who typed its URL (#878). The list is
 * never empty: every signed-in user holds `user`, and the instance admin
 * also holds `admin`, the same flag the menu filter above uses.
 *
 * @param {boolean} isAdmin The instance admin flag.
 * @return {Array<string>} The permissions the user holds.
 * @spec openspec/specs/admin-integrations/spec.md#requirement-req-keepiq-conn-004-an-admin-reads-the-connections-on-an-integrations-page
 */
export function shellPermissions(isAdmin) {
	return isAdmin === true ? ['user', 'admin'] : ['user']
}
