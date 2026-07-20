/**
 * TOTP service for the extension (extension-totp-autofill). Decrypts a matched
 * `totp` secret's seed in the worker and computes the current RFC 6238 code +
 * seconds-remaining, reusing the web app's generator verbatim (`src/totp`). The
 * seed, derived HMAC key, and code are held only transiently and never written
 * to `storage.*` or a request body.
 *
 * An unparseable seed yields `{ valid: false }` — never a fabricated code.
 */

import { parseOtpauth, generateTotp, secondsRemaining } from '../totp/index.js'

/**
 * Compute the current TOTP code for a decrypted seed string (an `otpauth://`
 * URI or a bare base32 secret).
 *
 * @param {string} seed The decrypted seed
 * @param {number} [now] Epoch ms (injectable for tests)
 * @return {Promise<{ valid: boolean, code?: string, secondsRemaining?: number, period?: number }>}
 */
export async function computeTotp(seed, now = undefined) {
	let params
	try {
		params = parseOtpauth(seed)
	} catch {
		return { valid: false }
	}
	if (!params || !params.secret) {
		return { valid: false }
	}
	try {
		const epoch = now === undefined ? undefined : now
		const code = epoch === undefined ? await generateTotp(params) : await generateTotp(params, epoch)
		const remaining = epoch === undefined ? secondsRemaining(params.period) : secondsRemaining(params.period, epoch)
		return { valid: true, code, secondsRemaining: remaining, period: params.period }
	} catch {
		return { valid: false }
	}
}
