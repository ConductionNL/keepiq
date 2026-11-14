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

/**
 * The CXF field type closest to a schema.
 *
 * @param {object} schema The field's schema.
 * @return {string} The CXF field type.
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-schemas-survive-export-and-import
 */
export function cxfFieldType(schema) {
	const clean = cleanSchema(schema)
	if (isHiddenSchema(clean)) {
		return 'concealed-string'
	}
	if (clean.type === 'boolean') {
		return 'boolean'
	}
	if (clean.type === 'integer' || clean.type === 'number') {
		return 'number'
	}
	if (clean.format === 'email' || clean.format === 'date') {
		return clean.format
	}
	return 'string'
}

/**
 * The `custom-fields` credential of a secret, or null when it has none.
 *
 * @param {object|null|undefined} additionalFields The decrypted additional fields.
 * @param {Array<string>} used Field names another credential already carries.
 * @param {string} typeName The secret's type name, for its built-in fields.
 * @return {object|null} The CXF credential.
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-schemas-survive-export-and-import
 */
export function customFieldsCredential(additionalFields, used = [], typeName = '') {
	if (additionalFields === null || typeof additionalFields !== 'object') {
		return null
	}
	const schemas = schemasOf(additionalFields)
	const fields = Object.entries(fieldsOf(additionalFields))
		.filter(([name]) => !used.includes(name))
		.map(([name, value], index) => {
			const builtin = builtinFieldNamed(typeName, name)
			const schema = cleanSchema(
				schemas[name] ?? (builtin ? typeFieldSchema(builtin) : null),
			)
			const field = {
				id: `field-${index + 1}`,
				label: name,
				fieldType: cxfFieldType(schema),
				value: value === null || value === undefined ? '' : String(value),
			}
			if (!isPlainSchema(schema)) {
				field.extensions = [{ name: SCHEMA_EXTENSION, schema }]
			}
			return field
		})
	return fields.length === 0 ? null : { type: 'custom-fields', fields }
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
		return cleanSchema(extension.schema)
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
 * The additional fields a `custom-fields` credential holds, with their schemas.
 *
 * @param {object} credential The CXF credential.
 * @return {object} The blob, possibly empty.
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-schemas-survive-export-and-import
 */
export function readCustomFields(credential) {
	const fields = {}
	const schemas = {}
	for (const field of Array.isArray(credential?.fields) ? credential.fields : []) {
		const label = String(field?.label ?? field?.id ?? '').trim()
		if (label === '') {
			continue
		}
		const name = importedName(label, fields)
		fields[name] =
			field?.value === null || field?.value === undefined
				? ''
				: String(field.value)
		schemas[name] = schemaOfField(field)
	}
	return withSchemas(fields, schemas)
}
