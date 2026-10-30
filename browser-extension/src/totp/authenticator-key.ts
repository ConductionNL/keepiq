/** A login's authenticator key as typed, pasted or read from a QR code, taken as the web app's `src/totp/authenticatorKey.js` takes it. */
import { parseTotpSeed } from './totp'

const OTPAUTH = /^otpauth:\/\//i

/** The key as it is stored: a link trimmed, a bare secret without whitespace and upper-cased, so a key copied in groups is saved as one. */
export function normaliseAuthenticatorKey(raw: string): string {
	const value = raw.trim()
	return OTPAUTH.test(value) ? value : value.replace(/\s+/g, '').toUpperCase()
}

/** Whether the code generator reads it, so a key that passes always yields a code. */
export function isAuthenticatorKey(raw: string): boolean {
	try {
		parseTotpSeed(raw)
		return true
	} catch {
		return false
	}
}

/** Only an `otpauth://totp/` link that reads, so a QR code for a website or a payment never becomes a key. */
export function otpauthFromQrText(text: string): string | null {
	const value = text.trim()
	return OTPAUTH.test(value) && isAuthenticatorKey(value) ? value : null
}
