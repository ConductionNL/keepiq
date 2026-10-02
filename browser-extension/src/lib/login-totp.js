/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The one-time code of the login the extension just filled
 * (vault-login-totp-codes design D3). A login may keep its own seed inside its
 * encrypted additional fields under `totp` (legacy `otp` / `otpauth` are read
 * too, the same rule as the web app's src/totp/seedField.js). That seed wins;
 * only a login without a seed falls back to a separate Authenticator item
 * matched by host, so two logins on one host never get each other's code.
 * The seed is decrypted in the worker, used once and dropped.
 *
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-the-extension-fills-the-logins-own-code
 */

const SEED_FIELD_NAMES = ['totp', 'otp', 'otpauth']

/**
 * Read the seed out of a decrypted additional-fields JSON string.
 *
 * @param {string} json The decrypted blob.
 * @return {string} The seed, or '' when there is none.
 */
export function seedFromBlob(json) {
	if (!json) return ''
	let fields
	try {
		fields = JSON.parse(json)
	} catch {
		return ''
	}
	if (fields === null || typeof fields !== 'object') return ''
	for (const name of SEED_FIELD_NAMES) {
		const value = fields[name]
		if (typeof value === 'string' && value.trim() !== '') {
			return value.trim()
		}
	}
	return ''
}

/**
 * The code to fill and copy after filling `row`.
 *
 * @param {object} row The filled secret's blob row.
 * @param {object} deps The worker's tools.
 * @param {function(string): Promise<string>} deps.decryptField Decrypt one blob.
 * @param {function(string): Promise<{valid: boolean, code?: string}>} deps.compute Compute a code.
 * @param {function(): Promise<string|null>} deps.fallback The host lookup.
 * @return {Promise<string|null>} The code, or null.
 */
export async function loginTotpCode(row, { decryptField, compute, fallback }) {
	let seed = ''
	try {
		seed = row?.additionalFields
			? seedFromBlob(await decryptField(row.additionalFields))
			: ''
	} catch {
		return null
	}
	if (seed === '') {
		return fallback()
	}
	const result = await compute(seed)
	return result?.valid ? result.code : null
}
