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
