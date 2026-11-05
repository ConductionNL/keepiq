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

import CARD_BRANDS from './card-brands.json'

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

const digitsOf = (number) => String(number ?? '').replace(/\D/g, '')

// Ranges are compared at this many digits to find the narrowest (design D3).
const RANGE_WIDTH = 8

const BRAND_RANGES = CARD_BRANDS.brands.flatMap((brand) => brand.ranges.map((range) => {
	const [start, end] = Array.isArray(range) ? range : [range, range]
	const width = Number(end.padEnd(RANGE_WIDTH, '9')) - Number(start.padEnd(RANGE_WIDTH, '0')) + 1
	return { brand, start, end, width }
}))

/**
 * The brand table entry for a (decrypted) card number: the brand of the
 * narrowest matching range, or null when none matches or two brands tie.
 *
 * @param {string} number The decrypted card number (spaces allowed).
 * @return {object|null} The `card-brands.json` entry.
 * @spec openspec/changes/vault-card-brand-formats/specs/card-identity-items/spec.md#requirement-card-brand-detection-from-a-brand-table
 */
export function cardBrandInfo(number) {
	const digits = digitsOf(number)
	let best = null
	let tied = false
	for (const range of BRAND_RANGES) {
		const head = digits.slice(0, range.start.length)
		// Equal-length digit strings compare as numbers do.
		if (head.length < range.start.length || head < range.start || head > range.end) {
			continue
		}
		if (best === null || range.width < best.width) {
			best = range
			tied = false
		} else if (range.width === best.width && range.brand !== best.brand) {
			tied = true
		}
	}
	return best !== null && !tied ? best.brand : null
}

/**
 * The brand label of a (decrypted) card number. Unknown prefixes yield
 * "Card" — never a fabricated brand.
 *
 * @param {string} number The decrypted card number (spaces allowed).
 * @return {string} The brand label.
 * @spec openspec/changes/vault-card-brand-formats/specs/card-identity-items/spec.md#requirement-card-brand-detection-from-a-brand-table
 */
export function cardBrand(number) {
	return cardBrandInfo(number)?.label ?? 'Card'
}

/**
 * The digits split at the brand's gaps for their length, every four for
 * an unknown brand.
 *
 * @param {string} digits Digits only.
 * @return {string[]} The groups.
 */
function cardGroups(digits) {
	const gaps = cardBrandInfo(digits)?.gaps
	const at = gaps ? (gaps[digits.length] ?? gaps.default) : [4, 8, 12, 16]
	const groups = []
	let from = 0
	for (const gap of at.filter((gap) => gap < digits.length)) {
		groups.push(digits.slice(from, gap))
		from = gap
	}
	groups.push(digits.slice(from))
	return groups
}

/**
 * A card number grouped as its brand prints it (Amex 4-6-5); a value
 * without digits is returned as stored.
 *
 * @param {string} number The decrypted card number.
 * @return {string}
 * @spec openspec/changes/vault-card-brand-formats/specs/card-identity-items/spec.md#requirement-type-specific-presentation-and-masked-reveal
 */
export function formatCardNumber(number) {
	const digits = digitsOf(number)
	return digits === '' ? String(number ?? '') : cardGroups(digits).join(' ')
}

/**
 * A card number masked in its brand's grouping: the first group and the
 * last four digits shown, fully dotted under 12 digits.
 *
 * @param {string} number The decrypted card number.
 * @return {string}
 * @spec openspec/changes/vault-card-brand-formats/specs/card-identity-items/spec.md#requirement-type-specific-presentation-and-masked-reveal
 */
export function maskCardNumber(number) {
	const digits = digitsOf(number)
	if (digits.length < 12) {
		return '•••• •••• •••• ••••'
	}
	const groups = cardGroups(digits)
	const shown = groups[0].length
	let index = 0
	return groups.map((group) => [...group].map((digit) => {
		const keep = index < shown || index >= digits.length - 4
		index++
		return keep ? digit : '•'
	}).join('')).join(' ')
}

/**
 * A non-blocking hint about a card number: a length its brand does not
 * issue (or outside 12–19 without a brand), else a failed Luhn check,
 * which UnionPay numbers need not pass.
 *
 * @param {string} number The card number as typed.
 * @return {{code: string, brand?: string}|null}
 * @spec openspec/changes/vault-card-brand-formats/specs/card-identity-items/spec.md#requirement-non-blocking-card-format-hints
 */
export function cardNumberHint(number) {
	const digits = digitsOf(number)
	if (digits === '') {
		return null
	}
	const brand = cardBrandInfo(digits)
	if (brand ? !brand.lengths.includes(digits.length) : digits.length < 12 || digits.length > 19) {
		return brand ? { code: 'length', brand: brand.label } : { code: 'lengthUnknown' }
	}
	return brand?.luhn !== false && !luhnValid(digits) ? { code: 'luhn' } : null
}

/**
 * A non-blocking hint when a security code's length is not the one the
 * card's brand uses; nothing for an unknown brand or one without a code.
 *
 * @param {string} number The card number.
 * @param {string} code The security code as typed.
 * @return {{brand: string, name: string, size: number}|null}
 * @spec openspec/changes/vault-card-brand-formats/specs/card-identity-items/spec.md#requirement-non-blocking-card-format-hints
 */
export function securityCodeHint(number, code) {
	const typed = String(code ?? '').trim()
	const expected = cardBrandInfo(number)?.code
	if (typed === '' || !expected || typed.length === expected.size) {
		return null
	}
	return { brand: cardBrand(number), name: expected.name, size: expected.size }
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
