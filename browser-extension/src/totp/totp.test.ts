import { describe, expect, it } from 'vitest'
import { generateCode, InvalidTotpSeed, parseTotpSeed, secondsRemaining, type TotpAlgorithm } from './totp'

// RFC 6238 appendix B; the seeds are its ASCII keys in base32.
const SEEDS: Record<TotpAlgorithm, string> = {
	SHA1: 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',
	SHA256: 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQGEZA',
	SHA512: 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQGEZDGNA',
}

describe('generateCode', () => {
	it.each([
		[59, 'SHA1', '94287082'], [59, 'SHA256', '46119246'], [59, 'SHA512', '90693936'],
		[1111111109, 'SHA1', '07081804'], [1111111109, 'SHA256', '68084774'], [1111111109, 'SHA512', '25091201'],
		[1111111111, 'SHA1', '14050471'], [1234567890, 'SHA1', '89005924'],
		[2000000000, 'SHA1', '69279037'], [20000000000, 'SHA1', '65353130'],
	] as const)('at %i with %s gives %s', async (seconds, algorithm, code) => {
		expect(await generateCode({ secret: SEEDS[algorithm], algorithm, digits: 8, period: 30 }, seconds * 1000)).toBe(code)
	})

	it('keeps leading zeros at 6 digits', async () => {
		expect(await generateCode({ secret: SEEDS.SHA1, algorithm: 'SHA1', digits: 6, period: 30 }, 1111111109_000)).toBe('081804')
	})
})

describe('parseTotpSeed', () => {
	it('reads a bare base32 secret with the defaults', () => {
		expect(parseTotpSeed(' gezd gnbv gy3t qojq ')).toMatchObject({ secret: 'GEZDGNBVGY3TQOJQ', algorithm: 'SHA1', digits: 6, period: 30 })
	})

	it('reads an otpauth URI with its parameters and label', () => {
		expect(parseTotpSeed('otpauth://totp/Example:alice@example.com?secret=GEZDGNBVGY3TQOJQ&algorithm=sha256&digits=8&period=60')).toEqual({
			secret: 'GEZDGNBVGY3TQOJQ', algorithm: 'SHA256', digits: 8, period: 60, issuer: 'Example', account: 'alice@example.com',
		})
	})

	it('falls back to the defaults for odd digits and periods', () => {
		expect(parseTotpSeed('otpauth://totp/x?secret=GEZDGNBV&digits=7&period=-5')).toMatchObject({ digits: 6, period: 30 })
	})

	it.each([
		'not-a-seed', '', 'otpauth://hotp/x?secret=GEZDGNBV&counter=1', 'otpauth://totp/x', 'otpauth://totp/x?secret=GEZ1',
		'otpauth://totp/x?secret=GEZDGNBV&algorithm=MD5',
	])('rejects %j', (seed) => {
		expect(() => parseTotpSeed(seed)).toThrow(InvalidTotpSeed)
	})
})

describe('secondsRemaining', () => {
	it('counts down to the next period', () => {
		expect(secondsRemaining(30, 0)).toBe(30)
		expect(secondsRemaining(30, 29_000)).toBe(1)
		expect(secondsRemaining(30, 30_500)).toBe(30)
	})
})
