/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The authenticator key a user enters on a login: an `otpauth://totp/` link
 * or a bare base32 secret, typed, pasted or read from a QR image. Validation
 * runs through the same parser that later generates the codes, so a key that
 * passes here always yields a code. Nothing here touches the network or any
 * Web Storage API.
 *
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
 */

import { translate as t } from '@nextcloud/l10n'
import { parseOtpauth, TOTP_DEFAULTS } from './totp.js'

const OTPAUTH_SCHEME = /^otpauth:\/\//i

/**
 * The key to store for TOTP parameters held one by one, as CXF and KeePass
 * hold them: the bare base32 secret when period, digits and algorithm are
 * the defaults (30, 6, SHA1) and there is no issuer or account, else an
 * `otpauth://totp/` link carrying them, so the code display keeps its label.
 *
 * @param {object} parts The parameters.
 * @param {string} parts.secret The base32 secret.
 * @param {number|string|null} [parts.period] The time step in seconds.
 * @param {number|string|null} [parts.digits] The code length.
 * @param {string|null} [parts.algorithm] `sha1`, `SHA-256`, `HMAC-SHA-512` and the like.
 * @param {string|null} [parts.issuer] The issuer, for the link's label.
 * @param {string|null} [parts.account] The account name, for the link's label.
 * @return {string} The key, '' when there is no secret.
 * @throws {Error} When the code generator cannot use these parameters.
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-import-and-export-keep-a-logins-code-and-passkeys-on-it
 */
export function authenticatorKeyFromParts(parts) {
	const secret = compactSecret(parts.secret)
	if (secret === '') {
		return ''
	}
	const totp = totpParameters(parts)
	if (isBareSecret(totp)) {
		parseOtpauth(secret)
		return secret
	}
	return otpauthLink(secret, totp)
}

/**
 * A base32 secret without its spacing, upper-cased.
 *
 * @param {string|null|undefined} raw The secret as held.
 * @return {string} The compacted secret, '' when there is none.
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-import-and-export-keep-a-logins-code-and-passkeys-on-it
 */
function compactSecret(raw) {
	return String(raw ?? '')
		.replace(/\s+/g, '')
		.toUpperCase()
}

/**
 * A parameter as given, or its fallback when it is missing or empty.
 *
 * @param {number|string|null|undefined} value The given parameter.
 * @param {number|string} fallback The default.
 * @return {number|string} The value to use.
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-import-and-export-keep-a-logins-code-and-passkeys-on-it
 */
function given(value, fallback) {
	return value === null || value === undefined || value === '' ? fallback : value
}

/**
 * An algorithm name in the generator's token form: `HMAC-SHA-256` becomes `SHA256`.
 *
 * @param {string|null|undefined} algorithm The algorithm as held.
 * @return {string} The token.
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-import-and-export-keep-a-logins-code-and-passkeys-on-it
 */
function algorithmToken(algorithm) {
	return String(given(algorithm, TOTP_DEFAULTS.algorithm))
		.toUpperCase()
		.replace(/^HMAC-/, '')
		.replaceAll('-', '')
}

/**
 * The period, digits, algorithm, issuer and label the parts describe.
 *
 * @param {object} parts The parameters, as authenticatorKeyFromParts takes them.
 * @return {{period: number, digits: number, algorithm: string, issuer: string, label: string}} The parameters.
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-import-and-export-keep-a-logins-code-and-passkeys-on-it
 */
function totpParameters(parts) {
	const issuer = String(parts.issuer ?? '')
	return {
		period: Number(given(parts.period, TOTP_DEFAULTS.period)),
		digits: Number(given(parts.digits, TOTP_DEFAULTS.digits)),
		algorithm: algorithmToken(parts.algorithm),
		issuer,
		label: [issuer, String(parts.account ?? '')]
			.filter((part) => part !== '')
			.join(':'),
	}
}

/**
 * Whether the parameters are the defaults with no label, so the bare secret says it all.
 *
 * @param {{period: number, digits: number, algorithm: string, label: string}} totp The parameters.
 * @return {boolean} True when the bare secret is the key.
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-import-and-export-keep-a-logins-code-and-passkeys-on-it
 */
function isBareSecret(totp) {
	return totp.period === TOTP_DEFAULTS.period
		&& totp.digits === TOTP_DEFAULTS.digits
		&& totp.algorithm === TOTP_DEFAULTS.algorithm
		&& totp.label === ''
}

/**
 * The `otpauth://totp/` link carrying the secret and its parameters, checked
 * against the parser that later generates the codes.
 *
 * @param {string} secret The compacted base32 secret.
 * @param {{period: number, digits: number, algorithm: string, issuer: string, label: string}} totp The parameters.
 * @return {string} The link.
 * @throws {Error} When the code generator cannot use these parameters.
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-import-and-export-keep-a-logins-code-and-passkeys-on-it
 */
function otpauthLink(secret, totp) {
	const params = new URLSearchParams({
		secret,
		period: String(totp.period),
		digits: String(totp.digits),
		algorithm: totp.algorithm,
	})
	if (totp.issuer !== '') {
		params.set('issuer', totp.issuer)
	}
	const key = `otpauth://totp/${encodeURIComponent(totp.label)}?${params}`
	const parsed = parseOtpauth(key)
	if (parsed.period !== totp.period || parsed.digits !== totp.digits) {
		throw new Error(
			`Unsupported TOTP parameters: ${totp.digits} digits every ${totp.period} seconds`,
		)
	}
	return key
}

/**
 * The key as it is stored: a link trimmed, a bare secret without its spacing
 * and upper-cased, so a key copied in groups ("JBSW Y3DP") is saved as one.
 *
 * @param {string} raw The entered key.
 * @return {string} The key to store, '' when nothing was entered.
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
 */
export function normaliseAuthenticatorKey(raw) {
	const value = String(raw ?? '').trim()
	if (OTPAUTH_SCHEME.test(value)) {
		return value
	}
	return value.replace(/\s+/g, '').toUpperCase()
}

/**
 * Why an entered key cannot be saved, or '' when it can (an empty entry is
 * no key at all, which is allowed).
 *
 * @param {string} raw The entered key.
 * @return {string} The error to show, or ''.
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
 */
export function authenticatorKeyError(raw) {
	const value = String(raw ?? '').trim()
	if (value === '') {
		return ''
	}
	try {
		parseOtpauth(value)
		return ''
	} catch {
		return t(
			'keepiq',
			'This is not an authenticator key. Paste an otpauth://totp/ link or the base32 secret.',
		)
	}
}

/**
 * The authenticator key held by a QR code's text: only an `otpauth://totp/`
 * link that parses is accepted, so a QR code for a website or a payment is
 * never saved as a key.
 *
 * @param {string} text The decoded QR text.
 * @return {string} The link.
 * @throws {Error} When the text is not a usable `otpauth://totp/` link.
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
 */
export function otpauthFromQrText(text) {
	const value = String(text ?? '').trim()
	if (OTPAUTH_SCHEME.test(value)) {
		try {
			parseOtpauth(value)
			return value
		} catch {
			// Falls through to the same refusal as any other content.
		}
	}
	throw new Error(
		t(
			'keepiq',
			'This QR code does not hold an authenticator key. Use the QR code the site shows when you set up two-factor sign-in.',
		),
	)
}
