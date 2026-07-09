/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Pure client-side TOTP (RFC 6238) parser + one-time-code generator.
 *
 * A `totp` secret's decrypted `key` holds either an `otpauth://totp/...` URI
 * (Key Uri Format) or a bare base32 secret. This module parses that value and
 * computes the current 6/8-digit code with WebCrypto HMAC, entirely in the
 * browser. The seed, the imported HMAC `CryptoKey`, and the generated code are
 * never returned to or persisted by anything outside the caller's memory — the
 * caller (the TotpDisplay component) drops them on vault lock, mirroring the
 * password-health no-leak contract. Nothing here touches the network or any
 * Web Storage API.
 *
 * @spec openspec/changes/add-totp-secrets/specs/secrets/spec.md#requirement-client-side-totp-code-generation
 */

/** RFC 6238 defaults applied to a bare base32 secret / omitted URI params. */
export const TOTP_DEFAULTS = { algorithm: 'SHA1', digits: 6, period: 30 }

/** WebCrypto hash name for each supported OTP algorithm token. */
const HASH_BY_ALGORITHM = {
	SHA1: 'SHA-1',
	SHA256: 'SHA-256',
	SHA512: 'SHA-512',
}

const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'

/**
 * Decode a base32 (RFC 4648, no padding required) string into bytes.
 *
 * Whitespace and `=` padding are ignored; the input is upper-cased. Any
 * character outside the base32 alphabet makes the seed invalid.
 *
 * @param {string} input The base32 secret.
 * @return {Uint8Array} The decoded bytes.
 * @throws {Error} When the input contains a non-base32 character or is empty.
 */
export function base32Decode(input) {
	const clean = String(input ?? '').toUpperCase().replace(/=+$/, '').replace(/\s+/g, '')
	if (clean === '') {
		throw new Error('Empty base32 secret')
	}
	let bits = 0
	let value = 0
	const out = []
	for (const char of clean) {
		const idx = BASE32_ALPHABET.indexOf(char)
		if (idx === -1) {
			throw new Error('Invalid base32 character')
		}
		value = (value << 5) | idx
		bits += 5
		if (bits >= 8) {
			bits -= 8
			out.push((value >>> bits) & 0xff)
		}
	}
	if (out.length === 0) {
		throw new Error('Base32 secret too short')
	}
	return new Uint8Array(out)
}

/**
 * Parse an `otpauth://totp/...` URI or a bare base32 secret into TOTP params.
 *
 * Recognised URI query params: `secret` (required base32), `algorithm`
 * (SHA1|SHA256|SHA512), `digits` (6|8), `period` (seconds), plus the `issuer`
 * and the label's account name for display. A bare base32 string (no `otpauth`
 * scheme) is treated with the RFC 6238 defaults.
 *
 * @param {string} raw The decrypted seed value.
 * @return {{secret: string, algorithm: string, digits: number, period: number,
 *   issuer: (string|null), account: (string|null)}} The parsed parameters.
 * @throws {Error} When the value is not a valid `otpauth://totp` URI or base32
 *   secret (the caller renders an explicit invalid-seed state — never a code).
 */
export function parseOtpauth(raw) {
	const value = String(raw ?? '').trim()
	if (value === '') {
		throw new Error('Empty TOTP seed')
	}

	// Bare base32 secret (no scheme): validate + apply defaults.
	if (!/^otpauth:\/\//i.test(value)) {
		base32Decode(value) // Throws when not valid base32.
		return {
			secret: value.replace(/\s+/g, '').toUpperCase(),
			algorithm: TOTP_DEFAULTS.algorithm,
			digits: TOTP_DEFAULTS.digits,
			period: TOTP_DEFAULTS.period,
			issuer: null,
			account: null,
		}
	}

	let url
	try {
		url = new URL(value)
	} catch {
		throw new Error('Malformed otpauth URI')
	}
	if (url.host.toLowerCase() !== 'totp') {
		// HOTP and other otpauth types are unsupported.
		throw new Error('Not an otpauth://totp URI')
	}

	const params = url.searchParams
	const secretParam = params.get('secret')
	if (!secretParam) {
		throw new Error('otpauth URI has no secret')
	}
	base32Decode(secretParam) // Validate.

	const algorithm = normaliseAlgorithm(params.get('algorithm'))
	const digits = normaliseDigits(params.get('digits'))
	const period = normalisePeriod(params.get('period'))

	// Label is `otpauth://totp/Issuer:Account` — decode for display only.
	const label = decodeURIComponent(url.pathname.replace(/^\//, ''))
	let account = label || null
	let issuer = params.get('issuer')
	if (label.includes(':')) {
		const [labelIssuer, ...rest] = label.split(':')
		if (!issuer) {
			issuer = labelIssuer.trim() || null
		}
		account = rest.join(':').trim() || null
	}

	return {
		secret: secretParam.replace(/\s+/g, '').toUpperCase(),
		algorithm,
		digits,
		period,
		issuer: issuer || null,
		account,
	}
}

/**
 * Normalise an algorithm token to one of SHA1|SHA256|SHA512 (default SHA1).
 *
 * @param {string|null} raw The raw algorithm token.
 * @return {string} A supported algorithm token.
 * @throws {Error} When the token names an unsupported algorithm.
 */
function normaliseAlgorithm(raw) {
	if (raw == null || raw === '') {
		return TOTP_DEFAULTS.algorithm
	}
	const token = String(raw).toUpperCase()
	if (!HASH_BY_ALGORITHM[token]) {
		throw new Error(`Unsupported TOTP algorithm: ${token}`)
	}
	return token
}

/**
 * Normalise the digit count to 6 or 8 (default 6).
 *
 * @param {string|null} raw The raw digits token.
 * @return {number} 6 or 8.
 */
function normaliseDigits(raw) {
	const n = parseInt(raw ?? '', 10)
	return n === 8 ? 8 : TOTP_DEFAULTS.digits
}

/**
 * Normalise the period to a positive integer number of seconds (default 30).
 *
 * @param {string|null} raw The raw period token.
 * @return {number} The period in seconds.
 */
function normalisePeriod(raw) {
	const n = parseInt(raw ?? '', 10)
	return Number.isFinite(n) && n > 0 ? n : TOTP_DEFAULTS.period
}

/**
 * Compute the RFC 6238 one-time code for parsed params at a given time.
 *
 * Computes `HMAC-<alg>(secret, counter)` where `counter = floor(epochSeconds /
 * period)`, then applies RFC 4226 dynamic truncation to `digits`. The HMAC key
 * is imported non-extractable and only lives for this call.
 *
 * @param {object} params The parsed TOTP parameters.
 * @param {string} params.secret The base32 secret.
 * @param {string} params.algorithm SHA1 | SHA256 | SHA512.
 * @param {number} params.digits The code length (6 or 8).
 * @param {number} params.period The window length in seconds.
 * @param {number} [epochMs] Reference time in ms (defaults to Date.now()).
 * @return {Promise<string>} The zero-padded one-time code.
 */
export async function generateTotp(params, epochMs = Date.now()) {
	const { secret, algorithm, digits, period } = params
	const keyBytes = base32Decode(secret)
	const hash = HASH_BY_ALGORITHM[algorithm] ?? 'SHA-1'
	const counter = Math.floor(epochMs / 1000 / period)

	// 8-byte big-endian counter.
	const counterBytes = new Uint8Array(8)
	let temp = counter
	for (let i = 7; i >= 0; i--) {
		counterBytes[i] = temp & 0xff
		temp = Math.floor(temp / 256)
	}

	const cryptoKey = await crypto.subtle.importKey(
		'raw',
		keyBytes,
		{ name: 'HMAC', hash: { name: hash } },
		false,
		['sign'],
	)
	const sigBuffer = await crypto.subtle.sign('HMAC', cryptoKey, counterBytes)
	const hmac = new Uint8Array(sigBuffer)

	// RFC 4226 dynamic truncation.
	const offset = hmac[hmac.length - 1] & 0x0f
	const binary = ((hmac[offset] & 0x7f) << 24)
		| ((hmac[offset + 1] & 0xff) << 16)
		| ((hmac[offset + 2] & 0xff) << 8)
		| (hmac[offset + 3] & 0xff)

	const code = binary % (10 ** digits)
	return String(code).padStart(digits, '0')
}

/**
 * Seconds remaining in the current time window (for the countdown ring).
 *
 * @param {number} period The window length in seconds.
 * @param {number} [epochMs] Reference time in ms (defaults to Date.now()).
 * @return {number} Whole seconds remaining until the next window.
 */
export function secondsRemaining(period, epochMs = Date.now()) {
	const epochSeconds = Math.floor(epochMs / 1000)
	return period - (epochSeconds % period)
}
