/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Nextcloud Passwords app migration parser (secret-import D3, tasks §2.6).
 *
 * Parses the Passwords app's own JSON backup export (Settings → Backup →
 * Export, format "Predefined JSON"). Migration is FILE-BASED only: the parser
 * never reads the Passwords app's DB or API, because a server-side migration
 * would decrypt credentials on the server, violating Keepiq's always-E2E
 * architecture (ADR-003). The user exports a backup from Passwords and imports
 * the file here; plaintext stays in their browser.
 *
 * Field mapping: label→name, url→url, username→login, password→key,
 * notes + customFields→additionalFields. The Passwords folder tree (folders
 * keyed by id, each with a parent reference) is resolved into slash-separated
 * folder paths, preserving hierarchy.
 */

import { joinFolderPath, makeRow, validateRow } from '../model.js'

/** The Passwords app "base" folder id (root); treated as no folder. */
const ROOT_FOLDER_ID = '00000000-0000-0000-0000-000000000000'

/**
 * Build a folderId → path-segments map from the Passwords folder list.
 *
 * @param {Array<object>} folders The Passwords folders ({ id, label, parent }).
 * @return {Map<string, Array<string>>} folderId → path segments.
 */
function buildFolderPaths(folders) {
	const byId = new Map()
	for (const folder of folders || []) {
		if (folder && folder.id != null) {
			byId.set(folder.id, folder)
		}
	}
	const cache = new Map()
	const resolve = (id, guard = 0) => {
		if (isRootFolder(id)) {
			return []
		}
		if (cache.has(id)) {
			return cache.get(id)
		}
		const folder = byId.get(id)
		if (!folder || guard > 1000) {
			return []
		}
		const path = withLabel(resolve(folder.parent, guard + 1), folder)
		cache.set(id, path)
		return path
	}
	const map = new Map()
	for (const id of byId.keys()) {
		map.set(id, resolve(id))
	}
	return map
}

/**
 * Whether a folder id names the vault root: none, or Passwords' all-zero root id.
 *
 * @param {string|null|undefined} id The folder id.
 * @return {boolean} True for the root.
 */
function isRootFolder(id) {
	return id == null || id === ROOT_FOLDER_ID
}

/**
 * A folder's path: its parent's, plus its label when it has one.
 *
 * @param {Array<string>} parentPath The parent's path segments.
 * @param {object} folder The Passwords folder.
 * @return {Array<string>} The folder's path segments.
 */
function withLabel(parentPath, folder) {
	return folder.label ? [...parentPath, String(folder.label)] : parentPath
}

/**
 * Build additionalFields from a Passwords password's notes + custom fields.
 *
 * Passwords stores customFields as a JSON string or an array of
 * `{ label, type, value }`; both shapes are normalized into named entries.
 *
 * @param {object} password The Passwords password object.
 * @return {object|null} The additional fields, or null when empty.
 */
function buildAdditional(password) {
	const additional = {}
	if (password.notes) {
		additional.notes = password.notes
	}
	addLabelledFields(additional, customFieldList(password.customFields))
	return Object.keys(additional).length > 0 ? additional : null
}

/**
 * Copy the custom fields that carry both a label and a value.
 *
 * @param {object} additional The additional fields to add to.
 * @param {Array<object>} fields The custom fields.
 * @return {void}
 */
function addLabelledFields(additional, fields) {
	for (const field of fields) {
		if (field && field.label != null && field.value != null) {
			additional[String(field.label)] = field.value
		}
	}
}

/**
 * The custom fields as a list, read from their JSON text when Passwords
 * stored them as a string; none when they are neither.
 *
 * @param {string|Array<object>|undefined} custom The stored custom fields.
 * @return {Array<object>} The fields.
 */
function customFieldList(custom) {
	let list = custom
	if (typeof custom === 'string') {
		try {
			list = JSON.parse(custom)
		} catch {
			list = null
		}
	}
	return Array.isArray(list) ? list : []
}

/**
 * Parse a Nextcloud Passwords JSON backup into normalized rows.
 *
 * @param {object|string} input The backup object or its JSON text.
 * @return {Array<object>} Normalized rows.
 * @throws {Error} When the file is not a Passwords JSON backup.
 */
export function parseNcPasswords(input) {
	const data = typeof input === 'string' ? JSON.parse(input) : input
	if (!data || !Array.isArray(data.passwords)) {
		throw new Error(
			'Not a Nextcloud Passwords JSON backup (missing passwords array)',
		)
	}

	const folderPaths = buildFolderPaths(data.folders)

	return data.passwords.map((password, index) =>
		validateRow(makeRow(passwordFields(password, folderPaths), index + 1)),
	)
}

/**
 * The row fields of one Passwords password, filed under its folder's path.
 *
 * @param {object} password The Passwords password object.
 * @param {Map<string, Array<string>>} folderPaths Each folder's path segments, by id.
 * @return {object} The row fields.
 */
function passwordFields(password, folderPaths) {
	return {
		name: password.label ?? '',
		url: password.url ?? null,
		login: password.username ?? null,
		password: password.password ?? null,
		folder: joinFolderPath(folderSegments(password, folderPaths)),
		type: 'login',
		additionalFields: buildAdditional(password),
	}
}

/**
 * The path segments of the password's folder; none at the root or for an unknown folder.
 *
 * @param {object} password The Passwords password object.
 * @param {Map<string, Array<string>>} folderPaths Each folder's path segments, by id.
 * @return {Array<string>} The segments.
 */
function folderSegments(password, folderPaths) {
	return password.folder ? folderPaths.get(password.folder) || [] : []
}

/** The Nextcloud Passwords parser descriptor. */
export const ncPasswordsParser = {
	id: 'nc-passwords',
	label: 'Nextcloud Passwords (JSON backup)',
	requiresPassphrase: false,
	adjustableMapping: false,
	/**
	 * Parse entry point matching the registry contract.
	 *
	 * @param {object|string} input The backup text or object.
	 * @return {Promise<Array<object>>} Normalized rows.
	 */
	parse: async (input) => parseNcPasswords(input),
}
