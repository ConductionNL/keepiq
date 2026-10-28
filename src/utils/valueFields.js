/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * A login's `totp` and `passkeys` value fields (keepiq#1325), and whether the
 * server stores them, read from the `appVersion` initial state. The web app
 * is served by its own server, so the gate only guards a mixed deploy.
 */

import { loadState } from '@nextcloud/initial-state'

/**
 * The first Keepiq release that stores `totp` and `passkeys`, mirroring
 * Application::TOTP_PASSKEYS_FIELDS_SINCE. That constant is a placeholder
 * until the release is cut; keep both in step.
 */
export const TOTP_PASSKEYS_FIELDS_SINCE = '0.3.12'

/**
 * The value fields a literal `''` leaves unset, so one is cleared with `''`
 * and never encrypted empty; a partner gets them only when it advertised it
 * stores them.
 */
export const BLOB_FIELDS = ['totp', 'passkeys']

/**
 * A version's `major.minor.patch` and whether it is an `-unstable` build, as
 * the browser extension reads one; null for anything else.
 *
 * @param {string} version The version.
 * @return {{core: Array<number>, unstable: boolean}|null}
 */
function parseVersion(version) {
	const match = /^(\d+)\.(\d+)\.(\d+)(?:-([0-9A-Za-z.-]+))?$/.exec(
		String(version).trim(),
	)
	if (!match) {
		return null
	}
	return {
		core: [Number(match[1]), Number(match[2]), Number(match[3])],
		unstable: match[4]?.startsWith('unstable') ?? false,
	}
}

/**
 * Whether a server version stores `totp` and `passkeys`. A prerelease counts
 * as its release; an `-unstable` build passes, as a development checkout
 * reports an older version. A missing or unreadable version passes too.
 *
 * @param {string|null|undefined} version The server's Keepiq version.
 * @return {boolean}
 * @spec openspec/specs/secrets/spec.md#requirement-create-secret
 */
export function versionStoresValueFields(version) {
	const parsed = version ? parseVersion(version) : null
	if (parsed === null || parsed.unstable) {
		return true
	}
	const minimum = parseVersion(TOTP_PASSKEYS_FIELDS_SINCE).core
	for (let i = 0; i < 3; i++) {
		if (parsed.core[i] !== minimum[i]) {
			return parsed.core[i] > minimum[i]
		}
	}
	return true
}

/**
 * Whether this server stores a login's `totp` and `passkeys`.
 *
 * @return {boolean}
 * @spec openspec/specs/secrets/spec.md#requirement-create-secret
 */
export function valueFieldsSupported() {
	let version = ''
	try {
		version = loadState('keepiq', 'appVersion', '')
	} catch {
		// No initial state (a test, a page without it): fail open.
	}
	return versionStoresValueFields(version)
}
