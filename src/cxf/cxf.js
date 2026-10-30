/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * FIDO Credential Exchange Format (CXF) mapping module
 * (cxf-import-export D3). This single module owns the bidirectional
 * CXF-entity ↔ Keepiq-secret-type table and the document structure
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
 * @spec openspec/specs/cxf-import-export/spec.md#requirement-cxf-entity-to-keepiq-type-mapping
 * @spec openspec/specs/cxf-import-export/spec.md#requirement-unmapped-item-report
 */

import {
	attachPasskeys,
	attachTotp,
	SECOND_PASSKEY_REASON,
} from '../import/loginValueFields.js'
import { makeRow, rejectRow, validateRow } from '../import/model.js'
import { passkeyEntries } from '../passkey/loginPasskeys.js'
import { buildPasskeyCredential, parsePasskey } from '../passkey/passkey.js'
import {
	authenticatorKeyError,
	authenticatorKeyFromParts,
} from '../totp/authenticatorKey.js'
import { parseOtpauth } from '../totp/totp.js'

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
 * The canonical passkey credential of a CXF passkey entity. CXF 1.0 names
 * the account `username`; earlier Keepiq exports wrote `userName`.
 *
 * @param {object} credential The CXF passkey entity.
 * @return {object|null} The credential, or null when it lacks a credential
 *   id, RP id or key material.
 */
function cxfPasskey(credential) {
	return buildPasskeyCredential({
		credentialId: fieldValue(credential.credentialId),
		rpId: fieldValue(credential.rpId),
		rpName: fieldValue(credential.rpName),
		userName: fieldValue(credential.username) || fieldValue(credential.userName),
		userDisplayName: fieldValue(credential.userDisplayName),
		userHandle: fieldValue(credential.userHandle),
		privateKey: fieldValue(credential.key),
	})
}

/**
 * The key to store for a CXF TOTP entity. CXF 1.0 holds a base32 `secret`
 * with `period`, `digits` and `algorithm`; earlier Keepiq exports wrote the
 * stored key as `url`.
 *
 * @param {object} credential The CXF TOTP entity.
 * @return {string} The key, '' when the entity carries no seed.
 * @throws {Error} When the code generator cannot use its parameters, such
 *   as an unknown algorithm (CXF: importers MUST ignore those).
 */
function cxfTotpKey(credential) {
	const url = fieldValue(credential.url)
	if (url !== '') {
		return url
	}
	return authenticatorKeyFromParts({
		secret: fieldValue(credential.secret),
		period: fieldValue(credential.period),
		digits: fieldValue(credential.digits),
		algorithm: fieldValue(credential.algorithm),
		issuer: fieldValue(credential.issuer),
		account: fieldValue(credential.username),
	})
}

/**
 * The CXF 1.0 TOTP entity for a stored key.
 *
 * @param {string} key An otpauth URI or base32 secret.
 * @return {object|null} The entity, or null when the key does not parse.
 */
function totpToCredential(key) {
	let parsed
	try {
		parsed = parseOtpauth(key)
	} catch {
		return null
	}
	const credential = {
		type: 'totp',
		secret: parsed.secret,
		period: parsed.period,
		digits: parsed.digits,
		algorithm: parsed.algorithm.toLowerCase(),
	}
	if (parsed.account) {
		credential.username = parsed.account
	}
	if (parsed.issuer) {
		credential.issuer = parsed.issuer
	}
	return credential
}

/**
 * Map one CXF credential that is not a TOTP or a passkey to a normalized
 * import row (or a rejected row).
 *
 * @param {object} credential The CXF credential entity.
 * @param {object} item The parent CXF item.
 * @param {string} folder The resolved folder path.
 * @param {number} sourceRow The source row number.
 * @return {object} The row.
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
			// No dedicated Keepiq type — map to `note` with the SSID and
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
 * Map one CXF item to normalized rows. Every credential is read first; then
 * the item's TOTP and passkeys go on its first accepted login (keepiq#1327),
 * else on its first other accepted row (a note or key that carried them),
 * else on a new login with an empty password, whatever the credential order.
 * They never go on a rejected row. The first seed wins: an identical seed is
 * the same code, a different or unusable one is rejected with a reason; a
 * passkey clashing with one already on the row (same credential id and RP
 * id, another key) is rejected too.
 *
 * @param {object} item The CXF item.
 * @param {string} folder The resolved folder path.
 * @param {number} firstRow The source row number of the item's first credential.
 * @return {Array<object>} The item's rows, in source order.
 */
function itemToRows(item, folder, firstRow) {
	const title = String(item.title ?? '')
	const rows = []
	const seeds = []
	const passkeys = []
	const reject = (sourceRow, reason) =>
		rows.push(rejectRow(makeRow({ name: title, folder }, sourceRow), reason))

	for (const [index, credential] of item.credentials.entries()) {
		const sourceRow = firstRow + index
		const type = String(credential?.type ?? '')
		if (type === 'totp') {
			let seed
			try {
				seed = cxfTotpKey(credential)
			} catch (e) {
				reject(sourceRow, e.message)
				continue
			}
			const error =
				seed === ''
					? 'CXF TOTP entry without a seed'
					: authenticatorKeyError(seed)
			if (error === '') {
				seeds.push({ seed, sourceRow })
			} else {
				reject(sourceRow, error)
			}
		} else if (type === 'passkey') {
			const passkey = cxfPasskey(credential)
			if (passkey === null) {
				reject(
					sourceRow,
					'Incomplete CXF passkey (needs credentialId, rpId, and key material)',
				)
			} else {
				passkeys.push({ passkey, sourceRow })
			}
		} else {
			rows.push(credentialToRow(credential, item, folder, sourceRow))
		}
	}

	if (seeds.length === 0 && passkeys.length === 0) {
		return rows
	}
	const accepted = rows.filter((row) => row.errors.length === 0)
	let target = accepted.find((row) => row.type === 'login') ?? accepted[0]
	if (target === undefined) {
		const rpId = passkeys[0]?.passkey.rpId ?? null
		target = validateRow(
			makeRow(
				{
					name: title !== '' ? title : (rpId ?? 'TOTP'),
					url: rpId,
					password: '',
					folder,
					type: 'login',
				},
				Math.min(...[...seeds, ...passkeys].map((entry) => entry.sourceRow)),
			),
		)
		rows.push(target)
	}
	for (const { seed, sourceRow } of seeds) {
		const reason = attachTotp(target, seed)
		if (reason !== null) {
			reject(sourceRow, reason)
		}
	}
	const clashes = attachPasskeys(
		target,
		passkeys.map(({ passkey }) => passkey),
	)
	for (const { sourceRow } of passkeys.filter(({ passkey }) =>
		clashes.includes(passkey),
	)) {
		reject(sourceRow, SECOND_PASSKEY_REASON)
	}
	return rows.sort((a, b) => a.sourceRow - b.sourceRow)
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
			rows.push(...itemToRows({ ...item, credentials }, folder, sourceRow + 1))
			sourceRow += credentials.length
		}
	}
	return rows
}

/**
 * Report, once per secret, that its passkeys' `counter` and `transports`
 * are Keepiq extensions with no CXF-core home and are dropped (design D4).
 *
 * @param {Array<object>} credentials The secret's canonical credentials.
 * @param {string} name The secret's name.
 * @param {Array<string>} unmapped The unmapped-item report (appended to).
 * @return {void}
 */
function reportPasskeyExtensions(credentials, name, unmapped) {
	if (
		credentials.some(
			(credential) =>
				credential.counter !== 0 || credential.transports.length > 0,
		)
	) {
		unmapped.push(
			`${name}: passkey counter/transports are Keepiq extensions and do not survive CXF export`,
		)
	}
}

/**
 * The CXF passkey entity for a canonical credential.
 *
 * @param {object} credential The canonical credential.
 * @return {object} The CXF credential.
 */
function passkeyToCredential(credential) {
	return {
		type: 'passkey',
		credentialId: credential.credentialId,
		rpId: credential.rpId,
		rpName: credential.rpName,
		username: credential.userName,
		userDisplayName: credential.userDisplayName,
		userHandle: credential.userHandle,
		key: credential.privateKey,
	}
}

/**
 * The CXF credentials for a row's own `totp` and `passkeys` fields. They go
 * in the same CXF item as the row's main credential, so an import puts them
 * back on the same login.
 *
 * @param {object} row The serialized secret.
 * @param {Array<string>} unmapped The unmapped-item report (appended to).
 * @return {Array<object>} The CXF credentials ([] when the row has neither).
 */
function valueFieldCredentials(row, unmapped) {
	const credentials = []
	if (typeof row.totp === 'string' && row.totp !== '') {
		const totp = totpToCredential(row.totp)
		if (totp === null) {
			unmapped.push(
				`${row.name}: stored one-time code is not an authenticator key — not exported`,
			)
		} else {
			credentials.push(totp)
		}
	}
	if (typeof row.passkeys !== 'string' || row.passkeys === '') {
		return credentials
	}
	const entries = passkeyEntries(row.passkeys)
	if (entries.length === 0) {
		unmapped.push(
			`${row.name}: stored passkeys could not be read — not exported`,
		)
	}
	const passkeys = []
	for (const { credential: stored } of entries) {
		const credential = buildPasskeyCredential(stored)
		if (credential === null) {
			unmapped.push(
				`${row.name}: a stored passkey is not a complete credential — not exported`,
			)
		} else {
			passkeys.push(credential)
		}
	}
	reportPasskeyExtensions(passkeys, row.name, unmapped)
	return [...credentials, ...passkeys.map(passkeyToCredential)]
}

/**
 * Map one serialized vault row to a CXF credential entity.
 *
 * @param {object} row The serialized secret ({ name, url, type, login,
 *   password, additionalFields, folder }).
 * @param {string} typeName The resolved Keepiq type name.
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
			reportPasskeyExtensions([credential], row.name, unmapped)
			return passkeyToCredential(credential)
		}
		case 'totp': {
			const credential = totpToCredential(row.password ?? '')
			if (credential === null) {
				unmapped.push(
					`${row.name}: stored one-time code is not an authenticator key — not exported`,
				)
			}
			return credential
		}
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
			credentials: [credential, ...valueFieldCredentials(row, unmapped)],
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
			exporter: 'keepiq',
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
