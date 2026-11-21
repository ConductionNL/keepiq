/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Unit tests for the client-side TOTP parser + RFC 6238 code generator.
 *
 * Asserts the published RFC 6238 test vectors, otpauth:// parsing variants, the
 * bare-base32 default path, the invalid-seed error branch (never a fabricated
 * code), and the no-leak contract (the generator touches no network and no Web
 * Storage API).
 *
 * @spec openspec/changes/add-totp-secrets/specs/secrets/spec.md#requirement-client-side-totp-code-generation
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import {
	base32Decode,
	generateTotp,
	parseOtpauth,
	secondsRemaining,
	TOTP_DEFAULTS,
} from '../../src/totp/totp.js'

// RFC 6238 Appendix B — the SHA1 seed is ASCII "12345678901234567890" (20
// bytes); its base32 encoding is the widely published value below. Each vector
// is (unix time seconds -> 8-digit code) for SHA1, period 30.
const SHA1_SECRET_BASE32 = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'
const SHA1_VECTORS = [
	[59, '94287082'],
	[1111111109, '07081804'],
	[1111111111, '14050471'],
	[1234567890, '89005924'],
	[2000000000, '69279037'],
	[20000000000, '65353130'],
]

describe('base32Decode', () => {
	it('decodes the RFC 6238 SHA1 seed to the ASCII bytes', () => {
		const bytes = base32Decode(SHA1_SECRET_BASE32)
		expect(new TextDecoder().decode(bytes)).toBe('12345678901234567890')
	})

	it('ignores whitespace and padding', () => {
		const a = base32Decode('JBSWY3DPEHPK3PXP')
		const b = base32Decode('jbsw y3dp ehpk 3pxp====')
		expect(Array.from(a)).toEqual(Array.from(b))
	})

	it('rejects a non-base32 character', () => {
		expect(() => base32Decode('JBSWY3DP1')).toThrow()
	})

	it('rejects an empty secret', () => {
		expect(() => base32Decode('')).toThrow()
	})

	it('names each refusal', () => {
		expect(() => base32Decode(null)).toThrow('Empty base32 secret')
		expect(() => base32Decode('  ==')).toThrow('Empty base32 secret')
		expect(() => base32Decode('JB1')).toThrow('Invalid base32 character')
		expect(() => base32Decode('A')).toThrow('Base32 secret too short')
		expect(Array.from(base32Decode('ME'))).toEqual([0x61])
	})
})

describe('parseOtpauth', () => {
	it('parses a bare base32 secret with RFC 6238 defaults', () => {
		const params = parseOtpauth(SHA1_SECRET_BASE32)
		expect(params.secret).toBe(SHA1_SECRET_BASE32)
		expect(params.algorithm).toBe(TOTP_DEFAULTS.algorithm)
		expect(params.digits).toBe(6)
		expect(params.period).toBe(30)
	})

	it('parses a full otpauth URI with issuer/account/algorithm/digits/period', () => {
		const uri =
			'otpauth://totp/ACME%20Co:alice@acme.com?secret='
			+ SHA1_SECRET_BASE32
			+ '&issuer=ACME%20Co&algorithm=SHA256&digits=8&period=60'
		const params = parseOtpauth(uri)
		expect(params.secret).toBe(SHA1_SECRET_BASE32)
		expect(params.issuer).toBe('ACME Co')
		expect(params.account).toBe('alice@acme.com')
		expect(params.algorithm).toBe('SHA256')
		expect(params.digits).toBe(8)
		expect(params.period).toBe(60)
	})

	it('applies defaults for omitted URI params', () => {
		const params = parseOtpauth(
			'otpauth://totp/Example?secret=' + SHA1_SECRET_BASE32,
		)
		expect(params.algorithm).toBe('SHA1')
		expect(params.digits).toBe(6)
		expect(params.period).toBe(30)
	})

	it('rejects a non-totp otpauth URI (e.g. HOTP)', () => {
		expect(() =>
			parseOtpauth(
				'otpauth://hotp/Example?secret=' + SHA1_SECRET_BASE32 + '&counter=1',
			),
		).toThrow()
	})

	it('rejects an otpauth URI with no secret', () => {
		expect(() => parseOtpauth('otpauth://totp/Example?issuer=ACME')).toThrow()
	})

	it('rejects a garbage seed (never returns params)', () => {
		expect(() => parseOtpauth('not a valid seed !!!')).toThrow()
		expect(() => parseOtpauth('')).toThrow()
	})

	const KEY = 'JBSWY3DPEHPK3PXP'
	const URI = 'otpauth://totp/'

	it.each([
		[
			'bare key, lower case with spaces',
			' jbsw y3dp ehpk 3pxp ',
			{ secret: KEY },
		],
		['bare key, padded', `${KEY}====`, { secret: `${KEY}====` }],
		[
			'secret parameter, lower case with spaces',
			`${URI}A:b?secret=jbsw%20y3dp%20ehpk%203pxp`,
			{ secret: KEY },
		],
		[
			'upper-case scheme and host',
			`OTPAUTH://TOTP/Acme:alice?secret=${KEY}`,
			{ issuer: 'Acme', account: 'alice' },
		],
		[
			'surrounding whitespace',
			`  ${URI}A:b?secret=${KEY}  `,
			{ secret: KEY, issuer: 'A', account: 'b' },
		],
		[
			'label issuer and account',
			`${URI}Acme:alice@example.org?secret=${KEY}`,
			{ issuer: 'Acme', account: 'alice@example.org' },
		],
		[
			'issuer parameter wins over the label',
			`${URI}Label:alice?secret=${KEY}&issuer=Param`,
			{ issuer: 'Param', account: 'alice' },
		],
		[
			'blank issuer parameter falls back to the label',
			`${URI}Label:alice?secret=${KEY}&issuer=`,
			{ issuer: 'Label', account: 'alice' },
		],
		[
			'issuer parameter, no colon',
			`${URI}alice?secret=${KEY}&issuer=Acme`,
			{ issuer: 'Acme', account: 'alice' },
		],
		[
			'blank issuer parameter, no colon',
			`${URI}alice?secret=${KEY}&issuer=`,
			{ issuer: null, account: 'alice' },
		],
		[
			'account only',
			`${URI}alice?secret=${KEY}`,
			{ issuer: null, account: 'alice' },
		],
		['no label', `${URI}?secret=${KEY}`, { issuer: null, account: null }],
		[
			'no label, no slash',
			`otpauth://totp?secret=${KEY}`,
			{ issuer: null, account: null },
		],
		['colon only', `${URI}:?secret=${KEY}`, { issuer: null, account: null }],
		[
			'blank issuer in label',
			`${URI}%20:alice?secret=${KEY}`,
			{ issuer: null, account: 'alice' },
		],
		[
			'blank account in label',
			`${URI}Acme:%20?secret=${KEY}`,
			{ issuer: 'Acme', account: null },
		],
		[
			'account with spaces around it',
			`${URI}Acme:%20alice%20?secret=${KEY}`,
			{ issuer: 'Acme', account: 'alice' },
		],
		[
			'several colons',
			`${URI}Acme:team:alice?secret=${KEY}`,
			{ issuer: 'Acme', account: 'team:alice' },
		],
		[
			'encoded label',
			`${URI}Acme%20Corp%3Aalice%20doe?secret=${KEY}`,
			{ issuer: 'Acme Corp', account: 'alice doe' },
		],
		[
			'sha256, eight digits, sixty seconds',
			`${URI}A:b?secret=${KEY}&algorithm=SHA256&digits=8&period=60`,
			{ algorithm: 'SHA256', digits: 8, period: 60 },
		],
		[
			'lower-case algorithm',
			`${URI}A:b?secret=${KEY}&algorithm=sha512`,
			{ algorithm: 'SHA512' },
		],
		['seven digits', `${URI}A:b?secret=${KEY}&digits=7`, { digits: 6 }],
		['ten digits', `${URI}A:b?secret=${KEY}&digits=10`, { digits: 6 }],
		['digits not a number', `${URI}A:b?secret=${KEY}&digits=x`, { digits: 6 }],
		['zero period', `${URI}A:b?secret=${KEY}&period=0`, { period: 30 }],
		['negative period', `${URI}A:b?secret=${KEY}&period=-30`, { period: 30 }],
		[
			'period not a number',
			`${URI}A:b?secret=${KEY}&period=soon`,
			{ period: 30 },
		],
		[
			'empty parameters',
			`${URI}A:b?secret=${KEY}&algorithm=&digits=&period=`,
			{ algorithm: 'SHA1', digits: 6, period: 30 },
		],
	])('parses %s', (_, input, expected) => {
		expect(parseOtpauth(input)).toMatchObject(expected)
	})

	it.each([
		['null', null, 'Empty TOTP seed'],
		['undefined', undefined, 'Empty TOTP seed'],
		['blank', '   \n ', 'Empty TOTP seed'],
		['a number', 12345, 'Invalid base32 character'],
		[
			'bare key, invalid character',
			'JBSWY3DPEHPK3PX1',
			'Invalid base32 character',
		],
		['bare key, one character', 'A', 'Base32 secret too short'],
		['scheme only', 'otpauth://', 'Not an otpauth://totp URI'],
		[
			'hotp URI',
			`otpauth://hotp/Acme:alice?secret=${KEY}&counter=1`,
			'Not an otpauth://totp URI',
		],
		[
			'host with port',
			`otpauth://totp:99/A:b?secret=${KEY}`,
			'Not an otpauth://totp URI',
		],
		['broken host', `otpauth://[::1/A:b?secret=${KEY}`, 'Malformed otpauth URI'],
		['no secret', `${URI}Acme:alice?issuer=Acme`, 'otpauth URI has no secret'],
		['empty secret', `${URI}Acme:alice?secret=`, 'otpauth URI has no secret'],
		[
			'invalid secret',
			`${URI}Acme:alice?secret=NOT-BASE32`,
			'Invalid base32 character',
		],
		[
			'unknown algorithm',
			`${URI}A:b?secret=${KEY}&algorithm=MD5`,
			'Unsupported TOTP algorithm: MD5',
		],
		[
			'unknown algorithm before a malformed label',
			`${URI}Acme%E0%A4%A:alice?secret=${KEY}&algorithm=MD5`,
			'Unsupported TOTP algorithm: MD5',
		],
	])('refuses %s', (_, input, message) => {
		expect(() => parseOtpauth(input)).toThrow(message)
	})
})

describe('generateTotp — RFC 6238 SHA1 vectors', () => {
	it.each(SHA1_VECTORS)('time %i s -> %s', async (timeSeconds, expected) => {
		const params = {
			secret: SHA1_SECRET_BASE32,
			algorithm: 'SHA1',
			digits: 8,
			period: 30,
		}
		const code = await generateTotp(params, timeSeconds * 1000)
		expect(code).toBe(expected)
	})

	it('produces a zero-padded 6-digit code by default', async () => {
		const params = parseOtpauth(SHA1_SECRET_BASE32)
		const code = await generateTotp(params, 59 * 1000)
		// The 6-digit truncation of the 8-digit "94287082" vector.
		expect(code).toBe('287082')
		expect(code).toHaveLength(6)
	})
})

describe('secondsRemaining', () => {
	it('counts down within a 30s window', () => {
		expect(secondsRemaining(30, 0)).toBe(30)
		expect(secondsRemaining(30, 1000)).toBe(29)
		expect(secondsRemaining(30, 29000)).toBe(1)
		expect(secondsRemaining(30, 30000)).toBe(30)
	})
})

describe('no-leak contract', () => {
	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('the generator issues no network request and no storage write', async () => {
		const originalFetch = globalThis.fetch
		const originalLocal = globalThis.localStorage
		const originalSession = globalThis.sessionStorage
		const fetchSpy = vi.fn()
		const xhrSpy = vi.fn()
		globalThis.fetch = fetchSpy
		if (typeof globalThis.XMLHttpRequest === 'function') {
			vi.spyOn(globalThis.XMLHttpRequest.prototype, 'open').mockImplementation(
				xhrSpy,
			)
		}
		const setItem = vi.fn()
		const store = { setItem, getItem: () => null, removeItem: () => {} }
		globalThis.localStorage = store
		globalThis.sessionStorage = store

		try {
			const params = parseOtpauth(
				'otpauth://totp/ACME:alice?secret=' + SHA1_SECRET_BASE32,
			)
			const code = await generateTotp(params, 59 * 1000)

			expect(code).toBeTruthy()
			expect(fetchSpy).not.toHaveBeenCalled()
			expect(xhrSpy).not.toHaveBeenCalled()
			expect(setItem).not.toHaveBeenCalled()
		} finally {
			globalThis.fetch = originalFetch
			globalThis.localStorage = originalLocal
			globalThis.sessionStorage = originalSession
		}
	})
})
