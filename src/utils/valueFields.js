/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * A login's `totp` and `passkeys` value fields (keepiq#1325), and whether the
 * server stores them. The server declares both in the `loginValueFields`
 * initial state, so this file keeps no release number of its own.
 */

import { loadState } from '@nextcloud/initial-state'

/**
 * The value fields a literal `''` leaves unset, so one is cleared with `''`
 * and never encrypted empty; a partner gets them only when it advertised it
 * stores them.
 */
export const BLOB_FIELDS = ['totp', 'passkeys']

/**
 * What the server declares about a login's `totp` and `passkeys`: `{since}`,
 * the first stable release that stores them, or null when it declares nothing
 * (a server from before the fields, behind this page).
 *
 * @return {{since: string}|null}
 */
function declared() {
	try {
		const state = loadState('keepiq', 'loginValueFields', null)
		return state && typeof state === 'object' ? state : null
	} catch {
		return null
	}
}

/**
 * Whether this server stores a login's `totp` and `passkeys`.
 *
 * @return {boolean}
 * @spec openspec/specs/secrets/spec.md#requirement-create-secret
 */
export function valueFieldsSupported() {
	return declared() !== null
}

/**
 * The first stable Keepiq release that stores `totp` and `passkeys`, for a
 * message that tells a partner what to upgrade to; empty when the server
 * declares none.
 *
 * @return {string}
 * @spec openspec/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
 */
export function valueFieldsSince() {
	return String(declared()?.since ?? '')
}
