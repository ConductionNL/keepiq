/**
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-the-extension-fills-the-logins-own-code
 *
 * At fill time the worker computes the code from the filled login's own seed
 * and only falls back to the host lookup when the login carries none. Two
 * logins on one host never get each other's code.
 */
import { describe, expect, it, vi } from 'vitest'
import {
	loginTotpCode,
	seedFromBlob,
} from '../../browser-extension/src/lib/login-totp.js'
import { computeTotp } from '../../browser-extension/src/lib/totp-service.js'

// RFC 6238 SHA1 seed "12345678901234567890" and a second, different seed.
const SEED_A = 'otpauth://totp/a?secret=GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'
const SEED_B = 'otpauth://totp/b?secret=JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP'
const AT = 59000

/** The "ciphertext" is the plaintext with a prefix; decrypt strips it. */
const decryptField = async (c) => (c ? c.replace(/^enc:/, '') : '')
function rowWith(fields) {
	return {
		id: 'x',
		additionalFields: 'enc:' + JSON.stringify(fields),
	}
}
const compute = (seed) => computeTotp(seed, AT)

describe('seedFromBlob', () => {
	it.each(['totp', 'otp', 'otpauth'])('reads %s', (name) => {
		expect(seedFromBlob(JSON.stringify({ [name]: SEED_A }))).toBe(SEED_A)
	})

	it('answers empty for no blob, bad JSON and a blank seed', () => {
		expect(seedFromBlob('')).toBe('')
		expect(seedFromBlob('not json')).toBe('')
		expect(seedFromBlob(JSON.stringify({ totp: '  ' }))).toBe('')
	})
})

describe('loginTotpCode', () => {
	it('two logins on one host each get their own code', async () => {
		const fallback = vi.fn().mockResolvedValue('999999')
		const a = await loginTotpCode(rowWith({ totp: SEED_A }), {
			decryptField,
			compute,
			fallback,
		})
		const b = await loginTotpCode(rowWith({ totp: SEED_B }), {
			decryptField,
			compute,
			fallback,
		})

		expect(a).toBe('287082')
		expect(b).not.toBe(a)
		expect(b).toBe((await computeTotp(SEED_B, AT)).code)
		expect(fallback).not.toHaveBeenCalled()
	})

	it('falls back to the host lookup only when the login carries no seed', async () => {
		const fallback = vi.fn().mockResolvedValue('123456')
		expect(
			await loginTotpCode(rowWith({ pin: '1' }), {
				decryptField,
				compute,
				fallback,
			}),
		).toBe('123456')
		expect(
			await loginTotpCode({ id: 'y' }, { decryptField, compute, fallback }),
		).toBe('123456')
		expect(fallback).toHaveBeenCalledTimes(2)
	})

	it("an invalid seed on the login gives no code and does not borrow another item's", async () => {
		const fallback = vi.fn().mockResolvedValue('123456')
		expect(
			await loginTotpCode(rowWith({ totp: 'not a seed !@#' }), {
				decryptField,
				compute,
				fallback,
			}),
		).toBe(null)
		expect(fallback).not.toHaveBeenCalled()
	})

	it('a decrypt failure gives no code', async () => {
		const fallback = vi.fn().mockResolvedValue('123456')
		const failing = async () => {
			throw new Error('locked')
		}
		expect(
			await loginTotpCode(rowWith({ totp: SEED_A }), {
				decryptField: failing,
				compute,
				fallback,
			}),
		).toBe(null)
	})
})
