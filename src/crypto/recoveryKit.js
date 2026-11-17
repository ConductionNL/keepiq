/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The recovery kit's crypto (crypto-recovery-kit 2.1). The browser makes a
 * random recovery code, derives a key from it with Argon2id and wraps the
 * user's suite private key with that key; only the wrapped copy and the
 * derivation parameters leave the browser. Unwrapping with the code gives the
 * private key back; a wrong code fails the AES-GCM tag and gives nothing.
 */

import { ARGON2_PARAMETERS, decryptSnapshot, encryptSnapshot } from './argon2.js'

/** The wrapped copy's format version. */
export const RECOVERY_KIT_VERSION = 1

/** Crockford base32: no I, L, O or U, so a printed code reads unambiguously. */
const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'

/** 20 random bytes, 160 bits, read as 32 base32 characters. */
const CODE_BYTES = 20

/** Characters per printed group. */
const GROUP = 4

/**
 * A new random recovery code: 32 base32 characters in groups of four.
 *
 * @return {string} For example `7K3M-Q9TB-...`.
 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#requirement-a-user-can-create-a-recovery-kit-that-reopens-their-own-vault
 */
export function generateRecoveryCode() {
	const bytes = crypto.getRandomValues(new Uint8Array(CODE_BYTES))
	let bits = 0
	let value = 0
	let out = ''
	for (const byte of bytes) {
		value = (value << 8) | byte
		bits += 8
		while (bits >= 5) {
			out += ALPHABET[(value >>> (bits - 5)) & 31]
			bits -= 5
		}
		value &= (1 << bits) - 1
	}
	return out.match(new RegExp('.{1,' + GROUP + '}', 'g')).join('-')
}

/**
 * A code as typed, read the way it was printed: case, spaces and dashes do
 * not matter, and O, I and L read as 0, 1 and 1.
 *
 * @param {string} code The code as entered.
 * @return {string} The 32 code characters, or '' when it cannot be a code.
 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#requirement-the-recovery-code-reopens-the-vault-and-sets-a-new-master-password
 */
export function normaliseRecoveryCode(code) {
	const plain = String(code ?? '')
		.toUpperCase()
		.replace(/[\s-]/g, '')
		.replace(/O/g, '0')
		.replace(/[IL]/g, '1')
	if (plain.length !== CODE_BYTES * 8 / 5 || [...plain].some((ch) => !ALPHABET.includes(ch))) {
		return ''
	}
	return plain
}

/**
 * Wrap a private key with a recovery code.
 *
 * @param {string} privateKeyPem The suite private key PEM.
 * @param {string} code The recovery code.
 * @return {Promise<{wrappedKey: string, kdf: string}>} What the server stores: the wrapped key and the derivation parameters, as text.
 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#scenario-a-kit-is-created-without-the-code-leaving-the-browser
 */
export async function wrapWithRecoveryCode(privateKeyPem, code) {
	const secret = normaliseRecoveryCode(code)
	if (!privateKeyPem || secret === '') {
		throw new Error('A private key and a recovery code are required')
	}
	const { blob, salt } = await encryptSnapshot(privateKeyPem, secret)
	return {
		wrappedKey: blob,
		kdf: JSON.stringify({ version: RECOVERY_KIT_VERSION, ...ARGON2_PARAMETERS, salt }),
	}
}

/**
 * The stored derivation parameters, or null when they are not JSON.
 *
 * @param {string} text The stored parameters.
 * @return {object|null}
 */
function parseKdf(text) {
	try {
		return JSON.parse(text ?? '')
	} catch {
		return null
	}
}

/**
 * Unwrap the private key from the stored copy with a recovery code.
 *
 * @param {{wrappedKey: string, kdf: string}} copy The stored copy.
 * @param {string} code The recovery code as entered.
 * @return {Promise<string>} The private key PEM.
 * @throws {Error} When the code is malformed or wrong, or the copy is not one this version reads.
 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#scenario-a-wrong-code-opens-nothing
 */
export async function unwrapWithRecoveryCode(copy, code) {
	const secret = normaliseRecoveryCode(code)
	if (secret === '') {
		throw new Error('This is not a recovery code')
	}
	const kdf = parseKdf(copy?.kdf)
	if (kdf?.version !== RECOVERY_KIT_VERSION || kdf?.kdf !== ARGON2_PARAMETERS.kdf || typeof kdf?.salt !== 'string') {
		throw new Error('This recovery copy cannot be read')
	}
	try {
		return await decryptSnapshot(copy.wrappedKey, kdf.salt, secret)
	} catch {
		throw new Error('The recovery code does not open this vault')
	}
}

/**
 * The kit as a text file: the code, the account and the date.
 *
 * @param {object} kit The kit.
 * @param {string} kit.code The recovery code.
 * @param {string} kit.account The account it opens.
 * @param {string} kit.createdAt When it was made (ISO date).
 * @param {(app: string, text: string) => string} t The translate function.
 * @return {string}
 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#requirement-a-user-can-create-a-recovery-kit-that-reopens-their-own-vault
 */
export function recoveryKitText({ code, account, createdAt }, t) {
	return [
		t('keepiq', 'Keepiq recovery kit'),
		'',
		t('keepiq', 'Account') + ': ' + account,
		t('keepiq', 'Made on') + ': ' + createdAt,
		t('keepiq', 'Recovery code') + ': ' + code,
		'',
		t('keepiq', 'Keep this somewhere safe and offline. Anyone with this code and your account can open your vault. A kit works once: after you use it, make a new one.'),
		'',
	].join('\n')
}
