/**
 * SPDX-FileCopyrightText: 2026 Conduction / Doriath Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Test stub for `@nextcloud/router`.
 *
 * Provides the path-construction helpers without the Nextcloud-window
 * lookup (`OC.getRootPath()` etc.). For unit tests, `generateUrl`
 * returns the path it was given, which is exactly what the store /
 * component code asserts against in `axios.<method>` calls.
 */

export function generateUrl(path) {
	return path
}

export function generateOcsUrl(path) {
	return `/ocs/v2.php${path}`
}

export function generateRemoteUrl(path) {
	return path
}

export function getRootUrl() {
	return ''
}

export default {
	generateUrl,
	generateOcsUrl,
	generateRemoteUrl,
	getRootUrl,
}
