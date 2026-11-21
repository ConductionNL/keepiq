/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The forms of the built-in item types that need more than a generic secret
 * value: the value field's label and input, the extra fields, and the generic fields
 * the type leaves out. The definitions live here rather than in the type
 * table so their labels can be translated. An extra field keeps the shape of
 * an admin-defined field: `label` is the fixed English member name it is
 * stored under in the encrypted additional fields, `displayLabel` what the
 * user reads.
 */

import { translate as t } from '@nextcloud/l10n'
import { typedFieldsOf } from './typedFields.js'

/**
 * The definitions by type name. Built on each call so the labels follow the
 * loaded translations. A field may state a `schema` its value must fit.
 *
 * @return {object} The definitions.
 * @spec openspec/specs/builtin-type-fields/spec.md#requirement-built-in-types-carry-their-own-fields
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-a-value-must-fit-its-schema
 */
function definitions() {
	return {
		login: {
			value: { label: t('keepiq', 'Password'), kind: 'hidden' },
			lockedInRequests: true,
			leaveOut: [],
			fields: [],
		},
		certificate: certificateDefinition(),
		api_key: apiKeyDefinition(),
		database: databaseDefinition(),
		ssh_key: sshKeyDefinition(),
		note: {
			value: { label: t('keepiq', 'Note'), kind: 'multiline' },
			lockedInRequests: true,
			leaveOut: ['login', 'url'],
			fields: [],
		},
	}
}

/**
 * The passphrase of a private key, as a certificate and an SSH key keep it.
 *
 * @return {object} The field.
 * @spec openspec/specs/builtin-type-fields/spec.md#requirement-built-in-types-carry-their-own-fields
 */
function passphraseField() {
	return {
		key: 'passphrase',
		label: 'Passphrase',
		displayLabel: t('keepiq', 'Passphrase'),
		kind: 'hidden',
	}
}

/**
 * A certificate: public by design, so never masked, with its private key
 * and that key's passphrase.
 *
 * @return {object} The definition.
 * @spec openspec/specs/builtin-type-fields/spec.md#requirement-built-in-types-carry-their-own-fields
 */
function certificateDefinition() {
	return {
		value: {
			label: t('keepiq', 'Certificate'),
			kind: 'multiline',
			public: true,
		},
		lockedInRequests: true,
		leaveOut: ['login'],
		fields: [
			{
				key: 'private-key',
				label: 'Private key',
				displayLabel: t('keepiq', 'Private key'),
				kind: 'multiline',
			},
			passphraseField(),
		],
	}
}

/**
 * An API key, with its API secret.
 *
 * @return {object} The definition.
 * @spec openspec/specs/builtin-type-fields/spec.md#requirement-built-in-types-carry-their-own-fields
 */
function apiKeyDefinition() {
	return {
		value: { label: t('keepiq', 'API key'), kind: 'hidden' },
		lockedInRequests: true,
		leaveOut: [],
		fields: [
			{
				key: 'api-secret',
				label: 'API secret',
				displayLabel: t('keepiq', 'API secret'),
				kind: 'hidden',
			},
		],
	}
}

/**
 * A database: a username, host, port and database name instead of a URL.
 *
 * @return {object} The definition.
 * @spec openspec/specs/builtin-type-fields/spec.md#requirement-built-in-types-carry-their-own-fields
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-a-value-must-fit-its-schema
 */
function databaseDefinition() {
	return {
		value: { label: t('keepiq', 'Password'), kind: 'hidden' },
		loginLabel: {
			short: t('keepiq', 'Username'),
			optional: t('keepiq', 'Username (optional)'),
		},
		lockedInRequests: true,
		leaveOut: ['url'],
		fields: [
			{
				key: 'host',
				label: 'Host',
				displayLabel: t('keepiq', 'Host'),
				kind: 'text',
			},
			{
				key: 'port',
				label: 'Port',
				displayLabel: t('keepiq', 'Port'),
				kind: 'text',
				// A port is a whole number from 1 to 65535.
				schema: { type: 'integer', minimum: 1, maximum: 65535 },
			},
			{
				key: 'database',
				label: 'Database',
				displayLabel: t('keepiq', 'Database'),
				kind: 'text',
			},
		],
	}
}

/**
 * An SSH key: an OpenSSH private key is several lines. Its public key and
 * fingerprint have their own form, SshKeyFields, so they are not fields here.
 *
 * @return {object} The definition.
 * @spec openspec/specs/builtin-type-fields/spec.md#requirement-built-in-types-carry-their-own-fields
 */
function sshKeyDefinition() {
	return {
		value: { label: t('keepiq', 'Private key'), kind: 'multiline' },
		lockedInRequests: true,
		leaveOut: [],
		fields: [passphraseField()],
	}
}

/**
 * The definition of a built-in type, or null for a type without one.
 *
 * @param {string} typeName The type's system name.
 * @return {object|null} The definition.
 * @spec openspec/specs/builtin-type-fields/spec.md#requirement-built-in-types-carry-their-own-fields
 */
function definitionOf(typeName) {
	return definitions()[typeName] ?? null
}

/**
 * Whether a value holds a PEM private key block, as a certificate bundle
 * saved with its key does: such a value is masked even where the type's
 * value is public.
 *
 * @param {string|null|undefined} value The value.
 * @return {boolean}
 * @spec openspec/specs/builtin-type-fields/spec.md#requirement-built-in-types-carry-their-own-fields
 */
export function holdsPrivateKey(value) {
	return /-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----/.test(value || '')
}

/**
 * The value field of a type: its label, its kind (`hidden` or `multiline`)
 * and whether it is public, or null when the type keeps the generic value
 * field.
 *
 * @param {string} typeName The type's system name.
 * @return {{label: string, kind: string, public?: boolean}|null} The value field.
 * @spec openspec/specs/builtin-type-fields/spec.md#requirement-built-in-types-carry-their-own-fields
 */
export function valueFieldOf(typeName) {
	return definitionOf(typeName)?.value ?? null
}

/**
 * The label of the login field, or null when the type keeps the generic one.
 *
 * @param {string} typeName The type's system name.
 * @param {boolean} optional Whether the form marks the field optional.
 * @return {string|null} The label.
 * @spec openspec/specs/builtin-type-fields/spec.md#requirement-built-in-types-carry-their-own-fields
 */
export function loginLabelOf(typeName, optional = false) {
	const labels = definitionOf(typeName)?.loginLabel
	if (labels === undefined) {
		return null
	}
	return optional ? labels.optional : labels.short
}

/**
 * Whether a type leaves the generic `login` or `url` field out.
 *
 * @param {string} typeName The type's system name.
 * @param {string} field `login` or `url`.
 * @return {boolean} True when the type has no use for the field.
 * @spec openspec/specs/builtin-type-fields/spec.md#requirement-built-in-types-carry-their-own-fields
 */
export function leavesOut(typeName, field) {
	return (definitionOf(typeName)?.leaveOut ?? []).includes(field)
}

/**
 * Whether a request for a secret of this type always asks for its value.
 *
 * @param {string} typeName The type's system name.
 * @return {boolean} True when the value is ticked and locked.
 * @spec openspec/specs/builtin-type-fields/spec.md#requirement-a-request-always-asks-for-the-required-fields-of-its-type
 */
export function valueLockedInRequests(typeName) {
	return definitionOf(typeName)?.lockedInRequests === true
}

/**
 * The extra fields of a type: a built-in type's own, else the fields an
 * administrator defined on it.
 *
 * @param {object|undefined|null} type A secret type from the type store.
 * @return {Array<{key: string, label: string, displayLabel?: string, kind: string, required?: boolean}>} The fields.
 * @spec openspec/specs/builtin-type-fields/spec.md#requirement-built-in-types-carry-their-own-fields
 */
export function typeFieldsOf(type) {
	const builtin = definitionOf(type?.name)
	return builtin === null ? typedFieldsOf(type) : builtin.fields
}

/**
 * The extra field of a type stored under a member name, or null.
 *
 * @param {string} typeName The type's system name.
 * @param {string} name The member name in the additional fields.
 * @return {object|null} The field.
 * @spec openspec/specs/builtin-type-fields/spec.md#requirement-built-in-field-values-keep-a-fixed-name
 */
export function builtinFieldNamed(typeName, name) {
	return (
		(definitionOf(typeName)?.fields ?? []).find((f) => f.label === name) ?? null
	)
}

/**
 * The label to show for an additional-fields member: the translated label of
 * a built-in field, else the member name itself.
 *
 * @param {string} typeName The type's system name.
 * @param {string} name The member name.
 * @return {string} The label.
 * @spec openspec/specs/builtin-type-fields/spec.md#requirement-built-in-field-values-keep-a-fixed-name
 */
export function memberLabel(typeName, name) {
	return builtinFieldNamed(typeName, name)?.displayLabel ?? name
}
