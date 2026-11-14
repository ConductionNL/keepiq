/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * An ISO timestamp as locale date-time text; a value that does not parse is
 * shown as it is, and a missing one as ''.
 *
 * @param {string|null|undefined} iso The ISO timestamp.
 * @return {string} The text to show.
 * @spec exclude Presentation-only formatter: renders an ISO timestamp as a locale date string.
 */
export function localDateTime(iso) {
	const parsed = Date.parse(iso ?? '')
	return Number.isNaN(parsed)
		? (iso ?? '')
		: new Date(parsed).toLocaleString()
}

/**
 * An ISO timestamp as locale date-time text, or a dash when there is none —
 * the timestamp column of the honey and SIEM screens.
 *
 * @param {string|null|undefined} iso The ISO timestamp.
 * @return {string} The text to show.
 * @spec exclude Presentation-only formatter: renders an ISO timestamp as a locale string.
 */
export function dateTimeOrDash(iso) {
	if (!iso) {
		return '—'
	}
	return new Date(iso).toLocaleString()
}

/**
 * An audit entry's ISO timestamp as locale date-time text, or nothing when
 * there is none; a value the browser cannot format is shown as it is.
 *
 * @param {string|null|undefined} iso The ISO timestamp.
 * @return {string} The text to show.
 * @spec exclude Presentation-only formatter: renders an ISO timestamp as a locale string.
 */
export function auditTime(iso) {
	if (!iso) {
		return ''
	}
	try {
		return new Date(iso).toLocaleString()
	} catch {
		return iso
	}
}
