/**
 * The item form's rules: which fields a type has, how a form maps to the
 * secret's key, login and additional fields, what changed, and the limits.
 * Pure: no DOM, no network. Card, identity and passkey payloads use the web
 * app's own modules.
 *
 * @spec openspec/changes/clients-extension-complete/specs/extension-vault/spec.md#requirement-edit-every-kind-of-item
 */

import {
	CARD_FIELDS,
	IDENTITY_FIELDS,
	parsePayload,
	serializeCard,
	serializeIdentity,
} from '../../../src/cardIdentity/cardIdentity.js'

/** Longest name, address or field value, in characters (the import limits). */
export const MAX_FIELD_CHARS = 4096

/** Largest key or additional-fields payload, in UTF-8 bytes. */
export const MAX_PAYLOAD_BYTES = 65536

/** Additional-field names that would collide with the secret's own fields. */
export const RESERVED_FIELD_NAMES = Object.freeze(['key', 'login', 'url'])

/** The additional field a non-note item keeps its notes in. */
export const NOTES_FIELD = 'notes'

/**
 * How the form treats a type.
 *
 * @param {string} typeName The secret type's name.
 * @return {'login'|'note'|'totp'|'card'|'identity'|'passkey'|'generic'}
 */
export function formKind(typeName) {
	if (
		['login', 'note', 'totp', 'card', 'identity', 'passkey'].includes(typeName)
	) {
		return typeName
	}
	return 'generic'
}

/** Labels of the card and identity fields, in form order. */
export const COMPOSITE_LABELS = Object.freeze({
	number: 'Card number',
	expiry: 'Expiry (MM/YY)',
	cvv: 'CVV',
	pin: 'PIN',
	cardholder: 'Cardholder',
	firstName: 'First name',
	lastName: 'Last name',
	address: 'Address',
	phone: 'Phone',
	email: 'Email',
	bsn: 'BSN',
})

/** The card or identity fields shown masked. */
export const MASKED_COMPOSITE = Object.freeze(['number', 'cvv', 'pin', 'bsn'])

/**
 * The composite field names of a kind, or [].
 *
 * @param {string} kind The form kind.
 * @return {string[]}
 */
export function compositeFields(kind) {
	if (kind === 'card') return CARD_FIELDS
	if (kind === 'identity') return IDENTITY_FIELDS
	return []
}

/**
 * The draft the form edits, from a decrypted item (or an empty one).
 *
 * @param {object|null} item The decrypted item from the worker.
 * @param {string} typeName The item's type name.
 * @return {object}
 */
export function draftFromItem(item, typeName) {
	const kind = formKind(typeName)
	const fields =
		item?.additionalFields
		&& typeof item.additionalFields === 'object'
		&& !Array.isArray(item.additionalFields)
			? { ...item.additionalFields }
			: {}
	const notesKey = Object.keys(fields).find((k) => k.toLowerCase() === NOTES_FIELD)
	const notes =
		kind === 'note'
			? item?.secret || ''
			: notesKey
				? String(fields[notesKey])
				: ''
	if (notesKey) delete fields[notesKey]
	const composite = {}
	if (kind === 'card' || kind === 'identity') {
		Object.assign(
			composite,
			Object.fromEntries(compositeFields(kind).map((f) => [f, ''])),
		)
		const parsed = parsePayload(item?.secret || '')
		if (parsed && typeof parsed === 'object') {
			for (const f of compositeFields(kind)) {
				if (parsed[f] !== undefined) composite[f] = String(parsed[f])
			}
		}
	}
	return {
		kind,
		name: item?.name || '',
		url: item?.url || '',
		folderId: item?.folderId || null,
		login: item?.login || '',
		secret:
			kind === 'note' || kind === 'card' || kind === 'identity'
				? ''
				: item?.secret || '',
		notes,
		composite,
		fields: Object.entries(fields).map(([name, value]) => ({
			name,
			value: String(value),
		})),
	}
}

/**
 * The secret's plaintext parts from a draft.
 *
 * @param {object} draft The form's draft.
 * @return {{name: string, url: string, folderId: string|null, login: string, key: string, additionalFields: object|null}}
 */
export function partsFromDraft(draft) {
	let key = draft.secret
	if (draft.kind === 'note') key = draft.notes
	if (draft.kind === 'card') key = serializeCard(draft.composite)
	if (draft.kind === 'identity') key = serializeIdentity(draft.composite)
	const fields = {}
	for (const { name, value } of draft.fields) {
		fields[name.trim()] = value
	}
	if (draft.kind !== 'note' && draft.notes.trim() !== '') {
		fields[NOTES_FIELD] = draft.notes
	}
	return {
		name: draft.name.trim(),
		url: draft.url.trim(),
		folderId: draft.folderId || null,
		login: draft.kind === 'login' || draft.kind === 'generic' ? draft.login : '',
		key,
		additionalFields: Object.keys(fields).length > 0 ? fields : null,
	}
}

/**
 * What changed between the original and the edited parts, as a sparse
 * update: only changed fields are sent, so a field the user did not touch is
 * never rewritten from a stale form.
 *
 * @param {object} before The parts the form opened with.
 * @param {object} after The parts on Save.
 * @return {object} The changed parts only.
 */
export function changedParts(before, after) {
	const changes = {}
	for (const field of ['name', 'url', 'folderId', 'login', 'key']) {
		if ((before[field] ?? '') !== (after[field] ?? ''))
			changes[field] = after[field]
	}
	if (
		JSON.stringify(before.additionalFields ?? null)
		!== JSON.stringify(after.additionalFields ?? null)
	) {
		changes.additionalFields = after.additionalFields
	}
	return changes
}

/**
 * UTF-8 byte length.
 *
 * @param {string} text The text.
 * @return {number}
 */
function bytes(text) {
	return new TextEncoder().encode(text).length
}

/**
 * Why the draft cannot be saved, by field, or {} when it can.
 *
 * @param {object} draft The form's draft.
 * @return {Record<string, string>}
 */
export function validateDraft(draft) {
	const errors = {}
	if (draft.name.trim() === '') errors.name = 'Give the item a name'
	if (draft.name.length > MAX_FIELD_CHARS)
		errors.name = `At most ${MAX_FIELD_CHARS} characters`
	if (draft.url.length > MAX_FIELD_CHARS)
		errors.url = `At most ${MAX_FIELD_CHARS} characters`
	if (draft.login.length > MAX_FIELD_CHARS)
		errors.login = `At most ${MAX_FIELD_CHARS} characters`
	const seen = new Set()
	draft.fields.forEach(({ name, value }, i) => {
		const clean = name.trim().toLowerCase()
		if (clean === '') errors['field-' + i] = 'Give the field a name'
		else if (RESERVED_FIELD_NAMES.includes(clean) || clean === NOTES_FIELD)
			errors['field-' + i] = 'This name is reserved'
		else if (seen.has(clean))
			errors['field-' + i] = 'Another field has this name'
		else if (value.length > MAX_FIELD_CHARS)
			errors['field-' + i] = `At most ${MAX_FIELD_CHARS} characters`
		seen.add(clean)
	})
	const parts = partsFromDraft(draft)
	if (bytes(parts.key) > MAX_PAYLOAD_BYTES)
		errors.secret = 'This value is too long to save'
	if (
		parts.additionalFields
		&& bytes(JSON.stringify(parts.additionalFields)) > MAX_PAYLOAD_BYTES
	) {
		errors.fields = 'The additional fields are too long to save'
	}
	return errors
}

/**
 * A folder's path, "Work / Clients", or "No folder".
 *
 * @param {Array<{id: string, name: string, parentId?: string|null}>} folders The folders.
 * @param {string|null} folderId The folder.
 * @return {string}
 */
export function folderPath(folders, folderId) {
	const byId = new Map(folders.map((f) => [f.id, f]))
	const names = []
	const seen = new Set()
	let current = folderId ? byId.get(folderId) : null
	while (current && !seen.has(current.id)) {
		seen.add(current.id)
		names.unshift(current.name)
		current = current.parentId ? byId.get(current.parentId) : null
	}
	return names.length > 0 ? names.join(' / ') : 'No folder'
}

/**
 * A server write failure in words the user can act on.
 *
 * @param {{status?: number, body?: string, message?: string}} error The failure.
 * @return {string}
 */
export function writeErrorMessage(error) {
	if (error?.status === 423)
		return 'Vault is temporarily locked for a key migration, try again later'
	if (error?.status === 403)
		return 'Your encryption suite is blocked, open Keepiq to resolve it'
	if (error?.status === 400 || error?.status === 409) {
		try {
			const message = JSON.parse(error.body || '{}').message
			if (message) return String(message)
		} catch {
			// Fall through to the generic text.
		}
		return 'The server refused this change'
	}
	if (!error?.status) return 'Could not reach the server'
	return error.message || 'Saving failed'
}
