/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Reading a secret's `passkeys` value: a JSON array of credential objects,
 * each the shape a standalone passkey item holds in its `key` (passkey.js),
 * or '' when there are none.
 *
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
 */

/**
 * The stored array, or null when the value is empty or not a JSON array.
 *
 * @param {string} json The decrypted `passkeys` value.
 * @return {Array|null}
 * @spec exclude Parsing helper for the two functions below.
 */
function storedArray(json) {
	if (typeof json !== 'string' || json.trim() === '') {
		return null
	}
	try {
		const parsed = JSON.parse(json)
		return Array.isArray(parsed) ? parsed : null
	} catch {
		return null
	}
}

/**
 * The credential objects in a decrypted `passkeys` value, each with its
 * position in the stored array. Entries that are not objects are skipped
 * here but kept in storage.
 *
 * @param {string} json The decrypted `passkeys` value.
 * @return {Array<{index: number, credential: object}>} [] when there are none or it does not parse.
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
 */
export function passkeyEntries(json) {
	return (storedArray(json) ?? []).flatMap((credential, index) =>
		credential !== null
		&& typeof credential === 'object'
		&& !Array.isArray(credential)
			? [{ index, credential }]
			: [],
	)
}

/**
 * The `passkeys` value without the entry at `index` of the stored array:
 * every other entry is kept exactly as stored, whatever its shape, and ''
 * is returned only when nothing remains. A value that is not an array is
 * returned unchanged.
 *
 * @param {string} json The decrypted `passkeys` value.
 * @param {number} index The stored position of the entry to drop.
 * @return {string} The value to save.
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
 */
export function withoutPasskey(json, index) {
	const stored = storedArray(json)
	if (stored === null) {
		return json
	}
	const remaining = stored.filter((_entry, i) => i !== index)
	return remaining.length > 0 ? JSON.stringify(remaining) : ''
}
