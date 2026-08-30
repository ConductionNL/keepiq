/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Canonical passkey credential schema (passkey-item-type D2).
 *
 * A `passkey`-typed secret stores one WebAuthn credential as a canonical
 * JSON object inside the existing encrypted `key` field. The core fields
 * align 1:1 with the FIDO Credential Exchange Format (CXF) passkey
 * entity (`credentialId`, `rpId`, `rpName`, `userName`, `userDisplayName`,
 * `userHandle`, `privateKey`); `algorithm`, `counter`, `transports`, and
 * `createdAt` are documented Keepiq extensions. Everything here runs
 * client-side only — the serialized JSON is encrypted before it ever
 * leaves the browser (ADR-003).
 *
 * @spec openspec/changes/passkey-item-type/specs/passkey-item-type/spec.md#requirement-canonical-cxf-aligned-passkey-credential-schema
 */

/** The system type name for passkey secrets. */
export const PASSKEY_TYPE_NAME = 'passkey'

/** Fields a credential must carry to count as a usable passkey. */
const REQUIRED_FIELDS = ['credentialId', 'rpId', 'privateKey']

/**
 * Build a canonical passkey credential object from loose input.
 *
 * Required: `credentialId`, `rpId`, `privateKey`. Optional fields default
 * honestly (`counter: 0`, `transports: []`, `createdAt: now`) — defaults
 * are only applied for the documented Keepiq extension fields, never
 * for credential material (passkey-item-type D2).
 *
 * @param {object} input Loose credential fields.
 * @return {object|null} The canonical credential, or null when a
 *   required field is missing.
 */
export function buildPasskeyCredential(input) {
	if (input == null || typeof input !== 'object') {
		return null
	}
	for (const field of REQUIRED_FIELDS) {
		if (typeof input[field] !== 'string' || input[field] === '') {
			return null
		}
	}
	return {
		credentialId: String(input.credentialId),
		rpId: String(input.rpId),
		rpName: input.rpName != null ? String(input.rpName) : '',
		userName: input.userName != null ? String(input.userName) : '',
		userDisplayName:
			input.userDisplayName != null ? String(input.userDisplayName) : '',
		userHandle: input.userHandle != null ? String(input.userHandle) : '',
		privateKey: String(input.privateKey),
		algorithm: Number.isInteger(input.algorithm) ? input.algorithm : -7,
		counter: Number.isInteger(input.counter) ? input.counter : 0,
		transports: Array.isArray(input.transports)
			? input.transports.map(String)
			: [],
		createdAt:
			typeof input.createdAt === 'string' && input.createdAt !== ''
				? input.createdAt
				: new Date().toISOString(),
	}
}

/**
 * Serialize a canonical credential to the JSON string stored (encrypted)
 * in the secret's `key` field.
 *
 * @param {object} credential The canonical credential object.
 * @return {string|null} The JSON string, or null for invalid input.
 */
export function serializePasskey(credential) {
	const canonical = buildPasskeyCredential(credential)
	return canonical === null ? null : JSON.stringify(canonical)
}

/**
 * Parse a decrypted `key` value into a canonical passkey credential.
 *
 * Honest failure: anything unparseable or missing required credential
 * fields yields null — the UI shows an explicit invalid state and never
 * fabricates fields (passkey-item-type D7).
 *
 * @param {string} json The decrypted `key` value.
 * @return {object|null} The canonical credential, or null when invalid.
 */
export function parsePasskey(json) {
	if (typeof json !== 'string' || json.trim() === '') {
		return null
	}
	let raw
	try {
		raw = JSON.parse(json)
	} catch {
		return null
	}
	return buildPasskeyCredential(raw)
}

/**
 * The RP id to mirror into the plaintext `url` field (passkey-item-type
 * D3) — a registrable public domain, never credential material.
 *
 * @param {string} json The (decrypted or pre-encryption) `key` JSON.
 * @return {string|null} The RP id, or null when the JSON is not a valid
 *   passkey credential.
 */
export function passkeyRpId(json) {
	const credential = parsePasskey(json)
	return credential === null ? null : credential.rpId
}

/**
 * Truncate a credential id for display (never render it in full inline).
 *
 * @param {string} credentialId The base64url credential id.
 * @return {string} A short display form like "AbCdEfGh…".
 */
export function truncateCredentialId(credentialId) {
	const id = String(credentialId ?? '')
	return id.length <= 12 ? id : id.slice(0, 12) + '…'
}
