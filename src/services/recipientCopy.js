// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

/**
 * The value of a decrypted secret as a recipient copy carries it: one plain
 * snapshot for `encryptForRecipient`, and the `encrypted*` columns a
 * register-batch or team-folder row sends. Every share path builds its copy
 * here, so a value field reaches every copy.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-share-a-secret
 */

/**
 * The value fields of a decrypted secret as strings, `''` where empty;
 * `encryptForRecipient` encrypts only the non-empty ones.
 *
 * @param {object} plain The decrypted secret.
 * @return {{key: string, login: string, additionalFields: string, totp: string, passkeys: string}}
 * @spec openspec/specs/user-sharing/spec.md#requirement-share-a-secret
 */
export function recipientFields(plain) {
	return {
		key: plain?.key ?? '',
		login: plain?.login ?? '',
		additionalFields:
			typeof plain?.additionalFields === 'object'
			&& plain.additionalFields !== null
				? JSON.stringify(plain.additionalFields)
				: (plain?.additionalFields ?? ''),
		totp: plain?.totp ?? '',
		passkeys: plain?.passkeys ?? '',
	}
}

/**
 * The ciphertext columns of a copy row from an encrypted snapshot, null for
 * a field the snapshot did not carry.
 *
 * @param {object} blob The `encryptForRecipient` result.
 * @return {object}
 * @spec openspec/specs/user-sharing/spec.md#requirement-share-a-secret
 */
export function copyCiphertext(blob) {
	return {
		encryptedKey: blob.key ?? '',
		encryptedLogin: blob.login ?? null,
		encryptedAdditionalFields: blob.additionalFields ?? null,
		encryptedTotp: blob.totp ?? null,
		encryptedPasskeys: blob.passkeys ?? null,
	}
}
