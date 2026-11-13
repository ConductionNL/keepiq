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
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-schemas-survive-export-and-import
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
import { mergeBlobs } from '../utils/fieldSchema.js'
import { customFieldsCredential, readCustomFields } from './cxfCustomFields.js'

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
 * Row fields of a login, password or basic-auth credential.
 *
 * @param {object} credential The CXF credential entity.
 * @param {string} title The parent item's title ('' when absent).
 * @return {object} The row fields, without folder.
 */
function loginFields(credential, title) {
	const urls = Array.isArray(credential.urls)
		? credential.urls.map(fieldValue)
		: []
	const notes = fieldValue(credential.notes)
	return {
		name: title,
		url: urls[0] ?? null,
		login: fieldValue(credential.username) || null,
		password: fieldValue(credential.password),
		type: 'login',
		additionalFields: notes ? { notes } : null,
	}
}

/**
 * Row fields of a note credential.
 *
 * @param {object} credential The CXF credential entity.
 * @param {string} title The parent item's title ('' when absent).
 * @return {object} The row fields, without folder.
 */
function noteFields(credential, title) {
	return {
		name: title !== '' ? title : 'Note',
		password: fieldValue(credential.content),
		type: 'note',
	}
}

/**
 * Row fields of an API key credential.
 *
 * @param {object} credential The CXF credential entity.
 * @param {string} title The parent item's title ('' when absent).
 * @return {object} The row fields, without folder.
 */
function apiKeyFields(credential, title) {
	return {
		name: title !== '' ? title : 'API key',
		login: fieldValue(credential.username) || null,
		password: fieldValue(credential.key),
		type: 'api_key',
	}
}

/**
 * Row fields of an SSH key credential.
 *
 * @param {object} credential The CXF credential entity.
 * @param {string} title The parent item's title ('' when absent).
 * @return {object} The row fields, without folder.
 */
function sshKeyFields(credential, title) {
	const publicKey = fieldValue(credential.publicKey)
	return {
		name: title !== '' ? title : 'SSH key',
		password: fieldValue(credential.privateKey),
		type: 'ssh_key',
		additionalFields: publicKey ? { publicKey } : null,
	}
}

/**
 * Row fields of a Wi-Fi credential. There is no dedicated Keepiq type, so it
 * maps to `note` with the SSID and security type as additional fields
 * (design decision).
 *
 * @param {object} credential The CXF credential entity.
 * @param {string} title The parent item's title ('' when absent).
 * @return {object} The row fields, without folder.
 */
function wifiFields(credential, title) {
	const ssid = fieldValue(credential.ssid)
	const security = fieldValue(credential.security)
	const fallback = ssid !== '' ? `Wi-Fi ${ssid}` : 'Wi-Fi'
	return {
		name: title !== '' ? title : fallback,
		password: fieldValue(credential.passphrase),
		type: 'note',
		additionalFields: {
			...(ssid !== '' ? { ssid } : {}),
			...(security !== '' ? { security } : {}),
		},
	}
}

/** The row fields of each supported CXF credential type. */
const CREDENTIAL_FIELDS = new Map([
	['basic-auth', loginFields],
	['password', loginFields],
	['login', loginFields],
	['note', noteFields],
	['api-key', apiKeyFields],
	['ssh-key', sshKeyFields],
	['wifi', wifiFields],
])

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
	const fields = CREDENTIAL_FIELDS.get(type)
	if (fields === undefined) {
		const row = makeRow(
			{ name: title !== '' ? title : 'Unknown credential', folder },
			sourceRow,
		)
		return rejectRow(
			row,
			`Unsupported CXF credential type "${type || 'unknown'}"`,
		)
	}
	return validateRow(makeRow({ ...fields(credential, title), folder }, sourceRow))
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
	const ctx = {
		item,
		folder,
		title: String(item.title ?? ''),
		rows: [],
		seeds: [],
		passkeys: [],
		custom: [],
	}
	for (const [index, credential] of item.credentials.entries()) {
		readCredential(ctx, { credential, sourceRow: firstRow + index })
	}
	if (
		ctx.seeds.length === 0
		&& ctx.passkeys.length === 0
		&& ctx.custom.length === 0
	) {
		return ctx.rows
	}
	attachExtras(ctx)
	return ctx.rows.sort((a, b) => a.sourceRow - b.sourceRow)
}

/**
 * Add a rejected row for one of the item's credentials.
 *
 * @param {object} ctx The item being read (itemToRows).
 * @param {{sourceRow: number, reason: string}} entry The credential's row and the reason.
 * @return {void}
 */
function rejectEntry(ctx, entry) {
	ctx.rows.push(
		rejectRow(
			makeRow({ name: ctx.title, folder: ctx.folder }, entry.sourceRow),
			entry.reason,
		),
	)
}

/**
 * Read one credential of the item: a TOTP seed, a passkey and custom fields
 * wait for the row they go on, anything else becomes a row of its own.
 *
 * @param {object} ctx The item being read (itemToRows).
 * @param {{credential: object, sourceRow: number}} entry The credential and its source row.
 * @return {void}
 */
function readCredential(ctx, entry) {
	const type = String(entry.credential?.type ?? '')
	if (type === 'totp') {
		readTotp(ctx, entry)
	} else if (type === 'passkey') {
		readPasskey(ctx, entry)
	} else if (type === 'custom-fields') {
		ctx.custom.push(readCustomFields(entry.credential))
	} else {
		ctx.rows.push(
			credentialToRow(entry.credential, ctx.item, ctx.folder, entry.sourceRow),
		)
	}
}

/**
 * Keep a usable TOTP seed for the item's login, or reject the entity.
 *
 * @param {object} ctx The item being read (itemToRows).
 * @param {{credential: object, sourceRow: number}} entry The TOTP entity and its source row.
 * @return {void}
 */
function readTotp(ctx, entry) {
	const sourceRow = entry.sourceRow
	let seed
	try {
		seed = cxfTotpKey(entry.credential)
	} catch (e) {
		rejectEntry(ctx, { sourceRow, reason: e.message })
		return
	}
	const error =
		seed === '' ? 'CXF TOTP entry without a seed' : authenticatorKeyError(seed)
	if (error === '') {
		ctx.seeds.push({ seed, sourceRow })
	} else {
		rejectEntry(ctx, { sourceRow, reason: error })
	}
}

/**
 * Keep a complete passkey for the item's login, or reject the entity.
 *
 * @param {object} ctx The item being read (itemToRows).
 * @param {{credential: object, sourceRow: number}} entry The passkey entity and its source row.
 * @return {void}
 */
function readPasskey(ctx, entry) {
	const passkey = cxfPasskey(entry.credential)
	if (passkey === null) {
		rejectEntry(ctx, {
			sourceRow: entry.sourceRow,
			reason: 'Incomplete CXF passkey (needs credentialId, rpId, and key material)',
		})
	} else {
		ctx.passkeys.push({ passkey, sourceRow: entry.sourceRow })
	}
}

/**
 * The row the item's TOTP and passkeys go on: its first accepted login,
 * else its first other accepted row, else a new login with an empty password.
 *
 * @param {object} ctx The item being read (itemToRows).
 * @return {object} The row.
 */
function extrasTarget(ctx) {
	const accepted = ctx.rows.filter((row) => row.errors.length === 0)
	const found = accepted.find((row) => row.type === 'login') ?? accepted[0]
	if (found !== undefined) {
		return found
	}
	const rpId = ctx.passkeys[0]?.passkey.rpId ?? null
	const target = validateRow(
		makeRow(
			{
				name: ctx.title !== '' ? ctx.title : (rpId ?? 'TOTP'),
				url: rpId,
				password: '',
				folder: ctx.folder,
				type: 'login',
			},
			Math.min(
				...[...ctx.seeds, ...ctx.passkeys].map((entry) => entry.sourceRow),
			),
		),
	)
	ctx.rows.push(target)
	return target
}

/**
 * Put the item's TOTP seeds, passkeys and custom fields on its target row,
 * rejecting a seed or passkey that row cannot take. Custom fields join the
 * row's own, each with its schema.
 *
 * @param {object} ctx The item being read (itemToRows).
 * @return {void}
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-schemas-survive-export-and-import
 */
function attachExtras(ctx) {
	const target = extrasTarget(ctx)
	for (const blob of ctx.custom) {
		target.additionalFields = mergeBlobs(target.additionalFields || {}, blob)
	}
	for (const { seed, sourceRow } of ctx.seeds) {
		const reason = attachTotp(target, seed)
		if (reason !== null) {
			rejectEntry(ctx, { sourceRow, reason })
		}
	}
	const clashes = attachPasskeys(
		target,
		ctx.passkeys.map(({ passkey }) => passkey),
	)
	for (const { sourceRow } of ctx.passkeys.filter(({ passkey }) =>
		clashes.includes(passkey),
	)) {
		rejectEntry(ctx, { sourceRow, reason: SECOND_PASSKEY_REASON })
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
	return [
		...ownTotpCredentials(row, unmapped),
		...ownPasskeyCredentials(row, unmapped),
	]
}

/**
 * The CXF TOTP entity for a row's own `totp`, when it has a usable one.
 *
 * @param {object} row The serialized secret.
 * @param {Array<string>} unmapped The unmapped-item report (appended to).
 * @return {Array<object>} [] or the one entity.
 */
function ownTotpCredentials(row, unmapped) {
	if (typeof row.totp !== 'string' || row.totp === '') {
		return []
	}
	const totp = totpToCredential(row.totp)
	if (totp === null) {
		unmapped.push(
			`${row.name}: stored one-time code is not an authenticator key — not exported`,
		)
		return []
	}
	return [totp]
}

/**
 * The CXF passkey entities for a row's own `passkeys`, leaving out those
 * that are not complete credentials.
 *
 * @param {object} row The serialized secret.
 * @param {Array<string>} unmapped The unmapped-item report (appended to).
 * @return {Array<object>}
 */
function ownPasskeyCredentials(row, unmapped) {
	if (typeof row.passkeys !== 'string' || row.passkeys === '') {
		return []
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
	return passkeys.map(passkeyToCredential)
}

/**
 * The CXF basic-auth entity of a login or database row.
 *
 * @param {object} row The serialized secret.
 * @return {object}
 */
function basicAuthCredential(row) {
	return {
		type: 'basic-auth',
		urls: row.url ? [row.url] : [],
		username: { fieldType: 'string', value: row.login ?? '' },
		password: {
			fieldType: 'concealed-string',
			value: row.password ?? '',
		},
	}
}

/**
 * The CXF passkey entity of a passkey row, or null when its stored
 * credential is not valid.
 *
 * @param {object} row The serialized secret.
 * @param {Array<string>} unmapped The unmapped-item report (appended to).
 * @return {object|null}
 */
function passkeyRowCredential(row, unmapped) {
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

/**
 * The CXF TOTP entity of a TOTP row, or null when it holds no usable key.
 *
 * @param {object} row The serialized secret.
 * @param {Array<string>} unmapped The unmapped-item report (appended to).
 * @return {object|null}
 */
function totpRowCredential(row, unmapped) {
	const credential = totpToCredential(row.password ?? '')
	if (credential === null) {
		unmapped.push(
			`${row.name}: stored one-time code is not an authenticator key — not exported`,
		)
	}
	return credential
}

/**
 * The CXF note entity of a note row.
 *
 * @param {object} row The serialized secret.
 * @return {object}
 */
function noteCredential(row) {
	return { type: 'note', content: row.password ?? '' }
}

/**
 * The CXF API key entity of an API key row.
 *
 * @param {object} row The serialized secret.
 * @return {object}
 */
function apiKeyCredential(row) {
	return {
		type: 'api-key',
		username: { fieldType: 'string', value: row.login ?? '' },
		key: { fieldType: 'concealed-string', value: row.password ?? '' },
	}
}

/**
 * The CXF SSH key entity of an SSH key row.
 *
 * @param {object} row The serialized secret.
 * @return {object}
 */
function sshKeyCredential(row) {
	const fields = row.additionalFields
	const publicKey =
		fields && typeof fields === 'object' ? (fields.publicKey ?? '') : ''
	return {
		type: 'ssh-key',
		privateKey: {
			fieldType: 'concealed-string',
			value: row.password ?? '',
		},
		publicKey: { fieldType: 'string', value: publicKey },
	}
}

/**
 * A certificate row: it has no CXF entity.
 *
 * @param {object} row The serialized secret.
 * @param {Array<string>} unmapped The unmapped-item report (appended to).
 * @return {null}
 */
function certificateCredential(row, unmapped) {
	unmapped.push(
		`${row.name}: certificate secrets have no CXF entity — not exported`,
	)
	return null
}

/** The CXF entity builder of each Keepiq type name. */
const ROW_CREDENTIALS = new Map([
	['login', basicAuthCredential],
	['database', basicAuthCredential],
	['passkey', passkeyRowCredential],
	['totp', totpRowCredential],
	['note', noteCredential],
	['api_key', apiKeyCredential],
	['ssh_key', sshKeyCredential],
	['certificate', certificateCredential],
])

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
	const build = ROW_CREDENTIALS.get(typeName)
	if (build === undefined) {
		unmapped.push(
			`${row.name}: type "${typeName}" has no CXF entity — not exported`,
		)
		return null
	}
	return build(row, unmapped)
}

/** The Keepiq type names a row's `type` can carry as they are. */
const TYPE_NAMES = new Set([
	'login',
	'api_key',
	'ssh_key',
	'certificate',
	'note',
	'database',
	'totp',
	'passkey',
])

/**
 * Build a CXF document from the client-decrypted serialized vault.
 *
 * @param {Array<object>} serializedSecrets The serializer.js secret rows.
 * @param {object} [options] Options.
 * @param {Map<string,string>|object} [options.typeNamesById] typeId → type
 *   name map, used when a row's `type` carries an id rather than a name.
 * @param {string} [options.accountUserName] The exporting account's user name.
 * @return {{document: object, unmapped: Array<string>, itemCount: number}}
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-schemas-survive-export-and-import
 */
export function buildCxfDocument(serializedSecrets, options = {}) {
	const unmapped = []
	const items = []
	const collections = new Map()

	for (const [index, row] of serializedSecrets.entries()) {
		const credential = rowToCredential(row, typeNameOf(row, options), unmapped)
		if (credential === null) {
			continue
		}

		const itemId = `item-${index + 1}`
		// The SSH key entity carries the public key itself.
		const custom = customFieldsCredential(
			row.additionalFields,
			credential.type === 'ssh-key' ? ['publicKey'] : [],
		)
		items.push({
			id: itemId,
			title: row.name ?? '',
			credentials: [
				credential,
				...valueFieldCredentials(row, unmapped),
				...(custom === null ? [] : [custom]),
			],
		})
		addToCollection(collections, { folder: row.folder, itemId })
	}

	return {
		document: cxfDocument({ items, collections, options }),
		unmapped,
		itemCount: items.length,
	}
}

/**
 * The Keepiq type name of a row: its `type` when that is a type name, else
 * the name `options.typeNamesById` gives that id, else the id itself.
 *
 * @param {object} row The serialized secret.
 * @param {object} options The buildCxfDocument options.
 * @return {string}
 */
function typeNameOf(row, options) {
	const type = String(row.type ?? 'login')
	if (TYPE_NAMES.has(type)) {
		return type
	}
	const mapped =
		options.typeNamesById instanceof Map
			? options.typeNamesById.get(type)
			: (options.typeNamesById || {})[type]
	return mapped ?? type
}

/**
 * Add an exported item to the collection of its folder, opening the
 * collection on its first item.
 *
 * @param {Map<string, object>} collections Folder path to collection.
 * @param {{folder: (string|null|undefined), itemId: string}} entry The item's folder and id.
 * @return {void}
 */
function addToCollection(collections, entry) {
	const folder = entry.folder != null ? String(entry.folder) : ''
	if (folder === '') {
		return
	}
	if (!collections.has(folder)) {
		collections.set(folder, {
			id: `collection-${collections.size + 1}`,
			title: folder,
			items: [],
		})
	}
	collections.get(folder).items.push(entry.itemId)
}

/**
 * The CXF document of one account holding the exported items.
 *
 * @param {{items: Array<object>, collections: Map<string, object>, options: object}} parts The items, their collections and the options.
 * @return {object}
 */
function cxfDocument(parts) {
	return {
		version: CXF_VERSION,
		exporter: 'keepiq',
		timestamp: Math.floor(Date.now() / 1000),
		accounts: [
			{
				id: 'account-1',
				userName: parts.options.accountUserName ?? '',
				email: '',
				items: parts.items,
				collections: [...parts.collections.values()],
			},
		],
	}
}
