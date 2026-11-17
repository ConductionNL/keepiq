/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A secret's own custom fields as a CXF `custom-fields` credential, and back.
 * Each field gets the CXF field type closest to its schema (a hidden field is
 * a `concealed-string`), and the full schema rides along in the field's
 * extensions, so a Keepiq-to-Keepiq round trip keeps formats and limits CXF
 * has no words for. A built-in type's own field without a stored schema, such
 * as an SSH key's Passphrase, goes out with the schema its type gives it.
 */

import { RESERVED_MEMBER_NAMES } from '../utils/additionalFields.js'
import { builtinFieldNamed } from '../utils/builtinTypeFields.js'
import {
	cleanSchema,
	fieldsOf,
	isHiddenSchema,
	isPlainSchema,
	SCHEMA_MEMBER,
	schemasOf,
	typeFieldSchema,
	withSchemas,
} from '../utils/fieldSchema.js'

/**
 * Names an imported field cannot keep: the request columns, and the member
 * that holds the schemas.
 */
const RESERVED_NAMES = Object.freeze([...RESERVED_MEMBER_NAMES, SCHEMA_MEMBER])

/** The extension name the full schema travels under. */
export const SCHEMA_EXTENSION = 'nl.conduction.keepiq.field-schema'

/** The schema a CXF field type implies when no Keepiq schema rides along. */
const SCHEMA_BY_FIELD_TYPE = Object.freeze({
	'concealed-string': { type: 'string', hidden: true },
	boolean: { type: 'boolean' },
	number: { type: 'number' },
	email: { type: 'string', format: 'email' },
	date: { type: 'string', format: 'date' },
})

/** The CXF field type of each schema type that has one of its own. */
const FIELD_TYPE_BY_SCHEMA_TYPE = Object.freeze({
	boolean: 'boolean',
	integer: 'number',
	number: 'number',
})

/**
 * The CXF field type closest to a schema.
 *
 * @param {object} schema The field's schema.
 * @return {string} The CXF field type.
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-schemas-survive-export-and-import
 */
export function cxfFieldType(schema) {
	const clean = cleanSchema(schema)
	if (isHiddenSchema(clean)) {
		return 'concealed-string'
	}
	if (Object.hasOwn(FIELD_TYPE_BY_SCHEMA_TYPE, clean.type)) {
		return FIELD_TYPE_BY_SCHEMA_TYPE[clean.type]
	}
	return ['email', 'date'].includes(clean.format) ? clean.format : 'string'
}

/**
 * The `custom-fields` credential of a secret, or null when it has none.
 *
 * @param {object|null|undefined} additionalFields The decrypted additional fields.
 * @param {Array<string>} used Field names another credential already carries.
 * @param {string} typeName The secret's type name, for its built-in fields.
 * @return {object|null} The CXF credential.
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-schemas-survive-export-and-import
 */
export function customFieldsCredential(additionalFields, used = [], typeName = '') {
	if (additionalFields === null || typeof additionalFields !== 'object') {
		return null
	}
	const schemas = schemasOf(additionalFields)
	const fields = Object.entries(fieldsOf(additionalFields))
		.filter(([name]) => !used.includes(name))
		.map(([name, value], index) =>
			customField(
				name,
				value,
				index + 1,
				fieldSchemaFor(schemas, typeName, name),
			),
		)
	return fields.length === 0 ? null : { type: 'custom-fields', fields }
}

/**
 * The schema a field goes out with: its stored one, else the one its type
 * gives a built-in field, else plain text.
 *
 * @param {object} schemas The stored schemas, by field name.
 * @param {string} typeName The secret's type name.
 * @param {string} name The field's name.
 * @return {object} The clean schema.
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-schemas-survive-export-and-import
 */
function fieldSchemaFor(schemas, typeName, name) {
	if (schemas[name]) {
		return cleanSchema(schemas[name])
	}
	const builtin = builtinFieldNamed(typeName, name)
	return cleanSchema(builtin ? typeFieldSchema(builtin) : null)
}

/**
 * One field of the `custom-fields` credential, with its schema in the
 * extensions when it is more than plain text.
 *
 * @param {string} name The field's name.
 * @param {string|number|boolean|null|undefined} value The field's value.
 * @param {number} number The field's place, from 1.
 * @param {object} schema The field's clean schema.
 * @return {object} The CXF field.
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-schemas-survive-export-and-import
 */
function customField(name, value, number, schema) {
	const field = {
		id: `field-${number}`,
		label: name,
		fieldType: cxfFieldType(schema),
		value: value === null || value === undefined ? '' : String(value),
	}
	if (!isPlainSchema(schema)) {
		field.extensions = [{ name: SCHEMA_EXTENSION, schema }]
	}
	return field
}

/**
 * The schema a CXF field carries: the Keepiq one when it rides along, else
 * the one its field type implies.
 *
 * @param {object} field The CXF field.
 * @return {object} The schema.
 */
function schemaOfField(field) {
	const extension = Array.isArray(field?.extensions)
		? field.extensions.find((e) => e?.name === SCHEMA_EXTENSION)
		: undefined
	if (extension?.schema && typeof extension.schema === 'object') {
		// A concealed field stays hidden whatever its schema says.
		const schema = cleanSchema(extension.schema)
		return field?.fieldType === 'concealed-string' ? { ...schema, hidden: true } : schema
	}
	return { ...(SCHEMA_BY_FIELD_TYPE[field?.fieldType] ?? { type: 'string' }) }
}

/**
 * The name an imported field is stored under: its label, with " (imported)"
 * added when the label is a reserved name, such as "URL" from another
 * password manager, so the value is kept without taking the reserved name.
 *
 * @param {string} label The field's label.
 * @param {object} fields The fields read so far.
 * @return {string} The name.
 */
function importedName(label, fields) {
	if (!RESERVED_NAMES.includes(label.toLowerCase())) {
		return label
	}
	let name = `${label} (imported)`
	for (let n = 2; Object.hasOwn(fields, name); n++) {
		name = `${label} (imported ${n})`
	}
	return name
}

/**
 * A custom-fields blob whose names already on the row it joins get
 * " (imported)" added, with their schemas, so an imported field never
 * replaces one of the row's own, such as an SSH key's `publicKey`.
 *
 * @param {object|null|undefined} own The row's additional fields.
 * @param {object} blob The imported custom fields, with `@schema`.
 * @return {object} The blob to merge.
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-schemas-survive-export-and-import
 */
export function withoutClashes(own, blob) {
	const taken = fieldsOf(own)
	const schemas = schemasOf(blob)
	const fields = {}
	const kept = {}
	for (const [label, value] of Object.entries(fieldsOf(blob))) {
		let name = label
		for (let n = 1; Object.hasOwn(taken, name) || Object.hasOwn(fields, name); n++) {
			name = n === 1 ? `${label} (imported)` : `${label} (imported ${n})`
		}
		fields[name] = value
		if (schemas[label] !== undefined) {
			kept[name] = schemas[label]
		}
	}
	return withSchemas(fields, kept)
}

/**
 * The additional fields a `custom-fields` credential holds, with their schemas.
 *
 * @param {object} credential The CXF credential.
 * @return {object} The blob, possibly empty.
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-schemas-survive-export-and-import
 */
export function readCustomFields(credential) {
	const read = { fields: {}, schemas: {} }
	for (const field of Array.isArray(credential?.fields) ? credential.fields : []) {
		readCustomField(read, field || {})
	}
	return withSchemas(read.fields, read.schemas)
}

/**
 * Add one CXF field to the fields and schemas read so far; a field without a
 * label or id is skipped.
 *
 * @param {{fields: object, schemas: object}} read The fields and schemas read so far.
 * @param {object} field The CXF field.
 * @return {void}
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-schemas-survive-export-and-import
 */
function readCustomField(read, field) {
	const label = String(field.label ?? field.id ?? '').trim()
	if (label === '') {
		return
	}
	const name = importedName(label, read.fields)
	read.fields[name] =
		field.value === null || field.value === undefined ? '' : String(field.value)
	read.schemas[name] = schemaOfField(field)
}
