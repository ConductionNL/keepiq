/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A secret's own custom fields as a CXF `custom-fields` credential, and back.
 * Each field gets the CXF field type closest to its schema (a hidden field is
 * a `concealed-string`), and the full schema rides along in the field's
 * extensions, so a Keepiq-to-Keepiq round trip keeps formats and limits CXF
 * has no words for.
 */

import {
	cleanSchema,
	fieldsOf,
	isHiddenSchema,
	isPlainSchema,
	schemasOf,
	withSchemas,
} from '../utils/fieldSchema.js'

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
 * @return {object|null} The CXF credential.
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-schemas-survive-export-and-import
 */
export function customFieldsCredential(additionalFields, used = []) {
	if (additionalFields === null || typeof additionalFields !== 'object') {
		return null
	}
	const schemas = schemasOf(additionalFields)
	const fields = Object.entries(fieldsOf(additionalFields))
		.filter(([name]) => !used.includes(name))
		.map(([name, value], index) => {
			const schema = cleanSchema(schemas[name])
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
		const name = String(field?.label ?? field?.id ?? '').trim()
		if (name === '') {
			continue
		}
		fields[name] =
			field?.value === null || field?.value === undefined
				? ''
				: String(field.value)
		schemas[name] = schemaOfField(field)
	}
	return withSchemas(fields, schemas)
}
