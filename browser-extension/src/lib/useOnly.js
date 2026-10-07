/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The extension's rules for a use-only copy (sharing-use-only-and-expiring-
 * shares D3): fill it only on a site whose registrable domain matches the
 * copy's URL, with no "fill anyway"; fill only into a real password field;
 * never offer to save or update it; report each fill. The popup never shows
 * or copies a value, use-only or not.
 *
 * @spec openspec/specs/use-only-shares/spec.md#requirement-keepiqs-clients-never-reveal-a-use-only-value
 */

import { hostOf, registrableDomain } from './match.js'

/**
 * Whether a secret row is a use-only copy.
 *
 * @param {object} row A secret row from the match API.
 * @return {boolean}
 */
export function isUseOnly(row) {
	return row?.useOnly === true
}

/**
 * Whether a use-only row may be filled on a host: its URL's registrable
 * domain must equal the host's. A row without a URL never matches. A row
 * that is not use-only is always allowed (the normal rules apply).
 *
 * @param {object} row A secret row.
 * @param {string} host The page host.
 * @return {boolean}
 */
export function allowedOnHost(row, host) {
	if (!isUseOnly(row)) {
		return true
	}
	const own = registrableDomain(hostOf(row?.url ?? ''))
	return own !== '' && own === registrableDomain(host)
}

/**
 * Drop the use-only rows a host may not fill.
 *
 * @param {Array<object>} rows Ranked candidate rows.
 * @param {string} host The page host.
 * @return {Array<object>}
 */
export function filterForHost(rows, host) {
	return (rows ?? []).filter((row) => allowedOnHost(row, host))
}

/**
 * Whether a submitted login on this host belongs to a use-only copy, so no
 * save or update may be offered for it.
 *
 * @param {Array<object>} rows The candidate rows for the host.
 * @param {string} host The page host.
 * @return {boolean}
 */
export function blocksSavePrompt(rows, host) {
	return (rows ?? []).some((row) => isUseOnly(row) && allowedOnHost(row, host))
}

/**
 * The field a use-only value may go into: only an input whose type is
 * `password`, so the value never lands in a visible text field.
 *
 * @param {HTMLInputElement|null} field The detected password field.
 * @return {HTMLInputElement|null}
 */
export function useOnlyPasswordTarget(field) {
	if (!field || String(field.type || '').toLowerCase() !== 'password') {
		return null
	}
	return field
}
