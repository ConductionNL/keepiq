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

import { makeRow, validateRow, rejectRow, joinFolderPath } from '../model.js'
import { serializePasskey } from '../../passkey/passkey.js'
import { serializeCard, serializeIdentity } from '../../cardIdentity/cardIdentity.js'
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

	const rows = []
	let nextSourceRow = data.items.length
	for (const [index, item] of data.items.entries()) {
		const sourceRow = index + 1
		// Bitwarden item types: 1=login, 2=secureNote, 3=card, 4=identity.
		// Card + identity route into their composite payload types with
		// the JSON in `password` — encrypted in the browser at commit,
		// exactly like passkeys (card-identity-items §5.1).
		if (item.type === 3) {
			rows.push(buildCardRow(item, sourceRow))
			continue
		}
		if (item.type === 4) {
			rows.push(buildIdentityRow(item, sourceRow))
			continue
		}
		if (item.type !== 1 && item.type !== undefined) {
			const row = makeRow({ name: item.name ?? '' }, sourceRow)
			const kind = { 2: 'secure note' }[item.type] ?? 'non-login'
			row.errors.push(`Unsupported Bitwarden item type (${kind}); only login items import`)
			rows.push(row)
			continue
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
		rows.push(validateRow(makeRow(fields, sourceRow)))

		// Passkeys: each `login.fido2Credentials[]` entry becomes its own
		// `passkey`-typed row carrying the canonical CXF-aligned credential
		// JSON in `password` (which becomes the encrypted `key` at commit).
		// A partial entry is rejected, never imported partially
		// (passkey-item-type D5).
		const fido2 = Array.isArray(login.fido2Credentials) ? login.fido2Credentials : []
		for (const entry of fido2) {
			nextSourceRow += 1
			rows.push(buildPasskeyRow(item, entry, nextSourceRow))
		}
	}

	return rows
}

/**
 * Build a `passkey` row from one Bitwarden `fido2Credentials[]` entry.
 *
 * Maps Bitwarden's field names onto the canonical schema (`keyValue` →
 * `privateKey`, `creationDate` → `createdAt`); an entry that cannot yield
 * at least `credentialId` + `rpId` + `privateKey` lands in the rejected
 * list with a reason (passkey-item-type D5).
 *
 * @param {object} item The parent Bitwarden login item.
 * @param {object} entry The fido2Credentials entry.
 * @param {number} sourceRow The synthetic source-row number.
 * @return {object} The normalized row (possibly carrying a rejection).
 */
function buildPasskeyRow(item, entry, sourceRow) {
	const json = serializePasskey({
		credentialId: entry?.credentialId ?? '',
		rpId: entry?.rpId ?? '',
		rpName: entry?.rpName ?? '',
		userName: entry?.userName ?? '',
		userDisplayName: entry?.userDisplayName ?? '',
		userHandle: entry?.userHandle ?? '',
		privateKey: entry?.keyValue ?? '',
		counter: Number.parseInt(entry?.counter, 10) || 0,
		createdAt: entry?.creationDate ?? '',
	})
	const row = makeRow({
		name: `${item.name ?? 'Passkey'} (passkey)`,
		url: entry?.rpId ?? null,
		password: json ?? '',
		folder: '',
		type: 'passkey',
	}, sourceRow)
	if (json === null) {
		return rejectRow(row, 'Incomplete Bitwarden passkey (needs credentialId, rpId, and key material)')
	}
	return validateRow(row)
}

/**
 * Build a `card` row from a Bitwarden card item: the composite payload
 * serializes into `password` (encrypted client-side at commit); brand is
 * deliberately NOT carried — it is derived from the number on render
 * (card-identity-items §5.1).
 *
 * @param {object} item The Bitwarden card item.
 * @param {number} sourceRow The source-row number.
 * @return {object} The normalized row.
 */
function buildCardRow(item, sourceRow) {
	const card = item.card || {}
	const expMonth = String(card.expMonth ?? '').padStart(2, '0')
	const expYear = String(card.expYear ?? '')
	const expiry = card.expMonth != null && card.expYear != null
		? `${expMonth}/${expYear.slice(-2)}`
		: ''
	const row = makeRow({
		name: item.name ?? 'Payment card',
		password: serializeCard({
			number: card.number ?? '',
			expiry,
			cvv: card.code ?? '',
			pin: '',
			cardholder: card.cardholderName ?? '',
		}),
		folder: '',
		type: 'card',
	}, sourceRow)
	if ((card.number ?? '') === '') {
		return rejectRow(row, 'Bitwarden card item without a card number')
	}
	return validateRow(row)
}

/**
 * Build an `identity` row from a Bitwarden identity item. The Bitwarden
 * `ssn` field maps onto `bsn` (the Dutch national number slot); the
 * whole payload is ciphertext at commit (card-identity-items §5.1).
 *
 * @param {object} item The Bitwarden identity item.
 * @param {number} sourceRow The source-row number.
 * @return {object} The normalized row.
 */
function buildIdentityRow(item, sourceRow) {
	const identity = item.identity || {}
	const address = [identity.address1, identity.address2, identity.city, identity.postalCode, identity.country]
		.filter(Boolean)
		.join(', ')
	const row = makeRow({
		name: item.name ?? 'Identity',
		password: serializeIdentity({
			firstName: identity.firstName ?? '',
			lastName: identity.lastName ?? '',
			address,
			phone: identity.phone ?? '',
			email: identity.email ?? '',
			bsn: identity.ssn ?? '',
		}),
		folder: '',
		type: 'identity',
	}, sourceRow)
	const hasAnything = [identity.firstName, identity.lastName, identity.email, identity.phone, identity.ssn]
		.some(v => v != null && v !== '')
	if (!hasAnything) {
		return rejectRow(row, 'Bitwarden identity item with no usable fields')
	}
	return validateRow(row)
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
