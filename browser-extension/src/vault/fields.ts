/** The item form's model: per-type fields, the payloads they serialize to, and the limits Keepiq enforces. */
import type { EditableItem, ItemDraft, ItemPatch } from '@/src/messages'
import { parseTotpSeed, type TotpAlgorithm } from '@/src/totp/totp'
import { parseObject, text } from './payloads'

/** The server's `name` column (Keepiq `item-name-limit`). */
export const NAME_MAX = 255
/** The Keepiq import limits (`ImportService::MAX_FIELD_LENGTH`, `MAX_BLOB_LENGTH`). */
export const FIELD_MAX = 4096
export const BLOB_MAX_BYTES = 65536

/** Reserved by the server (ADR-003), plus `notes`, which holds the Notes field. */
const RESERVED = new Set(['key', 'login', 'url', 'notes'])
const NOTES = 'notes'

/** Member order of the web app's `src/cardIdentity/cardIdentity.js`, so it renders what is written here. */
export const CARD_FIELDS = ['number', 'expiry', 'cvv', 'pin', 'cardholder'] as const
export const IDENTITY_FIELDS = ['firstName', 'lastName', 'address', 'phone', 'email', 'bsn'] as const
export type CardField = typeof CARD_FIELDS[number]
export type IdentityField = typeof IDENTITY_FIELDS[number]

/** `credential` is a username plus a secret: logins, keys, databases and custom types. */
export type FormKind = 'credential' | 'note' | 'totp' | 'card' | 'identity' | 'passkey'

export function formKind(typeName: string | undefined): FormKind {
	switch (typeName) {
		case 'note':
		case 'totp':
		case 'card':
		case 'identity':
		case 'passkey':
			return typeName
		default:
			return 'credential'
	}
}

/** Secrets that span lines. */
export const isMultiline = (typeName: string | undefined) => typeName === 'ssh_key' || typeName === 'certificate'

export interface TotpOptions {
	algorithm: TotpAlgorithm
	digits: 6 | 8
	period: number
}

export interface CustomField {
	/** Stable across renames, for React keys and error paths. */
	id: number
	name: string
	value: string
}

export interface FormValues {
	typeId: string
	name: string
	url: string
	folderId: string | null
	login: string
	/** A credential's or note's `key`, or a TOTP seed as typed. */
	secret: string
	/** Only the advanced TOTP options the user changed; any of them rewrites the seed as a URI. */
	totp: Partial<TotpOptions>
	card: Record<CardField, string>
	identity: Record<IdentityField, string>
	notes: string
	/** The member the notes came from, so an edit writes them back under the same name. */
	notesKey: string | null
	fields: CustomField[]
}

const blank = <K extends string>(keys: readonly K[]) => Object.fromEntries(keys.map((key) => [key, ''])) as Record<K, string>

let nextFieldId = 0
export const customField = (name = '', value = ''): CustomField => ({ id: ++nextFieldId, name, value })

export function emptyValues(typeId: string, url = '', folderId: string | null = null): FormValues {
	return {
		typeId, name: '', url, folderId, login: '', secret: '', totp: {},
		card: blank(CARD_FIELDS), identity: blank(IDENTITY_FIELDS), notes: '', notesKey: null, fields: [],
	}
}

function members<K extends string>(raw: string, keys: readonly K[]): Record<K, string> {
	const payload = parseObject(raw) ?? {}
	return Object.fromEntries(keys.map((key) => [key, text(payload, key)])) as Record<K, string>
}

export function fromItem(item: EditableItem): FormValues {
	const values = emptyValues(item.typeId, item.url ?? '', item.folderId)
	values.name = item.name
	values.login = item.login ?? ''
	values.secret = item.key
	values.card = members(item.key, CARD_FIELDS)
	values.identity = members(item.key, IDENTITY_FIELDS)
	for (const [name, value] of Object.entries(item.additionalFields)) {
		if (values.notesKey === null && name.toLowerCase() === NOTES) {
			values.notes = value
			values.notesKey = name
		} else {
			values.fields.push(customField(name, value))
		}
	}
	return values
}

function serialize<K extends string>(values: Record<K, string>, keys: readonly K[]): string {
	return JSON.stringify(Object.fromEntries(keys.map((key) => [key, values[key]])))
}

/** A bare seed stays as typed; changed options are written into an `otpauth://totp/` URI, labelled with the item name. */
export function totpKey(seed: string, options: Partial<TotpOptions>, label: string): string {
	if (Object.keys(options).length === 0) return seed
	const value = seed.trim()
	const params = { ...parseTotpSeed(value), ...options }
	const uri = /^otpauth:\/\//i.test(value) ? new URL(value) : new URL(`otpauth://totp/${encodeURIComponent(label)}`)
	uri.searchParams.set('secret', params.secret)
	uri.searchParams.set('algorithm', params.algorithm)
	uri.searchParams.set('digits', String(params.digits))
	uri.searchParams.set('period', String(params.period))
	return uri.toString()
}

function keyOf(values: FormValues, kind: FormKind): string {
	switch (kind) {
		case 'card': return serialize(values.card, CARD_FIELDS)
		case 'identity': return serialize(values.identity, IDENTITY_FIELDS)
		case 'passkey': return values.secret
		case 'totp':
			try {
				return totpKey(values.secret, values.totp, values.name)
			} catch {
				// Refused by `validate` before anything is sent.
				return values.secret
			}
		default: return values.secret
	}
}

export function toDraft(values: FormValues, typeName: string | undefined): ItemDraft {
	const kind = formKind(typeName)
	const entries = values.fields.filter((field) => field.name.trim()).map((field): [string, string] => [field.name.trim(), field.value])
	// A note's text is its `key`; notes it came with are kept, not offered.
	if (values.notes !== '' && (kind !== 'note' || values.notesKey !== null)) entries.push([values.notesKey ?? NOTES, values.notes])
	// `fromEntries` defines each name, so a field called `__proto__` is kept rather than setting the prototype.
	const additionalFields: Record<string, string> = Object.fromEntries(entries)
	return {
		typeId: values.typeId,
		name: values.name,
		url: values.url.trim() || null,
		folderId: values.folderId,
		key: keyOf(values, kind),
		login: kind === 'credential' && values.login !== '' ? values.login : null,
		additionalFields,
	}
}

function sameRecord(a: Record<string, string>, b: Record<string, string>): boolean {
	const keys = Object.keys(a)
	return keys.length === Object.keys(b).length && keys.every((key) => Object.hasOwn(b, key) && a[key] === b[key])
}

/** What changed from `before` to `after`; both come from `toDraft`, so formatting alone never counts as a change. */
export function diffDraft(before: ItemDraft, after: ItemDraft): ItemPatch {
	const patch: ItemPatch = {}
	if (before.typeId !== after.typeId) patch.typeId = after.typeId
	if (before.name !== after.name) patch.name = after.name
	if (before.url !== after.url) patch.url = after.url
	if (before.folderId !== after.folderId) patch.folderId = after.folderId
	if (before.key !== after.key) patch.key = after.key
	if (before.login !== after.login) patch.login = after.login
	if (!sameRecord(before.additionalFields, after.additionalFields)) patch.additionalFields = after.additionalFields
	return patch
}

export type FieldError =
	| { code: 'required' | 'reserved' | 'duplicate' | 'invalidTotp' }
	| { code: 'tooLong' | 'tooLongBytes'; max: number }

/**
 * Keyed by field: `name`, `url`, `login`, `secret`, `notes`, `card.<member>`, `identity.<member>`,
 * `field.<id>.name`, `field.<id>.value`, and `additionalFields` when the blob is too big without notes.
 */
export type FormErrors = Record<string, FieldError>

const length = (value: string) => [...value].length
const bytes = (value: string) => new TextEncoder().encode(value).length

function capChars(errors: FormErrors, path: string, value: string, max = FIELD_MAX) {
	if (length(value) > max) errors[path] = { code: 'tooLong', max }
}

export function validate(values: FormValues, typeName: string | undefined): FormErrors {
	const kind = formKind(typeName)
	const errors: FormErrors = {}
	if (!values.name.trim()) errors.name = { code: 'required' }
	else capChars(errors, 'name', values.name, NAME_MAX)
	capChars(errors, 'url', values.url)
	if (kind === 'passkey') return errors

	if (kind === 'credential') capChars(errors, 'login', values.login)
	if (kind === 'card') for (const member of CARD_FIELDS) capChars(errors, `card.${member}`, values.card[member])
	if (kind === 'identity') for (const member of IDENTITY_FIELDS) capChars(errors, `identity.${member}`, values.identity[member])
	if (kind === 'totp') {
		try {
			parseTotpSeed(values.secret)
		} catch {
			errors.secret = { code: 'invalidTotp' }
		}
	}
	const draft = toDraft(values, typeName)
	if (!errors.secret && bytes(draft.key) > BLOB_MAX_BYTES) errors.secret = { code: 'tooLongBytes', max: BLOB_MAX_BYTES }

	const seen = new Set<string>()
	for (const field of values.fields) {
		const name = field.name.trim()
		// A row left empty is dropped, not refused.
		if (!name && !field.value) continue
		const folded = name.toLowerCase()
		if (!name) errors[`field.${field.id}.name`] = { code: 'required' }
		else if (RESERVED.has(folded)) errors[`field.${field.id}.name`] = { code: 'reserved' }
		else if (seen.has(folded)) errors[`field.${field.id}.name`] = { code: 'duplicate' }
		seen.add(folded)
		capChars(errors, `field.${field.id}.value`, field.value)
	}
	if (bytes(JSON.stringify(draft.additionalFields)) > BLOB_MAX_BYTES) {
		errors[values.notes ? 'notes' : 'additionalFields'] = { code: 'tooLongBytes', max: BLOB_MAX_BYTES }
	}
	return errors
}

/** Errors shown while typing; the rest wait for Save, as in Bitwarden. */
export const isLiveError = (error: FieldError) => error.code === 'reserved' || error.code === 'duplicate'
