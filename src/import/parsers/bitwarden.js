/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Bitwarden import parser (secret-import D1, tasks §2.4).
 *
 * Handles both Bitwarden export shapes:
 *  - JSON export: `{ folders: [{id,name}], items: [...] }`. Login items become
 *    rows (name, url, login, password); the TOTP seed (`login.totp`) and the
 *    passkeys (`login.fido2Credentials[]`) go on the login's own `totp` and
 *    `passkeys` fields (keepiq#1327). Secure notes are rejected with a reason.
 *    Folder + collection names map onto the folder path.
 *  - CSV export: delegates to the generic CSV parser with Bitwarden's fixed
 *    header layout, so the round-trip CSV engine is reused (no second parser).
 *
 * All parsing is in-browser; plaintext never leaves the page.
 */

import { serializeCard, serializeIdentity } from '../../cardIdentity/cardIdentity.js'
import { buildPasskeyCredential } from '../../passkey/passkey.js'
import {
	attachPasskeys,
	attachTotp,
	keepAsAdditionalField,
	SECOND_PASSKEY_REASON,
} from '../loginValueFields.js'
import { joinFolderPath, makeRow, rejectRow, validateRow } from '../model.js'
import { parseCsvImport } from './csv.js'

/** Bitwarden CSV column → target-field mapping (fixed). */
const BITWARDEN_CSV_MAPPING = [
	{ column: 'name', target: 'name' },
	{ column: 'login_uri', target: 'url' },
	{ column: 'login_username', target: 'login' },
	{ column: 'login_password', target: 'password' },
	{ column: 'notes', target: 'notes' },
	{ column: 'login_totp', target: 'totp' },
	{ column: 'folder', target: 'folder' },
	{ column: 'type', target: 'type' },
]

/** The row builder of each Bitwarden item type that is not a login: 3 card, 4 identity. */
const ROW_BUILDERS = {
	3: buildCardRow,
	4: buildIdentityRow,
}

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
	addCustomFields(additional, item.fields)
	return Object.keys(additional).length > 0 ? additional : null
}

/**
 * Copy the custom fields that carry both a name and a value.
 *
 * @param {object} additional The additional fields to add to.
 * @param {Array<object>|undefined} fields The item's custom fields.
 * @return {void}
 */
function addCustomFields(additional, fields) {
	if (!Array.isArray(fields)) {
		return
	}
	for (const field of fields) {
		if (field && field.name != null && field.value != null) {
			additional[String(field.name)] = field.value
		}
	}
}

/**
 * Parse a Bitwarden JSON export into normalized rows.
 *
 * @param {object|string} input The export object or its JSON text.
 * @return {Array<object>} Normalized rows (login items) + rejected rows
 *   (non-login item types).
 * @throws {Error} When the input is not an unencrypted Bitwarden JSON export.
 */
export function parseBitwardenJson(input) {
	const data = readExport(input)
	const ctx = {
		folderNames: namesById(data.folders),
		collectionNames: namesById(data.collections),
		// Rejected passkey rows are numbered after the last item.
		state: { nextSourceRow: data.items.length },
	}
	const rows = []
	for (const [index, item] of data.items.entries()) {
		rows.push(...itemRows({ item, sourceRow: index + 1 }, ctx))
	}

	return rows
}

/**
 * The export as data, refused when it is encrypted or holds no items list.
 *
 * @param {object|string} input The parsed JSON, or its text.
 * @return {object} The export.
 * @throws {Error} When the export is encrypted or not a Bitwarden JSON export.
 */
function readExport(input) {
	const data = typeof input === 'string' ? JSON.parse(input) : input
	// Encrypted exports (account-restricted or password-protected) hold
	// cipher strings in place of names and values.
	if (data && data.encrypted) {
		throw new Error(
			'Encrypted Bitwarden exports cannot be imported. Export the vault again as unencrypted JSON and import that file.',
		)
	}
	if (!data || !Array.isArray(data.items)) {
		throw new Error('Not a Bitwarden JSON export (missing items array)')
	}
	return data
}

/**
 * Map the entries that have an id onto their name ('' when unnamed).
 *
 * @param {Array<object>|undefined} list Bitwarden folders or collections.
 * @return {Map<string,string>} id → name.
 */
function namesById(list) {
	const names = new Map()
	for (const entry of list || []) {
		if (entry && entry.id != null) {
			names.set(entry.id, entry.name ?? '')
		}
	}
	return names
}

/**
 * The rows for one Bitwarden item.
 *
 * Bitwarden item types: 1=login, 2=secureNote, 3=card, 4=identity.
 * Card + identity route into their composite payload types with the JSON
 * in `password`, encrypted in the browser at commit, exactly like passkeys
 * (card-identity-items §5.1).
 *
 * @param {{item: object, sourceRow: number}} entry The item and its 1-based row.
 * @param {object} ctx The folder and collection names and the row counter.
 * @return {Array<object>} The item's rows.
 */
function itemRows(entry, ctx) {
	const { item, sourceRow } = entry
	const build = ROW_BUILDERS[item.type]
	if (build) {
		return [build(item, sourceRow)]
	}
	if (item.type !== 1 && item.type !== undefined) {
		return [unsupportedRow(item, sourceRow)]
	}
	return loginRows(entry, ctx)
}

/**
 * A row refusing an item type that does not import.
 *
 * @param {object} item The Bitwarden item.
 * @param {number} sourceRow The source-row number.
 * @return {object} The rejected row.
 */
function unsupportedRow(item, sourceRow) {
	const row = makeRow({ name: item.name ?? '' }, sourceRow)
	const kind = { 2: 'secure note' }[item.type] ?? 'non-login'
	row.errors.push(
		`Unsupported Bitwarden item type (${kind}); only login items import`,
	)
	return row
}

/**
 * The fields of a Bitwarden login item.
 *
 * @param {object} item The Bitwarden item.
 * @param {object} ctx The folder and collection names.
 * @return {object} The row fields.
 */
function loginFields(item, ctx) {
	const login = item.login || {}
	return {
		name: item.name ?? '',
		url: loginUrl(login),
		login: login.username ?? null,
		password: login.password ?? null,
		folder: resolveFolder(item, ctx.folderNames, ctx.collectionNames),
		type: 'login',
		additionalFields: buildAdditional(item),
	}
}

/**
 * The login's address: its own `uri`, else the first of its `uris`.
 *
 * @param {object} login The Bitwarden login.
 * @return {string|null} The URL.
 */
function loginUrl(login) {
	const uris = Array.isArray(login.uris) ? login.uris : []
	return login.uri ?? (uris[0] && uris[0].uri) ?? null
}

/**
 * The login row, followed by a rejected row per passkey that did not import.
 *
 * @param {{item: object, sourceRow: number}} entry The item and its 1-based row.
 * @param {object} ctx The folder and collection names and the row counter.
 * @return {Array<object>} The login row and its rejected passkey rows.
 */
function loginRows(entry, ctx) {
	const { item, sourceRow } = entry
	const login = item.login || {}
	const row = makeRow(loginFields(item, ctx), sourceRow)
	// A seed that is no authenticator key (a Steam code, HOTP) stays an
	// additional field.
	if (attachTotp(row, login.totp) !== null) {
		keepAsAdditionalField(row, 'totp', login.totp)
	}

	// A partial passkey entry is rejected on a row of its own, never
	// imported partially (passkey-item-type D5); the login and its
	// complete passkeys still import.
	const rejected = []
	const reject = (reason) => {
		ctx.state.nextSourceRow += 1
		rejected.push(
			rejectRow(
				makeRow({ name: item.name ?? '' }, ctx.state.nextSourceRow),
				reason,
			),
		)
	}
	const passkeys = completePasskeys(login, reject)
	attachPasskeys(row, passkeys).forEach(() => reject(SECOND_PASSKEY_REASON))
	// A passkey-only login is found by its site, as a passkey item was.
	if (row.url === null && passkeys.length > 0) {
		row.url = passkeys[0].rpId
	}
	return [validateRow(row), ...rejected]
}

/**
 * The complete passkeys of a login; each incomplete entry goes to `reject`.
 *
 * @param {object} login The Bitwarden item's login.
 * @param {(reason: string) => void} reject Files a rejected row.
 * @return {Array<object>} The canonical credentials.
 */
function completePasskeys(login, reject) {
	const fido2 = Array.isArray(login.fido2Credentials) ? login.fido2Credentials : []
	const passkeys = []
	for (const entry of fido2) {
		const credential = passkeyFromFido2(entry)
		if (credential === null) {
			reject(
				'Incomplete Bitwarden passkey (needs credentialId, rpId, and key material)',
			)
		} else {
			passkeys.push(credential)
		}
	}
	return passkeys
}

/** The passkey fields read as text from a Bitwarden fido2 credential, by the name Bitwarden gives them. */
const FIDO2_TEXT_FIELDS = {
	credentialId: 'credentialId',
	rpId: 'rpId',
	rpName: 'rpName',
	userName: 'userName',
	userDisplayName: 'userDisplayName',
	userHandle: 'userHandle',
	privateKey: 'keyValue',
	createdAt: 'creationDate',
}

/**
 * Map one Bitwarden `fido2Credentials[]` entry onto the canonical passkey
 * schema (`keyValue` → `privateKey`, `creationDate` → `createdAt`).
 *
 * @param {object} entry The fido2Credentials entry.
 * @return {object|null} The canonical credential, or null when the entry
 *   lacks a credential id, RP id or key material.
 */
function passkeyFromFido2(entry) {
	return buildPasskeyCredential({
		...textsOf(entry, FIDO2_TEXT_FIELDS),
		counter: Number.parseInt(entry?.counter, 10) || 0,
	})
}

/**
 * The named fields of a source object, '' for each one it lacks.
 *
 * @param {object|null|undefined} source The source object.
 * @param {Object<string, string>} names Each target field, by the source field it is read from.
 * @return {Object<string, unknown>} The fields.
 */
function textsOf(source, names) {
	return Object.fromEntries(
		Object.entries(names).map(([target, from]) => [target, source?.[from] ?? '']),
	)
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
	const row = makeRow(
		{
			name: item.name ?? 'Payment card',
			password: serializeCard(cardPayload(card)),
			folder: '',
			type: 'card',
		},
		sourceRow,
	)
	if ((card.number ?? '') === '') {
		return rejectRow(row, 'Bitwarden card item without a card number')
	}
	return validateRow(row)
}

/**
 * The card payload of a Bitwarden card: its number, MM/YY expiry, code and
 * cardholder; Bitwarden has no PIN.
 *
 * @param {object} card The Bitwarden card.
 * @return {object} The payload to serialize.
 */
function cardPayload(card) {
	return {
		...textsOf(card, { number: 'number', cvv: 'code', cardholder: 'cardholderName' }),
		expiry: cardExpiry(card),
		pin: '',
	}
}

/**
 * The card's expiry as MM/YY, '' unless both month and year are given.
 *
 * @param {object} card The Bitwarden card.
 * @return {string} The expiry.
 */
function cardExpiry(card) {
	if (card.expMonth == null || card.expYear == null) {
		return ''
	}
	return `${String(card.expMonth).padStart(2, '0')}/${String(card.expYear).slice(-2)}`
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
	const row = makeRow(
		{
			name: item.name ?? 'Identity',
			password: serializeIdentity(identityPayload(identity)),
			folder: '',
			type: 'identity',
		},
		sourceRow,
	)
	if (!hasIdentityFields(identity)) {
		return rejectRow(row, 'Bitwarden identity item with no usable fields')
	}
	return validateRow(row)
}

/** The identity fields read as text from a Bitwarden identity; its `ssn` fills the Dutch `bsn` slot. */
const IDENTITY_TEXT_FIELDS = {
	firstName: 'firstName',
	lastName: 'lastName',
	phone: 'phone',
	email: 'email',
	bsn: 'ssn',
}

/**
 * The identity payload of a Bitwarden identity, its address parts joined.
 *
 * @param {object} identity The Bitwarden identity.
 * @return {object} The payload to serialize.
 */
function identityPayload(identity) {
	const address = [
		identity.address1,
		identity.address2,
		identity.city,
		identity.postalCode,
		identity.country,
	]
		.filter(Boolean)
		.join(', ')
	return { ...textsOf(identity, IDENTITY_TEXT_FIELDS), address }
}

/**
 * Whether the identity carries a name, email, phone or national number; an address alone does not count.
 *
 * @param {object} identity The Bitwarden identity.
 * @return {boolean} True when it can import.
 */
function hasIdentityFields(identity) {
	return [
		identity.firstName,
		identity.lastName,
		identity.email,
		identity.phone,
		identity.ssn,
	].some((v) => v != null && v !== '')
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
