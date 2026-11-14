/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The naming rules for a Secret's additional fields, in one place.
 *
 * `additional_fields` is a single encrypted blob holding named members (ADR-003).
 * Three names are NOT members: `key`, `login` and `url` address the Secret's own
 * columns. A member bearing one of those names is not a second field with the same
 * label — it is a value that will be misrouted or shadowed by the column, so the
 * user would store something other than what they typed.
 *
 * Extracted because the rule now has three call sites: the secret-create dialog,
 * the secret-edit dialog, and the request dialog that asks someone ELSE to fill a
 * member in. Three copies of a validation rule is how one of them ends up
 * accepting `url`.
 *
 * Only the VALIDATION is shared. The request dialog additionally lets a requester
 * name a member that does not exist yet, which is meaningless on a secret you are
 * editing yourself, so that affordance stays where it belongs.
 */

import { t } from '@nextcloud/l10n'
import { fieldsOf, SCHEMA_MEMBER, schemasOf, withSchemas } from './fieldSchema.js'

/**
 * Names that address the Secret's own columns and can never be members.
 *
 * @type {Array<string>}
 */
export const RESERVED_MEMBER_NAMES = ['key', 'login', 'url']

/**
 * Why a member name cannot be used, or an empty string when it can.
 *
 * Returns a REASON rather than a boolean: "refused with a reason" is what the spec
 * requires, and a silent rejection on a name the user just typed reads as the
 * dialog being broken.
 *
 * @param {string} name The name the user typed.
 * @param {Array<string>} taken Names already present, whatever their source.
 *
 * @return {string} A translated reason, or '' when the name is acceptable.
 *
 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-create-a-secret-from-the-ui
 */
export function memberNameError(name, taken = []) {
	const trimmed = (name || '').trim()

	if (trimmed === '') {
		return t('keepiq', 'Give the field a name.')
	}

	// Case-insensitive: `Key` reaches the same column as `key`, so accepting it
	// would produce exactly the misrouting this rule exists to prevent.
	if (RESERVED_MEMBER_NAMES.includes(trimmed.toLowerCase()) === true) {
		// Context-neutral wording on purpose. This message is now shared with the
		// REQUEST dialog, where the reserved fields are tickboxes rather than fields
		// above the input — telling that user to "use the field above" would send
		// them looking for something that is not there.
		return t(
			'keepiq',
			'“{name}” is a built-in field, not an additional one — choose a different name.',
			{ name: trimmed.toLowerCase() },
		)
	}

	// The member that holds the field schemas is not a field.
	if (trimmed === SCHEMA_MEMBER) {
		return t('keepiq', '“{name}” is reserved — choose a different name.', {
			name: SCHEMA_MEMBER,
		})
	}

	if (taken.some((existing) => (existing || '').trim() === trimmed) === true) {
		return t('keepiq', 'That field is already listed.')
	}

	return ''
}

/**
 * Turn a name/value member list into the object the store encrypts.
 *
 * Returns `{}` for an empty list rather than null, and that distinction is load
 * bearing: an empty blob means "this secret has no additional fields", while null
 * means "nothing was sent", which the store reads as "leave whatever is stored
 * alone". Removing the last member has to say the former.
 *
 * A member's `schema` goes into the reserved `@schema` member; a plain-text
 * schema is left out, so a blob without typed fields looks exactly as before.
 *
 * @param {Array<{name: string, value: string, schema?: object}>} members The edited member list.
 *
 * @return {object} A plain object of name → value, possibly empty.
 *
 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-edit-a-secret-from-the-ui
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-custom-fields-carry-a-schema
 */
export function membersToObject(members = []) {
	const fields = {}
	const schemas = {}
	for (const member of members) {
		const name = (member?.name || '').trim()
		if (name !== '') {
			fields[name] = member?.value ?? ''
			if (member?.schema) {
				schemas[name] = member.schema
			}
		}
	}

	return withSchemas(fields, schemas)
}

/**
 * Turn a decrypted blob back into an editable member list.
 *
 * Tolerates the shapes the store can hand over: a parsed object (the normal case),
 * or a string when the ciphertext did not contain JSON. A string is deliberately
 * NOT guessed at — it becomes no members, so an edit cannot silently discard
 * something unparseable by writing a blob that omits it.
 *
 * @param {object|string|null|undefined} blob The decrypted `additionalFields`.
 *
 * Each member carries its schema; a field without one reads as plain text.
 * The reserved `@schema` member itself never becomes a member.
 *
 * @return {Array<{name: string, value: string, schema: object}>} The editable member list.
 *
 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-edit-a-secret-from-the-ui
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-custom-fields-carry-a-schema
 */
export function objectToMembers(blob) {
	if (blob === null || blob === undefined || typeof blob !== 'object') {
		return []
	}

	const schemas = schemasOf(blob)
	return Object.entries(fieldsOf(blob)).map(([name, value]) => ({
		name,
		value: value === null || value === undefined ? '' : String(value),
		schema: schemas[name] ?? { type: 'string' },
	}))
}
