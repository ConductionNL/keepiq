/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * FIDO Credential Exchange Format (CXF) mapping module
 * (cxf-import-export D3). This single module owns the bidirectional
 * CXF-entity ↔ Doriath-secret-type table and the document structure
 * validation, so a revision of the (still-stabilising) standard touches
 * only this file. Everything runs client-side; a CXF document is
 * PLAINTEXT — the encryption in the FIDO stack lives in CXP, not CXF.
 *
 * Import direction: `cxfToRows()` produces the same normalized row shape
 * every import parser produces, so mapping preview, folder mapping,
 * duplicate detection, chunked encrypted commit, rejected rows, and the
 * summary all apply unchanged. Unrepresentable entries land in the
 * rejected list with a reason — never silently dropped (D4).
 *
 * Export direction: `buildCxfDocument()` consumes the client-decrypted
 * serialized vault rows and reports every value with no CXF home in an
 * unmapped-item list shown before download (D4).
 *
 * @spec openspec/specs/cxf-import-export/spec.md#requirement-cxf-entity-to-doriath-type-mapping
 * @spec openspec/specs/cxf-import-export/spec.md#requirement-unmapped-item-report
 */

import { makeRow, validateRow, rejectRow } from '../import/model.js'
import { serializePasskey, parsePasskey } from '../passkey/passkey.js'

/** The CXF version this module targets (Proposed Standard, Aug 2025 line). */
export const CXF_VERSION = { major: 1, minor: 0 }

/**
 * Unwrap a CXF editable field: either a bare value or `{ value }`.
 *
 * @param {*} field The CXF field.
 * @return {string} The string value ('' when absent).
 */
function fieldValue(field) {
	if (field == null) {
		return ''
	}
	if (typeof field === 'object' && 'value' in field) {
		return field.value == null ? '' : String(field.value)
	}
	return String(field)
}

/**
 * Validate and parse a CXF document from text or object.
 *
 * Strict at the boundary (decision under uncertainty): anything that does
 * not carry the expected `accounts[].items[].credentials[]` skeleton fails
 * here with a format-specific error, reusing secret-import's
 * "file does not match selected format" behaviour — never a silent
 * mis-mapping.
 *
 * @param {string|object} input The document text or parsed object.
 * @return {object} The parsed CXF document.
 * @throws {Error} When the input is not a CXF document.
 */
export function parseCxfDocument(input) {
	let doc
	try {
		doc = typeof input === 'string' ? JSON.parse(input) : input
	} catch {
		throw new Error('Not a CXF document (invalid JSON)')
	}
	if (!doc || typeof doc !== 'object' || !Array.isArray(doc.accounts)) {
		throw new Error('Not a CXF document (missing accounts array)')
	}
	for (const account of doc.accounts) {
		if (!account || !Array.isArray(account.items)) {
			throw new Error('Not a CXF document (account without items array)')
		}
	}
	return doc
}

/**
 * Resolve the folder path for an item from account-level collections.
 *
 * @param {object} account The CXF account.
 * @param {string} itemId The item id.
 * @return {string} The collection title ('' when uncollected).
 */
function collectionOf(account, itemId) {
	const collections = Array.isArray(account.collections) ? account.collections : []
	for (const collection of collections) {
		const ids = Array.isArray(collection.items) ? collection.items : []
		if (itemId != null && ids.includes(itemId)) {
			return collection.title != null ? String(collection.title) : ''
		}
	}
	return ''
}

/**
 * Map one CXF credential to a normalized import row (or a rejected row).
 *
 * @param {object} credential The CXF credential entity.
 * @param {object} item The parent CXF item.
 * @param {string} folder The resolved folder path.
 * @param {number} sourceRow The source row number.
 * @return {object|null} The row, or null for credential types that are
 *   merged elsewhere (none currently).
 */
function credentialToRow(credential, item, folder, sourceRow) {
	const title = item.title != null ? String(item.title) : ''
	const type = String(credential?.type ?? '')

	switch (type) {
		case 'basic-auth':
		case 'password':
		case 'login': {
			const urls = Array.isArray(credential.urls)
				? credential.urls.map(fieldValue)
				: []
			return validateRow(
				makeRow(
					{
						name: title,
						url: urls[0] ?? null,
						login: fieldValue(credential.username) || null,
						password: fieldValue(credential.password),
						folder,
						type: 'login',
						additionalFields: fieldValue(credential.notes)
							? { notes: fieldValue(credential.notes) }
							: null,
					},
					sourceRow,
				),
			)
		}
		case 'passkey': {
			const json = serializePasskey({
				credentialId: fieldValue(credential.credentialId),
				rpId: fieldValue(credential.rpId),
				rpName: fieldValue(credential.rpName),
				userName: fieldValue(credential.userName),
				userDisplayName: fieldValue(credential.userDisplayName),
				userHandle: fieldValue(credential.userHandle),
				privateKey: fieldValue(credential.key),
			})
			const row = makeRow(
				{
					name: title !== '' ? `${title} (passkey)` : 'Passkey',
					url: fieldValue(credential.rpId) || null,
					password: json ?? '',
					folder,
					type: 'passkey',
				},
				sourceRow,
			)
			if (json === null) {
				return rejectRow(
					row,
					'Incomplete CXF passkey (needs credentialId, rpId, and key material)',
				)
			}
			return validateRow(row)
		}
		case 'totp': {
			const seed = fieldValue(credential.url) || fieldValue(credential.secret)
			const row = makeRow(
				{
					name: title !== '' ? `${title} (TOTP)` : 'TOTP',
					url: null,
					password: seed,
					folder,
					type: 'totp',
				},
				sourceRow,
			)
			if (seed === '') {
				return rejectRow(row, 'CXF TOTP entry without a seed')
			}
			return validateRow(row)
		}
		case 'note':
			return validateRow(
				makeRow(
					{
						name: title !== '' ? title : 'Note',
						password: fieldValue(credential.content),
						folder,
						type: 'note',
					},
					sourceRow,
				),
			)
		case 'api-key':
			return validateRow(
				makeRow(
					{
						name: title !== '' ? title : 'API key',
						login: fieldValue(credential.username) || null,
						password: fieldValue(credential.key),
						folder,
						type: 'api_key',
					},
					sourceRow,
				),
			)
		case 'ssh-key':
			return validateRow(
				makeRow(
					{
						name: title !== '' ? title : 'SSH key',
						password: fieldValue(credential.privateKey),
						folder,
						type: 'ssh_key',
						additionalFields: fieldValue(credential.publicKey)
							? { publicKey: fieldValue(credential.publicKey) }
							: null,
					},
					sourceRow,
				),
			)
		case 'wifi': {
			// No dedicated Doriath type — map to `note` with the SSID and
			// security type as additional fields (design decision).
			const ssid = fieldValue(credential.ssid)
			return validateRow(
				makeRow(
					{
						name:
							title !== ''
								? title
								: ssid !== ''
									? `Wi-Fi ${ssid}`
									: 'Wi-Fi',
						password: fieldValue(credential.passphrase),
						folder,
						type: 'note',
						additionalFields: {
							...(ssid !== '' ? { ssid } : {}),
							...(fieldValue(credential.security) !== ''
								? { security: fieldValue(credential.security) }
								: {}),
						},
					},
					sourceRow,
				),
			)
		}
		default: {
			const row = makeRow(
				{ name: title !== '' ? title : 'Unknown credential', folder },
				sourceRow,
			)
			return rejectRow(
				row,
				`Unsupported CXF credential type "${type || 'unknown'}"`,
			)
		}
	}
}

/**
 * Convert a parsed CXF document to normalized import rows.
 *
 * @param {object} doc The parsed CXF document.
 * @return {Array<object>} Normalized rows (accepted + rejected-with-reason).
 */
export function cxfToRows(doc) {
	const rows = []
	let sourceRow = 0
	for (const account of doc.accounts) {
		for (const item of account.items) {
			const folder = collectionOf(account, item.id)
			const credentials = Array.isArray(item.credentials)
				? item.credentials
				: []
			if (credentials.length === 0) {
				sourceRow += 1
				rows.push(
					rejectRow(
						makeRow({ name: item.title ?? '', folder }, sourceRow),
						'CXF item carries no credentials',
					),
				)
				continue
			}
			for (const credential of credentials) {
				sourceRow += 1
				const row = credentialToRow(credential, item, folder, sourceRow)
				if (row !== null) {
					rows.push(row)
				}
			}
		}
	}
	return rows
}

/**
 * Map one serialized vault row to a CXF credential entity.
 *
 * @param {object} row The serialized secret ({ name, url, type, login,
 *   password, additionalFields, folder }).
 * @param {string} typeName The resolved Doriath type name.
 * @param {Array<string>} unmapped The unmapped-item report (appended to).
 * @return {object|null} The CXF credential, or null when unrepresentable.
 */
function rowToCredential(row, typeName, unmapped) {
	switch (typeName) {
		case 'login':
		case 'database':
			return {
				type: 'basic-auth',
				urls: row.url ? [row.url] : [],
				username: { fieldType: 'string', value: row.login ?? '' },
				password: {
					fieldType: 'concealed-string',
					value: row.password ?? '',
				},
			}
		case 'passkey': {
			const credential = parsePasskey(row.password ?? '')
			if (credential === null) {
				unmapped.push(
					`${row.name}: stored passkey credential is not valid canonical JSON`,
				)
				return null
			}
			// `counter`/`transports`/`createdAt` are Doriath extensions with no
			// CXF-core home — dropped on export, reported (design D4).
			if (credential.counter !== 0 || credential.transports.length > 0) {
				unmapped.push(
					`${row.name}: passkey counter/transports are Doriath extensions and do not survive CXF export`,
				)
			}
			return {
				type: 'passkey',
				credentialId: credential.credentialId,
				rpId: credential.rpId,
				rpName: credential.rpName,
				userName: credential.userName,
				userDisplayName: credential.userDisplayName,
				userHandle: credential.userHandle,
				key: credential.privateKey,
			}
		}
		case 'totp':
			return { type: 'totp', url: row.password ?? '' }
		case 'note':
			return { type: 'note', content: row.password ?? '' }
		case 'api_key':
			return {
				type: 'api-key',
				username: { fieldType: 'string', value: row.login ?? '' },
				key: { fieldType: 'concealed-string', value: row.password ?? '' },
			}
		case 'ssh_key':
			return {
				type: 'ssh-key',
				privateKey: {
					fieldType: 'concealed-string',
					value: row.password ?? '',
				},
				publicKey: {
					fieldType: 'string',
					value:
						row.additionalFields
						&& typeof row.additionalFields === 'object'
							? (row.additionalFields.publicKey ?? '')
							: '',
				},
			}
		case 'certificate':
			unmapped.push(
				`${row.name}: certificate secrets have no CXF entity — not exported`,
			)
			return null
		default:
			unmapped.push(
				`${row.name}: type "${typeName}" has no CXF entity — not exported`,
			)
			return null
	}
}

/**
 * Build a CXF document from the client-decrypted serialized vault.
 *
 * @param {Array<object>} serializedSecrets The serializer.js secret rows.
 * @param {object} [options] Options.
 * @param {Map<string,string>|object} [options.typeNamesById] typeId → type
 *   name map, used when a row's `type` carries an id rather than a name.
 * @param {string} [options.accountUserName] The exporting account's user name.
 * @return {{document: object, unmapped: Array<string>, itemCount: number}}
 */
export function buildCxfDocument(serializedSecrets, options = {}) {
	const unmapped = []
	const items = []
	const collections = new Map()
	const typeNames = new Set([
		'login',
		'api_key',
		'ssh_key',
		'certificate',
		'note',
		'database',
		'totp',
		'passkey',
	])

	for (const [index, row] of serializedSecrets.entries()) {
		let typeName = String(row.type ?? 'login')
		if (!typeNames.has(typeName)) {
			const mapped =
				options.typeNamesById instanceof Map
					? options.typeNamesById.get(typeName)
					: (options.typeNamesById || {})[typeName]
			typeName = mapped ?? typeName
		}

		const credential = rowToCredential(row, typeName, unmapped)
		if (credential === null) {
			continue
		}

		const itemId = `item-${index + 1}`
		items.push({
			id: itemId,
			title: row.name ?? '',
			credentials: [credential],
		})

		const folder = row.folder != null ? String(row.folder) : ''
		if (folder !== '') {
			if (!collections.has(folder)) {
				collections.set(folder, {
					id: `collection-${collections.size + 1}`,
					title: folder,
					items: [],
				})
			}
			collections.get(folder).items.push(itemId)
		}
	}

	return {
		document: {
			version: CXF_VERSION,
			exporter: 'doriath',
			timestamp: Math.floor(Date.now() / 1000),
			accounts: [
				{
					id: 'account-1',
					userName: options.accountUserName ?? '',
					email: '',
					items,
					collections: [...collections.values()],
				},
			],
		},
		unmapped,
		itemCount: items.length,
	}
}
