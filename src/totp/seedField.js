/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The TOTP seed a login keeps inside its encrypted additional fields
 * (vault-login-totp-codes design D1/D2). The key is `totp`, the name the
 * Bitwarden import already writes; legacy `otp` and `otpauth` members are read
 * as the seed and written back under `totp` on the next edit. The seed is
 * secret under the password's rules: it lives only in decrypted memory and is
 * never shown among the free fields.
 *
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
 */

/**
 * The member names read as the seed, in order of preference.
 *
 * @type {Array<string>}
 */
export const SEED_FIELD_NAMES = ['totp', 'otp', 'otpauth']

/**
 * Split a decrypted additional-fields blob into the seed and the other members.
 *
 * @param {object|string|null|undefined} blob The decrypted additional fields.
 * @return {{seed: string, rest: object}} The seed ('' when none) and the
 *   remaining members.
 */
export function seedFromAdditionalFields(blob) {
	if (blob === null || blob === undefined || typeof blob !== 'object') {
		return { seed: '', rest: {} }
	}
	let seed = ''
	for (const name of SEED_FIELD_NAMES) {
		const value = blob[name]
		if (seed === '' && typeof value === 'string' && value.trim() !== '') {
			seed = value.trim()
		}
	}
	const rest = {}
	for (const [name, value] of Object.entries(blob)) {
		if (!SEED_FIELD_NAMES.includes(name)) {
			rest[name] = value
		}
	}
	return { seed, rest }
}

/**
 * Put a seed into an additional-fields object under `totp`, dropping the
 * legacy names; a blank seed removes every seed member.
 *
 * @param {object} fields The other members.
 * @param {string} seed The seed, or '' to clear it.
 * @return {object} A new object.
 */
export function withSeed(fields, seed) {
	const next = {}
	for (const [name, value] of Object.entries(fields || {})) {
		if (!SEED_FIELD_NAMES.includes(name)) {
			next[name] = value
		}
	}
	const clean = String(seed ?? '').trim()
	if (clean !== '') {
		next.totp = clean
	}
	return next
}
