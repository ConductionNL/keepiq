/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Bitwarden import parser (secret-import D1, tasks §2.4).
 *
 * Handles both Bitwarden export shapes:
 *  - JSON export: `{ folders: [{id,name}], items: [...] }`. Login items become
 *    rows (name, url, login, password); the TOTP seed (`login.totp`) is kept as
 *    an opaque additionalFields.totp so a future TOTP feature needs no re-import
 *    (design Open Question — decision: keep). Non-login item types (card,
 *    identity, secureNote) are rejected with a reason. Folder + collection names
 *    map onto the folder path.
 *  - CSV export: delegates to the generic CSV parser with Bitwarden's fixed
 *    header layout, so the round-trip CSV engine is reused (no second parser).
 *
 * All parsing is in-browser; plaintext never leaves the page.
 */

import { makeRow, validateRow, joinFolderPath } from '../model.js'
import { parseCsvImport } from './csv.js'

/** Bitwarden CSV column → target-field mapping (fixed). */
const BITWARDEN_CSV_MAPPING = [
	{ column: 'name', target: 'name' },
	{ column: 'login_uri', target: 'url' },
	{ column: 'login_username', target: 'login' },
	{ column: 'login_password', target: 'password' },
	{ column: 'notes', target: 'notes' },
	{ column: 'login_totp', target: 'additional:totp' },
	{ column: 'folder', target: 'folder' },
	{ column: 'type', target: 'type' },
]

/**
 * Resolve a Bitwarden item's folder path from folderId + collectionIds.
 *
 * @param {object} item The Bitwarden item.
 * @param {Map<string,string>} folderNames folderId → name.
 * @param {Map<string,string>} collectionNames collectionId → name.
 * @return {string} The slash-joined folder path ('' for none).
 */
function resolveFolder(item, folderNames, collectionNames) {
	if (item.folderId && folderNames.has(item.folderId)) {
		// Bitwarden folder names already carry '/' for nesting; keep as-is.
		return folderNames.get(item.folderId)
	}
	const collections = Array.isArray(item.collectionIds) ? item.collectionIds : []
	for (const id of collections) {
		if (collectionNames.has(id)) {
			return collectionNames.get(id)
		}
	}
	return ''
}

/**
 * Build the additionalFields object for a Bitwarden login item.
 *
 * @param {object} item The Bitwarden item.
 * @return {object|null} The additional fields, or null when empty.
 */
function buildAdditional(item) {
	const additional = {}
	if (item.notes) {
		additional.notes = item.notes
	}
	if (item.login && item.login.totp) {
		additional.totp = item.login.totp
	}
	if (Array.isArray(item.fields)) {
		for (const field of item.fields) {
			if (field && field.name != null && field.value != null) {
				additional[String(field.name)] = field.value
			}
		}
	}
	return Object.keys(additional).length > 0 ? additional : null
}

/**
 * Parse a Bitwarden JSON export into normalized rows.
 *
 * @param {object|string} input The export object or its JSON text.
 * @return {Array<object>} Normalized rows (login items) + rejected rows
 *   (non-login item types).
 */
export function parseBitwardenJson(input) {
	const data = typeof input === 'string' ? JSON.parse(input) : input
	if (!data || !Array.isArray(data.items)) {
		throw new Error('Not a Bitwarden JSON export (missing items array)')
	}

	const folderNames = new Map()
	for (const folder of (data.folders || [])) {
		if (folder && folder.id != null) {
			folderNames.set(folder.id, folder.name ?? '')
		}
	}
	const collectionNames = new Map()
	for (const collection of (data.collections || [])) {
		if (collection && collection.id != null) {
			collectionNames.set(collection.id, collection.name ?? '')
		}
	}

	return data.items.map((item, index) => {
		const sourceRow = index + 1
		// Bitwarden item types: 1=login, 2=secureNote, 3=card, 4=identity.
		if (item.type !== 1 && item.type !== undefined) {
			const row = makeRow({ name: item.name ?? '' }, sourceRow)
			const kind = { 2: 'secure note', 3: 'card', 4: 'identity' }[item.type] ?? 'non-login'
			row.errors.push(`Unsupported Bitwarden item type (${kind}); only login items import`)
			return row
		}
		const login = item.login || {}
		const uris = Array.isArray(login.uris) ? login.uris : []
		const url = login.uri ?? (uris[0] && uris[0].uri) ?? null
		const fields = {
			name: item.name ?? '',
			url,
			login: login.username ?? null,
			password: login.password ?? null,
			folder: resolveFolder(item, folderNames, collectionNames),
			type: 'login',
			additionalFields: buildAdditional(item),
		}
		return validateRow(makeRow(fields, sourceRow))
	})
}

/**
 * Parse a Bitwarden CSV export by delegating to the generic CSV parser with a
 * fixed Bitwarden header mapping.
 *
 * @param {string} csv The CSV document text.
 * @return {Array<object>} Normalized rows.
 */
export function parseBitwardenCsv(csv) {
	return parseCsvImport(csv, { mapping: BITWARDEN_CSV_MAPPING }).rows
}

/**
 * Parse a Bitwarden export (auto-detects JSON vs CSV by leading character).
 *
 * @param {string} input The export text.
 * @return {Array<object>} Normalized rows.
 */
export function parseBitwarden(input) {
	const trimmed = String(input).trimStart()
	if (trimmed.startsWith('{') || trimmed.startsWith('[')) {
		return parseBitwardenJson(input)
	}
	return parseBitwardenCsv(input)
}

/** Re-export the folder-join helper for symmetry / tests. */
export { joinFolderPath }

/** The Bitwarden parser descriptor. */
export const bitwardenParser = {
	id: 'bitwarden',
	label: 'Bitwarden (JSON or CSV export)',
	requiresPassphrase: false,
	adjustableMapping: false,
	/**
	 * Parse entry point matching the registry contract.
	 *
	 * @param {string} input The export text.
	 * @return {Promise<Array<object>>} Normalized rows.
	 */
	parse: async (input) => parseBitwarden(input),
}
