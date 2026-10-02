/**
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
 *
 * The seed kept on a login lives inside its encrypted additional fields under
 * `totp`; legacy `otp` / `otpauth` members are read as the seed too, and the
 * seed never shows up among the free fields.
 */
import { describe, expect, it } from 'vitest'
import { SEED_FIELD_NAMES, seedFromAdditionalFields, withSeed } from '../../src/totp/seedField.js'

const URI = 'otpauth://totp/rfc?secret=GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'

describe('seedFromAdditionalFields', () => {
	it.each(['totp', 'otp', 'otpauth'])('reads the seed from %s and leaves the rest', (name) => {
		const { seed, rest } = seedFromAdditionalFields({ [name]: URI, pin: '1234' })
		expect(seed).toBe(URI)
		expect(rest).toEqual({ pin: '1234' })
	})

	it('prefers totp over the legacy names and drops all of them from the rest', () => {
		const { seed, rest } = seedFromAdditionalFields({ otp: 'OLD', totp: URI, otpauth: 'OLDER' })
		expect(seed).toBe(URI)
		expect(rest).toEqual({})
	})

	it('answers no seed for an empty object, null and a string blob', () => {
		expect(seedFromAdditionalFields({})).toEqual({ seed: '', rest: {} })
		expect(seedFromAdditionalFields(null)).toEqual({ seed: '', rest: {} })
		expect(seedFromAdditionalFields('not json')).toEqual({ seed: '', rest: {} })
	})

	it('treats a blank seed value as no seed', () => {
		expect(seedFromAdditionalFields({ totp: '   ' }).seed).toBe('')
	})

	it('hands back a non-seed value untouched, so the invalid-seed state can show it is wrong', () => {
		expect(seedFromAdditionalFields({ totp: 'not a seed' }).seed).toBe('not a seed')
	})

	it('names the three reserved keys', () => {
		expect(SEED_FIELD_NAMES).toEqual(['totp', 'otp', 'otpauth'])
	})
})

describe('withSeed', () => {
	it('stores the seed under totp and drops the legacy names', () => {
		expect(withSeed({ pin: '1', otp: 'OLD' }, URI)).toEqual({ pin: '1', totp: URI })
	})

	it('removes every seed key when the seed is cleared', () => {
		expect(withSeed({ pin: '1', totp: URI, otpauth: 'X' }, '  ')).toEqual({ pin: '1' })
	})
})
