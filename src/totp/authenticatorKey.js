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
import { parseOtpauth } from './totp.js'

const OTPAUTH_SCHEME = /^otpauth:\/\//i

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
