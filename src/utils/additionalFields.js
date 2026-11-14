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

	if (taken.some((existing) => (existing || '').trim() === trimmed) === true) {
		return t('keepiq', 'That field is already listed.')
	}

	return ''
}

/**
 * The kinds a custom field can have. `text` is the default and the reading of
 * every field saved before kinds existed; `hidden` is masked until revealed;
 * `boolean` holds `'true'` or `'false'`.
 *
 * @type {Array<string>}
 */
export const FIELD_KINDS = ['text', 'hidden', 'boolean']

/**
 * A known kind, or `text` for anything else (absent, misspelt, from a newer
 * client).
 *
 * @param {string|undefined|null} kind The stored kind.
 *
 * @return {string} One of FIELD_KINDS.
 *
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-hidden-custom-fields
 */
export function normaliseKind(kind) {
	return FIELD_KINDS.includes(kind) ? kind : 'text'
}

/**
 * Whether a blob entry is the `{ value, kind }` shape a non-text field is
 * stored in.
 *
 * @param {string|number|boolean|object|null|undefined} entry One entry of the decrypted blob.
 *
 * @return {boolean}
 */
function isKindedEntry(entry) {
	return (
		entry !== null
		&& typeof entry === 'object'
		&& !Array.isArray(entry)
		&& Object.hasOwn(entry, 'value')
	)
}

/**
 * The value of one blob entry as text, whichever shape it is stored in.
 *
 * @param {string|number|boolean|object|null|undefined} entry One entry of the decrypted blob.
 *
 * @return {string} The value, '' when absent.
 *
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-hidden-custom-fields
 */
export function fieldValueOf(entry) {
	const value = isKindedEntry(entry) ? entry.value : entry
	return value === null || value === undefined ? '' : String(value)
}

/**
 * The kind of one blob entry: `text` for a plain value, which is every entry
 * written before kinds existed.
 *
 * @param {string|number|boolean|object|null|undefined} entry One entry of the decrypted blob.
 *
 * @return {string} One of FIELD_KINDS.
 *
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-hidden-custom-fields
 */
export function fieldKindOf(entry) {
	return isKindedEntry(entry) ? normaliseKind(entry.kind) : 'text'
}

/**
 * The blob entry of one member. A text field stays a bare string, so a blob
 * without hidden or boolean fields is byte for byte what it was before kinds
 * existed and every older reader keeps working; any other kind is stored as
 * `{ value, kind }` inside the same encrypted blob (design D1).
 *
 * @param {string} value The value.
 * @param {string} kind The kind.
 *
 * @return {string|{value: string, kind: string}}
 *
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-hidden-custom-fields
 */
export function fieldEntry(value, kind) {
	const normalised = normaliseKind(kind)
	return normalised === 'text' ? value : { value, kind: normalised }
}

/**
 * Turn a name/value/kind member list into the object the store encrypts.
 *
 * Returns `{}` for an empty list rather than null, and that distinction is load
 * bearing: an empty blob means "this secret has no additional fields", while null
 * means "nothing was sent", which the store reads as "leave whatever is stored
 * alone". Removing the last member has to say the former.
 *
 * @param {Array<{name: string, value: string, kind?: string}>} members The edited member list.
 *
 * @return {object} A plain object of name → entry, possibly empty.
 *
 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-edit-a-secret-from-the-ui
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-hidden-custom-fields
 */
export function membersToObject(members = []) {
	return members.reduce((acc, member) => {
		const name = (member?.name || '').trim()
		if (name !== '') {
			acc[name] = fieldEntry(member?.value ?? '', member?.kind)
		}

		return acc
	}, {})
}

/**
 * Turn a decrypted blob back into an editable member list.
 *
 * Tolerates the shapes the store can hand over: a parsed object (the normal case),
 * or a string when the ciphertext did not contain JSON. A string is deliberately
 * NOT guessed at — it becomes no members, so an edit cannot silently discard
 * something unparseable by writing a blob that omits it. An entry without a kind
 * reads as `text`.
 *
 * @param {object|string|null|undefined} blob The decrypted `additionalFields`.
 *
 * @return {Array<{name: string, value: string, kind: string}>} The editable member list.
 *
 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-edit-a-secret-from-the-ui
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-hidden-custom-fields
 */
export function objectToMembers(blob) {
	if (blob === null || blob === undefined || typeof blob !== 'object') {
		return []
	}

	return Object.entries(blob).map(([name, entry]) => ({
		name,
		value: fieldValueOf(entry),
		kind: fieldKindOf(entry),
	}))
}
