import { describe, expect, it } from 'vitest'
import { toPolicy } from './policy'

const enabled = { policy_enabled: true, master_password_min_length: 12, block_on_hibp_hit: true }

describe('toPolicy', () => {
	it('is null without an enabled policy or a body', () => {
		for (const raw of [undefined, null, 'policy', 42, {}, { policy_enabled: false, generator_min_length: 20 }, { policy_enabled: 'true' }]) {
			expect(toPolicy(raw)).toBeNull()
		}
	})

	it('maps the generator fields and ignores the rest', () => {
		expect(toPolicy({
			...enabled, generator_min_length: 16, generator_require_upper: true, generator_require_lower: false,
			generator_require_digit: true, generator_require_symbol: true, generator_allow_passphrase: false,
		})).toEqual({ minLength: 16, requireUpper: true, requireLower: false, requireDigit: true, requireSymbol: true, allowPassphrase: false })
	})

	it('takes the server default floor and allows passphrases unless told otherwise', () => {
		expect(toPolicy(enabled)).toEqual({ minLength: 12, requireUpper: false, requireLower: false, requireDigit: false, requireSymbol: false, allowPassphrase: true })
		expect(toPolicy({ ...enabled, generator_allow_passphrase: 'no' })?.allowPassphrase).toBe(true)
	})

	it('keeps the floor between 8 and 128', () => {
		expect(toPolicy({ ...enabled, generator_min_length: 4 })?.minLength).toBe(8)
		expect(toPolicy({ ...enabled, generator_min_length: 200 })?.minLength).toBe(128)
		expect(toPolicy({ ...enabled, generator_min_length: '20' })?.minLength).toBe(20)
		expect(toPolicy({ ...enabled, generator_min_length: 'long' })?.minLength).toBe(12)
		expect(toPolicy({ ...enabled, generator_min_length: null })?.minLength).toBe(12)
	})

	it('truncates a fractional floor, as the web module\'s parseInt does', () => {
		expect(toPolicy({ ...enabled, generator_min_length: 16.9 })?.minLength).toBe(16)
		expect(toPolicy({ ...enabled, generator_min_length: '16.9' })?.minLength).toBe(16)
	})
})
