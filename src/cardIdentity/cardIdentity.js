/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Payment-card and identity payload model (card-identity-items D1/D2).
 *
 * A `card`-typed secret stores `{number, expiry, cvv, pin, cardholder}`
 * and an `identity`-typed secret stores `{firstName, lastName, address,
 * phone, email, bsn}` as a JSON object inside the existing encrypted
 * `key` field. Everything here runs client-side only — the serialized
 * JSON is encrypted before it ever leaves the browser (ADR-003). Card
 * brand and last-4 are DERIVED from the decrypted number on render and
 * never stored or transmitted.
 *
 * @spec openspec/specs/card-identity-items/spec.md#requirement-composite-payload-stored-as-ciphertext-in-the-key-field
 */

/** The system type names. */
export const CARD_TYPE_NAME = 'card'
export const IDENTITY_TYPE_NAME = 'identity'

/** Ordered card payload fields (all optional except number). */
export const CARD_FIELDS = ['number', 'expiry', 'cvv', 'pin', 'cardholder']

/** Ordered identity payload fields (all optional). */
export const IDENTITY_FIELDS = [
	'firstName',
	'lastName',
	'address',
	'phone',
	'email',
	'bsn',
]

/**
 * Serialize a card payload for the encrypted `key` field.
 *
 * @param {object} input Loose card fields.
 * @return {string} JSON string with exactly the card fields.
 */
export function serializeCard(input) {
	const out = {}
	for (const field of CARD_FIELDS) {
		out[field] = input?.[field] != null ? String(input[field]) : ''
	}
	return JSON.stringify(out)
}

/**
 * Serialize an identity payload for the encrypted `key` field.
 *
 * @param {object} input Loose identity fields.
 * @return {string} JSON string with exactly the identity fields.
 */
export function serializeIdentity(input) {
	const out = {}
	for (const field of IDENTITY_FIELDS) {
		out[field] = input?.[field] != null ? String(input[field]) : ''
	}
	return JSON.stringify(out)
}

/**
 * Parse a decrypted card/identity payload; tolerates a legacy plain
 * string by returning null (the caller falls back to generic display).
 *
 * @param {string} raw The decrypted `key` value.
 * @return {object|null} The payload object or null.
 */
export function parsePayload(raw) {
	if (typeof raw !== 'string' || raw === '' || raw[0] !== '{') {
		return null
	}
	try {
		const parsed = JSON.parse(raw)
		return typeof parsed === 'object' && parsed !== null ? parsed : null
	} catch {
		return null
	}
}

/**
 * Derive the card brand from the IIN/BIN prefix of a (decrypted) card
 * number. Unknown prefixes yield "Card" — never a fabricated brand.
 *
 * @param {string} number The decrypted card number (spaces allowed).
 * @return {string} The brand label.
 */
export function cardBrand(number) {
	const digits = String(number ?? '').replace(/\D/g, '')
	if (digits === '') {
		return 'Card'
	}
	if (/^4/.test(digits)) {
		return 'Visa'
	}
	if (/^(5[1-5]|2[2-7])/.test(digits)) {
		return 'Mastercard'
	}
	if (/^3[47]/.test(digits)) {
		return 'American Express'
	}
	if (/^(6011|65|64[4-9])/.test(digits)) {
		return 'Discover'
	}
	if (/^(50|56|57|58|63|67)/.test(digits)) {
		return 'Maestro'
	}
	return 'Card'
}

/**
 * The last four digits of a (decrypted) card number, or ''.
 *
 * @param {string} number The decrypted card number.
 * @return {string}
 */
export function cardLast4(number) {
	const digits = String(number ?? '').replace(/\D/g, '')
	return digits.length >= 4 ? digits.slice(-4) : ''
}

/**
 * Best-effort Luhn check for client-side hinting ONLY — never blocking,
 * never server-side (card-identity-items §3.2).
 *
 * @param {string} number The card number to check.
 * @return {boolean} Whether the number passes the Luhn checksum.
 */
export function luhnValid(number) {
	const digits = String(number ?? '').replace(/\D/g, '')
	if (digits.length < 12) {
		return false
	}
	let sum = 0
	let double = false
	for (let i = digits.length - 1; i >= 0; i--) {
		let d = digits.charCodeAt(i) - 48
		if (double) {
			d *= 2
			if (d > 9) {
				d -= 9
			}
		}
		sum += d
		double = !double
	}
	return sum % 10 === 0
}

/**
 * Best-effort MM/YY expiry format hint (non-blocking).
 *
 * @param {string} expiry The expiry string.
 * @return {boolean}
 */
export function expiryFormatValid(expiry) {
	return /^(0[1-9]|1[0-2])\/\d{2}(\d{2})?$/.test(String(expiry ?? '').trim())
}
