/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The fields an administrator defines on an item type (admin-18). The
 * definition is plain metadata on the type; the values live in the encrypted
 * additional-fields blob, one member per field, named by the field label.
 */

import { RESERVED_MEMBER_NAMES } from './additionalFields.js'
import { fieldsOf, fieldValueOf, schemasOf, withSchemas } from './fieldSchema.js'

/** The kinds a field can have, in the order the editor offers them. */
export const FIELD_KINDS = Object.freeze(['text', 'hidden', 'url', 'email'])

/** The most fields one type may carry; the server enforces the same cap. */
export const MAX_FIELDS = 30

/**
 * The field list of a type, or an empty list for a type without one.
 *
 * @param {object|undefined|null} type A secret type from the type store.
 * @return {Array<{key: string, label: string, kind: string, required: boolean}>} The fields.
 * @spec openspec/specs/admin-secret-types/spec.md#requirement-item-type-definitions
 */
export function typedFieldsOf(type) {
	return Array.isArray(type?.fields) ? type.fields : []
}

/**
 * A machine key for a field label: lowercase, dashes, unique within `taken`.
 *
 * @param {string} label The field label.
 * @param {string[]} taken Keys already in use.
 * @return {string} The key.
 * @spec openspec/specs/admin-secret-types/spec.md#requirement-item-type-definitions
 */
export function fieldKeyFor(label, taken = []) {
	const base =
		(label || '')
			.toLowerCase()
			.normalize('NFKD')
			.replace(/[^a-z0-9]+/g, '-')
			.replace(/^-+|-+$/g, '')
			.slice(0, 56) || 'field'
	let key = base
	let n = 2
	while (taken.includes(key)) {
		key = `${base}-${n}`
		n++
	}
	return key
}

/**
 * Why a field list cannot be saved, or an empty string when it can. The
 * kind select cannot be cleared and the add button stops at MAX_FIELDS, so
 * only the labels need checking here; the server checks everything again.
 *
 * @param {Array<{label: string, kind: string}>} fields The edited fields.
 * @param {(app: string, text: string, vars?: object) => string} t The translate function.
 * @return {string} The problem, in the user's language.
 * @spec openspec/specs/admin-secret-types/spec.md#requirement-item-type-definitions
 */
export function fieldListError(fields, t) {
	const seen = []
	for (const field of fields) {
		const label = (field.label || '').trim()
		const problem = labelError(label, { seen, t })
		if (problem !== '') {
			return problem
		}
		seen.push(label.toLowerCase())
	}
	return ''
}

/**
 * @param {string} label The trimmed label.
 * @param {{seen: string[], t: (app: string, text: string, vars?: object) => string}} context The lowercased labels before it and the translate function.
 * @return {string} What is wrong with the label, or ''.
 */
function labelError(label, { seen, t }) {
	if (label === '') {
		return t('keepiq', 'Give the field a name.')
	}
	if (RESERVED_MEMBER_NAMES.includes(label.toLowerCase())) {
		return t(
			'keepiq',
			'“{name}” is a built-in field, not an additional one — choose a different name.',
			{ name: label.toLowerCase() },
		)
	}
	return seen.includes(label.toLowerCase())
		? t('keepiq', 'That field is already listed.')
		: ''
}

/**
 * Split a decrypted additional-fields blob into the typed values (by field
 * key) and the remaining free members, so the generic editor does not list a
 * typed field twice.
 *
 * @param {object|null|undefined} blob The decrypted additional fields.
 * @param {Array<{key: string, label: string}>} fields The type's fields.
 * @return {{values: object, rest: object}} Typed values by key, and the rest by name.
 * @spec openspec/specs/admin-secret-types/spec.md#requirement-typed-fields-storage
 */
export function splitTypedValues(blob, fields) {
	const values = {}
	const rest = {}
	const byLabel = Object.fromEntries(fields.map((f) => [f.label, f.key]))
	for (const [name, value] of Object.entries(blob || {})) {
		if (Object.hasOwn(byLabel, name)) {
			values[byLabel[name]] = fieldValueOf(value)
		} else {
			rest[name] = value
		}
	}
	return { values, rest }
}

/**
 * The additional-fields object to encrypt: the free members plus every
 * non-empty typed value, named by its field label. With the blob the form
 * was opened from, a typed member it held stays, also when empty, keeps its
 * stored value when unchanged (a number stays a number), and keeps its
 * stored schema.
 *
 * @param {object} rest The free members, by name.
 * @param {Array<{key: string, label: string}>} fields The type's fields.
 * @param {object} values Typed values by field key.
 * @param {object|null} [original] The blob the form was opened from.
 * @return {object} The blob.
 * @spec openspec/specs/admin-secret-types/spec.md#requirement-typed-fields-storage
 * @spec openspec/specs/builtin-type-fields/spec.md#requirement-built-in-field-values-keep-a-fixed-name
 */
export function mergeTypedValues(rest, fields, values, original = null) {
	const had =
		original !== null && typeof original === 'object' ? fieldsOf(original) : {}
	const given = values || {}
	const out = { ...rest }
	const merged = []
	for (const field of fields) {
		const member = typedMember(had, field.label, given[field.key])
		if (member !== undefined) {
			out[field.label] = member
			merged.push(field.label)
		}
	}
	return withStoredSchemas(out, merged, schemasOf(original))
}

/**
 * The blob with the stored schemas of the typed members it was given back.
 *
 * @param {object} out The merged blob.
 * @param {Array<string>} merged The typed members written into it.
 * @param {object} stored The schemas of the blob the form was opened from.
 * @return {object} The blob.
 * @spec openspec/specs/builtin-type-fields/spec.md#requirement-built-in-field-values-keep-a-fixed-name
 */
function withStoredSchemas(out, merged, stored) {
	const kept = {}
	for (const label of merged) {
		if (stored[label]) {
			kept[label] = stored[label]
		}
	}
	return Object.keys(kept).length === 0
		? out
		: withSchemas(fieldsOf(out), { ...schemasOf(out), ...kept })
}

/**
 * A member value as text, '' for none.
 *
 * @param {string|number|boolean|object|null|undefined} value The value.
 * @return {string} The text.
 * @spec openspec/specs/builtin-type-fields/spec.md#requirement-built-in-field-values-keep-a-fixed-name
 */
function asText(value) {
	return value === null || value === undefined ? '' : String(value)
}

/**
 * What one typed value is stored as: the stored value when unchanged, so a
 * member that was already empty stays; nothing when it is empty otherwise,
 * so a cleared field is removed; else the value.
 *
 * @param {object} had The members of the blob the form was opened from.
 * @param {string} label The field's member name.
 * @param {string|undefined} value The value in the form.
 * @return {string|number|boolean|object|null|undefined} The member value, or undefined to leave it out.
 * @spec openspec/specs/builtin-type-fields/spec.md#requirement-built-in-field-values-keep-a-fixed-name
 */
function typedMember(had, label, value) {
	const text = asText(value)
	if (Object.hasOwn(had, label) && asText(had[label]) === text) {
		return had[label]
	}
	return text === '' ? undefined : text
}

/**
 * The keys of required fields that are still empty.
 *
 * @param {Array<{key: string, required: boolean}>} fields The type's fields.
 * @param {object} values Typed values by field key.
 * @return {string[]} The missing keys.
 * @spec openspec/specs/admin-secret-types/spec.md#requirement-item-type-definitions
 */
export function missingRequired(fields, values) {
	return fields
		.filter((f) => f.required && (values?.[f.key] ?? '').trim() === '')
		.map((f) => f.key)
}
