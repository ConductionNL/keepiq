/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What a custom field holds, as a JSON Schema property: a type, a format for
 * strings, limits, and Keepiq's own `hidden` switch. The names follow
 * OpenRegister's property editor so a field means the same in both apps.
 *
 * The schemas live in the encrypted additional-fields blob under the reserved
 * member `@schema`, a map from field name to schema. Every value stays a
 * string; a field without a schema is plain text.
 */

import { translate as t } from '@nextcloud/l10n'

/** The reserved member holding the schemas. */
export const SCHEMA_MEMBER = '@schema'

/** The types a field can have, in the order Advanced offers them. */
export const SCHEMA_TYPES = Object.freeze([
	'string',
	'integer',
	'number',
	'boolean',
	'object',
])

/** The formats a string can have, in the order Advanced offers them. */
export const STRING_FORMATS = Object.freeze([
	'text',
	'password',
	'uri',
	'email',
	'hostname',
	'ipv4',
	'ipv6',
	'date',
	'date-time',
	'time',
	'uuid',
	'color-hex',
])

/** The keys a schema may carry; anything else is dropped. */
const SCHEMA_KEYS = Object.freeze([
	'type',
	'format',
	'minLength',
	'maxLength',
	'pattern',
	'minimum',
	'maximum',
	'multipleOf',
	'hidden',
])

/** The schema a field without one has. */
const PLAIN = Object.freeze({ type: 'string' })

/** The presets by id, each with the schema it sets. */
const PRESET_SCHEMAS = Object.freeze({
	text: { type: 'string' },
	multiline: { type: 'string', format: 'text' },
	password: { type: 'string', hidden: true },
	url: { type: 'string', format: 'uri' },
	email: { type: 'string', format: 'email' },
	number: { type: 'integer' },
	boolean: { type: 'boolean' },
	date: { type: 'string', format: 'date' },
	json: { type: 'object' },
})

/** The checks a string format must pass, where it has one. */
const FORMAT_PATTERNS = Object.freeze({
	email: /^[^\s@]+@[^\s@]+\.[^\s@]+$/,
	hostname:
		/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/i,
	ipv4: /^(?:(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)\.){3}(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)$/,
	ipv6: /^[0-9a-f:]+(?:%[\w.]+)?$/i,
	date: /^\d{4}-\d{2}-\d{2}$/,
	'date-time':
		/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:\d{2})?$/,
	time: /^\d{2}:\d{2}(?::\d{2})?$/,
	uuid: /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i,
	'color-hex': /^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i,
})

/**
 * The presets in the order the select offers them, with translated labels.
 *
 * @return {Array<{id: string, label: string}>} The presets.
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-presets-pick-a-schema-advanced-shows-it
 */
export function presetOptions() {
	return [
		{ id: 'text', label: t('keepiq', 'Text') },
		{ id: 'multiline', label: t('keepiq', 'Multi-line text') },
		{ id: 'password', label: t('keepiq', 'Password') },
		{ id: 'url', label: t('keepiq', 'URL') },
		{ id: 'email', label: t('keepiq', 'Email') },
		{ id: 'number', label: t('keepiq', 'Number') },
		{ id: 'boolean', label: t('keepiq', 'Yes/no') },
		{ id: 'date', label: t('keepiq', 'Date') },
		{ id: 'json', label: t('keepiq', 'JSON object') },
	]
}

/**
 * The schema a preset sets.
 *
 * @param {string} id The preset id.
 * @return {object} A copy of its schema; plain text for an unknown id.
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-presets-pick-a-schema-advanced-shows-it
 */
export function presetSchema(id) {
	return { ...(PRESET_SCHEMAS[id] ?? PLAIN) }
}

/**
 * Whether a value is a finite number or a numeric string.
 *
 * @param {string|number|boolean|null|undefined} value The value.
 * @return {boolean} True for a usable number.
 */
function isNumeric(value) {
	return (
		value !== ''
		&& value !== null
		&& value !== true
		&& value !== false
		&& Number.isFinite(Number(value))
	)
}

/**
 * Keep the one schema key `key` when it is usable for the schema's type.
 *
 * @param {string} key The key.
 * @param {string|number|boolean} value Its value.
 * @param {string} type The schema's type.
 * @return {boolean} True to keep it.
 */
function keeps(key, value, type) {
	if (key === 'hidden') {
		return value === true
	}
	if (['format', 'minLength', 'maxLength', 'pattern'].includes(key)) {
		if (type !== 'string') {
			return false
		}
		if (key === 'format') {
			return STRING_FORMATS.includes(value)
		}
		if (key === 'pattern') {
			return typeof value === 'string' && value !== ''
		}
		return isNumeric(value) && Number(value) >= 0
	}
	if (key === 'multipleOf') {
		return type === 'number' && isNumeric(value) && Number(value) > 0
	}
	return ['integer', 'number'].includes(type) && isNumeric(value)
}

/**
 * A schema with only the keys that make sense for its type, numbers as
 * numbers, and `string` as the type when it is missing or unknown.
 *
 * @param {object|null|undefined} schema The schema.
 * @return {object} The clean schema.
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-custom-fields-carry-a-schema
 */
export function cleanSchema(schema) {
	const type = SCHEMA_TYPES.includes(schema?.type) ? schema.type : 'string'
	const clean = { type }
	for (const key of SCHEMA_KEYS) {
		if (key === 'type' || schema?.[key] === undefined) {
			continue
		}
		if (keeps(key, schema[key], type)) {
			clean[key] = ['format', 'pattern', 'hidden'].includes(key)
				? schema[key]
				: Number(schema[key])
		}
	}
	return clean
}

/**
 * Whether two schemas say the same once cleaned.
 *
 * @param {object} a A schema.
 * @param {object} b Another schema.
 * @return {boolean} True when they match.
 */
function sameSchema(a, b) {
	const left = cleanSchema(a)
	const right = cleanSchema(b)
	const keys = new Set([...Object.keys(left), ...Object.keys(right)])
	return [...keys].every((key) => left[key] === right[key])
}

/**
 * The preset a schema matches, or `custom`.
 *
 * @param {object|null|undefined} schema The schema.
 * @return {string} The preset id, or `custom`.
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-presets-pick-a-schema-advanced-shows-it
 */
export function presetOf(schema) {
	const match = Object.entries(PRESET_SCHEMAS).find(([, preset]) =>
		sameSchema(schema, preset),
	)
	return match ? match[0] : 'custom'
}

/**
 * Whether a schema is plain text, which is what a field without one reads as.
 *
 * @param {object|null|undefined} schema The schema.
 * @return {boolean} True for plain text.
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-custom-fields-carry-a-schema
 */
export function isPlainSchema(schema) {
	return sameSchema(schema, PLAIN)
}

/**
 * Whether a field starts masked: the `hidden` switch, or OpenRegister's
 * `password` format.
 *
 * @param {object|null|undefined} schema The schema.
 * @return {boolean} True when the value is masked until revealed.
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-any-custom-field-can-be-hidden
 */
export function isHiddenSchema(schema) {
	return schema?.hidden === true || schema?.format === 'password'
}

/**
 * How a field is entered and shown: one of `multiline`, `url`, `email`,
 * `integer`, `number`, `boolean`, `date`, `datetime`, `time`, `json` or `text`.
 *
 * @param {object|null|undefined} schema The schema.
 * @return {string} The input.
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-each-schema-has-its-own-input-and-display
 */
export function inputOf(schema) {
	const clean = cleanSchema(schema)
	if (clean.type === 'object') {
		return 'json'
	}
	if (clean.type !== 'string') {
		return clean.type
	}
	const byFormat = {
		text: 'multiline',
		uri: 'url',
		email: 'email',
		date: 'date',
		'date-time': 'datetime',
		time: 'time',
	}
	return byFormat[clean.format] ?? 'text'
}

/**
 * Why a JSON object value does not fit, or ''.
 *
 * @param {string} value The value.
 * @return {string} The reason.
 */
function objectError(value) {
	let parsed
	try {
		parsed = JSON.parse(value)
	} catch {
		return t('keepiq', 'Enter valid JSON.')
	}
	return parsed !== null && typeof parsed === 'object' && !Array.isArray(parsed)
		? ''
		: t('keepiq', 'Enter a JSON object.')
}

/**
 * Why a number value does not fit, or ''.
 *
 * @param {string} value The value.
 * @param {object} schema The clean schema.
 * @return {string} The reason.
 */
function numberError(value, schema) {
	if (schema.type === 'integer' && !/^-?\d+$/.test(value.trim())) {
		return t('keepiq', 'Enter a whole number.')
	}
	if (!isNumeric(value.trim())) {
		return t('keepiq', 'Enter a number.')
	}
	const number = Number(value)
	if (schema.minimum !== undefined && number < schema.minimum) {
		return t('keepiq', 'Enter at least {min}.', { min: schema.minimum })
	}
	if (schema.maximum !== undefined && number > schema.maximum) {
		return t('keepiq', 'Enter at most {max}.', { max: schema.maximum })
	}
	if (
		schema.multipleOf !== undefined
		&& Math.abs(
			number / schema.multipleOf - Math.round(number / schema.multipleOf),
		) > 1e-9
	) {
		return t('keepiq', 'Enter a multiple of {step}.', {
			step: schema.multipleOf,
		})
	}
	return ''
}

/**
 * Whether a value is a URL with a scheme.
 *
 * @param {string} value The value.
 * @return {boolean} True for a URL.
 */
function isUrl(value) {
	try {
		return /^[a-z][a-z0-9+.-]*:/i.test(value) && Boolean(new URL(value))
	} catch {
		return false
	}
}

/**
 * Why a string value does not fit, or ''.
 *
 * @param {string} value The value.
 * @param {object} schema The clean schema.
 * @return {string} The reason.
 */
function stringError(value, schema) {
	const length = [...value].length
	if (schema.minLength !== undefined && length < schema.minLength) {
		return t('keepiq', 'Use at least {min} characters.', {
			min: schema.minLength,
		})
	}
	if (schema.maxLength !== undefined && length > schema.maxLength) {
		return t('keepiq', 'Use at most {max} characters.', {
			max: schema.maxLength,
		})
	}
	if (schema.pattern !== undefined) {
		let fits = true
		try {
			fits = new RegExp(schema.pattern, 'u').test(value)
		} catch {
			// An invalid pattern cannot refuse anything.
		}
		if (!fits) {
			return t('keepiq', 'This does not match the required pattern.')
		}
	}
	const formatFits =
		schema.format === 'uri'
			? isUrl(value)
			: (FORMAT_PATTERNS[schema.format]?.test(value) ?? true)
	return formatFits
		? ''
		: t('keepiq', 'Enter a valid {format} value.', { format: schema.format })
}

/**
 * Why a value does not fit its schema, or '' when it does. An empty value
 * always fits: whether a field must be filled is a separate rule.
 *
 * @param {string} value The value as stored.
 * @param {object|null|undefined} schema The field's schema.
 * @return {string} A translated reason, or ''.
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-a-value-must-fit-its-schema
 */
export function schemaError(value, schema) {
	const text = value === null || value === undefined ? '' : String(value)
	if (text === '') {
		return ''
	}
	const clean = cleanSchema(schema)
	if (clean.type === 'object') {
		return objectError(text)
	}
	if (clean.type === 'boolean') {
		return ['true', 'false'].includes(text)
			? ''
			: t('keepiq', 'Choose yes or no.')
	}
	if (clean.type === 'integer' || clean.type === 'number') {
		return numberError(text, clean)
	}
	return stringError(text, clean)
}

/**
 * The schemas stored in a blob, by field name.
 *
 * @param {object|null|undefined} blob The decrypted additional fields.
 * @return {object} The schemas; empty when the blob has none.
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-custom-fields-carry-a-schema
 */
export function schemasOf(blob) {
	const stored = blob?.[SCHEMA_MEMBER]
	return stored !== null && typeof stored === 'object' && !Array.isArray(stored)
		? stored
		: {}
}

/**
 * The fields of a blob without the reserved schema member.
 *
 * @param {object|null|undefined} blob The decrypted additional fields.
 * @return {object} The fields by name.
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-custom-fields-carry-a-schema
 */
export function fieldsOf(blob) {
	if (blob === null || blob === undefined || typeof blob !== 'object') {
		return {}
	}
	const fields = { ...blob }
	delete fields[SCHEMA_MEMBER]
	return fields
}

/**
 * A blob from fields and their schemas. Plain-text schemas and schemas of
 * fields that are not there are left out, and so is the member when it would
 * be empty, so a blob without typed fields looks exactly as before.
 *
 * @param {object} fields The fields by name.
 * @param {object} schemas The schemas by field name.
 * @return {object} The blob.
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-custom-fields-carry-a-schema
 */
export function withSchemas(fields, schemas) {
	const kept = {}
	for (const [name, schema] of Object.entries(schemas || {})) {
		if (Object.hasOwn(fields, name) && !isPlainSchema(schema)) {
			kept[name] = cleanSchema(schema)
		}
	}
	return Object.keys(kept).length === 0
		? { ...fields }
		: { ...fields, [SCHEMA_MEMBER]: kept }
}

/**
 * An imported blob with the schema dropped from every field whose value does
 * not fit it, so the value is kept and reads as text.
 *
 * @param {object|null|undefined} blob The imported additional fields.
 * @return {object|null|undefined} The blob to store.
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-a-value-must-fit-its-schema
 */
export function fitImportedBlob(blob) {
	if (blob === null || blob === undefined || typeof blob !== 'object') {
		return blob
	}
	const fields = fieldsOf(blob)
	const schemas = schemasOf(blob)
	const fitting = Object.fromEntries(
		Object.entries(schemas).filter(
			([name, schema]) => schemaError(fields[name], schema) === '',
		),
	)
	return withSchemas(fields, fitting)
}

/**
 * Merge filled-in members into a blob, the schemas member by member, so a
 * request's schema joins the ones already stored rather than replacing them.
 *
 * @param {object} own The stored blob.
 * @param {object} incoming The members a request filled in.
 * @return {object} The merged blob.
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
 */
export function mergeBlobs(own, incoming) {
	const fields = { ...fieldsOf(own), ...fieldsOf(incoming) }
	const schemas = { ...schemasOf(own), ...schemasOf(incoming) }
	return withSchemas(fields, schemas)
}

/**
 * The schema of a type's own field: its stated schema, else the one its kind
 * implies (`hidden`, `multiline`, `url`, `email` or plain text).
 *
 * @param {{schema?: object, kind?: string}} field The type field.
 * @return {object} The schema.
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-custom-fields-carry-a-schema
 */
export function typeFieldSchema(field) {
	if (field?.schema) {
		return cleanSchema(field.schema)
	}
	const byKind = {
		hidden: { type: 'string', hidden: true },
		multiline: { type: 'string', format: 'text' },
		url: { type: 'string', format: 'uri' },
		email: { type: 'string', format: 'email' },
	}
	return { ...(byKind[field?.kind] ?? PLAIN) }
}

/**
 * Whether every member's value fits its schema, and every type field that
 * states a schema (a built-in one, such as a Database's Port) fits it.
 *
 * @param {Array<{value: string, schema?: object}>} members The custom members.
 * @param {Array<object>} typeFields The type's own fields.
 * @param {object} typedValues The type fields' values by key.
 * @return {boolean} True when the form may be saved.
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-a-value-must-fit-its-schema
 */
export function valuesFit(members = [], typeFields = [], typedValues = {}) {
	return (
		members.every((m) => schemaError(m.value, m.schema) === '')
		&& typeFields
			.filter((f) => f.schema)
			.every((f) => schemaError(typedValues?.[f.key], f.schema) === '')
	)
}
