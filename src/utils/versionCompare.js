/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Field-by-field comparison of two decrypted states of one secret, for the
 * side-by-side view in the version history panel. Pure: it runs on values
 * already decrypted in the browser and sends nothing anywhere.
 *
 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/secret-version-history/spec.md#requirement-compare-two-versions-side-by-side
 */

/**
 * The built-in fields compared, in display order. `masked` fields stay hidden
 * until the owner reveals them.
 */
export const COMPARED_FIELDS = [
	{ field: 'name', masked: false },
	{ field: 'url', masked: false },
	{ field: 'login', masked: false },
	{ field: 'key', masked: true },
]

/**
 * A field value as compared and shown: '' for an absent value, else a string.
 *
 * @param {unknown} value The raw value.
 * @return {string}
 */
function plain(value) {
	if (value === null || value === undefined) {
		return ''
	}
	return typeof value === 'object' ? JSON.stringify(value) : String(value)
}

/**
 * The additional fields of a state as a flat name → value object.
 *
 * @param {object|null} state The decrypted state.
 * @return {object}
 */
function additionalOf(state) {
	const fields = state && state.additionalFields
	return fields && typeof fields === 'object' && !Array.isArray(fields) ? fields : {}
}

/**
 * Compare two decrypted states of a secret.
 *
 * Each row names its field, both values and whether they differ. A row for an
 * additional field present on only one side counts as changed, with '' on the
 * side that lacks it. Additional fields are masked like the value, since they
 * are stored encrypted for the same reason.
 *
 * @param {object} left The earlier (or left-hand) state.
 * @param {object} right The later (or right-hand) state.
 * @return {Array<{field: string, additional: boolean, masked: boolean, left: string, right: string, changed: boolean}>}
 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/secret-version-history/spec.md#requirement-compare-two-versions-side-by-side
 */
export function compareStates(left, right) {
	const rows = COMPARED_FIELDS.map(({ field, masked }) => {
		const a = plain(left && left[field])
		const b = plain(right && right[field])
		return { field, additional: false, masked, left: a, right: b, changed: a !== b }
	})

	const leftExtra = additionalOf(left)
	const rightExtra = additionalOf(right)
	const names = [...new Set([...Object.keys(leftExtra), ...Object.keys(rightExtra)])]
		.sort((x, y) => x.localeCompare(y))
	for (const name of names) {
		const a = plain(leftExtra[name])
		const b = plain(rightExtra[name])
		const presenceDiffers = Object.hasOwn(leftExtra, name) !== Object.hasOwn(rightExtra, name)
		rows.push({ field: name, additional: true, masked: true, left: a, right: b, changed: presenceDiffers || a !== b })
	}

	return rows
}

/**
 * Whether any compared field differs.
 *
 * @param {Array<{changed: boolean}>} rows The comparison rows.
 * @return {boolean}
 */
export function anyChanged(rows) {
	return rows.some((row) => row.changed === true)
}
