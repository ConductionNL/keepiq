/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * The scope an export dialog hands to the serializer, built from what the
 * user chose (portability-export-choice-and-restore-fidelity D1).
 *
 * @spec openspec/changes/portability-export-choice-and-restore-fidelity/specs/export-selection-and-restore/spec.md#requirement-choose-what-an-export-contains
 */

/**
 * The kinds of scope a user can pick.
 *
 * @type {ReadonlyArray<string>}
 */
export const SCOPE_KINDS = Object.freeze(['vault', 'folders', 'types', 'selection'])

/**
 * Build the serializer scope.
 *
 * Folders and types filter on the ids chosen; none chosen is the whole vault,
 * never an empty file by accident. A selection exports exactly the selected
 * items, and an empty selection exports nothing, as the serializer reads it.
 *
 * @param {object} choice What the user chose.
 * @param {string} choice.kind One of SCOPE_KINDS.
 * @param {Array<string>} [choice.folderIds] The chosen folders.
 * @param {Array<string>} [choice.typeIds] The chosen types.
 * @param {Array<string>} [choice.secretIds] The selected items.
 * @return {object} `{mode: 'vault'}`, `{folderIds}`, `{typeIds}` or `{mode: 'selection', secretIds}`.
 * @spec openspec/changes/portability-export-choice-and-restore-fidelity/specs/export-selection-and-restore/spec.md#requirement-choose-what-an-export-contains
 */
export function buildScope(choice) {
	const ids = (list) => (Array.isArray(list) ? list.filter((id) => id !== null && id !== undefined && id !== '').map(String) : [])
	const kind = choice?.kind ?? 'vault'
	if (kind === 'selection') {
		return { mode: 'selection', secretIds: ids(choice.secretIds) }
	}
	if (kind === 'folders' && ids(choice.folderIds).length > 0) {
		return { folderIds: ids(choice.folderIds) }
	}
	if (kind === 'types' && ids(choice.typeIds).length > 0) {
		return { typeIds: ids(choice.typeIds) }
	}
	return { mode: 'vault' }
}

/**
 * The serializer options for the chosen fields to leave out.
 *
 * @param {Array<object>} types The vault's types, so the file carries type names.
 * @param {Array<string>} omitFields The fields to leave out.
 * @return {{types: Array<object>, omitFields: Array<string>}} The options.
 * @spec openspec/changes/portability-export-choice-and-restore-fidelity/specs/export-selection-and-restore/spec.md#requirement-choose-what-an-export-contains
 */
export function serializerOptions(types, omitFields) {
	return {
		types: Array.isArray(types) ? types : [],
		omitFields: Array.isArray(omitFields) ? [...omitFields] : [],
	}
}
