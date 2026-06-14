/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Password-bearing classification heuristic for the client-side health engine.
 *
 * A secret's decrypted `key` value is only meaningfully strength-scored when it
 * is plausibly a human-chosen password. zxcvbn over machine-generated key
 * material (PEM private keys, long random base64/hex API tokens) produces noise,
 * not signal, so such values are excluded from STRENGTH scoring — but they still
 * participate in REUSE detection (the same API key pasted into two secrets is a
 * real finding). The heuristic is a pure function with documented examples; it
 * runs only in the unlocked browser and never leaves it (password-health design
 * D1).
 *
 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-strength-scoring-and-badges
 */

/**
 * The maximum length a value may have to still be treated as a human password.
 * A 100-char passphrase is not weak; scoring it adds nothing.
 *
 * @type {number}
 */
export const MAX_PASSWORD_LENGTH = 72

/**
 * The minimum length at which an all-base64/hex value is treated as key material
 * rather than a password.
 *
 * @type {number}
 */
export const KEY_MATERIAL_MIN_LENGTH = 64

/**
 * Whether a value is plausibly machine-generated key material rather than a
 * human-chosen password. Such values are excluded from strength scoring.
 *
 * Examples classified as key material (NOT strength-scored):
 * - "-----BEGIN PRIVATE KEY-----\n..." (PEM block)
 * - "ssh-rsa AAAAB3Nza..." (OpenSSH public key)
 * - a 64+ char hex string (e.g. a hashed/derived token)
 * - a 64+ char base64 string (e.g. an API token)
 * - any value longer than 72 characters
 *
 * Examples classified as a password (strength-scored):
 * - "Summer2024!"
 * - "correct-horse-battery-staple"
 * - "hunter2"
 *
 * @param {string} value The decrypted value.
 * @return {boolean} True when the value is machine key material.
 */
export function isKeyMaterial(value) {
	if (typeof value !== 'string' || value.length === 0) {
		return true
	}

	if (value.length > MAX_PASSWORD_LENGTH) {
		return true
	}

	// PEM / OpenSSH key headers.
	if (/-----BEGIN [A-Z ]+-----/.test(value) || /^ssh-(rsa|ed25519|dss) /.test(value)) {
		return true
	}

	// Long, single-token base64 or hex blobs with no whitespace.
	if (value.length >= KEY_MATERIAL_MIN_LENGTH && !/\s/.test(value)) {
		if (/^[0-9a-fA-F]+$/.test(value)) {
			return true
		}
		if (/^[A-Za-z0-9+/=_-]+$/.test(value)) {
			return true
		}
	}

	return false
}

/**
 * Whether a value should be strength-scored (i.e. is a human-chosen password).
 *
 * @param {string} value The decrypted value.
 * @return {boolean} True when the value should be strength-scored.
 */
export function isPasswordBearing(value) {
	return typeof value === 'string' && value.length > 0 && !isKeyMaterial(value)
}
