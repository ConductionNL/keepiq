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
 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-keepiq-conn-004-an-admin-reads-the-connections-on-an-integrations-page
 */

/**
 * The router target for a route entry, with its `query` preset when it has one.
 *
 * @param {object} item The menu entry.
 * @return {object|null} A vue-router location, or null for a non-route entry.
 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-keepiq-conn-004-an-admin-reads-the-connections-on-an-integrations-page
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
 * Whether a menu entry may render for this user on this instance.
 *
 * @param {object} item The menu entry.
 * @param {{isAdmin: boolean, appsWebRoots: object|null|undefined}} context The instance admin flag, and `OC.appswebroots`: one key per app enabled for this user.
 * @return {boolean} False when the entry is admin only and the user is not an admin, or names an app that is not enabled.
 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-keepiq-conn-004-an-admin-reads-the-connections-on-an-integrations-page
 */
export function isMenuEntryVisible(item, { isAdmin, appsWebRoots }) {
	if (item?.permission === 'admin' && isAdmin !== true) {
		return false
	}

	const required = item?.visibleIf?.appInstalled
	if (typeof required === 'string' && required.length > 0) {
		// Hide on uncertainty, like CnAppNav: a missing map is not an installed app.
		return Boolean(appsWebRoots) && Object.hasOwn(appsWebRoots, required)
	}

	return true
}
