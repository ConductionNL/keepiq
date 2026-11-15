/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The public parameters of every scheme that protects a vault, assembled from
 * the crypto modules that use them. The encryption overview in user settings
 * and the generated docs page both render from `CRYPTO_PARAMETERS`, so neither
 * can drift from the code: change a constant in `rsa.js`, `aes.js` or
 * `argon2.js` and both follow without another edit.
 *
 * Only algorithm names and numbers live here — never key material, salts or
 * envelopes.
 *
 * @spec openspec/changes/crypto-vault-encryption-details/specs/vault-encryption-details/spec.md#requirement-the-user-sees-what-protects-the-vault
 */

import { CONTENT_KEY_PARAMETERS, MASTER_KEY_PARAMETERS } from './aes.js'
import { ARGON2_PARAMETERS } from './argon2.js'
import { RSA_PARAMETERS } from './rsa.js'

/**
 * @type {Readonly<{
 *   secretValues: Readonly<object>,
 *   privateKey: Readonly<object>,
 *   attachments: Readonly<object>,
 *   passwordProtected: Readonly<object>,
 * }>}
 */
export const CRYPTO_PARAMETERS = Object.freeze({
	// Secret values: encrypted to the user's own RSA key pair.
	secretValues: RSA_PARAMETERS,
	// The RSA private key: wrapped under a key derived from the master password.
	privateKey: MASTER_KEY_PARAMETERS,
	// Attachments: a fresh AES key per file, wrapped with the user's RSA key.
	attachments: Object.freeze({
		cipher: CONTENT_KEY_PARAMETERS.name,
		keyBits: CONTENT_KEY_PARAMETERS.length,
		keyWrap: RSA_PARAMETERS.algorithm,
		keyWrapBits: RSA_PARAMETERS.keyBits,
	}),
	// Encrypted backups, link shares and password-protected sends.
	passwordProtected: ARGON2_PARAMETERS,
})

/**
 * Format a number for display with thousands separators, independent of the
 * browser locale so the docs build and the app render the same digits.
 *
 * @param {number} value The number.
 * @return {string} e.g. `600,000`.
 */
function grouped(value) {
	return String(value).replace(/\B(?=(\d{3})+(?!\d))/g, ',')
}

/**
 * The technical names of each scheme, one line per protected thing, as an
 * auditor would write them. Shared by the settings overview and the docs page.
 *
 * @param {object} [parameters] The parameters to describe (defaults to the code's).
 * @return {{secretValues: string, privateKey: string, attachments: string, passwordProtected: string}}
 * @spec openspec/changes/crypto-vault-encryption-details/specs/vault-encryption-details/spec.md#requirement-the-user-sees-what-protects-the-vault
 */
export function technicalNames(parameters = CRYPTO_PARAMETERS) {
	const { secretValues, privateKey, attachments, passwordProtected } = parameters
	return {
		secretValues: `${secretValues.algorithm}, ${secretValues.hash}, ${secretValues.keyBits}-bit keys`,
		privateKey: `AES-${privateKey.keyBits}-GCM, ${privateKey.kdf}-${privateKey.kdfHash.replace('-', '')}, ${grouped(privateKey.iterations)} iterations`,
		attachments: `AES-${attachments.keyBits}-GCM per file, key wrapped with ${attachments.keyWrap} ${attachments.keyWrapBits}-bit`,
		passwordProtected: `${passwordProtected.kdf} (memory ${passwordProtected.memoryKiB / 1024} MiB, ${passwordProtected.iterations} iterations, parallelism ${passwordProtected.parallelism}), AES-${passwordProtected.keyBits}-GCM`,
	}
}
