/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * A one-time code and passkeys found next to a login in an import file go on
 * that login's own `totp` and `passkeys` fields (keepiq#1327), in the
 * plaintext shape the secret holds them: the seed as an entered key is
 * stored (authenticatorKey.js), the passkeys as a JSON array of canonical
 * credentials (loginPasskeys.js). A row without either has no such
 * property, so the commit sends nothing for it.
 *
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-import-and-export-keep-a-logins-code-and-passkeys-on-it
 */

import { passkeyEntries } from '../passkey/loginPasskeys.js'
import {
	authenticatorKeyError,
	normaliseAuthenticatorKey,
} from '../totp/authenticatorKey.js'

/** Why a second seed for the same login is not imported. */
export const SECOND_TOTP_REASON =
	'A second, different one-time code; only the first is imported'

/** Why a second passkey with a known credential id is not imported. */
export const SECOND_PASSKEY_REASON =
	'A second, different passkey with the same credential id and RP id; only the first is imported'

/**
 * Put a seed on a row's `totp` field when it is an authenticator key the code
 * generator reads (an `otpauth://totp/` link or a base32 secret), normalised
 * as the create dialog stores an entered key. The first seed wins.
 *
 * @param {object} row The normalized row (mutated).
 * @param {string|null|undefined} seed The seed from the source.
 * @return {string|null} null when the seed is on the row (or there is none);
 *   otherwise why not: it is not an authenticator key, or the row already
 *   holds a different one.
 */
export function attachTotp(row, seed) {
	if (String(seed ?? '').trim() === '') {
		return null
	}
	const error = authenticatorKeyError(seed)
	if (error !== '') {
		return error
	}
	const key = normaliseAuthenticatorKey(seed)
	if (typeof row.totp !== 'string' || row.totp === '') {
		row.totp = key
		return null
	}
	return row.totp === key ? null : SECOND_TOTP_REASON
}

/**
 * Keep a source value that did not go on a value field as an additional
 * field of the row, so nothing is lost.
 *
 * @param {object} row The normalized row (mutated).
 * @param {string} key The additional field's name.
 * @param {string} value Its value.
 * @return {object} The row.
 */
export function keepAsAdditionalField(row, key, value) {
	const fields =
		row.additionalFields && typeof row.additionalFields === 'object'
			? row.additionalFields
			: {}
	row.additionalFields = { ...fields, [key]: value }
	return row
}

/**
 * Add canonical passkey credentials to a row's `passkeys` field. A credential
 * whose credential id, RP id and private key match one already there is the
 * same passkey and is folded in; one with the same credential id and RP id
 * but another private key is not added.
 *
 * @param {object} row The normalized row (mutated).
 * @param {Array<object>} credentials Canonical credentials (passkey.js).
 * @return {Array<object>} The credentials not added because they clash.
 */
export function attachPasskeys(row, credentials) {
	const list = passkeyEntries(row.passkeys ?? '').map(
		({ credential }) => credential,
	)
	const clashes = []
	for (const credential of credentials) {
		const known = list.find(
			(entry) =>
				entry.credentialId === credential.credentialId
				&& entry.rpId === credential.rpId,
		)
		if (!known) {
			list.push(credential)
		} else if (known.privateKey !== credential.privateKey) {
			clashes.push(credential)
		}
	}
	if (list.length > 0) {
		row.passkeys = JSON.stringify(list)
	}
	return clashes
}
