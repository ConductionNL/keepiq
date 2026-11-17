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
import { isSafePattern, MAX_PATTERN_INPUT } from './safePattern.js'

/** The reserved member holding the schemas. */
export const SCHEMA_MEMBER = '@schema'

/**
 * The name a field called `@schema` is shown and saved under: a secret saved
 * before the member was reserved, or an import, may have one.
 */
export const RENAMED_SCHEMA_FIELD = `${SCHEMA_MEMBER} (imported)`

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
	time: /^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/,
	uuid: /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i,
	'color-hex': /^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i,
})

/**
 * The presets in the order the select offers them, with translated labels.
 *
 * @return {Array<{id: string, label: string}>} The presets.
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-presets-pick-a-schema-advanced-shows-it
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
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-presets-pick-a-schema-advanced-shows-it
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
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-custom-fields-carry-a-schema
 */
function keeps(key, value, type) {
	if (key === 'hidden') {
		return value === true
	}
	if (['format', 'minLength', 'maxLength', 'pattern'].includes(key)) {
		return type === 'string' && keepsStringKey(key, value)
	}
	return keepsNumberKey(key, value, type)
}

/**
 * Keep a number schema's `minimum`, `maximum` or `multipleOf`: a number, and
 * a step above zero that only a `number` takes.
 *
 * @param {string} key The key.
 * @param {string|number|boolean} value Its value.
 * @param {string} type The schema's type.
 * @return {boolean} True to keep it.
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-custom-fields-carry-a-schema
 */
function keepsNumberKey(key, value, type) {
	if (key === 'multipleOf') {
		return type === 'number' && isNumeric(value) && Number(value) > 0
	}
	return ['integer', 'number'].includes(type) && isNumeric(value)
}

/**
 * Keep a string schema's `format`, `pattern`, `minLength` or `maxLength`:
 * a known format, a pattern that is not empty, a whole length of zero or more.
 *
 * @param {string} key The key.
 * @param {string|number|boolean} value Its value.
 * @return {boolean} True to keep it.
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-custom-fields-carry-a-schema
 */
function keepsStringKey(key, value) {
	if (key === 'format') {
		return STRING_FORMATS.includes(value)
	}
	if (key === 'pattern') {
		return typeof value === 'string' && value !== ''
	}
	// A length is a whole number, as the server requires.
	return isNumeric(value) && Number.isInteger(Number(value)) && Number(value) >= 0
}

/**
 * A schema with only the keys that make sense for its type, numbers as
 * numbers, and `string` as the type when it is missing or unknown.
 *
 * @param {object|null|undefined} schema The schema.
 * @return {object} The clean schema.
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-custom-fields-carry-a-schema
 */
export function cleanSchema(schema) {
	const source = schema || {}
	const type = SCHEMA_TYPES.includes(source.type) ? source.type : 'string'
	const clean = { type }
	for (const key of SCHEMA_KEYS) {
		if (key !== 'type' && keeps(key, source[key], type)) {
			clean[key] = ['format', 'pattern', 'hidden'].includes(key)
				? source[key]
				: Number(source[key])
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
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-presets-pick-a-schema-advanced-shows-it
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
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-custom-fields-carry-a-schema
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
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-any-custom-field-can-be-hidden
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
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-each-schema-has-its-own-input-and-display
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

/** What a browser's number, date and time inputs can hold; anything else they show empty. */
const BROWSER_VALUES = Object.freeze({
	// The HTML floating-point number grammar: "5.", "+5" or "0x10" show empty.
	number: (value) => /^-?(?:\d+(?:\.\d+)?|\.\d+)(?:[eE][-+]?\d+)?$/.test(value),
	date: (value) => /^\d{4}-\d{2}-\d{2}$/.test(value),
	'datetime-local': (value) => /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?$/.test(value),
	time: (value) => /^\d{2}:\d{2}(?::\d{2})?$/.test(value),
})

/**
 * The HTML input type to use for a value: `type`, or `text` when a browser's
 * input of that type cannot hold the value and would show it empty, as a
 * Port of "default" in a number input.
 *
 * @param {string} type The HTML input type the schema asks for.
 * @param {string} value The value.
 * @return {string} The input type.
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-each-schema-has-its-own-input-and-display
 */
export function inputTypeFor(type, value) {
	const text = value === null || value === undefined ? '' : String(value)
	const holds = BROWSER_VALUES[type]
	return text === '' || holds === undefined || holds(text) ? type : 'text'
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
	return (
		numberTypeError(value, schema)
		|| boundError(Number(value), schema)
		|| stepError(Number(value), schema.multipleOf)
	)
}

/**
 * Why a value is not a number of the schema's type, or ''.
 *
 * @param {string} value The value.
 * @param {object} schema The clean schema.
 * @return {string} The message, or ''.
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-a-value-must-fit-its-schema
 */
function numberTypeError(value, schema) {
	if (schema.type === 'integer' && !/^-?\d+$/.test(value.trim())) {
		return t('keepiq', 'Enter a whole number.')
	}
	return isNumeric(value.trim()) ? '' : t('keepiq', 'Enter a number.')
}

/**
 * Why a number is below the schema's minimum or above its maximum, or ''.
 *
 * @param {number} number The number.
 * @param {object} schema The clean schema.
 * @return {string} The message, or ''.
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-a-value-must-fit-its-schema
 */
function boundError(number, schema) {
	if (schema.minimum !== undefined && number < schema.minimum) {
		return t('keepiq', 'Enter at least {min}.', { min: schema.minimum })
	}
	if (schema.maximum !== undefined && number > schema.maximum) {
		return t('keepiq', 'Enter at most {max}.', { max: schema.maximum })
	}
	return ''
}

/**
 * Why a number is not a multiple of the schema's step, or ''.
 *
 * @param {number} number The number.
 * @param {number|undefined} step The schema's `multipleOf`.
 * @return {string} The message, or ''.
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-a-value-must-fit-its-schema
 */
function stepError(number, step) {
	if (step === undefined) {
		return ''
	}
	return Math.abs(number / step - Math.round(number / step)) > 1e-9
		? t('keepiq', 'Enter a multiple of {step}.', { step })
		: ''
}

/**
 * Whether a date, or a date-time's date, is a real day: the pattern lets
 * 2026-02-31 through, which a calendar does not have.
 *
 * @param {string} value The value.
 * @return {boolean} True for a real day.
 */
function isRealDay(value) {
	const day = value.slice(0, 10)
	const date = new Date(`${day}T00:00:00Z`)
	return !Number.isNaN(date.getTime()) && date.toISOString().startsWith(day)
}

/**
 * Whether a date-time's time of day is a real one.
 *
 * @param {string} value The value.
 * @return {boolean} True when the hours, minutes and seconds exist.
 */
function isRealTime(value) {
	const [, hours, minutes, seconds = '0'] =
		/T(\d{2}):(\d{2})(?::(\d{2}))?/.exec(value) ?? []
	return Number(hours) < 24 && Number(minutes) < 60 && Number(seconds) < 60
}

/**
 * Whether a value fits a string format.
 *
 * @param {string} value The value.
 * @param {string|undefined} format The format.
 * @return {boolean} True when it fits, or when the format has no check.
 */
function fitsFormat(value, format) {
	if (format === 'uri') {
		return isUrl(value)
	}
	if (!(FORMAT_PATTERNS[format]?.test(value) ?? true)) {
		return false
	}
	if (format === 'date') {
		return isRealDay(value)
	}
	return format !== 'date-time' || (isRealDay(value) && isRealTime(value))
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
	return (
		lengthError([...value].length, schema)
		|| patternError(value, schema.pattern)
		|| (fitsFormat(value, schema.format) ? '' : formatError(schema.format))
	)
}

/**
 * Why a text's length is below the schema's minimum or above its maximum, or ''.
 *
 * @param {number} length The text's length in characters.
 * @param {object} schema The clean schema.
 * @return {string} The message, or ''.
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-a-value-must-fit-its-schema
 */
function lengthError(length, schema) {
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
	return ''
}

/**
 * Why a text does not match the schema's pattern, or ''. A pattern that is
 * not safe to run is never run, and no value fits it; nor is a pattern run on
 * a text longer than MAX_PATTERN_INPUT.
 *
 * @param {string} value The text.
 * @param {string|undefined} pattern The schema's pattern.
 * @return {string} The message, or ''.
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-a-value-must-fit-its-schema
 */
function patternError(value, pattern) {
	if (pattern === undefined) {
		return ''
	}
	if ([...value].length > MAX_PATTERN_INPUT) {
		return t('keepiq', 'Use at most {max} characters.', { max: MAX_PATTERN_INPUT })
	}
	const fits = isSafePattern(pattern) && new RegExp(pattern, 'u').test(value)
	return fits ? '' : t('keepiq', 'This does not match the required pattern.')
}

/**
 * What to enter for a format, in words a recipient without the JSON Schema
 * names understands.
 *
 * @param {string} format The format.
 * @return {string} The reason.
 */
function formatError(format) {
	const messages = {
		uri: () => t('keepiq', 'Enter a web address, such as https://example.com.'),
		email: () =>
			t('keepiq', 'Enter an email address, such as name@example.com.'),
		hostname: () => t('keepiq', 'Enter a host name, such as example.com.'),
		ipv4: () => t('keepiq', 'Enter an IPv4 address, such as 192.0.2.1.'),
		ipv6: () => t('keepiq', 'Enter an IPv6 address, such as 2001:db8::1.'),
		date: () => t('keepiq', 'Enter a real date, such as 2026-03-31.'),
		'date-time': () =>
			t('keepiq', 'Enter a real date and time, such as 2026-03-31T14:30.'),
		time: () => t('keepiq', 'Enter a time, such as 14:30.'),
		uuid: () =>
			t(
				'keepiq',
				'Enter a UUID, such as 123e4567-e89b-12d3-a456-426614174000.',
			),
		'color-hex': () => t('keepiq', 'Enter a colour, such as #1a2b3c.'),
	}
	return (
		messages[format]?.()
		?? t('keepiq', 'This value does not fit its field type.')
	)
}

/**
 * Why a value does not fit its schema, or '' when it does. An empty value
 * always fits: whether a field must be filled is a separate rule.
 *
 * @param {string} value The value as stored.
 * @param {object|null|undefined} schema The field's schema.
 * @return {string} A translated reason, or ''.
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-a-value-must-fit-its-schema
 */
export function schemaError(value, schema) {
	const text = value === null || value === undefined ? '' : String(value)
	return text === '' ? '' : typedError(text, cleanSchema(schema))
}

/**
 * Why a value that is not empty does not fit its clean schema, or ''.
 *
 * @param {string} text The value.
 * @param {object} clean The clean schema.
 * @return {string} The message, or ''.
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-a-value-must-fit-its-schema
 */
function typedError(text, clean) {
	if (clean.type === 'object') {
		return objectError(text)
	}
	if (clean.type === 'boolean') {
		return booleanError(text)
	}
	if (clean.type === 'integer' || clean.type === 'number') {
		return numberError(text, clean)
	}
	return stringError(text, clean)
}

/**
 * Why a value is not yes or no, or ''.
 *
 * @param {string} text The value.
 * @return {string} The message, or ''.
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-a-value-must-fit-its-schema
 */
function booleanError(text) {
	return ['true', 'false'].includes(text) ? '' : t('keepiq', 'Choose yes or no.')
}

/**
 * Whether a value is a schema map: an object of objects. Anything else under
 * `@schema` is a field a person named so.
 *
 * @param {unknown} value The value.
 * @return {boolean} True for a schema map.
 */
function isSchemaMap(value) {
	const isObject = (v) => v !== null && typeof v === 'object' && !Array.isArray(v)
	return isObject(value) && Object.values(value).every(isObject)
}

/**
 * The schemas of the `{ value, kind }` entries unstable 0.3.14 builds wrote
 * for hidden and yes/no fields.
 */
const KIND_SCHEMAS = Object.freeze({
	hidden: { type: 'string', hidden: true },
	boolean: { type: 'boolean' },
})

/**
 * Whether a blob entry is a `{ value, kind }` entry.
 *
 * @param {unknown} entry One entry of the decrypted blob.
 * @return {boolean} True for a kinded entry.
 */
function isKindedEntry(entry) {
	return (
		entry !== null
		&& typeof entry === 'object'
		&& !Array.isArray(entry)
		&& Object.hasOwn(entry, 'value')
		&& typeof entry.kind === 'string'
	)
}

/**
 * The schemas stored in a blob, by field name.
 *
 * @param {object|null|undefined} blob The decrypted additional fields.
 * @return {object} The schemas; empty when the blob has none.
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-custom-fields-carry-a-schema
 */
export function schemasOf(blob) {
	const stored = blob?.[SCHEMA_MEMBER]
	const schemas = isSchemaMap(stored) ? { ...stored } : {}
	for (const [name, entry] of Object.entries(blob ?? {})) {
		const known = isKindedEntry(entry) && Object.hasOwn(KIND_SCHEMAS, entry.kind)
		if (known && !Object.hasOwn(schemas, name)) {
			schemas[name] = { ...KIND_SCHEMAS[entry.kind] }
		}
	}
	return schemas
}

/**
 * One blob entry with a `{ value, kind }` entry unwrapped to its value.
 *
 * @param {unknown} entry One entry of the decrypted blob.
 * @return {unknown} The stored value.
 */
function unwrapEntry(entry) {
	return isKindedEntry(entry) ? entry.value : entry
}

/**
 * The value of one blob entry as text, '' when absent.
 *
 * @param {unknown} entry One entry of the decrypted blob.
 * @return {string} The value.
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-custom-fields-carry-a-schema
 */
export function fieldValueOf(entry) {
	const value = unwrapEntry(entry)
	return value === null || value === undefined ? '' : String(value)
}

/**
 * The fields of a blob without the reserved schema member. A field a person
 * named `@schema` is kept, under RENAMED_SCHEMA_FIELD, so it is not lost.
 *
 * @param {object|null|undefined} blob The decrypted additional fields.
 * @return {object} The fields by name.
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-custom-fields-carry-a-schema
 */
export function fieldsOf(blob) {
	if (blob === null || blob === undefined || typeof blob !== 'object') {
		return {}
	}
	const fields = Object.fromEntries(
		Object.entries(blob).map(([name, entry]) => [name, unwrapEntry(entry)]),
	)
	const stored = fields[SCHEMA_MEMBER]
	delete fields[SCHEMA_MEMBER]
	keepMisnamedField(fields, stored)
	return fields
}

/**
 * Keep a `@schema` member that holds no schema map, as a person's own field
 * may, under RENAMED_SCHEMA_FIELD, unless that name is taken.
 *
 * @param {object} fields The fields, added to.
 * @param {object|string|number|boolean|null|undefined} stored The `@schema` member.
 * @return {void}
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-custom-fields-carry-a-schema
 */
function keepMisnamedField(fields, stored) {
	if (
		stored !== undefined
		&& !isSchemaMap(stored)
		&& !Object.hasOwn(fields, RENAMED_SCHEMA_FIELD)
	) {
		fields[RENAMED_SCHEMA_FIELD] = stored
	}
}

/**
 * A blob from fields and their schemas. Plain-text schemas and schemas of
 * fields that are not there are left out, and so is the member when it would
 * be empty, so a blob without typed fields looks exactly as before.
 *
 * @param {object} fields The fields by name.
 * @param {object} schemas The schemas by field name.
 * @return {object} The blob.
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-custom-fields-carry-a-schema
 */
export function withSchemas(fields, schemas) {
	const kept = schemasFor(fields, schemas)
	return Object.keys(kept).length === 0
		? { ...fields }
		: { ...fields, [SCHEMA_MEMBER]: kept }
}

/**
 * The clean schemas of the fields that exist and are not plain text.
 *
 * @param {object} fields The fields, by name.
 * @param {object|null|undefined} schemas The schemas, by field name.
 * @return {object} The kept schemas, by field name.
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-custom-fields-carry-a-schema
 */
function schemasFor(fields, schemas) {
	const kept = {}
	for (const [name, schema] of Object.entries(schemas || {})) {
		if (Object.hasOwn(fields, name) && !isPlainSchema(schema)) {
			kept[name] = cleanSchema(schema)
		}
	}
	return kept
}

/** The limits that contradict each other when the lower is above the upper. */
const LIMIT_PAIRS = Object.freeze([
	['minLength', 'maxLength'],
	['minimum', 'maximum'],
])

/**
 * A schema without what the server refuses (lib/Service/FieldSchemaShape.php):
 * a pattern that is not safe to run, and a pair of limits whose lower is
 * above its upper.
 *
 * @param {object|null|undefined} schema The schema.
 * @return {object} The schema to keep.
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
 */
export function withoutConflicts(schema) {
	const kept = { ...(schema ?? {}) }
	if (kept.pattern !== undefined && !isSafePattern(kept.pattern)) {
		delete kept.pattern
	}
	for (const [low, high] of LIMIT_PAIRS) {
		if (
			kept[low] !== undefined
			&& kept[high] !== undefined
			&& Number(kept[low]) > Number(kept[high])
		) {
			delete kept[low]
			delete kept[high]
		}
	}
	return kept
}

/**
 * Whether a schema holds something the server refuses, so it may not be
 * saved or sent with a request.
 *
 * @param {object|null|undefined} schema The schema.
 * @return {boolean}
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
 */
export function schemaConflicts(schema) {
	// Read as stored, so a key its type ignores, such as a pattern on an
	// integer, does not count.
	const clean = cleanSchema(schema)
	return (
		Object.keys(withoutConflicts(clean)).length !== Object.keys(clean).length
	)
}

/**
 * An imported blob with the schema dropped from every field whose value does
 * not fit it, so the value is kept and reads as text. A hidden field stays
 * hidden: a mask is not a limit a value can fail. A pattern that is not safe
 * to run, and limits that contradict each other, are dropped whatever the value, also from an empty field, which no
 * value could ever fill.
 *
 * @param {object|null|undefined} blob The imported additional fields.
 * @return {object|null|undefined} The blob to store.
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-a-value-must-fit-its-schema
 */
export function fitImportedBlob(blob) {
	if (blob === null || blob === undefined || typeof blob !== 'object') {
		return blob
	}
	const fields = fieldsOf(blob)
	const fitting = Object.fromEntries(
		Object.entries(schemasOf(blob)).map(([name, stored]) => {
			const schema = withoutConflicts(stored)
			if (schemaError(fields[name], schema) === '') {
				return [name, schema]
			}
			return [
				name,
				isHiddenSchema(schema) ? { type: 'string', hidden: true } : PLAIN,
			]
		}),
	)
	return withSchemas(fields, fitting)
}

/**
 * Merge imported members into a blob, the schemas member by member, so an
 * imported schema joins the ones already stored rather than replacing them.
 *
 * @param {object} own The stored blob.
 * @param {object} incoming The imported members.
 * @return {object} The merged blob.
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-schemas-survive-export-and-import
 */
export function mergeBlobs(own, incoming) {
	const fields = { ...fieldsOf(own), ...fieldsOf(incoming) }
	const schemas = { ...schemasOf(own), ...schemasOf(incoming) }
	return withSchemas(fields, schemas)
}

/**
 * Merge the members a request filled in into a blob. Only the values are
 * taken, because whoever holds a fill link can put anything in the blob it
 * seals. A filled field keeps the schema stored on the secret; one without
 * a stored schema gets the schema its request carried, which the server
 * checked when the request was made and stored with the filled blob.
 *
 * @param {object} own The stored blob.
 * @param {object} incoming The members a request filled in.
 * @param {object} requested The schemas the filling request carried, by field name.
 * @return {object} The merged blob.
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
 */
export function mergeFilledBlob(own, incoming, requested = {}) {
	const filled = fieldsOf(incoming)
	const schemas = schemasOf(own)
	for (const name of Object.keys(filled)) {
		if (!Object.hasOwn(schemas, name) && isSchemaMap({ [name]: requested?.[name] })) {
			schemas[name] = requested[name]
		}
	}
	return withSchemas({ ...fieldsOf(own), ...filled }, schemas)
}

/**
 * The schema of a type's own field: its stated schema, else the one its kind
 * implies (`hidden`, `multiline`, `url`, `email` or plain text). A
 * multi-line field, such as a private key, is hidden unless it is public.
 *
 * @param {{schema?: object, kind?: string}} field The type field.
 * @return {object} The schema.
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-custom-fields-carry-a-schema
 */
export function typeFieldSchema(field) {
	if (field?.schema) {
		return cleanSchema(field.schema)
	}
	const byKind = {
		hidden: { type: 'string', hidden: true },
		multiline: field?.public
			? { type: 'string', format: 'text' }
			: { type: 'string', format: 'text', hidden: true },
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
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-a-value-must-fit-its-schema
 */
export function valuesFit(members = [], typeFields = [], typedValues = {}) {
	return (
		members.every((m) => schemaError(m.value, m.schema) === '')
		&& typeFields
			.filter((f) => f.schema)
			.every((f) => schemaError(typedValues?.[f.key], f.schema) === '')
	)
}
