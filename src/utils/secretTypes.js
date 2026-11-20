/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Secret-type display helpers.
 *
 * The type records come from the server with ENGLISH labels (seeded by
 * lib/Repair/SeedSecretTypes.php — "Login", "Payment Card", …), so every
 * surface that printed `type.label` raw showed English regardless of the
 * user's language. Routing the label through the translator fixes that:
 * the system labels exist as keys in the l10n catalogs, and an unknown
 * (custom) label falls through `t()` unchanged — the same dynamic-key
 * pattern KeepiqAppNav uses for manifest menu labels.
 */

import { translate as t } from '@nextcloud/l10n'

/**
 * The localized display label for a secret type.
 *
 * @param {object|null|undefined} type The type record ({ label, name }).
 * @return {string} The translated label, or '' without a type.
 * @spec openspec/specs/secrets/spec.md#requirement-secret-types
 */
export function secretTypeLabel(type) {
	const label = type ? type.label || type.name : ''
	return label ? t('keepiq', label) : ''
}

/**
 * System types new items are not created as: a login carries its own
 * one-time code and passkeys. Items of these types still show, edit, share
 * and filter as any other.
 */
export const NOT_CREATABLE_TYPE_NAMES = Object.freeze(['totp', 'passkey'])

/**
 * Whether new items may be created as this type.
 *
 * @param {object|null|undefined} type The type record ({ name }).
 * @return {boolean}
 * @spec openspec/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
 */
export function isCreatableType(type) {
	return Boolean(type) && !NOT_CREATABLE_TYPE_NAMES.includes(type.name)
}

/**
 * System types no request may ask for, as the server's
 * SecretRequestPolicy::NOT_REQUESTABLE_TYPE_NAMES: asking a stranger for
 * their card or identity is what a scam looks like, and whoever holds an
 * authenticator seed or a passkey's private key can sign in as its owner.
 */
export const NOT_REQUESTABLE_TYPE_NAMES = Object.freeze([
	'card',
	'identity',
	'totp',
	'passkey',
])

/**
 * Whether a request may ask for an item of this type.
 *
 * @param {string} typeName The type's system name.
 * @return {boolean}
 * @spec openspec/changes/vault-builtin-type-fields/specs/secret-requests/spec.md#requirement-item-types-that-cannot-be-requested
 */
export function isRequestableTypeName(typeName) {
	return !NOT_REQUESTABLE_TYPE_NAMES.includes(typeName)
}
